# OBJECTIVE — Star Swarm / Galaga fidelity, **phase 2: animation and points**

system: HARPP v2 · 2026-09-18 · chair-authored. Executor: any lane the driver assigns.
authority: director directive 2026-09-18. Requirements: `tools/harpp2/projects/star-swarm-galaga.json`.
Runs after `tools/harpp2/objectives/star-swarm-galaga-p1.md`, which built the state surface.

## The item

Make the phase-2 gate green:

```
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=2
```

It currently reports **12 passed / 5 failed** across nine requirements — four animation, five scoring:

**Animation** — enemies sweep in along curved entry paths from the top and sides, spinning, then settle into
the formation; the assembled formation *breathes* (expands and contracts) instead of sitting still; dives leave
the formation along curved paths singly, in pairs and in threes, firing as they dive; destruction produces an
explosion/spin animation rather than a silent removal.

**Points** — the arcade table, exactly: bee 50 in formation / 80 in flight; butterfly 80 / 160; boss 150 in
formation, 400 diving alone, 800 with one escort, 1,600 with two; challenging stage 100 per enemy and a 10,000
perfect bonus for all forty; an extra ship at 20,000 and every 70,000 after. The contract records one source
disagreement (30,000 / 80,000) and names the adopted values — follow the contract, do not re-derive it.

Scoring must be *reactive to real state*: the value paid depends on whether the enemy was in formation or in
flight, and on how many escorts a boss still has. A constant returned per caste satisfies no requirement that
says "in formation or in flight".

## Scope

- `public/star-swarm/`
- `templates/modules/star-swarm/`
- `modules/star-swarm/`
- `tests/browser/star-swarm.spec.ts`
- `tests/star_swarm_visual_test.php`
- `tools/harpp2/stability.sh`

## Acceptance — all of them, and the driver gates them

```
$ php tools/harpp2/gates/star_swarm_galaga_gate.php --phase=2
$ npx playwright test tests/browser/star-swarm.spec.ts
$ php tests/star_swarm_visual_test.php
$ php tests/star_swarm_concept_test.php
$ php tests/star_swarm_shot_check.php
$ composer test
```

Phase 2 does not include the 10-run stability gate (phase 3 owns it). The determinism invariant still applies:
a probe that samples a running animation once is a race by construction — step the clock, do not wait on it.

## Boundaries

- **Do not edit the requirements contract or anything under `tools/harpp2/gates/`.** They are the acceptance
  instruments; making the gate pass by editing either is a failure, not a pass.
- **Never loosen a threshold, delete an assertion, or add a sleep or `waitForTimeout`.**
- **Keep every existing assertion**; the spec may grow, not shrink.
- **Do not change what the scores mean to make a probe convenient.** If the arcade table cannot be paid
  honestly for some case, record the finding instead of adjusting the table.
- **No raster assets, no new dependency, no CDN, no bundler, no build step.**
- The same URL serves the same module, and any `.disyl` you touch stays `php _lint_disyl.php` clean.
- Say so if part of this cannot be met honestly.
