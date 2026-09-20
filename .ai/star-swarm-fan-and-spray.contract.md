# Star Swarm — the spread is a fan of five, and the rapid is a spray

## Objective

Director, verbatim: *"spread shot must be multi angle of 5 spread. rapid shot must be a spray."*

Neither is true today. Measured:

```
spread must fire five projectiles, got [-209.5, 209.5]
```

Spread fires a V of **two**, and rapid fires a **single straight shot** with a shorter reload.

Make `spread` fire **five projectiles at five different angles**, fanned around the centre line, and
make `rapid` fire a **spray** — more than one projectile, travelling in more than one direction.

## Architectural constraints

**The shot cap must be re-read, and this overrides a restriction stated in earlier contracts.**
`PLAYER_SHOT_LIMIT` is 2, and the volley loop is clamped by it:

```js
for (var i = 0; i < volley.length && state.shots.length < PLAYER_SHOT_LIMIT; i += 1)
```

A five-element volley is therefore **silently truncated to two**. The fan cannot exist while the cap
counts *projectiles*. The cap now governs **volleys in flight, not individual projectiles**: the player
still may not have two volleys in the air at once — the Galaga intent, that a second shot is a reward
and not spam — but one volley may deliver its full fan, which is the Raiden/Gradius behaviour these
weapons were chosen from. Galaga had no spread weapon at all, so the two-projectile reading was never
a Galaga rule; it was an inference, and it is wrong for these guns.

Do not delete `PLAYER_SHOT_LIMIT`. Change what it counts, and say so in a comment with the reasoning
above, because the next reader will otherwise "fix" it back.

- **The unarmed shot must stay a single straight projectile.** It is the control in the probe: if the
  bare shot became a spray too, "spray" would mean nothing. `lance` is the default and stays one shot.
- **The other weapons must not regress.** `twin` is two parallel shots, `nova` a wide pair, `pierce`
  passes through, and the dual-fighter loadout fires from two muzzles. All must still fire, and none
  may be silently truncated by the new cap semantics.
- **Screen flooding is the risk to manage.** Five angled projectiles per volley, on top of enemy fire,
  is the point of the weapon — but a cap that no longer counts projectiles must still bound total
  projectiles in flight, or holding the fire key fills the screen. Bound the volley count, and if you
  also need a ceiling on live projectiles, make it generous and explicit rather than absent.
- **Angles must be symmetric about the centre line** so the fan is centred on the ship.
- Keep the existing `{ muzzle, angle }` volley structure and the `vx` / `verticalSpeed` projection —
  the probe reads direction from `vx`.
- **Do not change the weapons' persistence, the pickup, or the HUD** beyond what the names require.
  `nova` and `rapid` may share the shorter reload; that is existing behaviour.
- Bump the `?v=` asset query string in `index.html` to the new `star-swarm.js` mtime — a version guard
  asserts the query string equals the file's mtime, and a warm browser otherwise serves the old bundle.
- Vanilla JS on a canvas. No frameworks, no new files, no build step, no dependency.

## Files likely affected

- `public/star-swarm/star-swarm.js`
- `public/star-swarm/index.html`

## Acceptance criteria

- Spread fires five projectiles at five distinct angles, fanned symmetrically (probe @p19).
- Rapid fires more than one projectile in more than one direction (probe @p19).
- The unarmed shot is still exactly one straight projectile (probe @p19).
- The whole rendered-canvas suite still passes: `npx playwright test tests/browser/star-swarm-pixels.spec.ts --reporter=line`
- The PHP-side visual test still passes: `php tests/star_swarm_visual_test.php`

## Required tests

- `npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p19" --reporter=line`

## Risks

- **Truncation by the cap.** The single most likely failure: the fan is defined as five but the loop
  still stops at two, and the probe reports a two-element volley. Fix the cap, not the fan.
- **Flooding the screen** — see above.
- **Breaking the other four weapons** while rewriting the volley selection.
- **The dual fighter.** It fires from two muzzles; make sure it still does, and that its volley is not
  multiplied into ten by accident.
- **Editing the probe.** It is the definition of done. Changing it changes the question.

## Forbidden changes

- `tests/`
- `tools/`
- `kernel/`
- `modules/star-swarm/`
- Do not delete or rename `PLAYER_SHOT_LIMIT`; change its meaning and document it.
- Do not change the pickup, the per-stage arsenal, the weapon persistence, or the challenging-stage
  cadence.
- Do not edit, weaken, skip or delete any assertion in the acceptance spec, and do not add a
  `waitForTimeout` to make anything pass.
