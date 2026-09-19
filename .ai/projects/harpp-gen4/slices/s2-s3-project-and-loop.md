# SLICE — harpp-gen4 S2+S3: the project object and the evidence-gated loop

project: harpp-gen4 · status: DELIVERED · revision: 2
repo: `/var/www/html/ikabudsix` — harness and subject in one tree
references: `.ai/ai-autonomy-harness.contract.md`

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

authority: owner directive 2026-09-14 — prove the concept *provable, stable, measurable, repeatable,
seamless*, with HARPP as the first governed project (CD-15, CD-16).
directive: **you hold the decision.** Decide, record, continue (CD-8). This slice covers two project phases
(S2 + S3) together because the loop cannot be tested without obligations to consume — a decomposition choice,
not a scope increase.

## Objective

Two artefacts that turn the harness from a *slice* runner into a *project* runner:

1. **`tools/ai-project.php`** — obligations as data, so the stop invariant works across a project.
2. **`tools/ai-loop.php`** — a loop that advances a project slice by slice, gating each stage on
   **re-derived evidence** and never on a string an executor wrote about itself.

## Verified facts — measured, do not re-derive

1. `tools/ai-run.php` exists: `start`/`finish`/`status`/`commit-check`/`claims`/`verify`. `commit-check` exits
   `0` only when every recorded run is `completed`; `running`, `silent`, `failed` and `abandoned` all block.
   `tests/ai_run_test.php` is **31/31, exit 0, zero skips**.
2. `verify` re-executes only allowlisted command shapes (`php tests/<pure>.php`, `php -l <file>.php`,
   `php tools/ai-contract-lint.php` with a closed argument set), as **argv with `bypass_shell`**, and records
   `RE_DERIVED` / `CONTRADICTED` / `UNVERIFIED` plus a tree binding (`rev`, `dirty`). A chained command is
   refused and never executed — asserted by a sentinel-planting test.
3. **The existing HARPP loop manifests trust markers.** `governed-loop.json` and `roadmap-slices.json` gate a
   stage on `marker: "SOL_IMPL status=PASS"` — a string the executor prints about itself — and on ad-hoc shell
   `verify` commands. They are the precedent to **improve on, not copy**; they also carry stale model ids.
4. `.ai/projects/harpp-gen4/project.md` defines this project: slices, the five properties, and the artefact
   convention
   `project.md` · `slices/<n>.md` · `metrics.json`.
5. `tools/ai-contract-lint.php` globs `.ai/*.contract.md` and therefore **does not see** `.ai/projects/**` —
   including this very file.
6. `tools/harpp-bridge/` is now in-tree (39 files), copied as-is (CD-16). It is the subject of S5, **not** of
   this slice.

## Deliverables

### D1 — `tools/ai-project.php`

- `status [--project=<id>] [--json]` — every project, its slices and their states.
- `next --project=<id>` — the next slice eligible to dispatch, or a clear reason there is none.
- `obligations --project=<id> [--json]` — **remaining obligations as a number**, derived from the slice table
  and its acceptance criteria, never hand-maintained. This is the number `stop-report --remaining=` consumes.
- Slice state transitions recorded per slice: `pending` → `running` → `done` | `blocked`, with the run id that
  evidenced it. A slice is `done` only when its claims are `RE_DERIVED` — not when someone says so.

### D2 — Make the project convention real

- Teach `tools/ai-contract-lint.php` to lint `.ai/projects/**/*.md` (slice files and `project.md`), **or**
  state plainly in its output why projects are excluded. Silence is not acceptable: this file is currently
  invisible to conformance tooling, which is exactly the class of defect fixed earlier this session.
- Do not weaken the existing lint behaviour for `.ai/*.contract.md`.

### D3 — `tools/ai-loop.php`

```
php tools/ai-loop.php --project=<id> [--max-slices=N] [--dry-run] [--json]
```

Per slice: pick it via `ai-project.php next` → `commit-check` **before** dispatching (refuse if not eligible)
→ ledger `start` → dispatch the slice's lane → ledger `finish` → extract claims → **`verify`** → advance only
if the slice's claims are `RE_DERIVED`; otherwise **stop the project** and record why.

Absolute requirements:

- **No marker trust.** A stage whose only evidence is a marker string in executor output does **not** pass.
  There must be a test that plants exactly that case and fails if it advances.
- **Fail closed.** `silent`, `failed`, `abandoned`, `CONTRADICTED` or a missing report all stop the project.
  There is no "probably fine" path.
- **Stop on first contradiction.** Do not continue to the next slice to "see how it goes".
- **`--dry-run`** prints the plan and touches nothing. Prove it touches nothing.
- **Bounded.** `--max-slices` caps a run; the loop must never advance past it.
- Every dispatch is recorded in the ledger. A loop that dispatches without a ledger record is a defect.
- The loop must not modify `tools/harpp-bridge/` — S5 owns the first real run.

### D4 — Tests

`tests/ai_project_test.php` and `tests/ai_loop_test.php`, pure (`MODE_PURE`, temp dirs, no DB, no network):

1. obligations: a project with N slices reports the right remaining count as slices complete; **no slice may be
   marked done without `RE_DERIVED` claims**.
2. `next` returns the first eligible slice, and refuses when a slice is `blocked`.
3. the loop advances ≥2 fixture slices unattended (no conversational turn), using stub slices;
4. **the marker-only stage blocks** (falsification anchor);
5. `silent`/`failed`/`CONTRADICTED` each stop the project;
6. `commit-check` is consulted before dispatch and its refusal stops the loop;
7. `--dry-run` records no run and writes no project state;
8. `--max-slices` is honoured.

### D5 — Documentation

- `.ai/ai-autonomy-harness.contract.md`: the project convention, the loop, and the rule that **a stage gate
  consumes `RE_DERIVED` claims, never a marker**.
- `.ai/projects/harpp-gen4/project.md`: mark S2 and S3 as delivered when they are, with the evidence.

## Architectural constraints

- **This is the most dangerous artefact yet built here**: it dispatches work by itself. Fail closed, consult
  `commit-check`, stop on the first contradiction, and never invent a path around a gate.
- **The loop must not be the source of its own evidence.** Stage gating reads `verify` output; it must not
  interpret the executor's prose.
- **No new authority semantics.** This adds sequencing and accounting only. Absolute prohibitions stay
  absolute; L4 stays a full stop.
- **Deterministic only.** No model judgement inside the loop, no heuristic scoring of a stage.
- Zero dependencies; PHP 8.2 compatible; PHPStan clean for changed files under `-c phpstan.neon`.
- **Do not run `scripts/run-tests.php`, `composer test`, Playwright, or anything that bootstraps the app or
  touches the web cache** — pure tests only.
- Existing suites must stay green: `ai_run_test` **31/31**, `ai_autonomy_test` **46/46**, zero skips.
- Do not commit, stage or push.

## Files likely affected

- `tools/ai-project.php` — new
- `tools/ai-loop.php` — new
- `tests/ai_project_test.php`, `tests/ai_loop_test.php` — new
- `tools/ai-contract-lint.php` — project coverage (or an explicit statement of exclusion)
- `.ai/ai-autonomy-harness.contract.md` — runbook
- `.ai/projects/harpp-gen4/` — project state

## Acceptance criteria

1. `ai-project.php obligations --project=harpp-gen4` prints a **number**, derived — and
   `stop-report --remaining=<that number>` behaves accordingly. Show both.
2. `next` returns the first eligible slice; a `blocked` slice is refused with a reason.
3. The loop advances **≥2 fixture slices with no conversational turn**, and the transcript shows each stage
   gated on evidence with the run-ledger entries.
4. **A marker-only stage does not advance the project** — output pasted. This is the slice's non-vacuity anchor.
5. Each of `silent`, `failed`, `CONTRADICTED` stops the project, demonstrated.
6. `--dry-run` demonstrably writes nothing (show the before/after state is identical).
7. `ai-contract-lint.php` either lints `.ai/projects/**` or states its exclusion explicitly; paste the output.
8. All four suites exit `0` with zero skips; state counts. No existing case relaxed.
9. The runbook documents the convention and the no-marker-trust rule.

## Required tests

- Every command above, with real output and exit codes.
- `php tests/ai_project_test.php`, `php tests/ai_loop_test.php`, `php tests/ai_run_test.php`,
  `php tests/ai_autonomy_test.php` — counts, exit codes, and an explicit statement that skips are zero.
- The marker-only falsification, and the `--dry-run` no-write proof.
- `php -l` on every new/changed PHP file; PHPStan under `-c phpstan.neon` for those files.

## Risks

- **Marker trust is the trap.** The strongest temptation will be to accept "status=PASS" as a gate. That is the
  defect this slice exists to remove; acceptance 4 is the guard.
- **A loop that advances on vague success is worse than no loop** — it manufactures unattended progress that
  nobody verified.
- **Recursion danger:** the loop dispatches slices whose contracts may themselves dispatch. Bound it
  (`--max-slices`), and refuse to nest.
- Do not let `metrics.json` become hand-edited; it is tool output (S4 owns it). Writing a placeholder now is
  worse than leaving it absent.

## Forbidden changes

- `tools/harpp-bridge/`
- `tools/ai-run.php`
- `tools/ai-autonomy.php`
- `tests/ai_run_test.php`
- `tests/ai_autonomy_test.php`
- `.github/workflows/`
- `kernel/`
- `modules/`
- `src/`
- `tests/browser/`
- `playwright.config.js`
- `phpstan.neon`
- `phpstan-baseline.neon`
- `composer.json`
- `package.json`
