# CONTRACT — HARPP 5: commit eligibility, claims as first-class objects, and re-derivation by execution

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

authority: independent review of 2026-09-14 (`PASS_WITH_CHANGES`) adopted as CD-13 in `.ai/chair-decisions.md`
directive: **you hold the decision** — decide, record, continue (CD-8). Sequence matters here: **P0 first, then
P1.** P0 is small, and the failure it prevents has already happened once.

## Objective

The review's central finding is that the harness records what an executor *claimed* but never **re-derives**
it. Independent verification is the stage that makes the difference between "another AI found the report
convincing" and "software reproduced the result". Two deliverables, in this order:

- **P0** — make committing during live or unstable state **impossible**, deterministically.
- **P1** — make claims **first-class objects** and **re-derive** the ones that can be re-derived by execution.

## Verified facts — measured, do not re-derive

1. `tools/ai-run.php` already classifies runs as `completed`, `failed`, `silent` or `abandoned`, records the
   pid, and reconciles a dead pid. `tests/ai_run_test.php` is a **pure** suite, **19/19**.
2. **The failure P0 prevents already happened:** during this session a commit was made while its run was still
   executing, capturing a non-final state (CD-11). The run itself detected and disclosed it (CD-12).
3. `claims` today extracts prose patterns from a report and marks them `unverified`. It executes nothing, and
   it says so.
4. The review's guidance is explicit: **do not solve semantic scope with an AI semantic-security layer** — that
   would add a probabilistic layer where the system must be deterministic. P1 is therefore limited to
   **deterministic re-derivation**; nothing about interpreting a diff.
5. `.ai/reviews` does not exist; the brief is `docs/reviews/harness-independent-evaluation-brief.md`.

## Deliverables

### P0 — Commit eligibility is deterministic

Add `commit-check` to `tools/ai-run.php`:

```
php tools/ai-run.php commit-check [--runs-dir=DIR] [--json]
```

- Exit **0** only when **no** recorded run is `running`, `silent`, `failed` or `abandoned` — i.e. every run is
  `completed`, or there are no runs at all.
- Exit **3** otherwise, naming each blocking run and its state in one line. A `running` run blocks; a dead-pid
  run reconciled to `abandoned` blocks; a `silent` run blocks.
- The check must be **cheap and side-effect-free** — no writes except the read-only reconciliation `status`
  already performs.

Then make the rule binding in the places that are actually read:

- `.ai/ai-autonomy-harness.contract.md` (runbook): **committing is forbidden while any run is not `completed`;
  run `commit-check` before staging.** State why: the failure is silent — the tree looks coherent while a run
  is still writing to it.
- `.github/instructions/ai-autonomy-escalation.instructions.md`: one line — committing during a live run is a
  process defect, and eligibility is decided by the ledger, not by how the tree looks.

### P1 — Claims as first-class objects

Upgrade `claims` to emit **structured objects**, not prose matches:

```json
{
  "claim_id": "CLM-<run-id>-<n>",
  "run": "<run id>",
  "type": "TEST_RESULT",
  "re_derivable": true,
  "subject": { "command": "php tests/ai_autonomy_test.php" },
  "executor_claim": { "exit_code": 0, "passed": 46, "total": 46 },
  "verification": { "method": null, "verifier": null, "observed_exit": null, "observed": null },
  "status": "UNVERIFIED"
}
```

Claim types to recognise, each declared `re_derivable` **honestly**:
`TEST_RESULT`, `LINT_RESULT`, `CONTRACT_CONFORMANCE`, `FILE_SCOPE`, `ARTIFACT_HASH`, `BROWSER_JOURNEY`,
`PERFORMANCE_MEASUREMENT`, `MIGRATION_STATE`. **Some of these cannot be re-derived by a pure tool** (a browser
journey, a performance measurement). The tool must say so rather than imply otherwise, and a claim that cannot
be re-derived must never be presented as verified.

### P1b — `verify`: re-derive by execution

```
php tools/ai-run.php verify --run=<id> [--claim=<claim_id>] [--runs-dir=DIR] [--json] [--timeout=SECONDS]
```

For each claim whose type is `re_derivable` **and** whose command passes the allowlist:

1. execute the declared command, capturing real exit code and output;
2. record `verification.method = "independent_execution"`, `verifier = "deterministic"`, the observed exit code
   and the observed values;
3. set status **`RE_DERIVED`** when the observation agrees with the executor's claim, and **`CONTRADICTED`**
   when it disagrees — with both the claimed and observed values recorded;
4. record the **tree binding**: the current revision (`git rev-parse HEAD`) and whether the working tree was
   dirty at verification time, so evidence cannot silently drift from the code it describes.

Exit codes: `0` when every re-derivable claim is `RE_DERIVED` (or there are none); `3` when any claim is
`CONTRADICTED`; `2` on usage error.

**Non-allowlisted commands are refused, never executed** — recorded with
`status: UNVERIFIED`, `reason: command_not_allowlisted`, and the command shown so a human can decide. The
allowlist is data-driven and documented in the usage text.

### P1c — The release gate consumes re-derived claims

Document, in the runbook: a phase may not advance on executor prose alone; it advances on claims whose status
is `RE_DERIVED`. Non-re-derivable claim types are listed as needing a human or a separate procedure — **not**
silently treated as satisfied.

## Architectural constraints

- **Deterministic only.** Do not add heuristic or model-based judgement of a diff, of intent, or of security
  semantics. The review refused this explicitly and the refusal is binding.
- **The allowlist is the security boundary.** Executing a command declared by a report is a code-execution
  surface: allowlist by shape, apply a per-command timeout, never pass the command through a shell in a way
  that permits chaining, and refuse anything unrecognised. Recording a refusal is a correct outcome.
- **A claim that cannot be re-derived must never look verified.** The three statuses are exhaustive;
  do not invent a fourth that softens this.
- **Do not run `scripts/run-tests.php`, `composer test`, or anything that bootstraps the app or touches the web
  cache** — those poison the APCu module-scan cache and turn a healthy tenant into a 503. Pure tests and the
  allowlisted `php tests/*.php` commands only. No Playwright.
- Zero dependencies, no DB, no network beyond what an allowlisted command itself does locally.
- `tests/ai_run_test.php` and `tests/ai_autonomy_test.php` must both remain green with no case relaxed.
- PHP 8.2 compatible; PHPStan clean for changed files under `-c phpstan.neon`.
- If a path you need is not in `allowed_scope`, report it rather than adding it silently.

## Files likely affected

- `tools/ai-run.php` — `commit-check`, structured claims, `verify`
- `tests/ai_run_test.php` — the new cases
- `.ai/ai-autonomy-harness.contract.md` — runbook: commit eligibility, release-gate rule
- `.github/instructions/ai-autonomy-escalation.instructions.md` — the commit rule

## Acceptance criteria

1. **`commit-check` matrix**, real exit codes: no runs → `0`; a `completed` run only → `0`; a `running` run →
   `3`; a `silent` run → `3`; a `failed` run → `3`; an `abandoned` run (dead pid) → `3`. Each blocking run is
   named in the output.
2. `claims` emits structured objects with `claim_id`, `type`, `re_derivable`, `subject.command`,
   `executor_claim` and `status: UNVERIFIED`; every recognised type declares `re_derivable` honestly.
3. `verify` re-executes an allowlisted command and records claimed vs observed values plus the tree binding
   (`rev`, dirty flag). Demonstrated on a real claim from this repository.
4. **A `CONTRADICTED` case is demonstrated**: a claim asserting a result the command does not produce must
   yield `CONTRADICTED` and exit `3`. Paste the output. This is the non-vacuity anchor — if everything is
   `RE_DERIVED`, the verifier is not verifying.
5. A **refused** command is shown, not executed: `status: UNVERIFIED`, `reason: command_not_allowlisted`.
6. A non-re-derivable claim type (e.g. `BROWSER_JOURNEY`) is demonstrably **not** reported as verified.
7. `php tests/ai_run_test.php` and `php tests/ai_autonomy_test.php` both exit `0`; state the counts and confirm
   no existing case was relaxed.
8. The runbook and the policy state the commit rule and the release-gate rule.

## Required tests

- The `commit-check` matrix — six lines with real exit codes.
- The `CONTRADICTED` demonstration (claimed vs observed, exit `3`).
- The refusal demonstration (command not executed).
- `php tests/ai_run_test.php` and `php tests/ai_autonomy_test.php` — counts and exit codes.
- `php -l` on every changed PHP file; PHPStan under `-c phpstan.neon` for those files.
- One real `verify` run against this repository, showing the tree binding recorded.

## Risks

- **Executing declared commands is the sharpest edge here.** If the allowlist is loose, this tool becomes a
  way to run arbitrary code from a report. Prove the refusal path works before proving the happy path.
- **A verifier that always agrees is worse than none** — it manufactures confidence. Acceptance 4 exists for
  exactly this reason; a `CONTRADICTED` must be reachable and demonstrated.
- **Do not let `verify` mutate the repository.** Re-derivation observes; it must not repair, format or rewrite.
  If an allowlisted command would write to the tree, exclude it and say so.
- The tree binding can be stale within seconds in a busy repo; record it as *evidence of what was verified*,
  not as a claim of eternal validity.

## Forbidden changes

- `tools/ai-autonomy.php`
- `tools/ai-contract-lint.php`
- `tests/ai_autonomy_test.php`
- `playwright.config.js`
- `tests/browser/`
- `docs/reviews/`
- `kernel/`
- `modules/`
- `src/`
- `.github/workflows/`
- `phpstan.neon`
- `phpstan-baseline.neon`
- `composer.json`
- `package.json`
