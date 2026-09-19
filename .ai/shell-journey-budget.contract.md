# SLICE — the shell journey must fit its budget: 11/11

project: akira-completion · status: READY_FOR_IMPLEMENTATION · revision: 1
milestone: 1 · phase: P1 (performance — the last thing between here and a green suite)
repo: `/var/www/html/ikabudsix`
lane: openai-codex/gpt-5.6-sol
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "openai-codex/gpt-5.6-sol", "--name", "shell-journey-budget", "<CONTRACT>"]

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

authority: `.ai/projects/akira-cms-completion/project.md` (ACTIVE), phase P1.

## Objective

`npx playwright test` must report **11 passed**, with **no change to `playwright.config.js`** and **no change to
`tests/browser/akira-admin-shell.spec.ts`**.

Theme Studio now renders inside the shared shell — that work is done and verified. What remains is budget: the shell
spec traverses **every sidebar destination × 2 viewports** and currently exceeds the configured per-test timeout.

Measured baseline (real output, 2026-09-15):

```
$ npx playwright test tests/browser/akira-admin-shell.spec.ts --timeout=120000
  2 passed (3.0m)                        # the assertions are correct and pass

$ npx playwright test
  9 passed, 2 failed                     # the same two shell tests time out
```

`playwright.config.js:43` sets `timeout: 30000`. **That file is an absolute prohibition** — the `gate_config`
matcher classifies it as *"disabling, skipping, deleting or weakening an existing test or gate to get a pass"*, and
`.github/instructions/ai-autonomy-escalation.instructions.md` states that no authorisation can lift an absolute
prohibition. So the timeout cannot be raised by you, by the director, or by any contract.

**Therefore the page must get faster.** The spec performs ~21 navigations per viewport, all with `?nocache=1`, so it
is measuring **cold** render. Roughly 45s per viewport over ~21 routes is ~2.1s per cold admin page; 30s needs about
**1.4s or less**. That is a ~35% reduction, and it is a real product objective: `?nocache=1` is what a first-time
visitor experiences.

## Lead — investigate this first, but prove it before relying on it

Every request appears to run the governance seeds. `cms-akira-core/helpers.php:352` calls `cacSeedGovernancePolicies()`
at **file load**, and `cms-akira-shell/helpers.php` calls `akiraShellSeedAdminPagePolicy()` the same way. The
tenant holds **1531** rows in `capability_authorization_policies`. If those seeds write on every request, they tax
every page in the product.

That is a **lead, not a conclusion**. Measure first (`curl -w '%{time_total}'` with a warmed session, or Playwright
timings), then attribute the cost to specific work. Do not optimise what you have not measured.

## Files likely affected

- `modules/cms-akira/cms-akira-shell/helpers.php`
- `modules/cms-akira/cms-akira-core/helpers.php`
- `modules/cms-akira/cms-akira-theme/helpers.php`

## Forbidden changes

- `kernel/`
- `tools/`
- `playwright.config.js`
- `phpstan-baseline.neon`
- `tests/`

## Architectural constraints

- This slice must make the full browser suite report 11 passed with the configuration and the spec **unchanged**.
- **Do not raise or bypass the timeout.** Editing `playwright.config.js` is an absolute prohibition, and adding a
  per-test timeout override to the spec is the same act in a different file. The budget is the budget.
- **Do not weaken or delete any test or assertion.** A skip is not a pass.
- **Do not edit the tenant database.** Correctness must not depend on tenant-57 state.
- If the root cause lies in `kernel/Capabilities/` — for example inside
  `CapabilityAuthorizationRegistry::seedPolicy()` — **stop and report it**. That path is an absolute prohibition;
  a clear statement of the cost and its location is a valuable result and will be accepted.
- Correctness first: no page may render differently or more cheaply in a way that changes what the user sees. Caching
  that hides a change is not a fix. If you add caching, it must be invalidated correctly and proven so.

## Required tests

```
npx playwright test                                          # must be 11 passed, 0 failed, 0 skipped
npx playwright test tests/browser/akira-admin-shell.spec.ts  # must be 2 passed within the 30s default
php modules/cms-akira/cms-akira-shell/tests/admin_page_capability_test.php
php -l <every changed .php file>
vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G <every changed file>
```

Report exact passed / failed / skipped counts, and the **before and after** timing for a single cold admin page.

## Acceptance criteria

1. `npx playwright test` reports **11 passed**, with `playwright.config.js` and the shell spec byte-identical to
   dispatch.
2. `npx playwright test tests/browser/akira-admin-shell.spec.ts` passes **within the 30s default** — not with an
   override.
3. The cold-render improvement is **attributed with measurements**, not asserted: a figure before, a figure after,
   and the specific work removed or deferred.
4. Every changed file passes PHPStan under `-c phpstan.neon`.
5. `git diff --stat` shows no change under `kernel/`, `tools/`, or `tests/`.

## Risks

- **Chasing the timeout instead of the cost.** The tempting moves — a config edit, a per-test override, dropping a
  route, relaxing an assertion — are all forbidden, and all of them would leave the slow page in place.
- **Caching that changes behaviour.** A page cache that serves one session's HTML to another is a defect this
  repository has already paid for once. If you cache, prove invalidation.
- **The negative result is acceptable.** If the cost is kernel-owned and therefore unreachable, say so with the
  measurement and the location; do not purchase a green suite by weakening the journey.
