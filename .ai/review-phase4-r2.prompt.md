# /review — PHASE 4 ROUND 2 — Workbench auditor detection power (architectural review gate) — Codex Sol

You are the /review agent on Codex Sol. Working directory: /var/www/html/ikabudsix.
Read-only review — do NOT edit files or run tests. Verdict + precise remediation only.

## Context
Phase-4 review R1 = CHANGES_REQUIRED (7 findings: bypassable signature-presence checks + evidence gaps).
R2 /implement (GPT sol) reports REVIEW_REQUIRED claiming all 7 addressed via method-local structural analysis.
Independently verify R2 truly closes them without false positives on the real file. Do not rubber-stamp.

## Inputs
1. Contract: /var/www/html/ikabudsix/.ai/phase4-workbench-auditor-contract-2026-09-06.md
2. R1 review findings: /var/www/html/ikabudsix/test_results/review-phase4.log
3. R2 implement report: /var/www/html/ikabudsix/test_results/implement-phase4-r2.log
4. RAW evidence: /var/www/html/ikabudsix/test_results/phase4-evidence-r2.log
5. Actual code: kernel/Workbench/Audit/WorkflowGuardAuditor.php (ctx_read full), tests/workbench_workflow_guard_audit_test.php,
   ikabud workbench:audit block, and the REAL kernel/WorkflowEngine.php target.

## Verify (R1 findings 1-7 are closed?)
1. Dispatch-outside-lock: auditor enforces ORDER (workflow_runs FOR UPDATE -> claim -> rejecting row-count -> commit
   -> cap->call()) and rejects commit-then-FOR-UPDATE-then-dispatch and FOR UPDATE on non-workflow_runs.
2. Lock symmetry: same-lock-name RELEASE_LOCK required in guaranteed cleanup (finally); conditional/unreachable/
   mismatched releases rejected — not merely equal token counts.
3. Atomic claim: single extracted SQL with status predicate + attempt++ together; rejecting row-count branch that
   rolls back/returns busy (no-op/decoy rejected).
4. Cancel/replay: workflow_runs FOR UPDATE identified; blocked-step guard evaluated with running early return
   BEFORE mutations; step-reset predicates that can touch running rejected (not only one literal spelling).
5. Fail-closed: completion row-count failure structurally enters interruption/throw; guarded running->interrupted
   update + fail-closed return associated as one structure (not unrelated fragments).
6. Adversarial fixtures exist for EVERY bypass scenario with exact code/severity/line assertions; real-file
   baseline stays ZERO (no false positives introduced by the stronger checks).
7. Evidence proves: finding ingestion via temporary IssueLedger round-trip; critical non-zero CLI exit (and the
   temporary regression was fully reverted — WorkflowEngine byte-identical); existing workbench:* smoke
   (workbench:validate dispatched; its 328/341 exit-1 is pre-existing missing daily-ledger fixtures, not Phase 4 —
   confirm); diff/name/stat/check INCLUDING untracked Phase-4 files; Phase-1/2 vs Phase-4 file inventory.
Regression/scope: auditor static+deterministic; no WorkflowEngine behaviour change; no IssueLedger/schema change;
no DDL/MySQL-8 SQL; no ARK/CMS; no other phases. Tests 26/26 in transcript; lint + PHPStan clean on added lines.

## Return format
verdict: PASS | CHANGES_REQUIRED
findings: (numbered; issue + why + file:line if known)
remediation: (ONLY if CHANGES_REQUIRED)
scope_note:
Do NOT return empty. Do NOT take over implementation.
