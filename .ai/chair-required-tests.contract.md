# Run every command the contract declares, not just the first

## Objective

A contract's `## Required tests` section declares its full verification. The harness runs **only the
first command** and ignores the rest. It even says so:

```
probe: npx playwright test ... --grep "@p21" --reporter=line
note: 3 command-shaped lines found in Required tests; the first is the probe.
```

Measured, with a contract whose second declared command is `bash -c 'exit 1'` — a guaranteed failure:

```
[PROBE] task=probe-required-tests exit=0 verdict=PASS
```

**The harness passed a contract containing a command that cannot succeed.**

This is not hypothetical. Every Star Swarm contract declares:

```
## Required tests

- `npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@pN" --reporter=line`

Also run, and they must still pass:

- `npx playwright test tests/browser/star-swarm-pixels.spec.ts --reporter=line`
- `php tests/star_swarm_visual_test.php`
```

The asset-version guard lives in `php tests/star_swarm_visual_test.php`. It has been declared in every
one of those contracts and **executed by none of them**. The lane forgot to bump the `?v=` query
string, the harness reported PASS, and the chair found it by hand — five times in one day, each time
looking like the same annoying little problem rather than a hole in the harness.

Run them all.

## Architectural constraints

- **Every command-shaped line under `## Required tests` must be executed.** The first remains the
  DECIDING probe — it is what baseline-RED/green is judged on, and what `--falsify` reverts against —
  but the others are verification the contract has already declared, and a contract is not decoration.
- **A failure in any declared command is a failure of the run.** Not a warning, not a note. The lane's
  work is not accepted while a command the contract declares is failing.
- **Report each command and its outcome**, so a failure names which one failed. "The contracts says
  three things and one of them failed" is not usable output; `[REQUIRED] 2/3 failed: php tests/...`
  is.
- Keep the harness's existing behaviour otherwise: the baseline is still taken on the first command
  before any work, and a probe that already passes still stops the run.
- Commands that take different lengths of time must all still run. Do not skip the slower ones for
  speed; that is the precise failure being fixed.
- Do not invent a new section or require contracts to be rewritten. The commands are already there.

## Files likely affected

- `tools/chair.php`

## Acceptance criteria

- A contract whose second declared command fails is refused: `bash tests/chair-required-tests-probe.sh`
- The self-test still passes in full, and covers both directions — all declared commands run, and a
  failing one is reported: `php tools/chair.php --self-test`

## Required tests

- `bash tests/chair-required-tests-probe.sh`

## Risks

- **Treating the extra commands as advisory.** That is the current behaviour and the whole defect.
- **Running them only when the first passes.** A contract whose first command fails and whose second
  would also fail should still name both; suppressing the rest hides the second problem.
- **Slowing the loop.** This genuinely adds work per run. That is the point: the work was already
  declared, and doing it by hand afterwards cost more.
- **Breaking `--falsify`.** It reverts the run's changed files and re-runs THE PROBE. The first
  command must remain the one used there, or falsification changes meaning.
- **Silently accepting a command that cannot be found.** A declared command that does not exist must
  fail, not be skipped.

## Forbidden changes

- `tests/browser/`
- `kernel/`
- `public/`
- `modules/`
- Do not weaken, delete or loosen any of the existing self-test checks.
- Do not change the baseline-before-work rule or the already-passes stop.
