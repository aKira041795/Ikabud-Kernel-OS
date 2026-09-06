# 6.6 — EVIDENCE: unified workflow→capability correlation trace — CONTRACT (2026-09-06)

task: Implement the Evidence-guarantee objective: thread a correlation id from a WorkflowEngine run through each
capability dispatch so a single operation can be followed end-to-end in the log/evidence trail (run ↔ capability
call linkage). The smallest high-value unit — NOT a sprawling tracing framework.

objective: Close the causal gap where a workflow run's capability calls cannot be traced back to the run. Grounded
gap (Explore 2026-09-06): CapabilityBus::trace() logs capability.call with request_id but NO run/correlation id
(kernel/Capabilities/CapabilityBus.php:518-570, log :542); WorkflowEngine::advance() dispatches capabilities with
NO options (:900) so workflow-driven capability traces are orphaned from their run; the engine logs run_id in
separate entries (:741-746, :958-963). Correlation plumbing already exists for event→trigger→capability
(kernelCorrelationId, EventTriggers.php:368) — Workflow is the odd path out. The CapabilityBusContract even
documents a correlation_id dispatch option (:19) that is dropped by resolveCaller (:861-873).

## Grounded seams (verified 2026-09-06)
- write_log auto-injects request_id (bootstrap.php:701-705); request_id() at bootstrap.php:375.
- CapabilityBus::resolveCaller (:861-873) returns module|user|request_id — add correlation_id from
  $options['correlation_id'] ?? ctx.
- CapabilityBus::trace (:542), logSchemaViolation (:796), logDenied (:1002) log entries — add correlation_id.
- WorkflowEngine step dispatch (:900): `$this->app->cap()->call($capabilityId, $resolvedArgs)` — add options
  ['correlation_id' => "wf:run:<runId>:step:<stepId>"]. Do NOT pass caller_user (authz defaults stay intact).
- Tests: workflow_engine_test.php already clears + reads app.log (:38,:169-171); durable_idempotency_test.php
  pattern (register _test capability, upsert workflow_definitions, start()).

## scope:
  allowed:
    - kernel/Capabilities/CapabilityBus.php: resolveCaller includes correlation_id (from options/ctx); trace(),
      logSchemaViolation(), logDenied() include correlation_id in the logged context.
    - kernel/WorkflowEngine.php step dispatch (:900): pass options ['correlation_id' => "wf:run:<runId>:step:<stepId>"]
      so each capability.call trace from a workflow step carries its run/step chain. No caller_user injection.
    - Test: append to tests/workflow_engine_test.php OR a new tests/unified_execution_trace_test.php (plain PHP):
      register a _test capability, upsert a one-step workflow_definitions row, start() a run; assert app.log
      contains a WorkflowEngine step-completed entry AND a capability.call entry whose context carries the SAME
      correlation_id ("wf:run:<id>:step:..."). Proves run ↔ capability linkage in one log file.
    - Doc: docs/kernel/workflow-system.md (correlation contract) + roadmap note.
  prohibited:
    - NO DDL/migration (workflow_runs.context_json already exists; not needed for the log-chain proof — do NOT add
      columns). NO MySQL-8-only SQL. NO change to dispatch/authz/policy semantics (no caller_user injection, no
      behavior change for non-workflow callers or callers without correlation_id).
    - NO new tracing framework, tables, or Workbench subsystems. NO ARK/CMS. NO other guarantees. NO full-suite runs.

### constraints:
  - Additive: callers without correlation_id behave exactly as today (field simply null/absent).
  - The correlation_id must survive to the log entries (trace/schema_violation/denied) and be identical between the
    WorkflowEngine step log and the capability.call trace for the same dispatch.
  - No caller_user is injected (authz defaults preserved); correlation is evidence-only.
  - PSR-12 + PHPStan level 6 (no new errors); read actual code first; check BOTH logs after runs.

### acceptance:
  - A workflow run that executes a capability produces, in app.log, a WorkflowEngine step-completed entry AND a
    capability.call entry sharing the SAME correlation_id ("wf:run:<runId>:step:<stepId>").
  - Non-workflow capability calls (direct cap()->call without correlation_id) log without correlation_id
    (unchanged).
  - No DDL; workflow/lifecycle/concurrency/durable-idempotency suites stay green.
  - New test passes; php -l clean; PHPStan no new errors; both logs clean.

### verification:
  - php -l on changed files; full new test output (or the appended workflow_engine_test block); re-run targeted
    suites (workflow_engine 32/32, lifecycle 12/12, concurrency 38/38, durable_idempotency 30/30); PHPStan level 6
    on changed files; git diff --check + name/stat; logs before/after (error.log empty). Capture RAW output to
    test_results/phase66-evidence.log.

### risk:
  - WorkflowEngine may be hard to drive in a unit test if the run requires a real capability provider — reuse the
    _test capability registration pattern (workflow_concurrency_test/durable_idempotency_test). Confirm trace() is
    enabled in the test environment (traceEnabled defaults dev; if the test env disables it, assert via the log
    entries that DO appear or enable trace for the test scope explicitly).
  - correlation_id option may be stripped by ServiceProxy/pipeline paths — verify resolveCaller reads it in all
    dispatch modes used by workflow steps.

### status: READY_FOR_IMPLEMENTATION
