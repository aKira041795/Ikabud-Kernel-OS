# OBJECTIVE — Star Swarm, **phase 11: the bonus round (kill them all, against the clock)**

system: HARPP v2 · 2026-09-19 · chair-authored. Executor: any lane the driver assigns.
authority: director request 2026-09-19 — *"add a bonus round where i can kill all if possible all enemies,
limited time"*.
Requirements: `tools/harpp2/projects/star-swarm-galaga.json`, group **bonus** (B1–B4).
Runs after `tools/harpp2/objectives/star-swarm-galaga-p10.md`.

## Extend the round that exists — do not build a second one

The game already has the arcade **challenging stage**, verified in phases 2 and 3:

- every fourth stage from stage 3 (`challengingPatternForStage`),
- **forty** enemies (5 rows × 8 columns) entering along preset **routes**, and they **do not fire**,
- **100** points per enemy and a **10,000 perfect bonus for all forty** (requirement P4, probe
  `perfect bonus of 10000`; G2, probe `every fourth stage is a challenging stage`).

What it does **not** have is a **time limit**, and without one "kill them all" is not a goal a player can
measure themselves against. So this item adds the clock and the all-killable guarantee to the round that
already exists. A second, parallel bonus mode would duplicate the scoring, the patterns and the perfect
bonus, and then drift from them.

**P4 and G2 stay true and stay asserted.** This is deliberately not a new requirement list for behaviour that
already exists; the acceptance below re-runs those phases so the addition cannot quietly rewrite them.
In particular: the forty enemies, the cadre cadence, the no-fire rule and 100-per-enemy scoring are the
arcade rules and must survive unchanged.

## Requirements

- **B1 — there is a clock, and the player can see it.** The bonus round runs on a countdown of at least
  **15 seconds** of game time, exposed as `state.bonus.remaining` (seconds, decreasing), starting from the
  full window when the round begins and never going negative. The countdown is **rendered in the top band of
  the play field — the top 16% of the canvas** — so the probe has an unambiguous place to look and the player
  has an unambiguous place to see it. A clock nobody can see is not a time limit.
- **B2 — clearing them all is possible, and it pays.** Every bonus-round enemy is still on the field and
  destroyable when the countdown starts, so a skilled player can clear all forty before it expires.
  Destroying all of them awards the **existing** 10,000 perfect bonus and announces it on screen (canvas,
  central band). The probe clears the field through the real destruction path, so the reward is earned by the
  same code the player's shots use.
- **B3 — the clock running out ends it cleanly.** With enemies still on the field when `remaining` reaches 0,
  the round closes: `state.bonus.active` false, `remaining` 0, **no** perfect bonus awarded, and play
  continues to the next stage. No stuck state, no endless round, no enemies frozen on screen forever.
- **B4 — the countdown is legible in the HUD.** `[data-star-swarm="bonus"]` reflects the seconds remaining and
  the enemies left, so the player can see both the pressure and the progress. The extra ship was once granted
  in silence (measured 12 → 12 rendered pixels); a clock hidden from the player would repeat that mistake.
- **B5 — nothing shoots, for the whole round including its exit.** Measured before this item was written, with
  a control: a bonus round fires **0** shots in 15 seconds while a standard stage fires **10** — so the
  no-fire rule is real, and the instrument that says so is not blind. The existing assertion
  (`'challenging-stage enemies fly their preset patterns and do not fire'`) samples a window; this one asserts
  the rule across the **entire** round *and the transition out of it*, because that is exactly where a timer
  could break it: enemies released from a frozen state while still on screen, or a round that expires into a
  standard firing regime with enemies left over. The probe must compare against a standard stage **in the same
  run**, so a failed measurement can always be told apart from a blind one.
- **B6 — the round says its own name.** When a bonus round begins, **BONUS ROUND** is rendered on the play
  field in the central band, in the game's pixel idiom, announced exactly as the extra ship and the opening
  title are. Exposed as `state.bonus.title === 'BONUS ROUND'`, so the wording is assertable as data while the
  pixels prove something is genuinely drawn there: a probe cannot read text off a canvas, and a string in state
  is exactly the kind of claim this project has already been burned by. Both halves are required.

Expose the surface as, at minimum:

```js
state.bonus = { active, remaining, window, enemiesLeft, cleared, perfect, title }   // seconds; title names the round
```

Driven by **game time** through the update path, so the probes can step the clock deterministically instead of
sleeping on a wall clock.

## Where the enemies must be, and where they must not be changed

The patterns, the routes and the entry choreography are **phase 2's verified work** — leave them alone. What
B2 requires is only that an enemy is not beyond reach when the clock starts: it must still be on the field and
killable for the duration of the window. If the current route choreography takes enemies off the field before
the clock expires, that is the one thing to adjust — and the adjustment is "keep them reachable", not "re-time
the entrance".

## Scope

- `public/star-swarm/`
- `templates/modules/star-swarm/`
- `modules/star-swarm/`
- `tests/browser/star-swarm.spec.ts`

## Acceptance — all of them, and the driver gates them

```
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=11
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=1
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=2
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=3
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=4
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=5
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=6
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=7
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=8
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=9
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=10
$ npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p11"
$ npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p2"
$ npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p3"
$ npx playwright test tests/browser/star-swarm-audio.spec.ts
$ npx playwright test tests/browser/star-swarm.spec.ts
$ php tests/star_swarm_visual_test.php
$ php tests/star_swarm_concept_test.php
$ php tests/star_swarm_galaga_gate_test.php
$ composer test
```

`@p2` (scoring) and `@p3` (gameplay) plus the state spec are the guard on the arcade rules this round is built
from: if any goes red, the clock changed the challenging stage rather than adding to it — report that rather
than adjusting the arcade expectations.

## Boundaries

- **Do not edit `tests/browser/star-swarm-pixels.spec.ts`, `tests/browser/star-swarm-audio.spec.ts`,
  `tools/harpp2/projects/star-swarm-galaga.json`, or anything under `tools/harpp2/gates/`.**
- **Never loosen a threshold, delete an assertion, or add a sleep or `waitForTimeout`.**
- **Do not change the forty, the cadence, the no-fire rule, the 100-per-enemy scoring or the 10,000 perfect
  bonus.** They are verified arcade behaviour; the clock is added to them, not over them.
- **Do not make the round unwinnable by accident**: the window must be long enough that clearing the field by
  destroying every enemy is achievable, which is what B2 asserts.
- **Do not leave enemies on the field after the round ends.** A frozen enemy after the clock is a stuck game.
- Say so if part of this cannot be met honestly.
