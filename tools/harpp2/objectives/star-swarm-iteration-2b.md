# OBJECTIVE — STAR SWARM iteration 2b: the cosmic theatre, with the evidence ENFORCED

system: HARPP v2 · 2026-09-16 · iteration 2 was **rejected by the chair on review**: the harness verified it, the
director's requirement was not met. This item fixes the requirement and the enforcement.

## Why iteration 2 was rejected — read this first

Iteration 2 was declared `verified`. Reviewing the running game, the chair found:

1. **The play field is light grey-blue, not dark space.** Every colour comes from theme tokens, and the active theme
   is light — so the game inherited a light background. The director asked for *"dark background, stars, planets
   nearby. whole feel of a cosmic gun battle"*.
2. **The deciding evidence was never produced.** The objective asked for canvas pixel probes and a screenshot
   artifact; `grep -c "getImageData|luminance|planet|starfield|twinkle" tests/browser/star-swarm.spec.ts` → **0**, and
   no PNG exists. The driver's acceptance gate was the structural test only, so it passed an item whose visual
   requirement was unproven.

**The lesson is in the acceptance section below: the deciding evidence is now part of the acceptance command, so a
pass cannot happen without it.**

## Scope

- `modules/star-swarm/`
- `templates/modules/star-swarm/`
- `public/star-swarm/`
- `tests/`

## Acceptance — **all three**, and the driver runs them as gates

```
$ php tests/star_swarm_visual_test.php
$ npx playwright test tests/browser/star-swarm.spec.ts
$ php tests/star_swarm_shot_check.php
```

The third command asserts the screenshot exists, is newer than the spec, and is larger than 20 KB — a look claim with
no image is not evidence. All three must pass; the item is not `verified` otherwise.

## The requirement: space is dark BY DESIGN, the chrome stays themed

- **The play field is dark, always.** Space is not a theme choice. If the active theme's tokens are light, the play
  field must **not** inherit them: introduce a dedicated dark space family (e.g. `--ss-space-bg`, `--ss-space-deep`,
  `--ss-star-*`) whose **fallbacks are dark**, and let a theme override them if it wants. A theme that defines nothing
  must still render dark space.
- **Chrome stays themable** — header, score/wave/lives, buttons and typography keep using the theme tokens they use
  today.
- **Legibility is part of the requirement**: the ship, enemies and both bullet types must be clearly distinguishable
  against the dark field.

### The theatre

- near-black background with depth (gradient or layered glow), never a flat fill;
- a **starfield in ≥3 parallax depths** with visible twinkle — the far layer barely drifts, the near layer fastest;
- **≥1 planet with real shading** (lit limb / dark side) and optionally a ring or moon — keep the ringed planet, it
  reads well;
- a soft **nebula/dust glow** behind the play objects for depth.

### The swarm and the rocket (iteration 2's intent, kept)

- swarm: distinct enemy silhouettes per type, formation that sways, a **curved dive attack** on a cadence, entrance
  flight-in, hit flash, explosion debris, score popup, enemy fire visually distinct from yours;
- rocket: hull + cockpit + fins + engine, flickering thruster, banking on movement, shots with a bright core and glow.

## The browser spec MUST gain pixel probes (this is the enforcement)

Extend `tests/browser/star-swarm.spec.ts` — **do not delete or weaken a single existing assertion** (200, canvas
visible, non-zero box, boot state, ArrowRight moves the ship, enemies advance, teardown) — with `getImageData` probes:

- **background is dark**: mean luminance of an object-free sample region < a dark threshold;
- **stars**: bright-pixel count above a floor, and the count **changes between two frames** (twinkle/drift);
- **planet**: a contiguous non-background cluster above a minimum radius;
- **ship**: non-background pixels in the player's region;
- **shot**: after firing, bright pixels above the ship;
- **enemies**: non-background clusters in the formation band;
- the spec **writes** `test-results/star-swarm.png` (`page.screenshot`) of the game **in play** — not the start
  overlay — and asserts the file exists.

## Boundaries

No new dependency, no CDN, no bundler, no image assets, no schema, no database write. `php -l` clean; every `.disyl`
passes `php _lint_disyl.php`. The iteration-1 assertions stay. Do not weaken a check to obtain a pass — if a probe
cannot be made to pass honestly, say so and stop.
