# /review — PHASE 4 ROUND 3 — Workbench auditor execution-aware (architectural review gate) — Codex Sol

You are the /review agent on Codex Sol. Working directory: /var/www/html/ikabudsix.
Read-only review — do NOT edit files or run tests. Verdict + precise remediation only.

## Context
R1 CHANGES_REQUIRED (signature-presence) -> R2 CHANGES_REQUIRED (execution-aware demands) -> chair architectural
decision (token_get_all statement/control-flow model + DEFINED GUARANTEE BOUNDARY, in the contract) -> R3 implement
(GPT sol) reports PASS. Judge R3 AGAINST THE DEFINED GUARANTEE BOUNDARY, not unbounded "any possible PHP" — the
chair decision explicitly scopes out-of-grammar obfuscation as a documented known-limit (covered by Phase-1 runtime
concurrency tests + human review). Do not rubber-stamp; verify detection power within that boundary + no false
positives on the real file.

## Inputs
1. Contract + chair decision: /var/www/html/ikabudsix/.ai/phase4-workbench-auditor-contract-2026-09-06.md
   (read the "Round 2 → round 3 — CHAIR ARCHITECTURAL DECISION" + guarantee boundary)
2. R2 review findings: /var/www/html/ikabudsix/test_results/review-phase4-r2.log
3. R3 implement report: /var/www/html/ikabudsix/test_results/implement-phase4-r3.log
4. RAW evidence: /var/www/html/ikabudsix/test_results/phase4-evidence-r3.log
5. Actual code: kernel/Workbench/Audit/WorkflowGuardAuditor.php (ctx_read full — the token model L296-565),
   tests/workbench_workflow_guard_audit_test.php, docs/kernel/workbench-workflow-guard-auditor.md,
   kernel/WorkflowEngine.php (target).

## Verify
1. Execution-aware model is real: token_get_all-based ordered operations with reachability (constant-false
   pruning, return/throw termination, try/finally association), statement→execute() binding, SQL-literal + bound
   param extraction, bound-value comparison — not offset/text heuristics.
2. Within the defined guarantee boundary, each R2 bypass is now DETECTED with exact code/severity/line:
   unexecuted lock/release/claim/FOR UPDATE/commit; bound-value lock-name mismatch; short-circuit/ternary release
   in finally; unreachable nested rejection (`if(false){rollBack();return busy;}`); unreachable fail-closed branch;
   parameterized/decoy step mutations; prepared-but-unexecuted statements. Adversarial fixtures prove each.
3. Real-file baseline stays ZERO (no false positives from the stronger model). 35/35 tests in transcript.
4. Fail-closed persistence: completion + interruption updates must EXECUTE with reachable fail-closed returns
   (throw/interrupt associated with the row-count failure branch).
5. Cancel/replay: ALL executed workflow_run_steps/status mutations checked; predicates must prove exclusion of
   'running' (bound-aware) unless protected targeted-range structure.
6. Guarantee boundary documented (auditor docblock + docs/kernel/workbench-workflow-guard-auditor.md) and the
   known-limits are honest (out-of-grammar obfuscation → covered by Phase-1 runtime tests + review). Consistent
   with the chair decision.
7. Evidence: real auditor finding round-tripped through temporary IssueLedger (invariant code + location
   preserved); critical CLI exit demo with byte-exact WorkflowEngine restoration; existing workbench:* smoke;
   PHPStan/lint clean; git diff incl. untracked phase-4 files + Phase-1/2 vs Phase-4 inventory; error.log empty.
Regression/scope: auditor static + deterministic; no WorkflowEngine behaviour change; no IssueLedger/schema
change; no DDL/MySQL-8 SQL; no ARK/CMS; no other phases.

## Return format
verdict: PASS | CHANGES_REQUIRED
findings: (numbered; issue + why + file:line if known)
remediation: (ONLY if CHANGES_REQUIRED)
scope_note:
Do NOT return empty. Do NOT take over implementation. Judge within the defined guarantee boundary.
