# OBJECTIVE — Star Swarm, **phase 7: the caste colours, and assets that cannot go stale**

system: HARPP v2 · 2026-09-19 · chair-authored. Executor: any lane the driver assigns.
authority: director requests 2026-09-19 — *"assert the colors"* and *"game width did not change to 16:9.
maybe a settings?"*. Requirements: `tools/harpp2/projects/star-swarm-galaga.json`, groups **palette** (V6–V8)
and **serving** (S1).
Runs after `tools/harpp2/objectives/star-swarm-galaga-p6.md`.

## The item

Make both of these green:

```
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=7
$ npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p7"
```

Measured on the rendered canvas before this phase (dominant ink per caste):

| Caste | Dominant ink | Luminance | Verdict |
|---|---|---|---|
| bee | (248, 168, 24) vivid amber | 175 | faithful — **already passes**, keep it |
| butterfly | **(88, 40, 24) dark brown** | **49** | the defect: it inherited `--color-tertiary` (`#7f4025`) |
| boss | (56, 248, 88) vivid green | 196 | faithful — already passes |

**V6 — the bee stays the arcade yellow** (guard: it already passes; do not drift it).
**V7 — the butterfly is painted vivid red (with white), not mud.** Dominant ink red-dominant
(`r >= 180 && r >= g + 80 && r >= b + 80`) or near-white, and luminance above 90.
**V8 — no caste is muddy and all three are told apart by colour.** Every caste's dominant ink has luminance
above 90, and the three dominant colours are pairwise distinct. This is a floor, not a fixed palette: it
rules out the whole failure class (a formation that reads as brown mush at arcade speed) without dictating a
taste choice.
**S1 — the page versions its assets.** The stylesheet and script are requested with a version parameter
tied to the asset's own timestamp (derive it, e.g. from the file's mtime — a hardcoded number is not a
version). The probe requires a numeric version of at least nine digits.

## Why S1 is in this item

The director reported *"game width did not change to 16:9"*. It **had** changed — measured in a fresh
context: backing store 1280×720, displayed 1278×719, `aspect-ratio: 16 / 9` in the CSS. What he saw was a
**stale stylesheet**: `/star-swarm/star-swarm.css` was requested with no version query, so his browser kept
the previous CSS, which carried no `aspect-ratio` and an 800×600 canvas — a 4:3 field. A delivered change
that looks undelivered costs a false defect report and the round trip that follows. There is **no size
setting** for this module: the field is CSS-driven, so there is nothing to configure.

## Scope

- `public/star-swarm/` (including `index.html` and the CSS)
- `templates/modules/star-swarm/`
- `modules/star-swarm/` (the token defaults and the render variables live here)
- `tests/browser/star-swarm.spec.ts`

## Acceptance — all of them, and the driver gates them

```
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=7
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=1
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=2
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=3
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=4
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=5
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=6
$ npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p7"
$ npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p5"
$ npx playwright test tests/browser/star-swarm.spec.ts
$ php tests/star_swarm_visual_test.php
$ php tests/star_swarm_concept_test.php
$ php tests/star_swarm_galaga_gate_test.php
$ composer test
```

Phases 1–6 must stay green: this item changes a colour and an asset URL, not the game. `@p5` is included
because the 16:9 requirement now also asserts the **displayed** geometry — the assertion your report
prompted.

## Boundaries

- **Do not edit `tests/browser/star-swarm-pixels.spec.ts`, `tools/harpp2/projects/star-swarm-galaga.json`, or
  anything under `tools/harpp2/gates/`.**
- **Never loosen a threshold, delete an assertion, or add a sleep or `waitForTimeout`.**
- **Colour comes from the token contract.** `tests/star_swarm_visual_test.php` fails if a draw routine
  introduces a literal colour outside a token fallback, and fails if the JS reads a token that
  `starSwarmTokenDefaults()` does not declare, and fails if `public/star-swarm/index.html` does not inject it.
  So the butterfly's red means a token (a dedicated caste token is preferred over re-purposing
  `--color-tertiary`, which also paints page chrome), declared in `modules/star-swarm/helpers.php` and
  injected in the page — not `#ff0000` in the renderer.
- **`S1` must not be faked.** A hardcoded version string that never changes satisfies the probe and leaves the
  defect in place; derive the version from the asset so the next change invalidates the cache too. If the
  helper cannot see the file, say so rather than inventing a constant.
- **Do not change the field size or the sprite data.** This item is a colour and a URL.
- Say so if part of this cannot be met honestly.
