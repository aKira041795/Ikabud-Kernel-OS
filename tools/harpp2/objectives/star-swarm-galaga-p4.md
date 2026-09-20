# OBJECTIVE — Star Swarm / Galaga fidelity, **phase 4: the pixels (components)**

system: HARPP v2 · 2026-09-19 · chair-authored. Executor: any lane the driver assigns.
authority: director review 2026-09-19 — *"the game play has improved but components are off… lacks the magnet
ship, extra life after points earned"*, against the arcade original as reference.
Requirements: `tools/harpp2/projects/star-swarm-galaga.json`, group **fidelity** (V1–V5).

## Why this phase exists

Phases 1–3 are verified and the gate was fully green — while the game did not look like Galaga. Every probe
they added read **state**; none read the rendered canvas. `tests/browser/star-swarm.spec.ts` says so in its own
header: *"It reads state, never pixels."* That is the blind spot the director found by looking at the screen:

| Rendered today | What it is |
|---|---|
| Boss Galaga | **orange** (`--ss-role-threat` #f78c6b), drawn as a polygon |
| Space | gradient nebula, radial dust wash, a shaded ringed planet — **47% near-black** |
| Stars | anti-aliased discs (`ctx.arc`) |
| Explosions | an anti-aliased **stroked ring**, hollow in the middle |
| Tractor beam | a warm translucent triangle at alpha 0.24 (not cyan, no scan lines) |
| All ships | vector outlines (`moveTo`/`lineTo`/`quadraticCurveTo`), not pixel art |

## The item

Make both of these green:

```
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=4
$ npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p4"
```

Five requirements, measured on the canvas:

- **V1 — pixel matrices.** Define `bee`, `butterfly`, `boss` and `fighter` as in-code pixel art (rows of `.`
  and `#`, equal width, at least 8×8) and draw the ships from that data, exposed as `StarSwarm.sprites`. **No
  raster assets**: the matrices are data in the source, so the module stays diffable.
- **V2 — the green magnet ship.** The Boss Galaga's dominant rendered ink is green-dominant. It is the ship the
  player must recognise instantly, because it is the one that takes their fighter.
- **V3 — black space.** With sprites cleared, at least 90% of the field is near-black and the stars leave no
  wide band of half-tones: the original is an empty black field with sparse pixel stars, not a nebula.
- **V4 — pixel starbursts.** Explosions are hard-edged pixel bursts with a **solid bright core** (a stroked
  ring is hollow in the middle, which is how the probe tells them apart). No blur, no soft glow: Galaga's
  explosions are opaque pixels.
- **V5 — the cyan cone.** The tractor beam is painted cyan, tapers, and carries horizontal scan lines running
  down it, animated.

## Scope

- `public/star-swarm/` (including `index.html` and the CSS)
- `templates/modules/star-swarm/`
- `modules/star-swarm/` (the token defaults live here)
- `tests/browser/star-swarm.spec.ts`

## Acceptance — all of them, and the driver gates them

```
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=4
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=1
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=2
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=3
$ npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p4"
$ npx playwright test tests/browser/star-swarm.spec.ts
$ php tests/star_swarm_visual_test.php
$ php tests/star_swarm_concept_test.php
$ php tests/star_swarm_galaga_gate_test.php
$ composer test
```

Phases 1–3 already pass. They must still pass: this phase changes how the game is drawn, not what it does.
The 10-run stability gate belongs to phase 3 and is not part of this item.

## Boundaries

- **Do not edit `tests/browser/star-swarm-pixels.spec.ts`, `tools/harpp2/projects/star-swarm-galaga.json`, or
  anything under `tools/harpp2/gates/`.** They are the acceptance instruments, and phase 4's requirements are
  asserted ONLY in the chair-owned pixel spec. A run that changes one of them is a failed run, not a pass.
- **Never loosen a threshold, delete an assertion, or add a sleep or `waitForTimeout`.** The pixel probes are
  measured with `expect(...)`; make the pixels right rather than the numbers convenient.
- **Colour discipline is enforced.** `tests/star_swarm_visual_test.php` fails if a draw routine introduces a
  literal colour outside a token fallback, and fails if the JS reads a token that `starSwarmTokenDefaults()`
  does not declare. So the green boss means **a new token** — declared in `modules/star-swarm/helpers.php` and
  injected in `public/star-swarm/index.html` (the test checks both), not `#00ff00` in the renderer.
- **Keep the deterministic test surface intact**: `testStep`, `testSpawnWave`, `testKill`,
  `testSnapshotEnemy`, the frozen `test` object, and `render()`/`update()` reachable from `window.StarSwarm`.
  The probes drive them.
- **Keep the sprites procedural.** No `drawImage`, no `createImageBitmap`, no `new Image(`, no CDN, no new
  dependency, no build step.
- The 16:9 field and the announced extra ship are **phase 5**, the opening animation is **phase 6**. Do not
  start them here; the phases are separate so each verdict is separately satisfiable.
- Say so if part of this cannot be met honestly.
