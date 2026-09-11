# CONTRACT — Wire the non-HTTP entry points to the authority scope

status: READY_FOR_IMPLEMENTATION
owner: implementation agent (Sol, via Pi)
repo: `/var/www/html/ikabudsix` — branch: `feat/authority-entrypoints`
chair: this session · authority: continues PR #118 (merged `2fd2105`)

Read first: `docs/architecture/authority-store-adr.md` (§*The one precondition that makes all five hold*)
and `docs/architecture/akira-beyond-the-cms.md` §P2 — specifically *"Not yet covered, and not claimed:
scheduled jobs, event handlers, CLI handlers and other direct callables remain outside this inventory."*

PR #118 built `AuthorityScope` + `AuthorityScopeResolver`. This slice **uses** it: every non-HTTP entry
point must declare its transport and tenant explicitly instead of relying on a SAPI guess.

## Verified facts — do not re-derive

`AuthorityScopeResolver::resolveForCapability()` selects the entry point as:

```php
$entryPoint = strtolower(trim((string)($options['authority_entry_point'] ?? '')));
if ($entryPoint === '') {
    $entryPoint = PHP_SAPI === 'cli' ? self::CLI : self::WEB;
}
```

and takes the tenant from `$options['tenant_id']`, else from `TenantResolver::current()`.

`authority_entry_point` is currently set in **exactly one place in the whole repository** — a test fixture
(`tests/capability_authorization_policy_migration_test.php:307`). Nothing in production declares its
transport.

**Not one non-HTTP capability invocation passes a tenant or an entry point today.** Verified sites and
what they actually pass:

| site | passes today |
|---|---|
| `kernel/EventTriggers.php:697` | `caller`, `correlation_id`, `request_id` |
| `kernel/WorkflowEngine.php:901` | `correlation_id` |
| `kernel/IntegrationBridge.php:334` | `caller`, `caller_module`, `correlation_id`, `request_id` |
| `kernel/Workbench/Scenario/run.php:56` | `$context` + `provider` |
| `kernel/Workbench/AI/WorkbenchAiAnalyzer.php:89` | `caller_module`, `timeout_ms` |
| `kernel/Capabilities/CapabilityAuthorizationRegistry.php:343` | *(the registry's own audit call — nothing)* |

They work over HTTP only because the SAPI guess lands on `WEB` and `TenantResolver::current()` is the
request tenant. Off HTTP they resolve `CLI` and depend on whether something happened to establish a
tenant — otherwise the call is denied, or the wrong store is read.

Tenant availability, verified:

- `kernel/EventTriggers.php:243` already reads `app()->tenant()->current()`.
- `kernel/WorkflowEngine.php:569` and `kernel/WorkflowRuntime.php:113,576` likewise.
- `kernel/Services/PushWorker.php` selects `tenant_id` per row (its queue table has the column).
- `kernel/IntegrationBridge.php` has **no tenant handling at all** — zero occurrences.
- `migrations/015_kernel_durable_event_outbox.sql` has `tenant_id`.
- `migrations/009_kernel_workflow_runs.sql` has **no** `tenant_id` (it has `context_json`).
- `migrations/006_kernel_job_queue.sql` has **no** `tenant_id`, and **no runner for it exists anywhere
  in the repository** — `kernel_job_queue` appears only in the migration registry and one test.

## Deliverables

### A1 — One explicit scope-establishment primitive

Add a single kernel primitive that runs a unit of work under an explicit authority scope, e.g.

```php
AuthorityScopeResolver::withScope(int $tenantId, string $entryPoint, callable $work): mixed
```

Requirements:
- Assert `$tenantId > 0` and a **known** entry point (`supportsEntryPoint()`); reject otherwise.
- Assert the tenant's authority store resolves. If it does not, **fail closed** and record the reason —
  do not run `$work` against an unresolved store.
- Establish the tenant for the **whole unit of work** (`TenantResolver`), not merely for the capability
  call: the DB reads, audits and writes inside the unit must target the same tenant. Restore the
  previous tenant in `finally`, including on exception.
- Declare the transport for the duration, so capability calls inside the unit resolve the right entry
  point **without every call site threading options**. A scoped "current entry point" that
  `resolveForCapability()` consults *before* the SAPI guess is the intended shape. The SAPI guess stays
  as the last-resort default for callers that declare nothing.
- Nesting must be safe (inner scope restores to the outer scope, not to null).

**Do not** thread `tenant_id` through every individual call site as an alternative — that leaves the
rest of each unit reading the wrong store, which is the defect this whole line of work exists to remove.

### A2 — Adopt it at every entry point whose tenant is knowable

Convert each of these to declare its transport and obtain its tenant explicitly:

1. `kernel/EventTriggers.php` — event handlers. Tenant is already read at `:243`; make it explicit.
2. `kernel/WorkflowEngine.php` + `kernel/WorkflowRuntime.php` — workflow execution.
3. `kernel/Services/PushWorker.php` — establish **per row** (the row carries `tenant_id`); this is a
   genuine background worker and is the clearest proof the primitive works off-request.
4. `kernel/Workbench/Scenario/run.php` + `kernel/Workbench/AI/WorkbenchAiAnalyzer.php` — `WORKBENCH`.
   The tenant must come from an explicit argument, never a guess.
5. **CLI handlers** in `./ikabud` — `CLI`. Commands that reach capability calls must establish the
   tenant from their own arguments (`tenant:migrate <tenant>`, etc. already take one). A command with no
   tenant must **not** silently proceed to a tenant read.
6. `kernel/Capabilities/CapabilityAuthorizationRegistry.php:343` — the registry's own
   `kernel.audit.record@1` call. It has the scope in hand; pass it. A grant transition whose audit is
   silently denied is an audit gap, which is worse than a failure.

If any site genuinely cannot determine a tenant, it must **fail closed with a recorded reason**, not
fall back to a guess. Say which ones those are.

### A3 — The integration bridge: decide, don't guess

`kernel/IntegrationBridge.php` has **no tenant handling whatsoever**. Before wiring it, determine how an
integration is scoped (is an integration global, per-tenant, or per-connection?) by reading the
integration tables and their migrations. Then either wire it with the tenant you established, or report
precisely why it cannot be wired without a schema decision. Do not invent a tenant for it.

## Explicitly out of scope — report, do not fake

- **Scheduled jobs.** No runner exists for `kernel_job_queue`, and the table has no `tenant_id`. There is
  nothing to wire. Adding a runner or a tenant column is a schema/architecture decision for the product
  owner — **do not add a migration for this**. State the finding and stop.
- **Queue-drained workflow runs.** `kernel_workflow_runs` has no `tenant_id`; a drain cannot know which
  tenant a run belongs to. Report the gap; do not invent schema.
- Do not change `authorize()`, the read-path denial semantics, or the ambient-fallback prohibition from
  #118. This slice consumes that API; it does not revise it.

## Constraints

- MySQL 5.7 safe. No new dependencies. No migrations in this slice.
- Do not touch `modules/daily-ledger/**`.
- Do **not** modify `tests/admin_platform_api_test.php` (rejected twice already).
- Do not commit, push or branch.
- Restore `storage/modules.json` ownership/permissions after `composer test` (it is gitignored, so a
  missing file is not itself a failure).

## Acceptance

1. **Proof of the primitive off-request:** a test that runs a unit of work under `withScope()` for a real
   tenant from a CLI context and shows a capability call inside it resolving that tenant's store — not
   the kernel store, and not a denial. Paste the output.
2. **Proof of fail-closed:** with a tenant whose store does not resolve, the primitive refuses to run the
   work and records why. State the falsifier.
3. **Proof of restoration:** after the scope exits — including when `$work` throws — the previous tenant
   is restored. Show it, don't assert it.
4. **PushWorker:** show it establishing the tenant per row (a worker with rows for two different tenants
   must not process both under one scope).
5. **The audit call:** show `kernel.audit.record@1` from the registry resolving a real tenant scope
   rather than being denied.
6. `composer test`: `0 failed`. **State explicitly which tests skip locally** — several tenant-dependent
   tests skip here and run in CI, so a local green does not prove their paths.
7. `php ikabud architecture:check` pass, `workbench:governance --all --gate` PASS, PHPStan clean for
   `kernel/`+`src/` with no baseline drift, php-cs-fixer clean.
8. Live tenant unaffected: `/`, `/posts`, `/login` all 200, `error.log` empty.
9. A short, honest list of every entry point you did **not** wire and why.

## Deliverable

Result block: status, changed, implementation summary, verification, evidence, scope, risks,
unresolved, recommended next state. If a deliverable cannot be completed, say which and why rather than
weakening a test or inventing schema to make it pass.
