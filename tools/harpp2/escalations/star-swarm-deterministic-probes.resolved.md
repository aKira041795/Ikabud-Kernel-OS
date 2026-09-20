# RESOLVED — star-swarm-deterministic-probes (closed by the chair, 2026-09-17)

**This marker exists so `tools/harpp2/status.sh` stops listing the item as awaiting the chair.** The driver's own
record is left intact (`escalated`, `condition: boundary`) — it is the evidence for the two defects described below.

## Verdict: the work was done, and the instrument is now stable

Chair verification, 2026-09-17, run by hand after the tree went quitement:

| check | result |
|---|---|
| `php tests/star_swarm_concept_test.php` | **23 passed, 0 failed** (9 probes intact) |
| `php tests/star_swarm_visual_test.php` | **21 passed, 0 failed** (was 18) |
| `npx playwright test tests/browser/star-swarm.spec.ts` | **2 passed** — 52 assertions (was 1 test / 28) |
| `bash tools/harpp2/stability.sh tests/browser/star-swarm.spec.ts 10` | **10/10 runs passed — STABILITY PASS** |
| thresholds | unchanged: `< 35` dark space, `> 8` shot pixels, `>= 1.5` overlap, `> 100` ship pixels |
| determinism mechanism | present: `spawnWave`, `snapshotEnemy`, `step`, `freeze` |

The second spec test is literally *"deterministic surface drives the real fixed-step game path"* — the mechanism the
objective asked for, asserted rather than described.

## Why the driver escalated (twice, each for a different reason)

1. **Attempt 1 — my fault, and the guard was right.** `boundary — outside objective scope:
   docs/reviews/harness-independent-evaluation-brief.md; outside objective scope: tools/harpp2/status.sh`. The chair
   edited those two files **while the run was live**; the driver attributed the changes to its own run and refused it.
   The rule was already recorded and was broken anyway: *never edit a path while a run is live.*
2. **Attempt 2 — a false positive (lesson L8).** `boundary — test assertion removed or weakened:
   tests/browser/star-swarm.spec.ts`, on a file that had been **strengthened**: 28 → 52 assertions, thresholds intact,
   all nine probes present, structural 18 → 21. The guard compares lines, so moving an assertion into another test is
   indistinguishable from deleting it. **A guard that blocks the work which strengthens the suite is the defect.**

## Still to do (named, not closed)

The weakening guard must compare **assertion sets with their thresholds**, not lines. Until it does, every legitimately
restructured test file will be refused at `finish`.

## Provenance

CD-77 (owner-authorised commit, the live-run defect, the L8 finding); `docs/testing/harness-lessons.md` (L1–L4, L7);
commits `efe458e` (the game and its gates) and `cc3e7e7` (the harness).
