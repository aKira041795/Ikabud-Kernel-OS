# Star Swarm — the HUD must name the weapon you are holding

## Objective

Six weapons now arrive, one per stage: `lance, rapid, spread, twin, pierce, nova`. **Nothing on screen
names the one in your hands.** Measured: rendering the HUD while holding each of the six produces
byte-identical ink (3316 for all six), so the display is completely indifferent to the weapon.

That defeats the design it was built for. Raiden's weapon is deterministic and colour-coded precisely
so a player learns which stage carries what; Gradius escalates the arsenal across a run so each stage
gives you something new. Both depend on the player being able to tell what they are holding. A
per-stage arsenal the player cannot identify is six random outcomes, not a progression.

Show the held weapon, by name, in the HUD.

## Architectural constraints

- The HUD already exists and already draws the score and status along the top strip. Extend it. Do not
  add a second overlay with its own lifetime, and do not put the weapon anywhere the player is not
  already looking during play.
- The name must be the weapon the player HOLDS, and it must change when a different pickup is taken.
  With weapons now persisting, the HUD shows the current one until it is replaced.
- **The pixel font silently skips characters it has no glyph for.** A name containing a character with
  no glyph loses that letter with no error, and the probe below measures INK — so a name that renders
  as "NOVA" vs "NV" would still differ and still pass. Check every character of every name against the
  glyph table before relying on it, and prefer names that are legible in the existing font.
- **Do not make the six names collide in rendered width.** The probe requires that the HUD renders at
  least two weapons differently; make it true for a real reason (different letters), not by accident.
- `PLAYER_SHOT_LIMIT` stays 2. Weapons change where shots go and how often, never how many are in the
  air at once. This task does not touch firing; do not change it while you are in the file.
- Vanilla JS on a canvas. No frameworks, no new files, no build step, no dependency.
- Bump the `?v=` asset query string in `index.html` to the new `star-swarm.js` mtime. A version guard
  asserts that the query string equals the file's mtime, and a warm browser otherwise serves the old
  bundle.

## Files likely affected

- `public/star-swarm/star-swarm.js`
- `public/star-swarm/index.html`
- `public/star-swarm/star-swarm.css`

## Acceptance criteria

- The HUD renders different weapons differently, so they can be told apart (probe @p18).
- The whole rendered-canvas suite still passes: `npx playwright test tests/browser/star-swarm-pixels.spec.ts --reporter=line`
- The PHP-side visual test still passes: `php tests/star_swarm_visual_test.php`
- The six weapon names are distinguishable in the pixel font, with no character lacking a glyph.

## Required tests

- `npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p18" --reporter=line`

## Risks

- **A name that renders invisibly.** Adding text in a colour or position that the probe's band does not
  cover would leave the HUD just as uninformative while the probe passes. The band is the top 12% of
  the canvas; put the name where it can be seen.
- **Characters with no glyph.** Silent, and the probe measures ink, so a partially-rendered name can
  still pass. This is the specific trap that produced "P ASMA ANCE" on this project before.
- **Moving the probe's goalposts.** The probe is the definition of done. Changing it changes the
  question and is not an answer to it.
- **Breaking the existing HUD.** The score and status already render there; the suite covers them.

## Forbidden changes

- `tests/`
- `tools/`
- `kernel/`
- `modules/star-swarm/`
- Do not change `PLAYER_SHOT_LIMIT` (2), the six-weapon arsenal, the challenging-stage cadence, or the
  pickup fall speed.
- Do not edit, weaken, skip or delete any assertion in the acceptance spec, and do not add a
  `waitForTimeout` to make anything pass.
