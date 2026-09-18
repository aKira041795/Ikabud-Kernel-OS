# OBJECTIVE — Star Swarm / Galaga fidelity, **phase 3: gameplay, and the finished game**

system: HARPP v2 · 2026-09-18 · chair-authored. Executor: any lane the driver assigns.
authority: director directive 2026-09-18. Requirements: `tools/harpp2/projects/star-swarm-galaga.json`.
Runs after phases 1 and 2.

## The item

Make the phase-3 gate green **and then the complete gate**, which is this phase's real finish line:

```
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=3
$ php tools/harpp2/gates/star_swarm_galaga_gate.php
```

Phase 3 currently reports **8 passed / 5 failed** and the complete gate **28 passed / 15 failed**. The five
gameplay requirements:

- **G1** — a stage is a cycle: entry, formation, repeated dive waves, stage clear, with difficulty ramping as
  stages advance (more enemy projectiles, faster dives).
- **G2** — every fourth stage is a **challenging stage**: forty enemies, preset patterns, no firing.
- **G3** — a Boss Galaga may capture the player's ship with a **tractor beam**, costing a life; destroying the
  diving captor frees the ship, which docks as a **dual fighter** with double firepower and a larger hitbox.
- **G4** — destroying the captor while it holds the fighter **in formation** turns the captured ship into an
  enemy instead of freeing it. This is the rule that makes capture a gamble.
- **G5** — colliding with an enemy or an enemy shot costs a life; the player respawns.

The complete gate must also be green: the whole 18-requirement set, the state surface, and the invariants —
including the assertion floor of 60 and the 10-run stability gate.

## Scope

- `public/star-swarm/`
- `templates/modules/star-swarm/`
- `modules/star-swarm/`
- `tests/browser/star-swarm.spec.ts`
- `tests/star_swarm_visual_test.php`
- `tools/harpp2/stability.sh`

## Acceptance — all of them, and the driver gates them

```
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=3
$ php tools/harpp2/gates/star_swarm_galaga_gate.php
$ bash tools/harpp2/stability.sh tests/browser/star-swarm.spec.ts 10
$ npx playwright test tests/browser/star-swarm.spec.ts
$ php tests/star_swarm_visual_test.php
$ php tests/star_swarm_concept_test.php
$ php tests/star_swarm_shot_check.php
$ composer test
```

The stability gate must report **10/10**: a game whose acceptance only passes sometimes is not finished. The
previous requirements contract (`tests/star_swarm_concept_test.php`) must stay satisfied — this phase adds
requirements, it does not replace the old ones.

## Boundaries

- **Do not edit the requirements contract or anything under `tools/harpp2/gates/`.** They are the acceptance
  instruments; making a gate pass by editing either one is a failure.
- **Never loosen a threshold, delete an assertion, or add a sleep or `waitForTimeout`.** Determinism comes from
  driving the game's clock, never from waiting or from a looser bound.
- **Keep every existing assertion**; the spec may grow, not shrink.
- **The tractor beam and the dual fighter must be real state transitions**, not a score bonus wearing their
  names: capture must cost a life, the rescue must depend on *where* the captor was destroyed, and the dual
  fighter must actually change firepower and hitbox.
- **No raster assets, no new dependency, no CDN, no bundler, no build step.**
- The same URL serves the same module, and any `.disyl` you touch stays `php _lint_disyl.php` clean.
- If a requirement cannot be met honestly, record that finding — a gate that passes while the game does not do
  the thing is worse than a red gate.
