# CONTRACT — Run ledger and claim extraction: the harness must know what a run did

status: READY_FOR_IMPLEMENTATION
repo: `/var/www/html/ikabudsix` — work in the current tree; do not create or switch branches

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

authority: owner directive 2026-09-14 — the harness must relieve the director, not the chair; refinements continue.
directive: **you hold the decision.** Decide, record, continue (CD-8).

## Objective

Twice this session the Chair could not tell whether a dispatched run had succeeded, and published wrong
answers because of it. The cause is not carelessness, it is a missing instrument: **run state is inferred
from unreliable signals instead of being recorded.**

- `pi` **exited 0 having written 0 bytes to stdout** — a *silent success*, indistinguishable from death.
- `pgrep -f "pi -p"` returned a false negative, because the run executes inside a `lean-ctx -c` wrapper — so
  a live run looked dead and a checking command matched itself.
- A dispatched slice therefore looked "dead" in one reading and "still running" in another, while it was in
  fact doing correct work the whole time.

Build the authoritative record. After this slice, no one — chair or director — infers run state again.

## Verified facts — measured, do not re-derive

1. `.ai/scope-path-semantics.flash-run.log` is **0 bytes** and the run exited `slice_exit=0`. Its file edits
   were coherent and correct; the work was real, only the *report* was absent.
2. `.ai/builder-spec-truth.flash-run.log` is **0 bytes**, the run ended, and it modified
   `playwright.config.js` (+54) and 6 spec files across 294 insertions — again real work, no report.
3. For contrast, `.ai/ai-autonomy-mechanise-doctrine.flash-run.log` is **7,592 bytes** with a full report, and
   `.ai/ai-autonomy-mechanise-doctrine.sol-run.log` is **46 bytes** containing
   `Codex error: The usage limit has been reached` (a genuinely failed run).
4. `tools/ai-autonomy.php` is dependency-free and requires only
   `kernel/Workbench/Development/DevelopmentTaskContract.php`; it exposes
   `DevelopmentTaskContract::revisionId()` for a contract's revision hash. Follow that shape: **zero
   dependencies, no bootstrap, no DB**.
5. `tests/ai-autonomy` is a `TestHarness` **MODE_PURE** suite — 46/46, no DB.

## Deliverables

### R1 — `tools/ai-run.php`: the ledger

Commands, modelled on `tools/ai-autonomy.php`'s style (usage block, `--json`, explicit exit codes):

- `start --contract=<path> --lane=<model> --name=<id> [--log=<path>]` — write `.ai/runs/<id>.json`:
  `id, contract, contract_revision` (from `revisionId()`), `allowed_count`, `forbidden_count`, `lane`,
  `started_at`, `pid`, `log`, `status: "running"`.
- `finish --id=<id> --exit=<code>` — record `finished_at`, `exit_code`, `log_bytes`, `report_bytes`, and
  classify **without guessing**:
  - `exit != 0` → `failed`
  - `exit == 0` and no report/log content → **`silent`** (the observed case; name it, do not hide it)
  - `exit == 0` with content → `completed`
- `status [--json]` — authoritative state for every run. **`running` runs whose recorded pid is no longer
  alive must be reconciled to `abandoned`.** This is the mechanical fix for "is it still running?" — answer
  it from the pid, never from a log's size.
- `--gate` on `status` exits `3` when any run is `silent`, `failed`, or `abandoned`, so a phase can refuse to
  advance on a run that never reported.

Support `--runs-dir=<path>` (default `.ai/runs`) so tests run against a temp directory.

### R2 — `claims`: extract, never assert

`claims --id=<id> [--json]` — parse the run's report/log text for test-result claims and emit each with its
source line, marked **`unverified`**:

- patterns such as `N/M passed`, `passed=N failed=M`, `exit=N`, `PASS`/`FAIL`/`SKIP`, and a bare
  `tests/… ok` line
- **Do not re-run anything in this slice, and never report a claim as verified.** Marking claims
  `unverified` is the honest output; re-derivation by software is the next slice, and the tool must say so
  rather than imply it.

The point of R2 is to turn *"evidence is the product"* from an instruction into a machine-readable object the
next slice can check.

### R3 — Tests (`tests/ai_run_test.php`, pure, MODE_PURE style)

Use a temp runs dir. Assert, with real exit codes:

1. `start` writes a record containing the contract revision and the pid; `status` reports `running`.
2. `finish` classification matrix — `exit=1` → `failed`; **`exit=0` with a 0-byte log → `silent`**;
   `exit=0` with content → `completed`.
3. **Replay the two observed real cases**: a start/finish pair reproducing `scope-path-semantics`
   (exit 0, 0-byte log) must classify `silent`, and one reproducing a run with a 7 KB report must classify
   `completed`. This is the non-vacuity anchor: the test must fail against a version that classifies purely
   on the exit code.
4. A record whose pid is dead reconciles from `running` to `abandoned`; a pid that is alive stays `running`.
5. `--gate` exits `3` with a `silent`/`failed`/`abandoned` run present, and `0` when all runs are clean.
6. `claims` extracts at least three distinct claim shapes from a fixture report and marks every one
   `unverified`; malformed input exits `2`.

### R4 — Make the rule binding, in the two places that are actually read

- `.ai/ai-autonomy-harness.contract.md` (runbook): dispatch protocol = `start` → run → `finish` → `status`,
  and state plainly: **never infer run state from log size or `pgrep`; the ledger is authoritative, and the
  terminal's completion notification carries the exit code.**
- `.github/copilot-instructions.md`: one short pointer under the existing harness section.
- `.github/instructions/ai-autonomy-escalation.instructions.md`: one line — an unreported run is not
  evidence, and a silent success is as blind as a failure.

## Architectural constraints

- **Do not run `scripts/run-tests.php`, `composer test`, or anything that bootstraps the app or touches the
  web cache.** A full PHP test run while a Playwright run drives the live tenant poisons the APCu module-scan
  cache and turns a healthy tenant into a 503, producing false failures in work that is not yours. Pure tests
  only: `php tests/ai_run_test.php` and `php tests/ai_autonomy_test.php` (MODE_PURE).
- Zero dependencies, no bootstrap, no DB, no network, no `~/.config/harpp`, no HARPP call. Follow
  `tools/ai-autonomy.php`'s dependency-free shape.
- The ledger records; it does not decide acceptance. Keep `silent`/`abandoned` as *facts*, and let `--gate`
  be the policy layer.
- Do not weaken or restate an existing check. `tests/ai_autonomy_test.php` must remain at **46/46**.
- PHP 8.2 compatible; PHPStan clean for changed files under `-c phpstan.neon`.
- If a path you need is not in `allowed_scope`, report it rather than adding it silently.

## Files likely affected

- `tools/ai-run.php` — new; the ledger and claim extractor
- `tests/ai_run_test.php` — new; the pure suite
- `.ai/ai-autonomy-harness.contract.md` — runbook: dispatch protocol
- `.github/copilot-instructions.md` — harness section pointer
- `.github/instructions/ai-autonomy-escalation.instructions.md` — the run-state rule
- `.ai/runs/` — run records (small JSON; treat as evidence, do not commit)

## Acceptance criteria

1. The classification matrix is demonstrated with real output and exit codes, including the **`silent`** case
   (exit 0, 0-byte log) and the `failed` case (non-zero exit).
2. `status` reconciles a dead pid to `abandoned` and leaves a live pid `running`; `--gate` returns `3`
   accordingly and `0` when clean.
3. The two observed runs replay to their true classifications (`silent`, `completed`), and the test proving it
   **fails** if classification is derived from the exit code alone — paste that falsification.
4. `claims` marks every extracted claim `unverified` and says re-derivation is a later slice.
5. `php tests/ai_run_test.php` exits `0`; `php tests/ai_autonomy_test.php` still exits `0` at 46/46.
6. The three documents state the dispatch protocol and the "never infer run state" rule.

## Required tests

- `php tests/ai_run_test.php` — full output, passed/failed counts, exit code.
- `php tools/ai-run.php status --json` and `--gate`, showing exit codes.
- A live demonstration of the three classifications, plus the `abandoned` reconciliation.
- `php tests/ai_autonomy_test.php` — exit `0`, 46/46 (proves nothing else was disturbed).
- `php -l` on every new/changed PHP file, and PHPStan under `-c phpstan.neon` for those files.

## Risks

- **A ledger that trusts a self-reported exit code is theatre.** `finish` is only meaningful because the
  *dispatcher* runs it in the shell that observed the exit code; say so in the usage text. The pid check in
  `status` is what catches a run that was never finished.
- **Do not over-claim.** This slice records claims; it does not verify them. Presenting `claims` output as
  verification would recreate the exact defect being fixed.
- Run records are evidence but also runtime state: keep them small, and do not let a stale record make a
  healthy run look broken — `status` must show age, and a `running` record with a dead pid is `abandoned`,
  not `failed`.

## Forbidden changes

- `playwright.config.js`
- `tests/browser/`
- `tests/ai_autonomy_test.php`
- `tools/ai-autonomy.php`
- `tools/ai-contract-lint.php`
- `kernel/`
- `modules/`
- `src/`
- `.github/workflows/`
- `phpstan.neon`
- `phpstan-baseline.neon`
- `composer.json`
- `package.json`
