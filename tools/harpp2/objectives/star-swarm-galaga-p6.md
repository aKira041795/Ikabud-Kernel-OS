# OBJECTIVE — Star Swarm, **phase 6: the opening animation**

system: HARPP v2 · 2026-09-19 · chair-authored. Executor: any lane the driver assigns.
authority: director request 2026-09-19 — *"can we add an opening animation showing 1. Star Swarm 2. then by
IKON"*. Requirements: `tools/harpp2/projects/star-swarm-galaga.json`, group **opening** (O1–O2).
Runs after `tools/harpp2/objectives/star-swarm-galaga-p5.md` (legibility).

## The item

Make both of these green:

```
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=6
$ npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p6"
```

- **O1 — the sequence runs BEFORE play.** On the title screen, before the player starts, an opening plays: it
  begins on the `title` stage, advances to the `byline` stage, and then finishes. It is driven by **game time
  through the update path** (`update(dt)`), not by a wall-clock sleep, and it is exposed as
  `state.opening.stage`, with `state.opening.title === 'Star Swarm'` and
  `state.opening.byline === 'by IKON'`.
- **O2 — the order the director asked for.** Stage one shows **Star Swarm** as a large pixel-block logo; stage
  two replaces it with **by IKON** as a smaller pixel-block byline. Both stages carry visible ink in the central
  band of the field (**y from 32% to 66% of the canvas height**), the title lockup is markedly the larger mark,
  and both are drawn in the game's own pixel idiom — no system font, no DOM overlay, no colour outside the token
  contract.

## Constraints that make this probe-able

- **Driven by the game clock.** The probe calls `game.test.step(1)` repeatedly and watches `state.opening.stage`
  change. A sequence driven by `Date.now()`, `setTimeout` or `requestAnimationFrame` timestamps outside `update`
  will not advance under the probe, and a probe that has to sleep to see it is a race by construction — the
  repository forbids `waitForTimeout` for exactly this reason.
- **A stage can be held.** The probe sets `state.opening.stage = 'title'`, renders, then sets `'byline'` and
  renders again, so the renderer must read the stage from state each frame rather than latching a local flag.
- **Starting play still works exactly as before.** The existing suite asserts the game is *not* running until the
  action button is clicked and that it *is* running after; the opening plays on that title screen and must not
  begin play by itself.
- **A skip is welcome, a lie is not.** Advancing on any input or click is fine arcade behaviour. What must not
  happen is the sequence silently not running, or running only when nobody looks.

## Scope

- `public/star-swarm/` (including `index.html` and the CSS)
- `templates/modules/star-swarm/`
- `modules/star-swarm/`
- `tests/browser/star-swarm.spec.ts`

## Acceptance — all of them, and the driver gates them

```
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=6
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=1
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=2
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=3
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=4
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=5
$ npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p6"
$ npx playwright test tests/browser/star-swarm.spec.ts
$ php tests/star_swarm_visual_test.php
$ php tests/star_swarm_concept_test.php
$ php tests/star_swarm_galaga_gate_test.php
$ composer test
```

## Boundaries

- **Do not edit `tests/browser/star-swarm-pixels.spec.ts`, `tools/harpp2/projects/star-swarm-galaga.json`, or
  anything under `tools/harpp2/gates/`.**
- **Never loosen a threshold, delete an assertion, or add a sleep or `waitForTimeout`.**
- **The byline is exactly `by IKON`.** Lowercase `by`, uppercase `IKON` — it is the director's own naming, not
  a style choice to improve.
- **The title is pixel art**, drawn from the same in-code pixel data as the ships (phase 4): no `<h1>` styling
  dressed up as a logo, and no webfont, which is a new dependency.
- Keep the token discipline, the deterministic test surface, procedural rendering, and no new dependency or
  build step.
- Say so if part of this cannot be met honestly.
