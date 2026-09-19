# OBJECTIVE — STAR SWARM iteration 3b: finish the colony (the 11 red items)

system: HARPP v2 · 2026-09-16 · iteration 3 was reported `verified` by the driver and **its brief was only partly
built**. This iteration finishes the concept and, more importantly, the requirement list is now a **command**, so
this cannot happen again.

## Read this first: what iteration 3 actually delivered

Delivered and proven — **do not rebuild, do not regress**: the cosmic theatre (3 parallax depths, twinkle, gradient
dust, ringed planet), four moods as executable named rules (`undulate`/`probe`/`dive`/`frenzy`) that alter
oscillation and body motion, a **breathing/swaying/leaning** formation (12 sway/lean/breathe sites), three named
enemy silhouettes (`commander`/`fighter`/`scout`), staggered entrances, curved dives, hit flash + debris + score
popups, distinct friend/foe shot languages, a rocket with flame/banking/fins/cockpit, and the dark space token
family. `tests/star_swarm_visual_test.php` passes 18/0; the browser spec passes with 28 assertions including all of
iteration 2b's pixel probes.

**Your worklist is a command, not prose.** Run it first and treat its failures as the task:

```
$ php tests/star_swarm_concept_test.php
```

It reads `tools/harpp2/projects/star-swarm-concept.json` — the concept contract — and currently reports
**12 passed, 11 failed**. Those 11 failures are this iteration. Nothing else in the concept is required.

## The 11 items, with the creative direction for each

### P1 — it is a swarm (emergent, and it breaks when struck)
- **Observability**: an enemy record must carry `scale`, `class`, `role`; state must expose `nursery`.
- `pairwiseMinDistance` — **no two live enemies overlap**, at several samples. Real separation: bodies push apart
  rather than sliding through each other. Cohesion keeps the colony together, alignment shares heading.
- `centroidPath` — the colony's **centroid does not travel a straight line** across samples. The body drifts,
  leans and reforms; it must not simply oscillate around a fixed centre.
- `dispersal` — **killing enemies measurably displaces their neighbours** (measure before/after). This is the
  mechanic that makes the game its own: the player can break the colony's mind, not just thin a row.

### P3 — it lives in a place (depth is a mechanic, not decoration)
- `scaleBands` — **≥3 distinct enemy scales live at once**: far (small, slow, dim), mid, near (larger, faster,
  brighter). Scale is the cheapest way to turn an 800×600 window into a corridor.
- `nurseryOrigin` — **new-wave enemies originate near the nursery planet** and fly out of it. The ringed planet
  stops being decoration and becomes the reason the wave exists.

### P4 — it is themable (shape carries meaning, hue never does)
- `silhouetteClasses` — **≥4 enemy classes separable by ink coverage and aspect ratio**, measured from canvas
  pixels. Add a fourth caste (e.g. a heavy `harvester` or a `queen`) whose *shape* is unmistakable at a glance.
- `roleCoverage` — `threat` / `ally` / `reward` / `hazard` all **resolve through theme tokens**, so a tenant theme
  can repaint the game without erasing meaning. Every colour still reads through a token with a fallback.

## Scope

- `modules/star-swarm/`
- `templates/modules/star-swarm/`
- `public/star-swarm/`
- `tests/browser/star-swarm.spec.ts`
- `tests/star_swarm_visual_test.php` (extend only)

## Acceptance — all four, and the driver gates them

```
$ php tests/star_swarm_concept_test.php
$ php tests/star_swarm_visual_test.php
$ npx playwright test tests/browser/star-swarm.spec.ts
$ php tests/star_swarm_shot_check.php
```

The first must reach **0 failed**, which means every probe named in the contract exists **inside an `expect(...)`
statement** in the spec (the contract accepts aliases where one already exists — see the `|` separators).
Existing assertions must all survive: you are **adding**. Never weaken a check to obtain a pass — if a probe cannot
be made to pass honestly, say so and stop.

## Boundaries

No new dependency, no CDN, no bundler, no build step, no image or audio assets (**raster count must stay 0**), no
schema, no database, no tenant change. `php -l` clean; every `.disyl` passes `php _lint_disyl.php`. The same URL
(`http://akiracms.test/star-swarm/`) and delivery must keep working.
