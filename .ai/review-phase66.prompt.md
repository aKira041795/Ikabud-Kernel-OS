# /review — 6.6 EVIDENCE: workflow→capability correlation trace — architectural review gate — Codex Sol

You are the /review agent on Codex Sol. Working directory: /var/www/html/ikabudsix.
Read-only review — do NOT edit files or run tests. Verdict + precise remediation only.

## Context
6.6 /implement (GPT sol) reports PASS. Independent review required — do not rubber-stamp.

## Inputs
1. Contract (authoritative): /var/www/html/ikabudsix/.ai/phase66-evidence-trace-contract-2026-09-06.md
2. Implement report: /var/www/html/ikabudsix/test_results/implement-phase66.log
3. RAW evidence: /var/www/html/ikabudsix/test_results/phase66-evidence.log
4. Actual code + diff: `git -C /var/www/html/ikabudsix diff -- kernel/Capabilities/CapabilityBus.php kernel/WorkflowEngine.php docs/kernel/workflow-system.md`,
   read tests/unified_execution_trace_test.php (ctx_read).

## Verify (hard scrutiny)
- CORRELATION FLOW is real and additive: resolveCaller reads correlation_id from dispatch options / capability ctx
  and returns it; trace()/logSchemaViolation()/logDenied() include it. Callers WITHOUT correlation_id behave
  unchanged (field absent/null). No caller_user injected; no dispatch/authz/policy semantics change.
- WORKFLOW LINKAGE: WorkflowEngine step dispatch passes ['correlation_id' => "wf:run:<runId>:step:<stepId>"]; the
  capability.call trace and the engine's step-completed log share the SAME correlation_id (evidence shows both).
  No DDL (no new columns); context_json persistence not required.
- TEST proves the chain (not vacuous): a run that executes a capability yields both log entries with the matching
  correlation id; trace is enabled correctly in the test scope and restored after (no global side effect).
- NO REGRESSION: workflow engine 32/32, lifecycle 12/12, concurrency 38/38, durable_idempotency 30/30,
  unified trace 5/5 green in transcript.
- SCOPE: only CapabilityBus.php + WorkflowEngine.php + new test + docs (+ roadmap note). No DDL/MySQL-8 SQL, no
  authz/policy change, no tracing framework/Workbench subsystem, no ARK/CMS, no other guarantees.
- EVIDENCE: phase66-evidence.log complete raw transcript incl. the correlation_id linkage lines; lint, PHPStan,
  diff name/stat (incl. untracked), logs (error.log empty).

## Return format
verdict: PASS | CHANGES_REQUIRED
findings: (numbered; issue + why + file:line if known)
remediation: (ONLY if CHANGES_REQUIRED)
scope_note:
Do NOT return empty. Do NOT take over implementation. If evidence is insufficient, say what is missing explicitly.
