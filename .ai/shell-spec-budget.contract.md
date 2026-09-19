# SLICE — the shell spec must fit its budget: screenshots on failure

project: akira-completion · status: READY_FOR_IMPLEMENTATION · revision: 1
milestone: 1 · phase: P1 (verification budget — option A, director-selected 2026-09-15)
repo: `/var/www/html/ikabudsix`
lane: openai-codex/gpt-5.6-sol
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "openai-codex/gpt-5.6-sol", "--name", "shell-spec-budget", "<CONTRACT>"]

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

authority: director selected **option A** on 2026-09-15, from the options presented after
`.ai/shell-journey-budget-2.contract.md`: *per-route screenshots become failure-only.*

## Objective

`npx playwright test` must report **11 passed**, with `playwright.config.js` **unchanged** and every assertion,
every route, and the test count **unchanged**.

### Why this is the change, measured

The product is no longer the constraint. Two performance passes took a cold admin request from **1.572s to 0.390s
(−75%)**. The shell test's own trace now reads:

| | |
|---|---|
| navigation — the product | 8.4s |
| locator waits | 8.1s |
| screenshots (42 × `fullPage`, every route × 2 viewports) | 4.5s |
| other assertions/operations | ~9s |
| **total** | **~30s against a 30s budget** |

`playwright.config.js:43` (`timeout: 30000`) is an **absolute prohibition** — the `gate_config` matcher classifies
it as *"disabling, skipping, deleting or weakening an existing test or gate to get a pass"* — so the budget cannot
move. The spec's own screenshot cost can, without touching what the journey proves.

## Files likely affected

- `tests/browser/akira-admin-shell.spec.ts`

## Forbidden changes

- `modules/`
- `kernel/`
- `tools/`
- `playwright.config.js`
- `phpstan-baseline.neon`

## Architectural constraints

**This slice edits a test. That is the single most abusable change available in this repository, so the boundary is
stated explicitly:**

- **Every route must still be visited and asserted, on both viewports.** Do not remove a route from the enumeration,
  do not skip one, do not narrow the loop.
- **The navigation assertion must remain intact** — `getByRole('navigation', { name: 'Akira administration' })` must
  still be asserted *visible* on every route, and the link-count assertion must stay.
- **Failure evidence must survive.** A screenshot must still be captured when the journey fails. Removing the
  screenshot entirely — rather than making it failure-only — is refused: it destroys the artefact at exactly the
  moment it is needed.
- **The test count must stay 11.** Do not split the spec into more tests to fit the budget; that changes the suite's
  contract with the release gate without proving anything new.
- **Do not touch the product.** `modules/` is forbidden: the performance work is complete and this slice must not be
  a pretext to change behaviour.
- If you find that a green suite requires relaxing anything above, **stop and report it** instead. A red suite with
  an honest explanation is a better result than a green one obtained by weakening the journey.

### Consequence to record, not solve

Phase **P4** calls for a *"screenshot set per surface"*. Making the in-suite screenshots failure-only reduces the
artefact set a green run produces, so P4's evidence must later come from a separate, **non-gated** capture rather
than from the assertion tests. **Note this in your report; do not build it here.**

## Required tests

```
npx playwright test                                          # must be 11 passed, 0 failed, 0 skipped
npx playwright test tests/browser/akira-admin-shell.spec.ts  # must be 2 passed within the 30s default
git diff --stat tests/browser/akira-admin-shell.spec.ts      # must show a change confined to the spec
```

Report exact passed / failed / skipped counts, the shell test's duration **before and after**, and confirmation that
`playwright.config.js` is byte-identical (a hash is acceptable evidence).

## Acceptance criteria

1. `npx playwright test` reports **11 passed**, 0 failed, 0 skipped — with `playwright.config.js` unchanged.
2. `npx playwright test tests/browser/akira-admin-shell.spec.ts` passes **within the 30s default**, not with an
   override.
3. **Coverage is provably unchanged**: state how many routes × viewports the test asserts, and show that the same
   number were asserted before your change. A copy of the enumeration argument is not evidence; a count from a run is.
4. **Failure evidence still exists.** Demonstrate it: force the assertion to fail once (for example by temporarily
   pointing one route at a surface without the navigation) and show that a screenshot artefact is still produced.
   Revert the temporary change afterwards and say so.
5. The only difference in the spec is the screenshot mechanism — assertions, enumeration and test count unchanged.

## Risks

- **Weakening the test to obtain a pass.** The green suite is the prize here, which makes this the most likely
  failure of the slice. Every constraint above exists for that reason.
- **Removing evidence instead of deferring it.** Failure-only is the selected option; no screenshots at all is not.
- **Silently changing coverage.** If the asserted route count differs after your change, that is a failure even if
  the suite is green.
