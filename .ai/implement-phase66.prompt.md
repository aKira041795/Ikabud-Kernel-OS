# /implement — 6.6 EVIDENCE: unified workflow→capability correlation trace — Codex Sol

You are the /implement agent on Codex Sol. Working directory: /var/www/html/ikabudsix (Ikabud-Kernel-OS, branch
feat/6x-evidence-trace-6.6, stacked on the 6.4 branch). Execute objective 6.6: thread a correlation id from a
WorkflowEngine run through each capability dispatch so run ↔ capability call is linkable in the evidence trail.
Real edits + real tests.

## Authority & rules (read first)
1. Contract (AUTHORITATIVE): /var/www/html/ikabudsix/.ai/phase66-evidence-trace-contract-2026-09-06.md
   (scope/constraints/acceptance/verification/risk). Roadmap: .ai/kernel-6x-five-guarantees-roadmap-2026-09-06.md.
2. Governing: /var/www/html/ikabudsix/.github/instructions/ai-development-execution-handoff.instructions.md
   (bounded repair, result format) + .github/copilot-instructions.md (check BOTH logs after runs).
3. Read the ACTUAL code (never assume): kernel/Capabilities/CapabilityBus.php (resolveCaller :861-873, trace :518-570
   log :542, logSchemaViolation :796, logDenied :1002), kernel/WorkflowEngine.php (step dispatch :900 — note this
   branch already includes the 6.3/6.4 changes, line numbers may shift; find the actual dispatch call),
   tests/workflow_engine_test.php (:38,:169-171 log pattern), tests/durable_idempotency_test.php (capability
   registration pattern).

## Task (single objective)
1. CapabilityBus: resolveCaller() also pulls correlation_id from $options['correlation_id'] ?? ctx['correlation_id']
   and returns it; trace()/logSchemaViolation()/logDenied() include correlation_id in the logged context (null/absent
   when not provided — additive, non-workflow callers unchanged).
2. WorkflowEngine step dispatch: pass options ['correlation_id' => "wf:run:<runId>:step:<stepId>"] so every
   capability.call trace from a workflow step carries the run/step chain. Do NOT inject caller_user (authz defaults
   preserved). No DDL (do NOT add columns; context_json persistence not required for the log-chain proof).
3. Test: append to tests/workflow_engine_test.php OR new tests/unified_execution_trace_test.php: register a _test
   capability, upsert a one-step workflow_definitions row, start() a run; assert app.log contains a WorkflowEngine
   step-completed entry AND a capability.call entry sharing the SAME correlation_id ("wf:run:<runId>:step:...").
   If traceEnabled() is off in the test environment, scope-enable it for the test (do not disable assertions).
4. Doc: docs/kernel/workflow-system.md (correlation contract) + roadmap note.

## Prohibitions (unchanged)
- Only: kernel/Capabilities/CapabilityBus.php + kernel/WorkflowEngine.php + tests/workflow_engine_test.php (or the
  new test file) + docs/kernel/workflow-system.md (+ roadmap note). NO migration/DDL. NO MySQL-8-only SQL. NO
  dispatch/authz/policy semantics change. NO caller_user injection. NO tracing framework/Workbench subsystem.
  NO ARK/CMS. NO other guarantees. NO full-suite runs.

## Verification (capture RAW to test_results/phase66-evidence.log)
- php -l on changed files; full new/appended test output; re-run targeted suites (workflow_engine 32/32 + any new,
  lifecycle 12/12, concurrency 38/38, durable_idempotency 30/30); PHPStan level 6 on changed files; git diff
  --check + --name-only/--stat (incl. untracked); logs before/after (error.log empty). In the evidence, SHOW the
  app.log lines proving the correlation_id linkage (grep the correlation id in both the step-completed and
  capability.call entries).

## Return format (final message; concise; file:line refs)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:              (files + file:line refs)
implementation_summary: (correlation flow, WorkflowEngine dispatch options, additive behavior, trace-enable in test)
verification:         (point to test_results/phase66-evidence.log; show the correlation_id linkage lines)
risks:
unresolved:
recommended_next_state: (REVIEW_REQUIRED if done per contract)
Do NOT return empty. Do NOT report success without the raw evidence file complete.
