# OBJECTIVE — Star Swarm, **phase 8: responsiveness (movement and firing)**

system: HARPP v2 · 2026-09-19 · chair-authored. Executor: any lane the driver assigns.
authority: director report 2026-09-19 — *"Shooting (arrow up) key is sluggish and so is horizontal
movement"*, alongside *"refer to Galaga"*. Requirements: `tools/harpp2/projects/star-swarm-galaga.json`,
group **responsiveness** (R1–R4).
Runs after `tools/harpp2/objectives/star-swarm-galaga-p7.md`.

## The item

Make both of these green:

```
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=8
$ npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p8"
```

## Cause — measured, not guessed

Every speed is an absolute constant in pixels per second, and phase 5 widened the field from **800×600 to
1280×720**. The geometry grew; the speeds did not.

| | Value | Consequence |
|---|---|---|
| `PLAYER_SPEED` | 420 px/s | **3.05 s** to cross the field — it was **1.9 s** on the 800 px field (**1.6× slower**) |
| `BULLET_SPEED` | 620 px/s | **1.16 s** for a shot to reach the top — it was **0.97 s** (**1.2× slower**) |
| `PLAYER_FIRE_COOLDOWN` | 0.22 s | not the limiter |
| `PLAYER_SHOT_LIMIT` | 2 | with a 1.16 s flight, this caps sustained fire at **2 shots/s** |

So this is not a broken input path — **input latency measured one frame**. It is the widening diluting
everything by 1.6× on the horizontal axis, which is exactly what the director felt.

## The fix, and the mistake to avoid

**Derive the speeds from the field** rather than raising three constants. The requirement is written as a
fraction of the field precisely so that this class of defect cannot come back the next time the geometry
changes. For example a crossing target of ~1.9 s gives `PLAYER_SPEED = fieldWidth / 1.9`, and a shot that
clears the height in under 0.8 s gives `BULLET_SPEED = fieldHeight / 0.75`. Those numbers are illustrative —
satisfy the probes, which are the requirement.

**The two-shots-in-flight allowance is fidelity and must not be raised to reach R3.** The rate has to come
from shots that clear the field faster. R2 and R3 were set together on purpose: at 0.8 s of travel the same
two-shot limit sustains 2.5/s, so the phase is satisfiable (CD-86).

**Both bounds are asserted.** Quick is required; twitchy is forbidden (at most 1.2 field widths per second),
and a shot may not be instant (at least 0.25 s). Do not overshoot in the other direction to make the probe
comfortable — a shot that hits the top in three frames removes the lead a diving enemy is supposed to have,
which is a gameplay change wearing a responsiveness label.

## Scope

- `public/star-swarm/`
- `templates/modules/star-swarm/`
- `modules/star-swarm/`
- `tests/browser/star-swarm.spec.ts`

## Acceptance — all of them, and the driver gates them

```
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=8
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=1
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=2
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=3
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=4
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=5
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=6
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=7
$ npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p8"
$ npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p4"
$ npx playwright test tests/browser/star-swarm.spec.ts
$ php tests/star_swarm_visual_test.php
$ php tests/star_swarm_concept_test.php
$ php tests/star_swarm_galaga_gate_test.php
$ composer test
```

`@p4` is included deliberately: enemy dive speed, the tractor-beam window and the collision/dodge behaviour
all scale with how fast the player moves. If those probes go red, the speed change broke the game rather
than the feel — report it, do not retune the enemy side to hide it.

Phases 1–7 must stay green. No existing assertion pins the player or bullet speed (checked), so the change
is free to make; the two-ship dodge window asserted by the state spec is the thing to watch.

## Boundaries

- **Do not edit `tests/browser/star-swarm-pixels.spec.ts`, `tools/harpp2/projects/star-swarm-galaga.json`, or
  anything under `tools/harpp2/gates/`.**
- **Never loosen a threshold, delete an assertion, or add a sleep or `waitForTimeout`.**
- **Do not raise `PLAYER_SHOT_LIMIT`.** Two shots in flight is the arcade rule, already verified as P/C
  behaviour; R3 is a consequence of faster shots, not of a bigger allowance.
- **Do not change the scoring table, the caste palette, the field size, or the sprite data.** This item is
  three speeds.
- **Do not make the game faster by shortening the update step or the physics.** `test.step` drives
  `update(1/60)` and the browser loop drives the same path; changing the step to multiply distance is not a
  speed change, it is a different simulation.
- Say so if part of this cannot be met honestly.
