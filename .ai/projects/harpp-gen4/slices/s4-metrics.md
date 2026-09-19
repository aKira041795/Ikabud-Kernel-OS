# SLICE — harpp-gen4 S4: metrics derived from artefacts

project: harpp-gen4 · status: READY_FOR_IMPLEMENTATION · revision: 1
repo: `/var/www/html/ikabudsix`
lane: deepseek/deepseek-v4-flash
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "deepseek/deepseek-v4-flash", "--name", "harpp-gen4-s4-metrics", "<CONTRACT>"]

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

references: `.ai/ai-autonomy-harness.contract.md`
authority: owner directive 2026-09-14 — make the concept *measurable* (CD-15).
directive: **you hold the decision.** Decide, record, continue (CD-8).

## Objective

Produce the project's metric table **from artefacts**, so the cost thesis becomes falsifiable instead of
rhetorical. The governing rule, from CD-15 and the measurement plan: **the harness must not be the source of
its own metrics.**

## Verified facts — measured, do not re-derive

1. `.ai/runs/*.json` holds one record per run: `id`, `contract`, `contract_revision`, `lane`, `started_at`,
   `finished_at`, `exit_code`, `log_bytes`, `report_bytes`, `status`, and a `claim_verification` block with
   `rev`, `dirty` and per-claim results. This is the primary artefact.
2. `tools/ai-run.php claims --id=<run> --json` emits structured claims;
   `verify --run=<id> --json` returns `RE_DERIVED` / `CONTRADICTED` / `UNVERIFIED`.
3. `tools/ai-project.php` knows slices, their states, and `obligations`. `.ai/projects/harpp-gen4/state.json`
   is its output and **must not be hand-edited**.
4. `.ai/chair-decisions.md` holds the decision record (`## CD-<n>` headings) — a file artefact, countable.
5. **Cost and tokens are not captured anywhere today.** Whether `pi` exposes usage is an open question this
   slice settles by measuring; `pi --mode json` may expose it.
6. Previous measurement attempts in this session were hand-derived by grepping (CD-15 §8). That is the
   baseline to improve on, and its numbers are reproducible: 15 Chair decisions, 16 commits, 3 self-corrections.

## Deliverables

### D1 — `tools/ai-project.php metrics --project=<id> [--json] [--write]`

Derive, per project, from artefacts only:

| metric | source |
|---|---|
| slices dispatched / completed | `.ai/runs/*.json` matched to the project's slice contracts |
| runs by status (`completed`/`silent`/`failed`/`abandoned`) | run records |
| wall-clock per slice and total | `started_at`/`finished_at` |
| lane distribution | run records' `lane` |
| claim outcomes (`RE_DERIVED` / `CONTRADICTED` / `UNVERIFIED`) | run records' `claim_verification` |
| Chair decisions, and **incorrect** Chair decisions | `.ai/chair-decisions.md` (count `## CD-` headings; the incorrect subset from an explicit, editable list — see D3) |
| contract violations | slices whose `check` or scope differs from the declared envelope — record `unavailable` if not derivable, **do not guess** |
| cost and tokens | capture if the runner exposes them; otherwise `null` with a stated reason |
| director minutes | read from D2, never inferred |

`--write` writes `.ai/projects/<id>/metrics.json` with a `generated_at`, a `source` note, and a
`tool_written: true` marker. Without `--write`, print only.

### D2 — Director minutes are an input, not a derivation

`.ai/projects/<id>/director-minutes.json`, a small schema the tool reads:

```json
{ "entries": [ { "at": "...", "minutes": 0, "category": "concept|harness_intervention|verification", "note": "" } ] }
```

The tool must never invent these. If absent, report `null` and say the director has not logged any — **not**
zero, which would understate the cost.

### D3 — The unflattering columns are first-class

- Incorrect Chair decisions are counted from an explicit list the tool reads
  (`.ai/projects/<id>/chair-errors.json`), seeded now with the three this session already recorded: CD-6
  (fabricated readiness), CD-11 (commit during a live run), CD-12 (a published claim that was false).
- **Never estimate.** Any metric that cannot be derived from an artefact is `null` with a `reason`. A
  fabricated cost figure would poison the one number the entire cost thesis rests on.

### D4 — Tests (`tests/ai_project_metrics_test.php`, pure)

1. given fixture run records, every derived metric matches hand-computed values;
2. an unavailable metric is `null` with a reason — **assert that no estimation path exists** (a fixture with
   partial data must not produce a number);
3. `--write` produces `metrics.json` with `tool_written: true` and a timestamp;
4. director minutes: absent → `null` (not `0`); present → summed by category;
5. claim outcomes are counted per status, including `CONTRADICTED`;
6. the project's own artefacts produce a table without error (a smoke run over `harpp-gen4`).

## Architectural constraints

- **Artefacts only.** No self-reporting, no model judgement, no heuristics scoring quality.
- **`null` over a guess.** Every unavailable metric states why.
- Do not modify `.ai/runs/*.json`, `state.json`, or any run record — these are evidence.
- Pure tests only: **do not run `scripts/run-tests.php`, `composer test`, Playwright, or anything that
  bootstraps the app or touches the web cache.**
- Existing suites must stay green: `ai_project_test` 6/6, `ai_loop_test` 8/8, `ai_run_test` 31/31,
  `ai_autonomy_test` 46/46, all zero skips.
- No new authority semantics. Zero dependencies. PHP 8.2 compatible; PHPStan clean for changed files.
- Do not commit, stage or push.

## Files likely affected

- `tools/ai-project.php` — the `metrics` subcommand
- `tests/ai_project_metrics_test.php` — new
- `.ai/projects/harpp-gen4/metrics.json` — tool output
- `.ai/projects/harpp-gen4/director-minutes.json`, `.ai/projects/harpp-gen4/chair-errors.json` — inputs
- `.ai/ai-autonomy-harness.contract.md` — runbook note

## Acceptance criteria

1. `metrics --project=harpp-gen4` prints a table whose numbers are traceable to `.ai/runs/*.json` — show the
   command, the output, and the artefact lines they came from.
2. Cost and tokens are either **captured for real** or `null` with a stated reason. State which you achieved
   and how you established it; if `pi` exposes usage, say where it appears.
3. An unavailable metric is demonstrated as `null` with a reason, and a test proves no estimation path exists.
4. Director minutes absent → `null`, demonstrated (not `0`).
5. `--write` produces `metrics.json` marked tool-written; paste it.
6. All five suites exit `0` with zero skips; state counts. No existing case relaxed.
7. The three already-recorded Chair errors appear in the error column.

## Required tests

- Every command above with real output and exit codes.
- `php tests/ai_project_metrics_test.php`, plus the four existing suites — counts and zero skips.
- The `null`-not-estimated demonstration and the director-minutes-absent demonstration.
- `php -l` on changed files; PHPStan under `-c phpstan.neon` for them.

## Risks

- **The tempting shortcut is to estimate cost from tokens, or tokens from file size.** Both are fabrication.
  Record `unavailable` and say so.
- A metrics tool that reads the Chair's prose and calls it data reintroduces self-reporting. Count only what is
  mechanical: headings, run records, exit codes.
- Do not let `metrics.json` be edited by hand — if the tool cannot regenerate a number, that is a finding.

## Forbidden changes

- `tools/harpp-bridge/`
- `tools/ai-run.php`
- `tools/ai-loop.php`
- `tools/ai-autonomy.php`
- `tests/ai_project_test.php`
- `tests/ai_loop_test.php`
- `tests/ai_run_test.php`
- `tests/ai_autonomy_test.php`
- `.ai/runs/`
- `.github/workflows/`
- `kernel/`
- `modules/`
- `src/`
- `phpstan.neon`
- `phpstan-baseline.neon`
- `composer.json`
- `package.json`
