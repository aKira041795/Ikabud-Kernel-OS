# SLICE — make the partitioned shell spec fit its budget by removing harness overhead

project: akira-completion · status: READY_FOR_IMPLEMENTATION · revision: 1
milestone: 1 · phase: P1 (verification budget — repair of `shell-spec-split`)
repo: `/var/www/html/ikabudsix`
lane: openai-codex/gpt-5.6-sol
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "openai-codex/gpt-5.6-sol", "--name", "shell-spec-split-2", "<CONTRACT>"]

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

predecessor_run: `shell-spec-split` · repair_level: **L2** (implementation-strategy change)

## Why this slice exists

`shell-spec-split` partitioned the shell spec and reported `17 passed, 0 failed, 0 skipped`. **An independent
run measured 15 passed, 2 failed.** The partitioning is correct in shape — coverage is intact, `playwright.config.js`
is untouched — but it does not meet its own acceptance criterion.

Measured truth (per-partition durations; the whole spec passes when given budget, so **there is no product defect**):

| viewport | 1/4 | 2/4 | 3/4 | 4/4 | **total work** |
|---|---|---|---|---|---|
| 900px | 19.2s | 23.4s | 24.9s | 19.9s | **87.4s** |
| 600px | 27.6s | 40.2s | 33.5s | 35.7s | **137.0s** |

Against an unchanged **30s** default. So: 600px partitions 2/4 (40.2s), 3/4 (33.5s) and 4/4 (35.7s) all exceed it,
and 900px at 24.9s has almost no margin.

**The partition multiplied total work instead of dividing it.** Splitting 20 routes across four tests means the
per-test setup — landing navigation, nav discovery, context setup — is paid four times per viewport instead of once.

## Objective

Every shell test passes within the **unchanged 30s default**, with coverage identical, **without the suite's total
wall time ballooning**, and with every existing assertion intact.

## The hypothesis to verify — do not skip this

`assertNoSignOutCollision()` performs **two sequential awaited calls per navigation link**
(`link.innerText()` and `link.boundingBox()`, awaited together but per link, in a loop). The sidebar carries
**19–20 links**, so that is **~40 round-trips per call** — and at 600px the function is called **twice** per route
(before the scroll, and again after). That is **~80 round-trips per route at 600px**, roughly **1600 per full
viewport pass**, plus a `fullPage` screenshot per route.

**Confirm or refute this by measurement before changing anything.** Instrument; do not assume. If the time is
somewhere else — the `fullPage` screenshots of the heavy admin pages (`permissions`, `authority`, `provenance`
render long capability tables) are a plausible alternative — report what you actually measured and target that.

## Required approach

1. **Measure first.** Establish where the per-route time actually goes. A raised `--timeout` is fine for
   *measurement*; the acceptance run must use the **default**.
2. **Remove harness round-trip overhead.** Where the same assertions can be expressed in a single browser
   evaluation — one `nav.evaluate()` returning every link's box, then the intersection maths in the test — do that.
   This is the preferred fix: identical assertions, ~40× fewer waits. It weakens nothing.
3. **Partition by measured cost, not by route count.** Routes are not equally expensive. If finer partitioning is
   still required, size each partition against its own measured cost so every one lands well inside 30s.
4. **Do not inflate the suite.** More tests each paying full setup is the failure mode this slice exists to correct.
   Report the full-suite wall time before and after.

## Files likely affected

- `tests/browser/akira-admin-shell.spec.ts`

## Forbidden changes

- `modules/`
- `kernel/`
- `tools/`
- `playwright.config.js`
- `phpstan-baseline.neon`

## Architectural constraints

- **Every assertion is preserved.** No weakening, no skipping, no deletion, no conditional early return, no
  `test.skip` / `test.fixme`, no per-test timeout override (`test.setTimeout`) and no `test.slow()`.
- **The 600px-specific coverage must survive in full**, including all four of its assertions: the nav must overflow
  its own scroll area (`scrollHeight > clientHeight`), it must be independently scrollable (`scrollTop > 0`),
  **Sign out must remain in the viewport after the nav scrolls**, and the collision check must still run **both
  before and after** that scroll. Collapsing the two collision calls into one silently deletes coverage — if you
  batch the round-trips, still assert both times.
- **Coverage is identical**: 20 routes per viewport, and the flattened-union assertion
  (`shell route partitions must contain every discovered route exactly once and in discovery order`) is retained.
- **The screenshot set is intact**: a green run still writes one `fullPage` screenshot per route — 40 in total.
- **This slice edits a test, which is the most abusable change in this repository.** If greening requires relaxing
  any assertion above, **stop and report** — a red suite with an honest explanation beats a green one obtained by
  weakening the journey.
- Keep `partitionRoutes()`'s union guard meaningful: it must still fail if a route is dropped.

## Required tests

```
npx playwright test tests/browser/akira-admin-shell.spec.ts   # all passed, at the DEFAULT timeout
npx playwright test                                          # 0 failed, 0 skipped
git diff --stat tests/browser/akira-admin-shell.spec.ts
```

Report exact passed / failed / skipped counts, **each** shell test's duration, the slowest shell test's duration,
the full-suite wall time, and the asserted route count per viewport — all as figures from a run, not from reading
the source.

## Acceptance criteria

1. `npx playwright test` reports **0 failed, 0 skipped**, with `playwright.config.js` unchanged (state its hash).
2. **Every** shell test passes at the **default** 30s timeout. State the slowest shell test's duration — it must
   have real margin below 30s, not 29.5s.
3. **Coverage proven unchanged**: 20 asserted routes per viewport, evidenced from run output, on both viewports.
4. **Screenshots intact**: a green run still produces 40 screenshot files.
5. **The union guard still fails on an incomplete partition.** Re-demonstrate it (omit a route, observe the
   failure, revert) and say so.
6. The full-suite wall time is reported and compared against the 17-test baseline, so a green suite is not bought
   with a much slower one.
7. `git diff --stat` shows changes confined to `tests/browser/akira-admin-shell.spec.ts`.
8. State plainly which hypothesis your measurement confirmed, with the numbers that confirmed it.

## Risks

- **Trading assertions for time.** The single most likely failure of this slice: making a test fit by dropping the
  post-scroll collision check or the deep-link assertions.
- **Adding tests until it fits.** That pays setup cost repeatedly and inflates the suite; it treats the symptom.
- **Optimising without measuring**, and removing something load-bearing by accident.
- Reporting a green suite without having run the *full* suite at the default timeout — the predecessor made exactly
  this claim and it did not reproduce.
