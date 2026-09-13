# /implement — PHASE 4 ROUND 2 (bounded repair) — Workbench auditor detection power (Codex Sol)

You are the /implement agent on Codex Sol. Working directory: /var/www/html/ikabudsix.
Phase-4 review returned CHANGES_REQUIRED (7 findings). The core complaint: the auditor's checks are
SIGNATURE-PRESENCE checks that are materially BYPASSABLE, plus CLI/scope evidence gaps. Repair per the
remediations below. This is the priority — a guard that can be bypassed is false confidence.

## Authority & context (read first)
1. Contract: /var/www/html/ikabudsix/.ai/phase4-workbench-auditor-contract-2026-09-06.md
2. Review findings: /var/www/html/ikabudsix/test_results/review-phase4.log (7 findings — your directive)
3. Governing: /var/www/html/ikabudsix/.github/instructions/ai-development-execution-handoff.instructions.md
   (bounded repair) + .github/copilot-instructions.md (check BOTH logs after runs).
4. Read the ACTUAL code: kernel/Workbench/Audit/WorkflowGuardAuditor.php (current checks), the REAL
   kernel/WorkflowEngine.php (the target — start/advance/cancel/replay + the fail-closed interrupt helper),
   tests/workbench_workflow_guard_audit_test.php, and the ikabud workbench:audit CLI block.

## Findings to FIX (with chair guidance) — raise detection power to METHOD-LOCAL STRUCTURAL analysis
Replace token-presence checks with a brace-aware method extractor + ordered, association-enforcing checks inside
each audited method (start, advance, cancel, replay and the persistence/interrupt region):
1. Dispatch-outside-lock: require, in order — a `workflow_runs ... FOR UPDATE` statement, a `commit()` that ends
   the transaction BEFORE `cap()->call()`, and no capability call while a run-row write lock is open. Reject
   `commit(); FOR UPDATE; call()` and `FOR UPDATE` on a non-`workflow_runs` table.
2. Lock symmetry: parse the GET_LOCK lock-name argument; require a RELEASE_LOCK with the SAME name in GUARANTEED
   cleanup (inside finally, or on every exit path). Flag conditional/unreachable/other-name releases.
3. Atomic claim: require the claim as ONE extracted SQL statement containing BOTH the status predicate
   (`status IN ('pending','failed')`) AND `attempt = attempt + 1`; require a rejecting
   `rowCount() !== 1` branch that rolls back / returns busy BEFORE the commit. A no-op/decoy row-count branch
   must be rejected (it must lead to rollback/return-busy, not an empty block).
4. Cancel/replay guards: identify the `workflow_runs ... FOR UPDATE`; require an effective blocked/running
   EARLY RETURN (the running-step check is actually evaluated and exits) BEFORE any step/run mutation; reject any
   step-reset predicate capable of touching `running` (not only the literal `status IN (... 'running' ...)`
   spelling).
5. Fail-closed persistence: the completion `rowCount() !== 1` failure path must THROW or enter the post-dispatch
   interruption path (not be a no-op); verify the guarded running→interrupted UPDATE and the fail-closed return as
   ONE associated structure (not unrelated string fragments elsewhere).
6. ADversarial fixtures (tests/workbench_workflow_guard_audit_test.php): add one fixture PER bypass scenario in
   the remediations (conditional release; commit-then-FOR-UPDATE-then-dispatch; decoy row-count branch; unqualified
   running-touching reset; no-op completion branch with unrelated interrupt call; other-lock-name release; etc.).
   Each must yield the expected finding with EXACT code/severity/line. Keep the real-file baseline at ZERO.
7. Evidence + CLI (test_results/phase4-evidence-r2.log):
   - Prove finding INGESTION through a TEMPORARY IssueLedger (write a finding, read it back).
   - Prove non-zero critical exit behavior of `php ikabud workbench:audit`.
   - Smoke at least one EXISTING workbench:* command (e.g. workbench:validate or doctor) to show no CLI breakage.
   - Capture diff/name/stat/check INCLUDING the untracked Phase-4 artifacts (kernel/Workbench/Audit/ + new test) —
     use `git add -N` or an explicit file inventory — plus a Phase-4 before/after file inventory that
     distinguishes pre-existing Phase-1/Phase-2 worktree changes (WorkflowEngine.php, workflow-system.md,
     FragmentStore, ComponentRenderer, EntityViewResolver, entity doc, the two cache/concurrency tests) from the
     Phase-4 files (ikabud wiring, kernel/Workbench/Audit/WorkflowGuardAuditor.php,
     tests/workbench_workflow_guard_audit_test.php).

## Prohibitions (unchanged)
- Only: kernel/Workbench/Audit/WorkflowGuardAuditor.php + tests/workbench_workflow_guard_audit_test.php + `ikabud`
  (if CLI fix needed) + docs only if warranted. NO change to WorkflowEngine.php behaviour; NO IssueLedger/schema
  change; NO migration/DDL; NO MySQL-8 SQL in the auditor; NO ARK/CMS; NO other roadmap phases; NO full-suite.
- Auditor stays static + deterministic (no live DB, no heavy runtime).

## Verification (capture RAW to test_results/phase4-evidence-r2.log)
- php -l on changed files; the full test file output (baseline zero + all adversarial fixtures); CLI audit output
  (zero criticals on the real file) + a critical-exit demonstration; IssueLedger ingestion read-back;
  workbench:* smoke; PHPStan level 6 on new/changed files (no new errors); git diff --check + name/stat incl.
  untracked; logs before/after (error.log empty).

## Return format (final message; concise; file:line refs)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
findings_addressed: (#1..#7: how + file:line + which adversarial fixture proves it)
changed:
implementation_summary: (structural analysis approach, method extractor, association enforcement)
verification: (point to test_results/phase4-evidence-r2.log; summarize counts + proofs)
risks:
unresolved:
recommended_next_state: (REVIEW_REQUIRED if all findings addressed)
Do NOT return empty. Do NOT report success without the raw evidence file complete.
