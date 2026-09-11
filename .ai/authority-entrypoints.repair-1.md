# REPAIR 1 — entry-point wiring (PR pending, branch `feat/authority-entrypoints`)

status: CHANGES_REQUIRED
owner: implementation agent (Sol, via Pi)
repo: `/var/www/html/ikabudsix` — branch: `feat/authority-entrypoints` (work in the tree)
context: `.ai/authority-entrypoints.contract.md`

The primitive (`AuthorityScopeResolver::withScope()`, `currentEntryPoint()`, the `EVENT` entry point) and
most of the wiring are good and worth keeping. **One design error runs through the whole slice and it
must be corrected before anything lands.**

## The rule you broke

**Declaring a transport is not the same as authorizing an operation.**

You consistently converted *"no tenant scope is available"* into *"refuse to perform the operation"*.
Those are different things, and the second is a behaviour regression:

- `kernel/EventTriggers.php` — dropped the event entirely (`return;`) when no tenant was current, and
  again if `withScope()` threw. **I have already fixed this one; do not redo it.** An event is a record
  of a fact, not an authorization decision. It is now emitted always; the capability call inside fails
  closed on its own. That single fix took `admin_platform_api_test` from 7/6 to **13/0** and
  `admin_kernel_control_plane_api_test` from 34/6 to **40/0**. It is the pattern to copy.
- `kernel/WorkflowEngine.php` — `start()` and `advance()` return a synthetic
  `['ok' => false, 'error' => 'missing_tenant_authority_scope']` instead of running.
- `kernel/WorkflowRuntime.php` — `stateGet()` / `transition()` likewise refuse.
- `kernel/IntegrationBridge.php`, `kernel/Services/PushWorker.php`, the Workbench sites — check each.

## Required behaviour

For every entry point you wire:

1. **If a tenant is resolvable** → declare it for the duration with
   `AuthorityScopeResolver::withScope($tenantId, <ENTRY_POINT>, $work)`. This is the valuable part of
   your work: capability calls inside now declare their transport instead of guessing from SAPI.
2. **If no tenant is resolvable** → **still perform the operation**, without a declared scope. Do not
   drop, do not return a synthetic error, do not skip the work.
   The capability calls inside then resolve no scope and **fail closed by themselves** — `#118` already
   guarantees that (denial with a recorded reason, and since repair 2 of that PR, no crash). That is the
   fail-closed boundary, and it is at the capability call, not at the operation.
3. Never swallow a `withScope()` failure into a silent skip or a synthetic error. Log it and **continue
   with the operation**.
4. Guard against re-entrancy the way the EventTriggers fix does (a boolean set before the body runs, so
   a throw after the body cannot cause a second execution).

Rule of thumb: a transport declaration changes *how authority is resolved*, never *whether the work
happens*.

## Evidence this is the defect, not the tests

`tests/workflow_concurrency_test.php` is **unmodified by you** and fails consistently (3/3 runs):

```
✗ both starts are blocked inside the exact production GET_LOCK
UNCAUGHT PDOException: SQLSTATE[HY000]: General error: 2006 MySQL server has gone away
```

On `main` that test passed in **1.7s**; with your change it takes **9.7s** and fails. Cause: production
`start()` no longer reaches the mutex, because it returns your synthetic
`missing_tenant_authority_scope` error first. The assertion is about the production lock path, so the
test is right and the change is wrong.

## Re-audit your own test edits

You modified four tests: `tests/unified_execution_trace_test.php`, `tests/workflow_engine_test.php`,
`tests/workflow_lifecycle_test.php`, `tests/workflow_runtime_guard_test.php`.

For **each** one, state whether the edit:
- **(a)** legitimately establishes a tenant fixture the test always needed, or
- **(b)** only exists to accommodate a refusal that should not be happening.

Revert every (b). A test edit that makes a test pass because the production code no longer does the work
is masking a regression, not fixing one. `tests/admin_platform_api_test.php` stays untouched.

## Verification

1. `tests/workflow_concurrency_test.php` passes **unmodified** — do not edit it.
2. `tests/admin_platform_api_test.php` and `tests/admin_kernel_control_plane_api_test.php` pass
   **unmodified** (I verified 13/0 and 40/0 after the EventTriggers fix; they must stay green).
3. Full suite `0 failed`, paste the `Total:` line. State which tests skip locally — CI is the oracle for
   those.
4. Show that a capability call with no resolvable scope still **denies with a recorded reason** rather
   than running: paste the evidence. This is the property that must not be lost while removing the
   refusals.
5. Live tenant on `akiracms.test` (NOT `cmsnew.test` — you used the wrong host last time): `/`, `/posts`,
   `/login` all 200, `error.log` empty.

## Constraints

- Do not touch `tests/admin_platform_api_test.php`.
- Do not add migrations, do not invent schema, do not change `authorize()` or the read-path denial
  semantics.
- Do not touch `modules/daily-ledger/**`.
- Do not commit, push or branch.
- Restore `storage/modules.json` ownership/permissions after `composer test`.
- Keep the honest out-of-scope report for scheduled jobs / queue-drained workflow runs — that part was
  correct.

## Deliverable

Result block: status, changed, implementation summary, verification, evidence, scope, risks,
unresolved, recommended next state. Do not weaken a test or invent behaviour to reach green.
