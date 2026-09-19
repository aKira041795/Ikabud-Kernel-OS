# Star Swarm — the drop must arrive, and every stage must carry its own weapon

## Objective

Two changes to Star Swarm, both already measured RED.

**1. The carrier's drop never reaches the player.** Reported from play: "the drop item does not
reach the bottom for the player to collect". Measured: the pickup falls at 18px/s and expires after
7s, so it travels **126px** in a field **720px** tall. The carrier sits at y≈176 and the player at
y≈660, so the drop dies at **y=301.9** — roughly two-fifths of the way down, out of reach even if the
player stands directly underneath it. A reward that cannot be collected is a taunt, not a reward.

**2. Every stage must carry a weapon of its own.** Today the drop rotates through three kinds by
`(stage - 1) % 3`, which across an eight-stage loop yields `lance, lance, lance, rapid, lance, rapid`
— **two** distinct weapons across the six stages that carry one. The director asked for "new weapons
per level", and this is the design decision, grounded in three real cabinets rather than taste:

- **Galaga (1981)** supplies the stage STRUCTURE. An endless loop of stages with a **Challenging
  Stage every fourth stage, starting at stage 3** — which in an eight-stage loop means stages 3 and 7,
  exactly as the game already behaves. Do not change that. Galaga also supplies the two-shot ceiling:
  the second shot in flight is a *reward*, not a weapon, so `PLAYER_SHOT_LIMIT` stays at 2.
- **Raiden (1990)** supplies weapon IDENTITY. Its pickups are colour-coded and **deterministic**, so a
  player learns which stage carries what instead of gambling on a random drop. Hence: one weapon per
  stage, fixed by stage number, learnable.
- **Gradius (1985)** supplies ESCALATION. Its arsenal grows with the run rather than being handed over
  at the start, so each stage introduces something the player has not had before.

The six stages that carry a weapon must therefore carry **six distinct ones**:

| stage | weapon | what it changes |
|---|---|---|
| 1 | `lance` | the baseline single fast forward shot — teaches the default |
| 2 | `rapid` | shorter cooldown, same single forward shot |
| 4 | `spread` | two shots diverging in a V |
| 5 | `twin` | two PARALLEL forward shots, wider muzzle spacing, no divergence |
| 6 | `pierce` | shots pass through an enemy and carry on |
| 8 | `nova` | the loop finisher: rapid cooldown *and* the widest volley |

## Architectural constraints

- **`PLAYER_SHOT_LIMIT` stays exactly 2. This is not negotiable and is verified behaviour.** Every
  weapon changes *where* shots go or *how often*, never **how many are in the air at once**. A weapon
  that raises the cap is a rejection, not a variation. `fireBullet()` already clamps its muzzle loop
  with `state.shots.length < PLAYER_SHOT_LIMIT`; keep that clamp meaningful.
- **Weapons must persist until replaced**, not expire on a timer the way the current rapid/spread
  timers do. A weapon you lose after twelve seconds cannot be learned, and Raiden's design is that the
  pickup defines your weapon until you take another. Persistent state belongs on `state.weapon`.
- **A weapon must be legible.** The player has to be able to tell which weapon they are holding, from
  what is on the field — not from source. The HUD must name the current weapon. (This is an acceptance
  criterion the probes do not measure; it will be verified by the chair with a screenshot, so do it.)
- **The drop must reach the bottom edge under its own momentum.** It must not expire in mid-air. Fixing
  this by giving it a longer life alone is acceptable only if the drop also spends that time actually
  descending — a slow flotilla is not a fix. A fall speed around 90px/s with a life of at least 12s
  gives ~1080px of travel in a 720px field, so the pickup always leaves through the bottom rather than
  vanishing part-way down. Choose the exact numbers; justify them in a comment with the arithmetic.
- **Keep the drop collectable and telegraphed.** The existing final-two-second flash is a warning to
  the player; do not remove it.
- **Do not change the challenging-stage cadence.** Stages 3 and 7 carry no carrier and therefore drop
  nothing. That is correct and is asserted by the probe.
- Vanilla JS on a canvas. No frameworks, no new files, no build step, no dependency.
- **The pixel font silently skips characters it has no glyph for.** Any new on-screen text must use
  characters that exist in the glyph table, or the text will silently lose letters. Check before use.
- Where the work lives, for orientation: `star-swarm.js` carries the weapon table, `grantPickup`,
  `fireBullet`, the pickup update, the HUD and the drop site in `destroyEnemy` — it is the whole task.
  `index.html` needs its `?v=` asset query string bumped so a warm browser cannot serve the stale
  bundle, and the stylesheet only if the HUD needs it.
- The contract format itself has one trap worth knowing: `## Files likely affected` and
  `## Forbidden changes` are parsed LINE BY LINE as scope entries, so no prose may share those
  sections. Everything explanatory belongs here, in the constraints.

## Files likely affected

- `public/star-swarm/star-swarm.js`
- `public/star-swarm/index.html`
- `public/star-swarm/star-swarm.css`

## Acceptance criteria

- A drop released at the carrier's row reaches the player's row before it is gone (probe @p15).
- The six carrier stages carry six distinct weapons (probe @p16).
- Stages 3 and 7 remain the challenging stages and carry no carrier (probe @p16).
- `PLAYER_SHOT_LIMIT` is still 2 and no weapon can put a third shot in the air.
- The HUD names the weapon currently held, and it changes when a new pickup is taken.
- A collected weapon persists for the rest of the run rather than expiring on a timer.

## Required tests

- `npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p15|@p16" --reporter=line`

Then, because this touches shooting, the rest of the game's own suite must stay green:

- `npx playwright test tests/browser/star-swarm-pixels.spec.ts --reporter=line`
- `php tests/star_swarm_visual_test.php`

## Risks

- **Raising the shot cap.** The obvious way to make "twin" feel different is to fire two shots — which
  is correct — but the tempting shortcut is to allow a third in flight. That breaks verified behaviour.
- **Weapons that expire.** Reusing the existing `state.weapon.rapid`/`spread` timers for permanent
  weapons leaves the player armed for a countdown, which reads as a bug.
- **A drop that is technically reachable but practically not.** Reaching y≈620 at 18px/s over 25
  seconds satisfies a distance check while being unplayable. The fix must change the *feel* of the
  descent, not just its total length.
- **`pierce` removing the wrong thing.** Making shots survive a hit touches the collision path where
  bullets are removed; it is easy to leave a shot alive that has already scored, or to let it score
  repeatedly on the same enemy. One hit must still score once.
- **Silent glyph gaps** in any new HUD text.

## Forbidden changes

- `tests/`
- `tools/`
- `kernel/`
- `modules/star-swarm/`
- Do not change `PLAYER_SHOT_LIMIT` (2) or lower it.
- Do not change the challenging-stage cadence, or the 8-stage loop length.
- Do not edit, weaken, skip, or delete any assertion in the acceptance spec, and do not add a
  `waitForTimeout` to make anything pass. The spec is the definition of done; changing it changes the
  question and is not an answer to it.
- Do not remove the pickup's expiry flash, the perfect bonus, or the existing `lance`/`rapid`/`spread`
  behaviour in a way that breaks the probes that already cover them.
