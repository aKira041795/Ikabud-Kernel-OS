# SLICE — close the seed cost across every module owner: 11/11

project: akira-completion · status: READY_FOR_IMPLEMENTATION · revision: 1
milestone: 1 · phase: P1 (performance, part 2 of 2)
repo: `/var/www/html/ikabudsix`
lane: openai-codex/gpt-5.6-sol
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "openai-codex/gpt-5.6-sol", "--name", "shell-journey-budget-2", "<CONTRACT>"]

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

authority: `.ai/projects/akira-cms-completion/project.md` (ACTIVE), phase P1. Continuation of
`.ai/shell-journey-budget.contract.md`, whose result is recorded below. HARPP #102 is **not** required: the residual
paths are contract-relative ("outside the approved scope"), which is the scope of this contract, so it is resolved by
authoring rather than by the director.

## Predecessor result — build on this, do not repeat it

The first pass proved the hypothesis and half-fixed it:

```
seedPolicy() runs a query per declaration; enabled module helpers invoke 20 policy-seed
batches on EVERY GET.

cold authenticated dashboard request:   1.572s  ->  1.035s     (34%)
SQL removed: 43 SELECTs + 43 prepared statements per request
```

Applied in `cms-akira-core`, `cms-akira-shell` and `cms-akira-theme` helpers: mutation declarations no longer
reconcile on GET/HEAD/OPTIONS, read declarations are limited to the surfaces that need them, and the active policy
version is resolved request-locally.

**The 30-second gate is still red**, because the remaining unconditional seeds live in the module helpers below.

## Objective

`npx playwright test` must report **11 passed**, with `playwright.config.js` and
`tests/browser/akira-admin-shell.spec.ts` **unchanged**.

Apply the same gating to the remaining seed owners, then prove the gate with the exact command. The spec traverses
every sidebar destination × 2 viewports with `?nocache=1` against a 30s per-test budget; ~1.035s per cold page is
close but not yet inside it.

## Files likely affected

- `modules/cms-akira/cms-akira-builder/helpers.php`
- `modules/cms-akira/cms-akira-media/helpers.php`
- `modules/cms-akira/cms-akira-navigation/helpers.php`
- `modules/cms-akira/cms-akira-search/helpers.php`
- `modules/cms-akira/cms-akira-seo/helpers.php`
- `modules/cms-akira/cms-akira-workflow/helpers.php`
- `modules/cms-akira/cms-akira-core/helpers.php`
- `modules/cms-akira/cms-akira-shell/helpers.php`
- `modules/cms-akira/cms-akira-theme/helpers.php`

## Forbidden changes

- `kernel/`
- `tools/`
- `playwright.config.js`
- `phpstan-baseline.neon`
- `tests/`

## Architectural constraints

- This slice must make the full browser suite report 11 passed with the configuration and the spec **unchanged**.
- **Do not raise or bypass the timeout.** Editing `playwright.config.js` is an absolute prohibition; a per-test
  override inside the spec is the same act in another file.
- **Do not weaken, skip or delete any test or assertion.**
- **Do not edit the tenant database.** Correctness must not depend on tenant-54 state.
- **Do not make seeding conditional in a way that changes authorisation outcome.** A policy row that a request
  genuinely needs must still be seeded for that request. Narrow *when* a declaration reconciles, never *whether* the
  resulting authority is correct. If a route can now be reached with a stale or missing policy row, that is a
  security regression, not an optimisation — prove the opposite or do not ship it.
- No page may render differently. If you add caching, invalidation must be proven; a cache that serves one session's
  HTML to another is a defect this repository has already paid for.
- If the remaining cost is owned by `kernel/Capabilities/`, **stop and report it** rather than widening scope.

## Required tests

```
npx playwright test                                          # must be 11 passed, 0 failed, 0 skipped
npx playwright test tests/browser/akira-admin-shell.spec.ts  # must be 2 passed within the 30s default
php modules/cms-akira/cms-akira-shell/tests/admin_page_capability_test.php
php -l <every changed .php file>
vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G <every changed file>
git diff --check
```

Report exact passed / failed / skipped counts, and the cold-request timing **before and after** this pass.

## Acceptance criteria

1. `npx playwright test` reports **11 passed**, with `playwright.config.js` and the shell spec byte-identical to
   dispatch.
2. `npx playwright test tests/browser/akira-admin-shell.spec.ts` passes **within the 30s default**, not with an
   override.
3. The improvement is **attributed with measurements**: a cold-request figure before and after, and the specific
   work removed.
4. **Authorisation is provably unchanged.** The capability test passes, and you state the argument for why gating a
   declaration cannot leave a route without the policy row it needs.
5. `git diff --stat` shows no change under `kernel/`, `tools/`, or `tests/`.

## Risks

- **Chasing the timeout instead of the cost** — every tempting shortcut (config edit, per-test override, dropping a
  route, relaxing an assertion) is forbidden and would leave the slow page in place.
- **Gating a declaration a route actually needs.** This is the one way this slice can do real harm: an admin surface
  that renders without its policy row fails closed and looks like a permissions bug. Test the surfaces, do not
  reason about them.
- **The honest negative result is acceptable.** If the residual cost is kernel-owned, say so with the measurement
  and the location.
