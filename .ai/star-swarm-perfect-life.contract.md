# A perfect bonus grants a life, every time

## Objective

Director, verbatim: *"add life when bonus is perfect, everytime."*

Measured: clearing the challenging stage perfectly sets `state.bonus.perfect = true` and awards 10,000
points — **and no life**. Lives gained: 0. It must be exactly 1, and it must be 1 on every occurrence.

For context: Galaga's challenging stage awards a perfect bonus of 10,000 for shooting all forty — points
only, no life. The director wants more than that, and on every occasion rather than once per run.

## Architectural constraints

- **One life per perfect bonus, and on every one.** "Everytime" is the load-bearing word. A
  once-per-run guard would satisfy the first half and fail the second, and the probe checks both.
- **Award the life explicitly in the perfect branch. Do NOT do it by adding more score.**
  `addScore()` already grants an extra ship whenever the score crosses `state.extraShipAt`, so raising
  the 10,000 to a larger number would grant a life only sometimes — when the total happens to cross a
  threshold — and would change the score display as a side effect. The probe isolates the two by
  pushing `extraShipAt` out of reach, so a score-based shortcut will not be able to pass it.
- `state.lives += 1` is the existing idiom for granting a life (see the extra-ship path in
  `addScore`). Use it; do not invent a second representation of lives.
- The `PERFECT 10000` announcement already exists. If the player is now also getting a life, the
  announcement should say so — a reward the player does not notice is half a reward. Keep it within the
  pixel font's glyph set, which the suite now verifies (an unknown character is silently skipped).
- Do not change the perfect bonus's score value, the extra-ship threshold or interval, the
  challenging-stage cadence, or the `challengingDestroyed === 40` condition.
- Bump the `?v=` asset query string in `index.html` to the new `star-swarm.js` mtime — a version guard
  asserts the query string equals the file's mtime.
- Vanilla JS on a canvas. No frameworks, no new files, no build step, no dependency.

## Files likely affected

- `public/star-swarm/star-swarm.js`
- `public/star-swarm/index.html`

## Acceptance criteria

- A perfect bonus grants exactly one life, on the first occurrence and again the next time: `npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p21" --reporter=line`
- The whole rendered-canvas suite still passes: `npx playwright test tests/browser/star-swarm-pixels.spec.ts --reporter=line`
- The PHP-side visual test still passes: `php tests/star_swarm_visual_test.php`

## Required tests

- `npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p21" --reporter=line`

## Risks

- **The score shortcut.** Granting the life by inflating the score works intermittently and looks like
  success on a lucky run; the probe is built to defeat it.
- **Once per run.** A `perfectBonusAwarded` style flag would pass a single-occurrence test and fail the
  director's actual requirement.
- **Doubling the life.** If the life is granted both in the perfect branch and via a score threshold,
  one perfect clear could award two.
- **Silently skipping a character** in any new announcement text — the glyph set now covers the
  printable suspects, but check anything exotic.
- **Editing the probe.** It is the definition of done.

## Forbidden changes

- `tests/`
- `tools/`
- `kernel/`
- `modules/star-swarm/`
- Do not change the perfect bonus score, the extra-ship threshold or interval, the challenging-stage
  cadence, or the 40-kill condition.
- Do not edit, weaken, skip or delete any assertion in the acceptance spec, and do not add a
  `waitForTimeout` to make anything pass.
