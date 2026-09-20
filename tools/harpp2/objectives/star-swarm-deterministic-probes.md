# OBJECTIVE — STAR SWARM: make the acceptance deterministic (fix the flaky instrument)

system: HARPP v2 · 2026-09-17 · chair-authored. Delegated to Sol.

## Why this exists (measured, not theorised)

The Star Swarm acceptance is **flaky**, and the flakiness has already cost a full escalation:

- Run 3b was recorded `verified: 0, no_progress: 4` and escalated with condition `irreversibility` while **every gate
  passed minutes later**. Cause: the driver ran the acceptance once and believed it.
- One flake was isolated to the exact assertion: `a fired shot creates bright pixels above the ship` —
  `Expected: > 8, Received: 0` while `state.bullets.length` had already grown. The probe sampled a fixed 30×130
  window **once**; the shot crossed it in a couple of hundred milliseconds, so the sample raced the projectile.
  Polling fixed that one: 1 failure in 5 runs → 10/10.
- Remaining probes still **sample live animation once**: `scaleBands`, `nurseryOrigin`, `dispersal`, `centroidPath`,
  `pairwiseMinDistance`, `starfield` bright-pixel counts and the planet/ship cluster probes. Each is a race by
  construction.

**The rule this item implements.** *A probe that samples a running animation once is a race.* Determinism comes from
driving the game's own clock and state, never from loosening a threshold, deleting an assertion, or adding a sleep.

## The work

### 1. Add a deterministic test surface to the game (the mechanism)
Expose a minimal, documented, read-only-in-spirit test hook on the running game, e.g.

- `window.StarSwarm.test.step(frames)` — advance exactly `frames` fixed-timestep frames **synchronously**, applying
  input in a defined order, without waiting on wall-clock time;
- `window.StarSwarm.test.spawnWave(index)` — start a specific wave deterministically;
- `window.StarSwarm.test.kill(index)` — remove a specific enemy through the real kill path (so dispersal and effects run);
- `window.StarSwarm.test.snapshotEnemy(index)` — read one enemy's full record (`x, y, scale, class, role`).

It must not become a second code path: the hooks drive the **same** update/draw functions the real loop uses. If a
probe can pass through the hook but fail in play, the hook is wrong.

### 2. Re-express every timing-sensitive probe on that surface
All of the following must become deterministic — same assertion, same threshold, no sleeps:

| probe | currently | must become |
|---|---|---|
| `pairwiseMinDistance` | samples live overlap across samples | step N frames deterministically, then measure |
| `centroidPath` | samples the centroid over wall-clock time | measure across a fixed frame count |
| `dispersal` | hopes a kill lands in time | `test.kill(...)` through the real kill path, measure before/after |
| `scaleBands` | hopes ≥3 scales are live at the sample moment | `spawnWave`/`step` to a state where the bands are live |
| `nurseryOrigin` | samples new-wave enemies after they may have flown off | step from wave start, measure the origin |
| `silhouetteClasses`, `roleCoverage`, starfield/planet/ship pixel probes | single canvas sample | step to a fixed frame, then sample |

### 3. Make stability a GATE, not an aspiration
A new command must fail when the suite is not repeatable:

```
$ bash tools/harpp2/stability.sh tests/browser/star-swarm.spec.ts 10
```

## Scope

- `modules/star-swarm/`
- `templates/modules/star-swarm/`
- `public/star-swarm/`
- `tests/browser/star-swarm.spec.ts`
- `tests/star_swarm_visual_test.php` (extend only)
- `tools/harpp2/stability.sh` (if it needs adjusting; it already exists)

## Acceptance — all five, and the driver gates them

```
$ bash tools/harpp2/stability.sh tests/browser/star-swarm.spec.ts 10
$ php tests/star_swarm_concept_test.php
$ php tests/star_swarm_visual_test.php
$ npx playwright test tests/browser/star-swarm.spec.ts
$ php tests/star_swarm_shot_check.php
```

The stability gate must report **10/10**. Expect the first runs to expose the remaining races — that is the point of
the item.

## Boundaries — these are the ways this item could be faked

- **Never loosen a threshold, never delete an assertion, never add a sleep or `waitForTimeout` to make a probe pass.**
  If a probe cannot be made deterministic honestly, say so and stop; that is a finding, not a failure.
- Keep every existing assertion; you are re-expressing, not replacing or reducing. The browser spec must not lose a
  single check and the structural test must not lose its count.
- No new dependency, no CDN, no bundler, no build step, no image/audio assets (**raster count stays 0**), no schema, no
  database, no tenant change. `php -l` clean; every `.disyl` passes `php _lint_disyl.php`.
- The same URL (`http://akiracms.test/star-swarm/`) and delivery keep working.
- The test surface must not change game behaviour when unused: with no test hooks called, play is identical.
