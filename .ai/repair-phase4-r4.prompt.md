# /implement — PHASE 4 ROUND 4 (chair two-layer re-scope) — Workbench guard (Codex Sol)

You are the /implement agent on Codex Sol. Working directory: /var/www/html/ikabudsix.
The chair made an autonomous architectural decision (user unavailable): TWO-LAYER RE-SCOPE. Implement it. This
round TERMINATES the static-analysis escalation; do NOT add dataflow analysis.

## Authority & context (read first)
1. Contract + chair decision: /var/www/html/ikabudsix/.ai/phase4-workbench-auditor-contract-2026-09-06.md
   — READ the "Round 4 — CHAIR DECISION ... TWO-LAYER RE-SCOPE" section (binding).
2. Round-3 review findings (for context on what is being deliberately bounded): test_results/review-phase4-r3.log
3. Governing: /var/www/html/ikabudsix/.github/instructions/ai-development-execution-handoff.instructions.md
   + .github/copilot-instructions.md (check BOTH logs after runs).

## Task (two layers)
LAYER A — Re-scope WorkflowGuardAuditor to an HONEST narrow regression TRIPWIRE:
- Rewrite the auditor's docblock + docs/kernel/workbench-workflow-guard-auditor.md guarantee section so the
  declared guarantee is EXACTLY what the model proves: method-local, presence/ordering of the canonical hardened
  patterns in kernel/WorkflowEngine.php, heuristic regression tripwire — explicitly NOT a correctness prover.
  Keep the real-file baseline at ZERO findings and keep the adversarial fixture suite green (they are the tripwire's
  regression net). Do NOT add reaching-definitions / path-sensitive value environments / all-path exit analysis —
  that escalation is recorded OUT OF SCOPE by chair decision. If any existing wording over-claims structural proof,
  tighten it to the honest heuristic scope.
LAYER B — Add the RUNTIME invariant self-check (the non-bypassable authority):
- In kernel/WorkflowEngine.php, add an OFF-BY-DEFAULT runtime guard: a private static bool (e.g.
  $runtimeGuardEnabled, default false) with a public static test-only setter (or equivalent additive seam). When
  enabled, immediately before `$this->app->cap()->call(...)` in the dispatch path, assert/verify the run-lock
  transaction is NOT open on the current DB connection (e.g. the claim/commit already happened — inTransaction()
  must be false); if the guard is violated, log at error level and fail closed (throw or return an explicit
  error) — do NOT dispatch the capability under the lock. This is defense-in-depth: a future refactor that moves
  dispatch inside the lock fails loudly in test/CI mode. Zero default behaviour change (guard off = identical).
- Do NOT weaken or alter any existing Phase-1 concurrency/idempotency logic. Preserve all return shapes.

## Tests
- Add tests for the runtime guard (in tests/workflow_engine_test.php or a small new test): (a) guard OFF =>
  normal dispatch unchanged; (b) guard ON + normal advance => dispatch passes; (c) guard ON + forced dispatch
  inside an open transaction => guard fires (error logged, capability NOT dispatched, fail-closed result).
- Re-run and keep green: the full Phase-1 suite (tests/workflow_engine_test.php 32/32,
  tests/workflow_lifecycle_test.php 12/12, tests/workflow_concurrency_test.php 38/38) AND the Phase-2 cache suite
  (tests/entity_view_render_cache_test.php 32/32) — the runtime-layer change must not regress Phases 1-2.
- Keep tests/workbench_workflow_guard_audit_test.php green (baseline zero + fixtures).

## Prohibitions
- Only: kernel/Workbench/Audit/WorkflowGuardAuditor.php (re-scope wording), docs/kernel/workbench-workflow-guard-auditor.md,
  kernel/WorkflowEngine.php (runtime guard only), + tests. NO dataflow escalation; NO migration/DDL; NO MySQL-8 SQL;
  NO IssueLedger/schema change; NO ARK/CMS; NO other roadmap phases; NO full-suite runs.

## Verification (capture RAW to test_results/phase4-evidence-r4.log)
- php -l on changed files; full outputs: workflow_engine_test.php, workflow_lifecycle_test.php,
  workflow_concurrency_test.php, entity_view_render_cache_test.php, workbench_workflow_guard_audit_test.php,
  + the new runtime-guard test; CLI `php ikabud workbench:audit` (zero criticals); PHPStan level 6 on changed
  files (no new errors); git diff --check + name/stat incl. untracked phase-4 files; logs before/after
  (error.log empty; no error-level app.log lines).

## Return format (final message; concise; file:line refs)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:              (files + file:line refs)
implementation_summary: (Layer A honest-guard wording; Layer B runtime guard design + off-by-default proof)
verification:         (point to test_results/phase4-evidence-r4.log; summarize all suite counts + guard tests)
risks:
unresolved:
recommended_next_state: (REVIEW_REQUIRED if both layers done per the chair decision)
Do NOT return empty. Do NOT report success without the raw evidence file complete.
