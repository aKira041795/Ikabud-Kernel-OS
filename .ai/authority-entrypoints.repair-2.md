# REPAIR 2 — entry-point wiring (PR #119, CI failures)

status: CHANGES_REQUIRED
owner: implementation agent (Sol, via Pi)
repo: `/var/www/html/ikabudsix` — branch: `feat/authority-entrypoints` (work in the tree)
context: `.ai/authority-entrypoints.contract.md`, `.ai/authority-entrypoints.repair-1.md`, PR #119

Good news first: the wiring repair worked. `static-analysis` passes, and **103 of 108 test files pass in CI**, up
from 96. All three previously failing tests are green there. Two failures remain, both with exact evidence.

## R1 — the "continuing without a scope" messages are logged at the wrong level

CI failure (`eventbus_durable_outbox_test`, 21 passed / 1 failed):

```
✗ app.log has no warning/error/critical findings —
  [warning] capability.authority_scope.unresolved {"reason":"tenant_authority_store_unresolv...
  [warning] WorkflowEngine: start could not establish a service authority scope; continuing without one
```

**The behaviour is correct** — the operation continued, exactly as repair brief 1 required. Only the **level**
is wrong. That test asserts a clean `app.log`, and it is right to: a healthy run should not emit warnings.

**The repository already has the convention for this**, in
`CapabilityAuthorizationRegistry::logSeedDecision()`:

```php
write_log('capability.policy.seed.' . $decision, $decision === 'widening_refused' ? 'warning' : 'info', $context);
```

A **handled skip is logged at `info`**; `warning` is reserved for something the system *refused* to do, such as
rejecting a widening declaration. Establish the same split:

| condition | level | why |
|---|---|---|
| `missing_tenant_authority_scope` | `info` | expected; the operation proceeds and capability calls fail closed |
| `tenant_authority_store_unavailable` | `info` | expected in environments where a tenant has no resolvable store |
| "could not establish a scope; continuing without one" | `info` | handled degradation, not a fault |
| `unknown_authority_entry_point` | **`warning`** | a programming error — keep it loud |

Apply to every message the wiring added: `AuthorityScopeResolver`'s logger
(`capability.authority_scope.unresolved`, line ~88), `EventTriggers`, `WorkflowEngine` (start + advance),
`WorkflowRuntime`, `IntegrationBridge`, `Workbench/Scenario/run.php` (×2), `Workbench/AI/WorkbenchAiAnalyzer`.

**Do not** silence these entirely, and do not stop recording the reason — the requirement from the ADR is that a
degraded authority decision is *recorded*, just not at a level that makes normal operation look broken.

## R2 — `tests/authority_scope_test.php` assumes a tenant never shares the kernel database

CI failure (20 passed / 1 failed):

```
✗ withScope dispatches a real capability through the CLI tenant store —
  {"ok":true,"tenant_id":7581061,"database":"ikabudsix_ci","entry_point":"cli"}
```

The dispatch **succeeded** with the right entry point. Note `database: ikabudsix_ci` — that is the CI **base**
database. In CI a fixture tenant can legitimately resolve to the base database, so an assertion that the
tenant store differs from the kernel store cannot hold there. (The sibling assertion at line ~212 comparing the
resolved database to `$fixtureDatabase` passed, so the mismatch is in the remaining conjuncts.)

Make the assertion environment-independent **without losing its substance**:
- Successful dispatch, correct tenant, correct entry point: keep all three.
- Compare the tenant id with a cast (`(int)($result['tenant_id'] ?? 0) === $fixtureTenantId`) so an integer/
  string difference cannot fail it.
- Compare `database` against the fixture tenant's own store derived **at that point** from
  `app()->dbForTenant($fixtureTenantId)`, not against a captured constant — and where the fixture tenant's store
  *is* the kernel store, that is a valid configuration, not a failure.
- Do **not** delete the assertion and do not weaken it to "no exception thrown".

Keep every other assertion, including the fail-closed and ambient-fallback falsifiers. Those are the point of the
test — I have verified twice that restoring the ambient fallback in `db()` makes it fail.

## Constraints

- Do not touch `tests/admin_platform_api_test.php` or `tests/workflow_concurrency_test.php`.
- Do not add migrations, do not invent schema, do not change `authorize()` or the read-path denial semantics.
- Keep the "declare when resolvable, never refuse the operation" rule from repair 1 intact. Do not reintroduce a
  refusal to make a log quiet.
- Do not touch `modules/daily-ledger/**`.
- Do not commit, push or branch.
- Restore `storage/modules.json` ownership/permissions after `composer test`.

## Verification

1. `tests/eventbus_durable_outbox_test.php` passes in CI — locally it may skip; say which.
2. `tests/authority_scope_test.php` passes **locally** (it did: 21/0) and the changed assertion is
   environment-independent. Show the new assertion text.
3. Show that a degraded scope is still **recorded** (the reason appears in the log) — just at `info`.
4. `unknown_authority_entry_point` still logs at `warning`.
5. Full suite `0 failed`; architecture:check; governance gate; PHPStan 0 in `kernel/`+`src/`+`tests/`;
   php-cs-fixer clean.
6. Live on `akiracms.test`: `/`, `/posts`, `/login` all 200, `error.log` empty.
7. State the CI-only risks you cannot verify locally.

## Deliverable

Result block: status, changed, implementation summary, verification, evidence, scope, risks, unresolved,
recommended next state.
