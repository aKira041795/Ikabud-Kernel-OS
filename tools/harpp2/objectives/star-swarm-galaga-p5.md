# OBJECTIVE — Star Swarm, **phase 5: legibility (the features the player must see)**

system: HARPP v2 · 2026-09-19 · chair-authored. Executor: any lane the driver assigns.
authority: director review 2026-09-19 — *"lacks some of the features of the original game like the magnet ship,
extra life after points earned"*, plus *"can we use the 16:9 screen ratio?"*
Requirements: `tools/harpp2/projects/star-swarm-galaga.json`, group **legibility** (L1–L3).
Runs after `tools/harpp2/objectives/star-swarm-galaga-p4.md` (the pixels).

## Why this phase exists

Both "missing" features are present in the code and invisible on the screen. That is why the director could not
find them, and why three green phase gates proved nothing:

- **The magnet ship is reachable.** Every fourth attack is a solo Boss Galaga dive (`groupId % 4 === 0`,
  `tools/harpp2/objectives/star-swarm-galaga-p4.md` sibling code in `public/star-swarm/star-swarm.js`) that
  opens a tractor beam at `max(220, player.y - 190)`, captures the fighter, hauls it into formation, and turns
  it hostile when the captor is destroyed. All of it exists. What the player cannot do is *see* it: the beam is
  the warm translucent triangle phase 4 repaints, and the captured fighter is drawn small under the captor.
- **The extra ship is granted silently.** `addScore` does `state.lives += 1` at 20,000 and every 70,000. It is
  announced **nowhere**. Measured by the chair-owned pixel probe: awarding 20,000 moved the lives count by one
  and the rendered centre band by **exactly zero pixels** (bright pixels before 12, after 12).

## The item

Make both of these green:

```
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=5
$ npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p5"
```

- **L1 — the captured fighter is drawn** under the ship that took it, and belongs to that ship. (This probe
  already passes; it is a regression guard for the magnet-ship loop.)
- **L2 — the extra ship is announced on screen.** When the score reaches 20,000, and every 70,000 after, the
  award is *visible* in the central band of the play field — **y from 38% to 62% of the canvas height** — and
  the lives count rises by one. An arcade announcement, in the game's own pixel idiom: no system font, no DOM
  overlay, no colour outside the token contract. It must be readable in one glance at 1,280 pixels wide.
- **L3 — the field is 16:9.** Canvas backing store exactly 16:9 and at least 1,280 pixels wide, with the
  formation, the player, the tractor beam and the HUD laid out for the wide field rather than merely stretched.
  The canvas element carries `width`/`height` (today `800`/`600`) and the CSS frames it; the field geometry
  already derives from `canvasWidth()`/`canvasHeight()`, so check every place a constant assumed 800×600.

**Recorded deviation.** The arcade original is portrait (224×288 on a rotated monitor), which is why the
reference screenshot the director supplied is tall. 16:9 is a deliberate modern reinterpretation of a portrait
game, and is recorded in the contract as a deviation rather than as fidelity.

## Scope

- `public/star-swarm/` (including `index.html` and the CSS)
- `templates/modules/star-swarm/`
- `modules/star-swarm/`
- `tests/browser/star-swarm.spec.ts`

## Acceptance — all of them, and the driver gates them

```
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=5
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=1
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=2
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=3
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=4
$ npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p5"
$ npx playwright test tests/browser/star-swarm.spec.ts
$ php tests/star_swarm_visual_test.php
$ php tests/star_swarm_concept_test.php
$ php tests/star_swarm_galaga_gate_test.php
$ composer test
```

Phase 4 must still pass: this phase changes the field size and announces an award, not how the ships are drawn.

## Boundaries

- **Do not edit `tests/browser/star-swarm-pixels.spec.ts`, `tools/harpp2/projects/star-swarm-galaga.json`, or
  anything under `tools/harpp2/gates/`.**
- **Never loosen a threshold, delete an assertion, or add a sleep or `waitForTimeout`.** The 16:9 probe reads
  the canvas backing store and the announcement probe compares two rendered frames — make the pixels right.
- **The announcement is drawn, not merely recorded.** A `state.announcement` field satisfies nothing on its own:
  the same mistake (state asserted, screen unchecked) is what this whole phase exists to correct.
- **The arcade scoring table does not move.** 20,000 then every 70,000 is already verified in phase 3; if the
  announcement cannot be made legible without changing the table, record the finding instead.
- **Widening the canvas must not move the goalposts for the other probes.** If a phase 4 requirement stops
  measuring what it measured at 1,280×720 — for example a beam box or a band that assumed 800×600 — say so
  rather than adjusting the threshold.
- Keep the token discipline (`starSwarmTokenDefaults()` declares it, `public/star-swarm/index.html` injects it),
  the deterministic test surface, procedural sprites, and no new dependency or build step.
- The opening animation is **phase 6**. Do not start it here.
- Say so if part of this cannot be met honestly.
