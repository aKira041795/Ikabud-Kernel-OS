# OBJECTIVE — Star Swarm, **phase 10: the earnable firepower ("Plasma Lance")**

system: HARPP v2 · 2026-09-19 · chair-authored. Executor: any lane the driver assigns.
authority: director request 2026-09-19 — *"can we add a firepower when kill points reach x amount? limited use
and can be activated using S key?"*
Requirements: `tools/harpp2/projects/star-swarm-galaga.json`, group **powerup** (W1–W5).
Runs after `tools/harpp2/objectives/star-swarm-galaga-p9.md`.

## This is an ADDITION, not fidelity

Galaga has no such weapon, so this joins the 16:9 field and the nursery planet as a **recorded deviation**:
it must never be presented as arcade fidelity, and no existing requirement may be re-worded to imply it is.

## The design, decided rather than guessed

| Property | Value |
|---|---|
| Earned at | **10,000** points, then every **20,000** (10k, 30k, 50k …) |
| Stored | up to **3** charges; further thresholds do not accumulate past the cap |
| Activated by | **S** (verified free: A/D/arrows move, Space/Up/W fire, P pauses) |
| Costs | exactly **one** charge |
| Lasts | **6 seconds** of game time, then expires on its own |
| Does | while active and while fire is held, projects a continuous **beam** from the fighter that destroys enemies it touches |

**Why a beam and not more bullets.** The arcade two-shots-in-flight rule is already verified fidelity
(`C4`, and `expect(result.shotCounts, 'at most two player shots survive repeated fire attempts').toEqual([1, 2, 2, 2])`
in `tests/browser/star-swarm.spec.ts`). A triple-shot power-up would satisfy this request by invalidating that
verified invariant. A beam is a different weapon class, so the fidelity rule is untouched and the power-up
reads as an *earned escalation* rather than an exception to a rule. W4 exists to hold that line.

## Requirements

- **W1 — earning it is announced.** At 10,000 and every 20,000 after, a charge is granted (up to the cap) and
  the gift is **announced on screen** in the central band, exactly as the extra ship is. Observable as
  `state.powerup.charges` and `state.powerup.nextAt`.
- **W2 — S activates it, and only with a charge.** With a charge, pressing `S` consumes exactly one and starts
  the window (`active: true`, `remaining > 0`). With **no** charge, `S` does nothing: no activation, no error,
  no charge produced. Both halves are asserted, because "spend what you have" and "do not invent what you
  lack" are different behaviours.
- **W3 — it does firepower and it expires.** While active, the beam destroys an enemy it touches, driven
  through the real destruction path (score rises, the enemy leaves the field). When the window ends the lance
  expires by itself: `active: false`, `remaining: 0`, and the charge is not refunded.
- **W4 — the arcade two-shot rule survives.** `state.bullets.length` stays at most **2** while the lance is
  being fired: the beam is not a bullet. This is the requirement that protects a verified invariant, and it is
  asserted while the lance is active, not merely in the default state.
- **W5 — the player can see it.** A HUD element `[data-star-swarm="lance"]` reflects the stored charges (and
  the live state while the window is open), so a charge is never invisible. The extra ship was once granted in
  silence (measured: 12 → 12 rendered pixels) and that is not happening twice.

Expose the surface as, at minimum:

```
state.powerup = { charges, active, remaining, nextAt, uses }   // remaining in seconds of game time
```

Driven by **game time** through the update path, not by `Date.now()`, so the probes can step the clock and
hold the window open deterministically.

## Scope

- `public/star-swarm/`
- `templates/modules/star-swarm/`
- `modules/star-swarm/`
- `tests/browser/star-swarm.spec.ts`

## Acceptance — all of them, and the driver gates them

```
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=10
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=1
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=2
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=3
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=4
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=5
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=6
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=7
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=8
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=9
$ npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p10"
$ npx playwright test tests/browser/star-swarm-audio.spec.ts
$ npx playwright test tests/browser/star-swarm.spec.ts
$ php tests/star_swarm_visual_test.php
$ php tests/star_swarm_concept_test.php
$ php tests/star_swarm_galaga_gate_test.php
$ composer test
```

The **state spec** and **gate phase 3** both cover the two-shot rule and the scoring table: if either goes red,
the power-up broke the game rather than extending it — report that, do not retune the arcade rules to hide it.
The audio spec is included because a new cue must not disturb the five that exist.

## Boundaries

- **Do not edit `tests/browser/star-swarm-pixels.spec.ts`, `tests/browser/star-swarm-audio.spec.ts`,
  `tools/harpp2/projects/star-swarm-galaga.json`, or anything under `tools/harpp2/gates/`.**
- **Never loosen a threshold, delete an assertion, or add a sleep or `waitForTimeout`.**
- **Do not satisfy this by changing the two-shot rule, the scoring table, the extra-ship thresholds, or the
  enemy side.** The power-up is earned by the existing score and spends only itself.
- **Do not use a second input path.** `S` goes through the same keyboard handler as the other keys, and
  activation is idempotent: holding `S` must not drain every charge in one press-and-hold.
- **The lance must not become a bullet.** If it is implemented as projectiles, W4 fails and the reason is real.
- Say so if part of this cannot be met honestly.
