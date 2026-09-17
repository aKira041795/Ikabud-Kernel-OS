# SLICE — partition the shell spec so every test fits its budget

project: akira-completion · status: READY_FOR_IMPLEMENTATION · revision: 1
milestone: 1 · phase: P1 (verification budget — option B, director-selected 2026-09-15)
repo: `/var/www/html/ikabudsix`
lane: openai-codex/gpt-5.6-sol
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "openai-codex/gpt-5.6-sol", "--name", "shell-spec-split", "<CONTRACT>"]

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

authority: the director selected **option B** on 2026-09-15. An option-A slice
(`.ai/shell-spec-budget.contract.md`, screenshots on failure) was **dispatched and then stopped** when B was
approved: it was superseded, and its partial spec edit was reverted to HEAD.

## Objective

`npx playwright test` must report **every test passing**, with `playwright.config.js` **unchanged** and **coverage
identical** to the current spec.

The current shell spec has one test per viewport, each traversing every sidebar destination plus `/cms-akira-theme`.
Measured trace of one such test:

| | |
|---|---|
| navigation — the product | 8.4s |
| locator waits | 8.1s |
| screenshots (every route × `fullPage`) | 4.5s |
| other assertions/operations | ~9s |
| **total** | **~30s against a 30s budget** |

`playwright.config.js:43` (`timeout: 30000`) is an **absolute prohibition** — the `gate_config` matcher classifies
it as *"disabling, skipping, deleting or weakening an existing test or gate to get a pass"* — so the budget cannot
move. The work can be **partitioned** instead: the same routes, the same assertions, the same screenshot set,
distributed across more tests so each one fits.

**B was chosen over A precisely because it keeps the full screenshot set on a green run.** Preserving that is part
of the objective, not a side effect.

## Files likely affected

- `tests/browser/akira-admin-shell.spec.ts`

## Forbidden changes

- `modules/`
- `kernel/`
- `tools/`
- `playwright.config.js`
- `phpstan-baseline.neon`

## Architectural constraints

**This slice edits a test. That is the most abusable change in this repository, so the boundary is explicit:**

- **Coverage must be identical.** Every route the current spec asserts must still be asserted, on **both**
  viewports. The union of the partitions must equal the original set exactly — no route dropped, added or skipped.
- **The navigation assertion must remain intact** on every route:
  `getByRole('navigation', { name: 'Akira administration' })` asserted *visible*, plus the link-count assertion.
  Do not add a timeout override to it.
- **The screenshot set must survive**: a green run must still produce a screenshot for every route, as it does now.
- **Do not weaken, skip, or delete any assertion**, and do not add `test.skip` / `test.fixme` / conditional
  early-returns.
- **Do not touch the product.** `modules/` is forbidden: the performance work is complete.
- **The partition must fail loudly if it is incomplete.** If the routes are discovered at runtime, slicing them
  silently risks dropping one. Whatever partitioning you choose, make an incomplete union an explicit test failure —
  a partition that quietly covers 20 of 21 routes is exactly the failure this constraint exists to prevent.
- If greening the suite requires relaxing anything above, **stop and report it**. A red suite with an honest
  explanation is a better result than a green one obtained by weakening the journey.

### Expected consequence, to record rather than hide

The suite total will rise above its current **11** (that is the point of partitioning). State the new total, the
number of shell tests, and the per-test duration. Note that `tests/browser/auth.setup.ts` documents an older
expectation (*"exactly 9 tests"*) in a comment — **do not edit that file**; simply report the new total so the
documentation can be corrected separately.

## Required tests

```
npx playwright test                                          # every test passed, 0 failed, 0 skipped
npx playwright test tests/browser/akira-admin-shell.spec.ts  # every test passed within the 30s default
git diff --stat tests/browser/akira-admin-shell.spec.ts
```

Report exact passed / failed / skipped counts, the new suite total, each shell test's duration, and the asserted
route count **per viewport** — with a figure from the run, not from reading the source.

## Acceptance criteria

1. `npx playwright test` reports **0 failed, 0 skipped**, with `playwright.config.js` unchanged (a hash is
   acceptable evidence).
2. Every shell test passes **within the 30s default**, with no per-test timeout override.
3. **Coverage is proven unchanged**: the asserted route count per viewport equals the count the current spec
   asserts, evidenced from a run. State both numbers.
4. **The screenshot set is intact**: a green run still writes a screenshot for every asserted route. Show the
   produced file count.
5. **An incomplete partition fails.** Demonstrate it — for example by temporarily un-slicing one route and showing
   the suite red — then revert and say so.
6. `git diff --stat` shows changes confined to the spec file.

## Risks

- **Weakening the test to obtain a pass.** The green suite is the prize, which makes this the likeliest failure of
  the slice. Every constraint exists for that reason.
- **A silent gap in the partition.** Runtime route discovery plus slicing is exactly how a route disappears without
  anyone noticing.
- **Dropping the screenshots** to buy time — that is option A, which the director did not choose.
- **Changing the product** under cover of a test change.
