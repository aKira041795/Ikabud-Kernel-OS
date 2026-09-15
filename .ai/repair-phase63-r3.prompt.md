# /implement — 6.3 ROUND 3 (cross-guard regression fix) — WorkflowEngine start() (Codex Sol)

You are the /implement agent on Codex Sol. Working directory: /var/www/html/ikabudsix (branch
feat/6x-consistency-idempotency-6.3 — the 6.3 PR branch). Fix a cross-objective regression the Phase-4 Workbench
guard CAUGHT: the 6.3 external-key path in WorkflowEngine::start() broke the WorkflowGuardAuditor zero-finding
baseline. Real edits + real tests.

## The regression (verified locally)
`php tests/workbench_workflow_guard_audit_test.php` → 34/35. The ONE failure is the real-file baseline:
```
✗ real hardened WorkflowEngine has zero findings —
  WORKFLOW_START_DEDUPE    (critical, kernel/WorkflowEngine.php:555) "start() must reach findActiveRun()
                           before executing a workflow-run INSERT."
  WORKFLOW_START_LOCK_SYMMETRY (critical, :555) "start() must execute release of the same bound tuple-lock
                           value as unconditional finally cleanup."
```
The 6.3 external-key/dedupe code added paths in start() (around L555+) that can return / insert a run before
findActiveRun() is reached, and/or skip the unconditional finally release of the tuple lock.

## Task (single objective)
Restructure WorkflowEngine::start() so the 6.3 external-key idempotency COMPOSES with the Phase-1 lock/claim
discipline, satisfying BOTH:
1. WORKFLOW_START_DEDUPE: no workflow-run INSERT may execute before findActiveRun()/active-run dedupe is reached
   on ANY path (including the idempotent-duplicate return path). The external-key claim must sit INSIDE the guarded
   structure (after the active-run check / before the INSERT), not short-circuit ahead of it.
2. WORKFLOW_START_LOCK_SYMMETRY: the SAME bound tuple-lock value must be released as unconditional finally cleanup
   on ALL exit paths (first-run, idempotent-duplicate hit, conflict, error) — including any advisory lock used for
   the external-key claim. No path may leak the lock or return before finally releases it.
3. PRESERVE 6.3 semantics exactly: first call executes + commits outcome; duplicate (same key + same normalized
   payload) returns stored run_id/result, NO second run, NO re-execution; conflicting payload → explicit
   idempotency_payload_conflict; keyless callers behave exactly as before; no DDL; MySQL-5.7-safe.
Read the actual start() + the auditor's expectations (kernel/Workbench/Audit/WorkflowGuardAuditor.php,
WORKFLOW_START_DEDUPE + WORKFLOW_START_LOCK_SYMMETRY checks) before editing. Do not weaken the auditor; make the
code satisfy it honestly.

## Verification (run all; keep ALL green)
- php tests/workbench_workflow_guard_audit_test.php  → must be 35/35 (baseline zero again)
- php tests/durable_idempotency_test.php             → 30/30 (semantics preserved)
- php tests/workflow_engine_test.php (32/32), tests/workflow_lifecycle_test.php (12/12),
  tests/workflow_concurrency_test.php (38/38)
- php -l on changed files; vendor/bin/phpstan analyse --level=6 --no-progress on changed files; git diff --check
- Check BOTH logs after runs (error.log empty).
- Capture RAW output to test_results/phase63-evidence-r3.log (full transcript incl. all above).

## Prohibitions (unchanged)
- Only kernel/WorkflowEngine.php (+ tests only if a NEW regression assertion is warranted) + docs if the semantics
  note changes. NO migration/DDL. NO MySQL-8-only SQL. NO WorkflowRuntime change. No weakening of the guard
  auditor or its expectations. NO other guarantees. NO full-suite runs.

## Return format (final message; concise; file:line refs)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:              (files + file:line refs)
implementation_summary: (how the external-key path now composes with findActiveRun + finally lock release; any
                         lock-scope change)
verification:         (point to test_results/phase63-evidence-r3.log; summarize each suite count)
risks:
unresolved:
recommended_next_state: (REVIEW_REQUIRED if all green)
Do NOT return empty. Do NOT report success without the raw evidence file complete.
