# OBJECTIVE — STAR SWARM iteration 3: *a colony, not a formation*

system: HARPP v2 · 2026-09-16 · director-approved creative direction. Iteration 2b made space dark and proved it
with pixel probes; this iteration makes the **enemy** worth looking at, and keeps that same evidence discipline.

---

## The concept (expounded)

**Design law — one sentence.** *The enemy is not a wall of ships; it is one animal with many bodies, and the
player's skill is reading its mind.*

Everything below follows from that sentence. Today the enemy is `ENEMY_ROWS × ENEMY_COLS` of one identical
archetype in a rectangle: a formation, which is what the name *Star Swarm* currently does **not** deliver.

**Aesthetic law — one sentence.** *Everything is light in darkness.* Bodies are silhouettes with a bright core and a
soft bloom, never flat fills, never sprites. This is not decoration: the constraints (no assets, no bundler, no
dependency, tenant-themable palette, 800×600 canvas) force identity into **silhouette, motion and light**, which is
also why the visual language is deep-sea bioluminescence rather than plastic spaceships.

### Four pillars — each one measurable or it is not a pillar

| # | Pillar | What it means | The probe that decides it |
|---|---|---|---|
| 1 | **It is a swarm** | Motion is emergent, not scripted: separation, cohesion, alignment. Bodies never overlap, and killing one disrupts its neighbours. | pairwise min distance > 0 across a wave · colony centroid path is non-linear · dispersal count after a kill > 0 |
| 2 | **It has a mind** | Named moods — `undulate` → `probe` → `dive` → `frenzy` — on a cadence that changes **behaviour**, not just speed, as waves advance. | the mood label changes across waves · oscillation cadence differs between waves |
| 3 | **It lives in a place** | The ringed planet stops being decoration and becomes the **nursery**: wave arrivals fly out from it. Parallax starfield, nebula, and enemies at ≥3 distinct scale bands so the void reads as depth. | ≥3 distinct scale bands among live enemies · new-wave enemies originate near the nursery |
| 4 | **It is themable** | Meaning never depends on hue. Semantic roles — `threat`, `ally`, `reward`, `hazard` — mapped from theme tokens, and ≥4 enemy classes distinguishable **by shape**. | ≥4 silhouette classes separable by ink coverage + aspect ratio · every colour resolves through a token fallback |

### The pilot's rocket (language, kept from 2b and deepened)

Hull, cockpit, fins and a flickering thruster; banking on movement; shots with a bright core and a soft bloom so
friend and foe fire are never confused. The player must always be able to name what killed them.

### What makes this *this game* and not Galaga

Galaga's swarm is a formation with dive arcs. Here the formation is a **body**: it breathes, it leans, it breaks
apart when struck, and it reassembles. Disruption is the mechanic — the player chooses between thinning a row and
**breaking the colony's mind**.

---

## Observability is part of the design (do not skip)

The probes above cannot be honest by pixel-guessing alone. Expose a small, deliberate read-only surface, e.g.
`window.StarSwarm.state.enemies = [{ x, y, scale, role, class }]`, plus `state.mood`, `state.wave`, `state.nursery`.
Keep it minimal and documented — it exists so behaviour is observable, not so tests can reach inside.

---

## Scope

- `modules/star-swarm/`
- `templates/modules/star-swarm/`
- `public/star-swarm/`
- `tests/` (extend the browser spec; add structural assertions where they belong)

## Acceptance — all three, and the driver gates them

```
$ php tests/star_swarm_visual_test.php
$ npx playwright test tests/browser/star-swarm.spec.ts
$ php tests/star_swarm_shot_check.php
```

**Every assertion that exists today must survive** — the iteration-1/2b checks (200, canvas, layout box, boot state,
ArrowRight, enemies advance, teardown) and the 2b pixel probes (dark mean luminance < 35, starfield bright pixels,
planet cluster radius, ship pixels, enemy clusters, shot pixels, screenshot artifact). You are **adding** probes, not
replacing them, and you are **never** weakening one to obtain a pass.

### The browser spec must additionally prove (via `page.evaluate`, not by eye)

1. **no overlap** — pairwise distance between live enemies > 0 at several samples;
2. **non-linear centroid** — the colony's centroid does not travel a straight line across samples;
3. **dispersal** — killing enemies measurably displaces their neighbours (measure before/after);
4. **moods** — `state.mood` takes at least two distinct values across ≥2 waves;
5. **scale bands** — at least 3 distinct `scale` values are live at once;
6. **silhouette classes** — at least 4 distinct enemy classes are present, and at least 4 are separable by
   **ink coverage and aspect ratio measured from canvas pixels**, so a palette change cannot collapse them into one;
7. **roles** — every drawn colour resolves through a theme token (no literal colour outside a token fallback);
8. the spec still writes `test-results/star-swarm.png` of the game **in play**, and asserts it exists.

### The structural test must additionally assert

- the swarm behaviour and mood vocabulary exist in the source as named rules (`separation`, `cohesion`,
  `alignment`, `undulate`/`probe`/`dive`/`frenzy`), so the concept cannot be faked by a comment;
- the observability surface is present and read-only in intent;
- **no raster assets**: `drawImage`, `createImageBitmap`, `new Image(` stay absent — 0 was true at iteration 2b and
  must remain 0, because "no assets" is what makes this module themable;
- the colour law holds: no literal colour outside a token fallback.

## Boundaries

No new dependency, no CDN, no bundler, no build step, no image/audio asset files, no schema, no database, no tenant
change, no new module. `php -l` clean; every `.disyl` passes `php _lint_disyl.php`. The same URL
(`http://akiracms.test/star-swarm/`) and the same delivery (kernel-rendered entry + static assets) must keep working.
**Do not weaken a check to obtain a pass** — if a probe cannot be made to pass honestly, say so and stop.
