# REVIEW — independent review of the harness implementations and the Python bridge changes

status: READY_FOR_REVIEW · revision: 1
repo: `/var/www/html/ikabudsix`
lane: openai-codex/gpt-5.6-sol
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "openai-codex/gpt-5.6-sol", "--name", "review-implementations", "<CONTRACT>"]

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

authority: owner directive 2026-09-14 — *"have sol review our implementations. recheck python too."*
directive: **THIS IS A REVIEW. Report only.** Deciding, recording and continuing applies to *your findings*, not
to the code: **the only file you may write is your report.** You are the reviewer; a reviewer that edits the
thing under review is not a reviewer.

## Objective

Independently review two things:

- **A — the harness implementations** (`tools/ai-autonomy.php`, `tools/ai-run.php`, `tools/ai-project.php`,
  `tools/ai-loop.php`, `tools/ai-contract-lint.php`, `kernel/Workbench/Development/DevelopmentTaskContract.php`,
  and their suites). Find real weaknesses, not stylistic preferences.
- **B — the Python bridge changes, made by an earlier slice and never verified** (`tools/harpp-bridge/`:
  `harpp_wake.py`, `tests/test_harpp_wake.py`, 4 workflow manifests, `README.md`). Recheck the Python.

## Verified facts — measured, do not re-derive

1. Harness suites: `ai_autonomy_test` 46/46, `ai_run_test` 35/35, `ai_project_test` 11/11,
   `ai_project_metrics_test` 16/16 — all exit 0 with **zero skips**.
2. `tools/ai-run.php` holds the verifier's trust surface: the **command allowlist** (three PHP shapes), the run
   classification (`completed`/`failed`/`silent`/`abandoned`), `commit-check`, and the **sentinel-planting test**
   asserting a chained command is refused and never executed.
3. The loop (`tools/ai-loop.php`) advances a slice only when every extracted claim is `RE_DERIVED`; it stops the
   project on `UNVERIFIED`/`CONTRADICTED`/`silent`/`failed`/`abandoned`, consults `commit-check` before each
   dispatch, and has no repair path — it does not repair, re-lane, or re-plan.
4. **The Python bridge changes are uncommitted and unverified.** They were produced by slice S5, which the loop
   then **blocked**: the report's evidence was Python (`python3 -m py_compile … PASS`) while the verifier's
   allowlist is PHP-only, so only one claim bound and it was `UNVERIFIED`. The changes are in the working tree
   right now — **do not disturb them.**
5. The manifests gained `"evidence": "required"` alongside their existing `marker` keys (e.g.
   `"marker": "SOL_ARCH status=PASS"`, `"verify": "test -s ARCHITECTURE.md && …"`, `"evidence": "required"`).
   Whether the runner **enforces** `evidence` or merely carries the key is exactly what B2 must settle.
6. **CD-17 (owner directive):** live decisions are permitted and expected — a test *may* file a real decision.
   The requirement is **provenance**, not prohibition. So do not report "a test can reach the service" as a
   defect by itself; report **which** invocations would, so the director's queue stays readable.

## Deliverables

### D1 — The report: `.ai/review-implementations.sol.md`

Per area (A and B): a verdict (`SOUND` / `SOUND_WITH_FINDINGS` / `UNSOUND`), then findings, each with:

- `file:line`
- what is wrong and **why it matters** (an exploitable path, a false-confidence path, or a fragile assumption)
- severity: `critical` / `major` / `minor`
- what evidence you produced: the command and its real output

Prefer **falsification over opinion**: if you claim a weakness, show the command that demonstrates it.

### D2 — Specifically answer these, definitively

**B1 — The non-vacuity anchor.** Does a stage whose executor prints `status=PASS` but produces **no passing
`verify` and no evidence** fail the bridge's gate? Demonstrate it against the checker that actually ships. If you
cannot demonstrate it, say so plainly — **"not demonstrated" is a valid and valuable answer**, and it is the
difference between S5 being committable and not.

**B2 — Is `evidence: "required"` enforced or decorative?** Trace it in `harpp_wake.py` to `file:line`. If it is
only carried in the JSON and never read by the runner, S5's change is incomplete and must be reported as such —
this is the single most important question in area B.

**B3 — Which bridge test invocations would reach the live service?** List them. Not a defect (CD-17), but the
director needs provenance. Note anything that would fire **unintentionally** during a routine test run.

**B4 — Is the Python work safe to commit?** A verdict with the evidence needed. If it is not, state exactly
what is missing rather than what you suspect.

**A1 — The guardrail probe (this informs the next design).** Attempt to make a contract authorise a change to the
verifier's trust surface: create a probe contract in **`/tmp`** whose `Files likely affected` includes
`tools/ai-run.php`, then run `php tools/ai-autonomy.php plan --json --contract=/tmp/<probe>.md` and
`php tools/ai-autonomy.php check "widen the command allowlist" --contract=/tmp/<probe>.md --path=tools/ai-run.php`.
**Paste the real exit codes.** If a contract can place the verifier in scope without escalation, that is a
**critical** finding: it means the rule that the verifier is unreachable is currently only prose.

**A2 — Weak spots in the harness tools.** Review the loop, `commit-check`, `verify` and the claim binding for:
a path that advances without evidence; a claim that could be bound to the *wrong* command; a way the loop's
own gates could be bypassed; anything that would let a repair change what counts as evidence.

### D3 — Do not fix anything

Findings only. The Chair decides what to act on, in what order, and on which lane — and the guardrail design
(A1) is being decided now, so a pre-emptive fix would pre-empt the decision.

## Architectural constraints

- **Report only.** The single file you may create or modify is `.ai/review-implementations.sol.md`.
- **Do not disturb the uncommitted `tools/harpp-bridge/` changes** — they are the subject of area B. Reading and
  running them is expected; editing them is not.
- You may run the bridge's Python tests. Prefer a scoped invocation over the whole suite, use a timeout, and be
  explicit about which invocations could reach the live service (B3).
- Do not commit, stage or push.
- Do not run `scripts/run-tests.php`, `composer test`, or anything that bootstraps the CMS app or touches its web
  cache; it poisons the APCu module-scan cache and 503s the live tenant.
- If you find something that looks like a **contract-level** issue (scope, authority, security, schema), report it
  as such — do not act on it.

## Files likely affected

- `.ai/review-implementations.sol.md` — the only file you may write
- `/tmp/<probe>.md` — the guardrail probe (A1)

## Acceptance criteria

1. The report exists with a verdict per area, every finding carrying `file:line`, severity, and the command and
   output that demonstrates it.
2. **B1** answered by demonstration, or explicitly "not demonstrated".
3. **B2** answered with a `file:line` in `harpp_wake.py` and a definitive enforced/decorative verdict.
4. **B4** a clear commit/don't-commit verdict with the missing evidence named.
5. **A1** pasted with the real exit codes of `plan` and `check`.
6. **A2** at least one genuine weak spot, or an explicit statement that a specific probe found none — with the
   probe shown.
7. The uncommitted bridge changes are **byte-identical** to before your review. Prove it: `git diff --stat
   tools/harpp-bridge/` before and after.
8. No file other than your report was written. Show `git status --porcelain` at the end.

## Required tests

- Every command you use as evidence, with its real output and exit code.
- The B1 demonstration (or the reason it cannot be given).
- The A1 probe's two exit codes.
- The before/after `git diff --stat tools/harpp-bridge/` proving you disturbed nothing.

## Risks

- **The main risk is a review that agrees too easily.** If you cannot falsify a claim, say so rather than
  restating it as confirmed. A review that finds nothing is only useful if it shows the probes that would have
  found something.
- Do not report stylistic preferences as findings; this codebase has deliberate patterns.
- Do not be misled by the harness's own reports — including mine. The loop's transcripts and this contract are
  claims; verify them.

## Forbidden changes

- `tools/ai-autonomy.php`
- `tools/ai-run.php`
- `tools/ai-project.php`
- `tools/ai-loop.php`
- `tools/ai-contract-lint.php`
- `tools/harpp-bridge/`
- `tests/`
- `kernel/`
- `modules/`
- `src/`
- `.ai/runs/`
- `.ai/projects/`
- `.ai/chair-decisions.md`
- `.github/`
- `phpstan.neon`
- `phpstan-baseline.neon`
- `composer.json`
- `package.json`
