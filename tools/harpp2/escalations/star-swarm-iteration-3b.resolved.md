# RESOLVED — star-swarm-iteration-3b (closed by the chair, 2026-09-16)

**This marker exists so `tools/harpp2/status.sh` stops listing the item as awaiting the chair.**
It is a chair closure, not a driver verdict: the driver still records `status: escalated, verified: 0, no_progress: 4`
for that run, and that record is left intact — the whole point of the episode is that the record describes the
instrument, not the product.

## Verdict

**The work was complete.** Chair verification, 2026-09-16:

| gate | result |
|---|---|
| concept (`tests/star_swarm_concept_test.php`) | 23 passed, 0 failed (was 12/11) |
| structural (`tests/star_swarm_visual_test.php`) | 18 passed, 0 failed |
| browser spec (×10 consecutive) | 10/10 after the shot-probe repair |
| screenshot artifact | 4 passed, 0 failed |
| project E2E (`tools/harpp2/e2e.sh star-swarm`) | **6/6 gates PASS** — message 1103 |

## Why the driver said otherwise (all three were harness defects)

1. **`globalSetup` spent a login per invocation**; the limiter allows 5 per 300 s and the driver re-runs the
   acceptance per chunk. Its own verification tripped the limiter, `waitForURL` timed out inside auth setup, and the
   run was scored as failing product work. *Fixed*: session reuse, zero login POSTs (proved over five runs).
2. **A probe raced the projectile it measured** — `a fired shot creates bright pixels above the ship`,
   `Expected: > 8, Received: 0`, single sample of a shot crossing its window. *Fixed*: same region, same threshold,
   now polled → 10/10.
3. **A non-boundary finding had to claim a boundary** — `escalate()` accepts only authority/boundary/irreversibility,
   so "no progress" was reported as `irreversibility`. *Fixed*: `needsChair()` writes `**Boundary:** NONE`.

## Non-vacuity evidence (the negative control the driver asked for)

`separateColony()`'s relaxation loop disabled (8 passes → 0) → the spec failed with
`no pair of settled live enemy bodies overlaps across ten samples`, `Expected: >= 1.5, Received: -24.589…`.
Restore verified byte-identically (`sha256 422f8442574c0d7e…`, zero lingering patches).

## Provenance

CD-74 (the failure), CD-75 (the closure and the proof), CD-76 (the driver fixes) in `.ai/chair-decisions.md`;
`docs/testing/harness-lessons.md` L1–L4 and L7.
