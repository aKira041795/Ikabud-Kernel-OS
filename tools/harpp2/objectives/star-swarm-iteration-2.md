# OBJECTIVE — STAR SWARM iteration 2: swarm, rocket, and a cosmic theatre

system: HARPP v2 · director's request, 2026-09-16 · follows `star-swarm-game.md` (iteration 1, verified)
director's words: *"improve the design of the game components. the Galaga game has good design of the swarm and the
shooting rocket"* · *"outer space environment. dark background, stars, planets nearby. whole feel of a cosmic gun
battle"*

## Scope

- `modules/star-swarm/`
- `templates/modules/star-swarm/`
- `public/star-swarm/`
- `tests/`

## Acceptance

```
$ php tests/star_swarm_visual_test.php
```

…plus the extended browser tier below, **which is what actually decides this item**.

## What to build

Everything is drawn **procedurally on the canvas** — no image files, no sprite sheets, no CDN, no build step. Every
colour and size comes from the ARK/theme tokens already wired in iteration 1, **each with a fallback**, so the game
still renders under a theme that defines none of them.

### 1. The cosmic theatre

- a **deep-space background**: near-black with a subtle vertical or radial gradient, never a flat fill;
- a **starfield in at least three parallax depths** — the far layer barely drifts, the near layer drifts fastest, and
  the whole field twinkles;
- **at least one planet**, drawn with visible curvature and shading (a lit limb and a dark side, or a ring), positioned
  so it reads as *nearby* rather than as a dot;
- a **nebula or dust glow** for depth — soft, low-contrast, behind the play objects;
- the play area must stay **readable**: player, enemies and bullets must remain distinguishable against it.

### 2. The swarm — Galaga's formation, not a grid of boxes

- a **formation** of several rows with **visually distinct enemy types** (different silhouettes, not one shape tinted);
- the formation **sways** as a body and, on a cadence, a **dive attack** leaves the formation and swoops at the player
  on a curved path before returning — the Galaga signature;
- **entrance**: enemies fly in and settle into formation rather than appearing in place;
- **hit feedback**: a flash on hit, an explosion (expanding debris, fading) on death, and a score popup;
- enemy fire is visually distinct from player fire (different shape and colour).

### 3. The rocket

- the player ship is **built from parts** — hull, cockpit, fins, engine — not a single triangle;
- a **thruster flame** that flickers, and **banking** as the ship moves;
- player bullets read as **shots**: a bright core with a glow or short trail, not a dot.

## Evidence — this is the part that matters

**Tier A — `tests/star_swarm_visual_test.php`** (deterministic, no browser): the game exposes the structures it claims
— starfield layers, planet(s), swarm rows/types, ship parts, projectile shape — and the page injects every token the
JS consumes, each with a fallback. Assert on names and structure, never on the lane's prose.

**Tier B — `tests/browser/star-swarm.spec.ts`, extended.** The existing test already proves it boots, takes input,
runs the loop and tears down. Add **pixel probes on the live canvas** (`getImageData`), which are the only honest way
to assert a *look*:

- **background is dark**: mean luminance of a sample region with no objects is below a dark threshold;
- **stars are drawn**: the count of pixels brighter than the background exceeds a floor, and it changes between frames
  (twinkle/drift);
- **a planet is present**: a contiguous cluster of non-background pixels above a minimum radius;
- **the ship is drawn**: non-background pixels exist in the player's expected region;
- **a shot is drawn**: after firing, bright pixels appear above the ship;
- **enemies are drawn**: non-background pixel clusters exist in the formation band;
- **it still plays**: the iteration-1 assertions (input moves the ship, the loop advances) must still pass — **do not
  delete or weaken them**, extend the file.

**Tier C — a screenshot artifact.** Capture the running game to `test-results/star-swarm.png` from the browser test and
name the path in the report. The **chair reviews that image**; a *look* claim that no human or vision check has seen
is not evidence. If the screenshot cannot be captured, say so and mark the look **unproven**.

## Boundaries

The constitution's list. No new dependency, no CDN, no bundler, no image assets, no schema, no database write. Do not
weaken or delete an existing assertion — extend it. `php -l` clean on every PHP file; `php _lint_disyl.php` clean on
every template. The iteration-1 acceptance test must still pass unchanged.
