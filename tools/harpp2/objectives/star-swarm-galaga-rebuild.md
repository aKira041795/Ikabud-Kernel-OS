# OBJECTIVE — Star Swarm rebuild: Galaga fidelity (components, animation, points, gameplay)

system: HARPP v2 · 2026-09-18 · chair-authored. Executor: any lane the driver assigns.
authority: director directive 2026-09-18 — *"let's try the star swarm. rebuild it. make it closer to the
original game. components, animation, points, game play and etc."* and *"check Galaga online search"*. The
reference is therefore **Galaga (Namco, 1981)**; the sources and one recorded scoring discrepancy are in the
requirements contract.

## The item

Rebuild the Star Swarm game so it plays like Galaga. The requirements are **data**, not prose, and the gate that
enforces them is the first acceptance command below:

- `tools/harpp2/projects/star-swarm-galaga.json` — 18 requirements across components, animation, points and
  gameplay, each with a probe name, plus 17 observability fields and 4 invariants.
- `tools/harpp2/gates/star_swarm_galaga_gate.php` — chair-authored, measured RED at 12 passed / 30 failed
  before any work: 0 of 18 probes asserted, 11 of 17 state fields missing, spec below its assertion floor.

Work until that gate is green **and** the game genuinely behaves that way. A probe name inside an `expect(...)`
satisfies the gate; a strict assertion satisfies the intent. Where the two differ, the assertion wins and the
gate is the floor, not the target.

## Scope

- `public/star-swarm/`
- `templates/modules/star-swarm/`
- `modules/star-swarm/`
- `tests/browser/star-swarm.spec.ts`
- `tests/star_swarm_visual_test.php`
- `tests/star_swarm_concept_test.php`
- `tools/harpp2/stability.sh`

## Acceptance — all of them, and the driver gates them

```
$ php tools/harpp2/gates/star_swarm_galaga_gate.php
$ bash tools/harpp2/stability.sh tests/browser/star-swarm.spec.ts 10
$ npx playwright test tests/browser/star-swarm.spec.ts
$ php tests/star_swarm_visual_test.php
$ php tests/star_swarm_concept_test.php
$ php tests/star_swarm_shot_check.php
$ composer test
```

The stability gate must report **10/10**. The concept gate is the *previous* requirements contract — it must
stay satisfied, not be relaxed to make room for this one.

## Regression guards

```
$ php tests/workbench_governance_census_test.php
$ php tests/read_authority_probe_test.php
```

## Boundaries — the ways this item could be faked

- **Do not edit the requirements contract or the gate.** `tools/harpp2/projects/star-swarm-galaga.json` and
  `tools/harpp2/gates/star_swarm_galaga_gate.php` are the acceptance instruments and are outside your scope.
  Making the gate pass by editing either one turns this item into a failure, not a pass.
- **Never loosen a threshold, delete an assertion, or add a sleep or `waitForTimeout`.** A probe that samples a
  running animation once is a race by construction (lesson L4). Drive the game's own deterministic test surface
  — `test.step(frames)`, `test.spawnWave(index)`, `test.kill(index)`, `test.snapshotEnemy(index)` — rather than
  wall-clock waiting. Extend that surface if a new probe needs it, and keep it driving the same update/draw
  functions the real loop uses: a hook that passes where real play fails is a lie.
- **Keep every existing assertion.** The spec may grow and may be re-expressed; it may not shrink. The contract's
  assertion floor is the mechanical check on that.
- **No raster assets, no new dependency, no CDN, no bundler, no build step.** Every sprite stays procedural
  (canvas paths). The silhouettes should read as Galaga's: bee, butterfly, boss.
- **The delivery path stays as it is**: the same URL serves the same module through the same DiSyL template, and
  `php _lint_disyl.php` stays clean for every `.disyl` you touch.
- **Do not invent the reference.** Where the sources disagree, the contract records the disagreement and names
  the adopted value; follow the contract. Do not silently pick a different scoring table.
- **Say so if a requirement cannot be met honestly.** A recorded finding beats a passing gate that lies.

## Where to start

The gate's output is the worklist, and it is ordered: 18 requirements, then 11 missing state fields, then the
assertion floor. The state fields come first — they are what the probes read — then the castes and the
formation, then dives, then scoring, then the tractor beam and dual fighter, then the challenging stage. The
existing deterministic test surface and the current 52-assertion spec are the foundation; re-express, do not
replace.
