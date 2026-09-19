# Star Swarm — the stage-clear beat must report, not just announce

## Objective

When a player clears a stage, Star Swarm says "STAGE n OF 8 CLEAR" and that is all it says. A beat that
only announces the obvious is decoration: the player already knows they cleared the stage, because the
field went quiet. What they do not know — and what the game has the information to tell them — is **what
the stage was worth**: how many enemies they destroyed, and how many points those kills earned.

Add that tally to the existing stage-clear beat, and render it. The beat already exists and already
renders (`state.stageClear` is populated in `startWave()`); the missing parts are the two numbers and the
line that shows them.

Measured red baseline — the probe this task must turn green fails today on the missing tally:

```
Error: expect(received).toBeGreaterThan(expected)
tests/browser/star-swarm-pixels.spec.ts  @p14  1 failed
```

## Architectural constraints

- **Do not add a new announcement channel.** Extend the existing `state.stageClear` object with `tally`
  (points earned this stage) and `destroyed` (enemies killed this stage); the render path in
  `drawStageClear()`/`drawAnnouncement()` already draws `state.stageClear.text`. Add the numbers to what
  it draws — do not add a second overlay with its own lifetime.
- **The counters belong to the stage, not to the run.** `tally` and `destroyed` must count only the stage
  being cleared, and reset when the next stage begins. A running total across the whole run is a
  different feature and the probe does not ask for it.
- **Count where the game already knows.** Points are credited at enemy destruction; the honest place to
  increment `destroyed`/`tally` is that same destruction path, not a diff of `state.score` sampled at
  wave start. Sampling the score would silently include any points awarded for something else (a pickup,
  a bonus ship) and would be wrong the first time such a source is added.
- The pixel font **silently skips characters it has no glyph for**. Before rendering `"STAGE 1 CLEAR —
  12 KILLED / 340 PTS"`, confirm every character used exists in the glyph table the renderer reads. A
  digit or dash with no glyph disappears and the probe's ink assertion can still pass on the rest of the
  line, so check the rendered ink, not just the state.
- Star Swarm is vanilla JS on a canvas. No frameworks, no new files, no build step, no dependency.
- Keep the existing newline/line-spacing idiom already used by the surrounding HUD text.

## Files likely affected

- `public/star-swarm/star-swarm.js` — the state (`state.stageClear`), the destruction path, and the draw
  call. This is the whole task; it should be a small number of edits in one file.

## Acceptance criteria

- `state.stageClear.tally` is greater than zero after a stage is cleared, and equals the points the stage
  actually earned.
- `state.stageClear.destroyed` is greater than zero after a stage is cleared, and equals the number of
  enemies destroyed in that stage.
- Both numbers appear on the rendered field inside the stage-clear beat, not only in state.
- Clearing a second stage reports that stage's numbers, not the first stage's.

## Required tests

- `npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p14" --reporter=line`

## Risks

- **Rendering nothing.** The probe asserts ink in the announcement band precisely because this project has
  twice shipped a value that existed in state and never reached the screen. A passing state assertion with
  no ink is a failed task.
- **A counter that is never reset** — the second stage then reports a cumulative figure. The probe's
  "clearing a second stage reports that stage" case is what catches it.
- **Credit in the wrong place.** Incrementing from a score delta silently counts non-kill points.

## Forbidden changes

- `tests/`
- `tools/`
- `kernel/`
- `modules/star-swarm/`
- `public/star-swarm/index.html`
- Do not change `PLAYER_SHOT_LIMIT`, `PLAYER_FIRE_COOLDOWN`, or any weapon timing: `PLAYER_SHOT_LIMIT = 2`
  is verified behaviour and this task has nothing to do with weapons.
- Do not edit, weaken, skip, or delete any assertion in the acceptance spec. The spec is the definition of
  done; changing it changes the question and is not a solution to it.
- Do not add a `waitForTimeout` or otherwise make the check pass by waiting.
