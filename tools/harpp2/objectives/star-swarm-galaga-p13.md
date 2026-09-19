# OBJECTIVE — Star Swarm, **phase 13: presentation (the score row on black, and the moon)**

system: HARPP v2 · 2026-09-19 · chair-authored. Executor: any lane the driver assigns.
authority: director requests 2026-09-19 — *"score row, black background color"* and *"planet, change to moon, make it
look like the moon"*.
Requirements: `tools/harpp2/projects/star-swarm-galaga.json`, groups **hud** (H1–H2) and **moon** (M1–M3).
Runs after `tools/harpp2/objectives/star-swarm-galaga-p12.md`.

## The item

Make both of these green:

```
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=13
$ npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p13"
```

### 1. The score row goes black

Cause, already located: `.star-swarm__hud` takes `background: var(--color-surface-raised, #141b33)`, and the page
sets `--color-surface-raised: #ffffff`. So the score row renders as a **white bar** on a black space field —
chrome, not arcade. It should use the space token (`--ss-space-bg`, `#02030b`) so the row belongs to the field,
with the values still bright enough to read instantly.

- **H1 — the row renders on black.** Measured from the HUD's *own pixels*: at least 60% of the rendered score
  row is near-black. The HUD is **DOM**, not canvas, so this cannot be read by the canvas probe — the probe
  screenshots the HUD element, decodes that PNG inside the browser, and measures its pixels. Measured before
  dispatch: **darkShare 0.0005**.
- **H2 — it stays legible.** The values are bright ink on that black: some bright pixels are present, and the
  computed contrast ratio between the text colour and the background is at least **7:1**. Going black must not
  mean going dim: a score nobody can read is not an improvement.

### 2. The planet becomes a moon

The field currently carries a **stepped pale-cyan disc** (`createPlanets`, `radius: 76`) drawn with a two-stop
radial gradient from `starBright` to `planetLit`. It becomes **a grey, cratered moon** — still the body that
colonies hatch from.

- **M1 — it is a moon.** Measured on the rendered body, before dispatch:
  | Measure | Now | Required |
  |---|---|---|
  | dominant ink | `[232, 248, 248]` | — |
  | channel spread | 16 | ≤ 24 |
  | **blue-red gap** | **16** | **≤ 12** |
  | **luminance** | **245** | **60 – 200** |
  | **distinct shades ≥ 3%** | **2** | **≥ 3** |
  | **tonal range** | **16** | **≥ 48** |

  Two notes on why the bound is what it is. A **channel spread of 30 was not enough**: the pale cyan disc
  scores 16 and passed a spread-only test while sitting there plainly cyan, so neutrality is a spread *and* a
  blue-red gap. And the disc must be a **mid grey tone**: at luminance 245 it is near-white, which is why the
  luminance band is a requirement and not a detail. Craters are then measured as **tonal structure** — a third
  shade and a 48-level range, because a two-stop radial gradient across a flat disc is exactly 2 shades 16
  apart, which is what is there today.
- **M2 — the ring is gone.** An annulus sampled just outside the body's radius is essentially black. **Already
  true** before dispatch: all four bands read 0 ink, because phase 4 replaced the ringed planet with black
  space and the renderer ignores `planet.ring`. It is asserted as a **regression guard** — M2 and M3 are what
  stop this item buying its look by putting a ring or a glow back.
- **M3 — the field still reads black with the moon in it.** At least 90% of the field near-black, measured with
  the moon drawn. This is the guard on V3: a big bright body is exactly how a "black space" requirement gets
  quietly broken.

## Constraints that matter more than the look

- **The moon keeps its role.** Requirement C1/phase 1 asserts that a new wave's bodies originate inside the
  nursery body (`nurseryOrigin < 30`). The moon replaces the planet **in place**: same position, same radius
  band, same role as the nursery. Its *appearance* changes; its *function* must not.
- **Pixel idiom, no blur.** Craters are hard-edged pixel marks, consistent with V4: no soft glow, no gradients
  that produce a wide band of half-tones.
- **No raster assets, no new dependency.** The moon is drawn procedurally like everything else.
- **Colour comes from the token contract.** A grey palette means tokens declared in
  `modules/star-swarm/helpers.php` and injected in `public/star-swarm/index.html` — the visual test fails on a
  literal colour in a draw routine and on a token the JS reads but the PHP does not declare.
- **Do not change the HUD's information.** Score, wave and lives stay, in the same order, with the same
  identifiers (`data-star-swarm="score"` etc.). This item changes the background and the palette, not the layout
  or the data.

## Scope

- `public/star-swarm/`
- `templates/modules/star-swarm/`
- `modules/star-swarm/`
- `tests/browser/star-swarm.spec.ts`

## Acceptance — the driver gates these per chunk, so they are the FAST ones

```
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=13
$ npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p13"
```

Measured red baseline, 2026-09-19: `@p13` is **2 failed / 1 passed** — H1 (`darkShare 0.0005`) and M1
(`blue-red gap 16`) fail, and the M2/M3 guards already hold. The gate `--phase=13` is green because the gate
is the vacuity guard: it proves each requirement has a probe in the chair-owned spec, not that the probe
passes. Both must be green.

**Why only two commands.** The driver gates acceptance PER CHUNK, so everything here is re-run on every
chunk. See the note in `star-swarm-galaga-p12.md`: the broad battery is not weakened, it is moved to the
once-per-batch block below so it stops being re-run four times for no added evidence.

## Final gate — the chair runs this once per batch, and it is binding

**Not `$ `-prefixed, and in a `text` fence, on purpose** — see the note in `star-swarm-galaga-p12.md`:
`acceptanceCommands()` collects every `$ ` line in every fenced block, whatever the heading says.

```text
php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=1
php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=4
npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p4"
npx playwright test tests/browser/star-swarm-audio.spec.ts
npx playwright test tests/browser/star-swarm.spec.ts
php tests/star_swarm_visual_test.php
php tests/star_swarm_concept_test.php
php tests/star_swarm_galaga_gate_test.php
composer test
```

Phase 1 is the nursery role: the moon replaces the planet in place and phase 1 is what asserts that. `@p4`
is the field's black-share, the pixel idiom and the sprite work — all phase 4's verified behaviour, and the
things a large pale body is most likely to break.

## Boundaries

- **Do not edit `tests/browser/star-swarm-pixels.spec.ts`, `tests/browser/star-swarm-audio.spec.ts`,
  `tools/harpp2/projects/star-swarm-galaga.json`, or anything under `tools/harpp2/gates/`.**
- **Never loosen a threshold, delete an assertion, or add a sleep or `waitForTimeout`.**
- **Do not move, resize or repurpose the nursery.** Its position and role are phase 1's verified behaviour and
  the hatch origin for every wave.
