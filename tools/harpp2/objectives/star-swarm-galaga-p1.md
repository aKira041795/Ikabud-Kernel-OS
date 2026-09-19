# OBJECTIVE — Star Swarm / Galaga fidelity, **phase 1: the state surface and the castes**

system: HARPP v2 · 2026-09-18 · chair-authored. Executor: any lane the driver assigns.
authority: director directive 2026-09-18 — *"rebuild it. make it closer to the original game. components,
animation, points, game play"*. Full requirements: `tools/harpp2/projects/star-swarm-galaga.json`.
master objective (do not read as this one): `tools/harpp2/objectives/star-swarm-galaga-rebuild.md`.

## Why this is a phase, not the whole milestone

Measured 2026-09-18: the complete rebuild is a single all-or-nothing gate, so it cannot turn green until the
last requirement — and the driver then reads every real chunk as `no_progress` (three chunks, 201 lines of
product change, gate 12 → 27, recorded as no progress, item escalated). That is an instrument defect, and this
phase split is its repair: one satisfiable slice per objective, one verdict per slice.

Phase 1 is the foundation every later phase reads: **the state surface** (all 17 observability fields) and the
**three enemy castes** as drawn components.

## The item

Make the phase-1 gate green without breaking anything else:

```
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=1
```

It currently reports **24 passed / 5 failed**. The worklist it prints is the whole brief:

- the state surface must expose `dualFighter`, `capturedFighter` and `perfectBonus` (the other 14 fields are
  already there) — real state, never a fabricated field;
- requirement **C2** — the player ship and the docked dual fighter are distinct silhouettes, the dual fighter
  visibly two ships;
- requirement **C3** — every sprite stays procedural (no raster assets), which the invariant already enforces.

Castes C1 (bee / butterfly / boss, visually distinct) and C4 (the two-shot limit) are already satisfied — do
not regress them.

## Scope

- `public/star-swarm/`
- `templates/modules/star-swarm/`
- `modules/star-swarm/`
- `tests/browser/star-swarm.spec.ts`
- `tests/star_swarm_visual_test.php`
- `tools/harpp2/stability.sh`

## Acceptance — all of them, and the driver gates them

```
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=1
$ npx playwright test tests/browser/star-swarm.spec.ts
$ php tests/star_swarm_visual_test.php
$ php tests/star_swarm_concept_test.php
$ php tests/star_swarm_shot_check.php
$ composer test
```

Phase 1 does not include the 10-run stability gate: that belongs to the finished game (phase 3). The
determinism invariant is still enforced here — `waitForTimeout` is forbidden.

## Boundaries

- **Do not edit `tools/harpp2/projects/star-swarm-galaga.json` or `tools/harpp2/gates/`.** They are the
  acceptance instruments and are outside your scope. Making the gate pass by editing either one is a failure.
- **No fabricated state.** A field may be a live view over real state (as `shots` already is over `bullets`),
  never a placeholder that exists only for a probe.
- **Never loosen a threshold, delete an assertion, or add a sleep or `waitForTimeout`.** Drive the game's own
  deterministic surface — `test.step(frames)`, `test.spawnWave(index)`, `test.kill(index)`,
  `test.snapshotEnemy(index)` — and extend it if a probe needs more.
- **Keep every existing assertion**; the spec may grow, not shrink. The invariant checks the baseline for you.
- **No raster assets, no new dependency, no CDN, no bundler.** Sprites stay procedural canvas paths whose
  silhouettes read as Galaga's.
- The same URL keeps serving the same module, and `php _lint_disyl.php` stays clean for any `.disyl` you touch.
- Say so if part of this cannot be met honestly — a recorded finding beats a passing gate that lies.
