# OBJECTIVE — Star Swarm, **phase 9: sound (start, fire, hit by size, the beam, game over)**

system: HARPP v2 · 2026-09-19 · chair-authored. Executor: any lane the driver assigns.
authority: director request 2026-09-19 — *"add sound effects to the firing, blasting of enemy, according to
size. tractor beam. game over. start"*.
Requirements: `tools/harpp2/projects/star-swarm-galaga.json`, group **audio** (A1–A6).
Runs after `tools/harpp2/objectives/star-swarm-galaga-p8.md`.

## The item

Make both of these green:

```
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=9
$ npx playwright test tests/browser/star-swarm-audio.spec.ts
```

There is **no audio in the game today** (zero references to `AudioContext` in the source), so this is a new
capability, not a repair.

## The cues

| Cue | When | Notes |
|---|---|---|
| `start` | play begins | the cue is the player's own gesture, which is the only legal moment to create the context |
| `fire` | the frame a player shot spawns | once per shot, not per held keypress-repeat |
| `hit` | an enemy is destroyed | **scaled by that enemy's size**: a bigger body sounds lower and longer |
| `beam` | a tractor beam is open | **ongoing** — it sounds for the ~1.8 s the beam is open and stops when it closes |
| `gameover` | the last life is lost | distinct from the `hit` that caused it |

## The observable the probes read

A machine cannot hear, so the acceptance is the **audio graph the game reports**. Expose it as
`state.audio`, exactly this shape:

```js
state.audio = {
    contextState: 'running' | 'suspended' | 'unavailable',
    sampleRate:   number,        // 0 when unavailable
    count:        number,        // total cues issued, monotonic
    beamActive:   boolean,       // true while an ongoing beam tone is sounding
    log:          [ { cue, at, size, frequency, duration } ]   // bounded; newest last
}
```

- `cue` is one of `start | fire | hit | beam | gameover`.
- `at` is **game time** (`state.elapsed`), never `Date.now()`, so the log stays reproducible.
- For `hit`, `size` is the destroyed enemy's own size and `frequency` is the pitch actually used. The probe
  asserts an **order** — a boss sounds lower than a bee, and records a larger size — not two fixed numbers,
  so a faithful palette of sounds passes and a constant that merely differs does not.
- The log is a bounded ring (last ~50 is plenty). `count` keeps counting.

## Sound must be synthesized

**No audio files, no new dependency, no CDN, no build step.** Use the Web Audio API directly: oscillators,
gain envelopes and a noise buffer. That keeps the module diffable and the parameters measurable, which is
the same reason the sprites are in-code pixel data rather than images.

**Create or resume the context from the player's Start gesture.** Browsers refuse audio before a user
gesture; creating it at load produces a `suspended` context and silence that looks like a bug.

**When Web Audio is absent, go silent and keep playing.** No throw, no error page, no cue issued, and
`contextState: 'unavailable'`. One of the probes deletes `AudioContext` before the page loads and asserts
exactly that.

## Scope

- `public/star-swarm/`
- `templates/modules/star-swarm/`
- `modules/star-swarm/`
- `tests/browser/star-swarm.spec.ts`

## Acceptance — all of them, and the driver gates them

```
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=9
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=1
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=2
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=3
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=4
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=5
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=6
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=7
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=8
$ npx playwright test tests/browser/star-swarm-audio.spec.ts
$ npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p8"
$ npx playwright test tests/browser/star-swarm.spec.ts
$ php tests/star_swarm_visual_test.php
$ php tests/star_swarm_concept_test.php
$ php tests/star_swarm_galaga_gate_test.php
$ composer test
```

`@p8` is included because the fire cue must land on the same frame as the shot, and phase 8 is what made the
shot appear on that frame. Phases 1–8 must stay green: this item adds a surface, it does not change play.

## Boundaries

- **Do not edit `tests/browser/star-swarm-audio.spec.ts`, `tests/browser/star-swarm-pixels.spec.ts`,
  `tools/harpp2/projects/star-swarm-galaga.json`, or anything under `tools/harpp2/gates/`.** A run that
  changes one of them is a failed run, not a pass.
- **Never loosen a threshold, delete an assertion, or add a sleep or `waitForTimeout`.**
- **Do not fake the log.** The cues must be issued to a real `AudioContext`: the probe asserts
  `contextState === 'running'` and a real `sampleRate`, which a log-only implementation cannot produce. A
  sound system that records what it would have played is not a sound system.
- **Do not add an audio file** and do not add a dependency for audio. If a cue genuinely cannot be
  synthesized, say so rather than shipping a binary asset.
- **Do not let audio touch the simulation.** A cue must not change enemies, shots, scoring, timing or the
  frame budget; if audio dispatch ever throws, the game must carry on.
- **No mute button in this item.** Volume and mute are a separate decision; do not add UI for them
  unrequested.
- Say so if part of this cannot be met honestly.
