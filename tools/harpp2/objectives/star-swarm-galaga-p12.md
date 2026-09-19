# OBJECTIVE — Star Swarm, **phase 12: the carrier, the drop and the grab**

system: HARPP v2 · 2026-09-19 · chair-authored. Executor: any lane the driver assigns.
authority: director request 2026-09-19 — *"when can a player get the beam? by reaching a point and maybe 1 enemy
with the beam weapon, when hit, player need to get it before it disappears?"*
Requirements: `tools/harpp2/projects/star-swarm-galaga.json`, group **pickup** (D1–D6).
Runs after `tools/harpp2/objectives/star-swarm-galaga-p11.md`.

## What changes, and what does not

Phase 10 gave the lance a **score threshold** (10,000 then every 20,000, cap 3, spent with `S`). That
requirement (W1) stays true and stays asserted — this item does not rewrite it. What it adds is a second,
better way to earn the weapon, which is the way the director described:

> **one enemy carries the lance. Kill it and the weapon drops. Collect it before it disappears.**

The threshold then becomes a **guarantee rather than the mechanic**: a player who never meets a carrier is
never starved of the weapon. Both paths end in the same place — a charge — so the lance, `S`, the window,
the HUD and the announcement are all phase 10's work and do not change here.

## The mechanic

| Property | Value |
|---|---|
| Carrier | **exactly one** enemy per standard wave carries the lance |
| Identifiable | the carrier is **marked on screen** — a drawn marker that adds visible ink (an outline, a ring, or a bright mark) |
| On kill | the weapon **drops** at the carrier's position and **drifts slowly downward** |
| Collectable for | a bounded lifetime (`state.pickups[].remaining`, seconds of game time, at least 5 s) |
| Urgency | the pickup **flashes** in its final ~2 seconds so the player can see it is about to go |
| Collected | **exactly one** charge granted (respecting the cap of 3), announced on screen, pickup leaves the field |
| Not collected | the pickup **expires and is gone** — no charge, nothing left behind |
| Backstop | reaching the score threshold while holding no charge still grants one (phase 10's W1) |

## The surface the probes read — implement these names

```
state.carrier = { enemyIndex, alive }                       // which live enemy carries it
state.pickups = [ { x, y, remaining, lifetime, kind } ]     // live drops; x, y are the mark's CENTRE
```

`x, y` is the centre of the drawn mark, which is drawn within roughly a 24 × 24 box. `lifetime` is the
full life in seconds and `remaining` counts down in **game time** (the clock `test.step(n)` advances).

**The marker must add rendered ink to the carrier's box.** The probe is a differential: it measures the
same box with `state.carrier.enemyIndex` set to that enemy, then moves the flag to another enemy and
measures the same box again. Everything that is not the marker — the sprite, its caste, its wing frame,
any neighbour whose sprite overlaps the box — is identical in both readings and cancels. This also means
the mark must **track the flag**, and it must be painted: a recolour that changes no pixel brightness
does not pass, because a carrier the player cannot pick out is just a random enemy that happened to drop
something.

## Requirements

- **D1 — the carrier is in play and identifiable.** In a standard wave exactly one carried enemy exists, and
  it is distinguishable **on the rendered field** (a marker drawn at or around it), because a carrier the
  player cannot pick out is just a random enemy that happened to drop something.
- **D2 — killing the carrier drops a collectable.** The drop appears at the carrier's position and drifts, with
  a bounded `remaining` that decreases in game time and never goes negative.
- **D3 — flying into it grants exactly one charge**, announced on screen, and the pickup leaves the field.
- **D4 — an uncollected drop expires with nothing granted.** The director's rule, stated as its own
  requirement: *get it before it disappears*. A drop that waits forever is not a decision.
- **D5 — the drop is legible as it expires.** Its rendered ink changes in the last ~2 seconds (a flash), so
  the player can see the deadline rather than discover it by losing the pickup.
- **D6 — the threshold remains a guarantee.** Earning the score threshold while holding no charge still grants
  one, so two carriers missed in a row cannot leave the weapon unreachable for the rest of a run.

**D6 already passes.** Measured before dispatch: with no carrier collected and nothing held, crossing
10,000 grants exactly one charge. It is asserted so that this item cannot buy the new path by deleting the
old one. Do not weaken it to make room.

## Scope

- `public/star-swarm/`
- `templates/modules/star-swarm/`
- `modules/star-swarm/`
- `tests/browser/star-swarm.spec.ts`

## Acceptance — all of them, and the driver gates them

```
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=12
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=10
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=3
$ npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p12"
$ npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p10"
$ npx playwright test tests/browser/star-swarm-audio.spec.ts
$ npx playwright test tests/browser/star-swarm.spec.ts
$ php tests/star_swarm_visual_test.php
$ php tests/star_swarm_concept_test.php
$ php tests/star_swarm_galaga_gate_test.php
$ composer test
```

Measured red baseline, 2026-09-19: `@p12` is **5 failed / 1 passed** — D1 to D5 fail, each naming itself,
and D6 passes. The gate `--phase=12` is green because the gate is the vacuity guard: it proves each
requirement has a probe in the chair-owned spec, not that the probe passes. Both must be green.

`@p10` is included because this item must not damage the lance it feeds: the charge cap, `S`, the window and
the threshold guard are all phase 10's verified behaviour. **Gate phase 3** and the state spec cover the
destruction path the drop depends on.

## Boundaries

- **Do not edit `tests/browser/star-swarm-pixels.spec.ts`, `tests/browser/star-swarm-audio.spec.ts`,
  `tools/harpp2/projects/star-swarm-galaga.json`, or anything under `tools/harpp2/gates/`.**
- **Never loosen a threshold, delete an assertion, or add a sleep or `waitForTimeout`.**
- **Do not remove the score threshold.** It is phase 10's verified behaviour and the anti-starvation backstop;
  this item adds a way to earn the lance, it does not take one away.
