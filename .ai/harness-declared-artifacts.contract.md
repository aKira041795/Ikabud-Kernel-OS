# SLICE D — the harness declares the files it writes in the run's name

project: harness-guardrail · status: DIRECTOR_AUTHORISED (CD-37: *"A is approved"*) · revision: 1
repo: `/var/www/html/ikabudsix`
lane: deepseek/deepseek-v4-flash
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "deepseek/deepseek-v4-flash", "--name", "harness-declared-artifacts", "<CONTRACT>"]

## THIS CONTRACT WILL BE REFUSED BY `plan`. THAT IS EXPECTED — READ FIRST.

**Authority: owner decision 2026-09-14, verbatim: *"A is approved"*** (recorded in `.ai/chair-decisions.md`
under CD-37). This contract names the trust surface, so `plan` **must** exit 2 with *"the trust surface is not
contract-authorisable"*. **That refusal is rule 1 working and is your first claim.**

## Objective

A-F2 currently blocks a real loop dispatch on the harness's **own** bookkeeping. Measured, not theorised:

```
LEDGER finish harpp-gen4-s5-... exit=0 status=blocked
SCOPE BLOCKED delta=3 offending=.ai/chair-decisions.md,.ai/projects/harpp-gen4/state.json
```

`.ai/projects/harpp-gen4/state.json` is written **by the loop** to record the slice transition; the run is
blamed for it. So every real dispatch blocks, and the gate cannot distinguish *what the executor touched* from
*what the harness wrote in the run's name*.

Make that distinction **declared and inspectable** rather than inferred.

## Architectural constraints

- **Declaration, never exemption.** The declared paths are **recorded and shown** in `scope_conformance`, so a
  reader sees both what was excluded and on what authority. Never silently drop a path.
- **GUARD 1 — a declared artefact may not be the verifier.** Refuse the declaration if the path is a
  trust-surface path or lies inside the contract's `forbidden_scope`. Without this guard the feature is a
  universal exemption: `--harness-artifact=tools/ai-run.php` would exempt the verifier itself.
- **GUARD 2 — declared at `start`, never at `finish`.** An artefact declared after the evidence exists is not a
  declaration, it is an exemption shaped to fit whatever the run happened to touch. Refuse `--harness-artifact`
  on `finish`. This is CD-22's razor applied to file declaration.
- **A-F2 must not weaken.** An *undeclared* out-of-scope path must still block, exactly as today. This slice
  narrows what is attributed to the executor; it must not narrow what is detected.
- Paths are normalised and repo-relative; refuse `..`, absolute paths, and anything outside the repository.
- PHP 8.3-compatible. No new runtime dependency. Exit codes unchanged (`0`/`2`/`3`/`4`).

## Files likely affected

- `tools/ai-run.php`
- `tools/ai-loop.php`
- `tests/ai_run_test.php`
- `tests/ai_loop_test.php`

## Deliverables

### D1 — Declare operational artefacts at `start`

`php tools/ai-run.php start … --harness-artifact=<path>` (repeatable). Record them in the run record as
`harness_artifacts`. Refuse (exit `2`, message naming the path and the reason) when:
- the path is a **trust-surface path** — GUARD 1;
- the path lies inside the contract's **`forbidden_scope`** — GUARD 1;
- the path is absolute, contains `..`, or resolves outside the repository;
- `--harness-artifact` is supplied to **`finish`** — GUARD 2.

### D2 — Exclude declared artefacts from the attributed delta, visibly

`scope_conformance` gains a `declared_harness_artifacts` list, and the delta that is checked **and reported**
excludes them. Everything else is unchanged: `checked`, `offending`, `ok` keep their current meaning, and an
**undeclared** out-of-scope path still blocks.

### D3 — The loop declares its own writes

`ai-loop.php` passes `--harness-artifact` for every path it writes on the run's behalf — at minimum the project
state file (`.ai/projects/<id>/state.json`). Locate what it writes by reading the code; do not guess. If it
writes more, declare those too.

### D4 — Non-vacuity, including the guards

For each of these, state which assertion fails when the fix is reverted, and paste the real exit code:
- declaring a trust-surface path is **refused** (GUARD 1);
- declaring a path inside `forbidden_scope` is **refused** (GUARD 1);
- `--harness-artifact` on `finish` is **refused** (GUARD 2);
- declaring `../` or an absolute path is **refused**;
- a run writing an **undeclared** out-of-scope path still **blocks** (A-F2 unweakened);
- a run writing only its **declared** artefacts **advances**.

## Acceptance criteria

- **AC1** — every refusal in D1 and D4 demonstrated with a real exit code and message.
- **AC2** — a run that declares its state file **advances**, and `scope_conformance` shows the declaration
  rather than hiding it.
- **AC3** — **A-F2 is unweakened**: the out-of-scope-write block still fires for undeclared paths, and the
  Slice-A/B/C behaviours all still hold exactly (covering scopes refused; `check` on `tools/ai-run.php`
  ESCALATE/L4/exit 3 while a benign in-scope path is RECORD/0; malformed ledger record blocks; foreign/stale run
  records refused; the ladder's five behaviours).
- **AC4** — `php tests/ai_run_test.php`, `tests/ai_autonomy_test.php`, `tests/ai_project_test.php`,
  `tests/ai_loop_test.php`, `tests/ai_contract_lint_test.php` all pass, **zero skips**.
- **AC5** — rule 1 still refuses this contract's own scope (`plan` exit 2).

## Required tests

Pure PHP only. **Do not run `scripts/run-tests.php`, `composer test`, or anything that bootstraps the CMS app** —
a full run poisons the APCu cache and 503s the live tenant. Write dispatch logs to `/tmp`, **never into the
repository** (a log inside the repo was flagged as out-of-scope three times today).

## Report format — required

```
CLAIM: <short assertion>
COMMAND: <exact command, copy-pasteable>
OBSERVED: <verbatim output, including the exit code>
```

Report path: `.ai/harness-declared-artifacts.report.txt`. First claim: the `plan` refusal of this contract.

## Risks

- **A declaration that becomes an exemption.** GUARD 1 is the defence; both halves must be tested, not just
  implemented. If a declared artefact could ever be the verifier, this slice makes the guardrail weaker than it
  was before it existed.
- **Declaring too much.** The set must be what the *harness* writes, not what the executor might want to write.
  `.ai/projects/<id>/state.json` is bookkeeping; `.ai/projects/<id>/slices/` is authored work and must not be
  declared.
- **Hiding rather than showing.** A path excluded but not reported is indistinguishable from a path never
  checked. `declared_harness_artifacts` must be in the record for that reason.

## Forbidden changes

- `phpstan.neon`
- `phpstan-baseline.neon`
- `.github/workflows/`
- `scripts/`
- `modules/`
- `tools/harpp-bridge/`
- `.ai/projects/harpp-gen4/slices/`
