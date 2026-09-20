# /implement — PHASE 4 ROUND 3 (bounded repair) — Workbench auditor execution-aware analysis (Codex Sol)

You are the /implement agent on Codex Sol. Working directory: /var/www/html/ikabudsix.
Phase-4 review R2 returned CHANGES_REQUIRED. The chair has made an ARCHITECTURAL DECISION (read it in the
contract below) and defined the guarantee boundary. Repair per that decision + the review's remediations.

## Authority & context (read first)
1. Contract + chair decision: /var/www/html/ikabudsix/.ai/phase4-workbench-auditor-contract-2026-09-06.md
   — READ the "Round 2 → round 3 — CHAIR ARCHITECTURAL DECISION" section; it is binding.
2. Review findings: /var/www/html/ikabudsix/test_results/review-phase4-r2.log (7 findings — your directive)
3. Governing: /var/www/html/ikabudsix/.github/instructions/ai-development-execution-handoff.instructions.md
   (bounded repair) + .github/copilot-instructions.md (check BOTH logs after runs).
4. Read the ACTUAL code: kernel/Workbench/Audit/WorkflowGuardAuditor.php (current state),
   tests/workbench_workflow_guard_audit_test.php, kernel/WorkflowEngine.php (the bounded target grammar you must
   model), ikabud workbench:audit wiring.

## Task (single objective)
Upgrade WorkflowGuardAuditor from offset/text checks to a **token_get_all-based statement/control-flow model**
with basic reachability + bound-value comparison, per the chair decision, and prove detection of every remaining
bypass via adversarial fixtures. Keep real-file baseline ZERO. Document the guarantee boundary.

## Requirements (from chair decision + review remediation)
1. Build a token_get_all-based parser producing, per audited method, an ordered list of statements with: kind
   (prepare/execute/update/commit/begin/lock call/return/throw/if/try/finally), the statement variable binding,
   the SQL literal (and bound params) used, and nesting/control-flow parents. Basic reachability: prune
   constant-false branches (`if (false)`), treat return/throw as terminating, associate try/finally cleanup.
2. Checks become execution-aware:
   - Each prepared-statement variable is only meaningful if a REACHABLE execute() call on that variable exists.
   - Compare BOUND lock-name values (SQL literal or bound param value), not placeholders.
   - REACHABLE branches only: rollback/busy/throw/interrupt/success must be reachable (reject if(false) /
     nested-conditional decoys). Release = unconditional cleanup in finally (or all-exit-path) with SAME bound
     lock name.
   - ALL status mutations in cancel/replay: predicates must prove exclusion of 'running' (bound-value aware)
     unless a protected targeted-range structure is present.
   - Completion-failure: REACHABLE executed completion-UPDATE + guaranteed fail-closed return (throw/interrupt)
     associated with the row-count failure branch.
3. Adversarial fixtures (tests/workbench_workflow_guard_audit_test.php) — EXACT code/severity/line, one per:
   unexecuted lock/release/claim; bound-value lock-name mismatch; short-circuit/ternary release in finally;
   unreachable nested rejection (`if(false){rollBack(); return busy;}`); unreachable fail-closed branch;
   parameterized or decoy step mutation beside a safe update; prepared-but-unexecuted FOR UPDATE/commit; real
   auditor finding round-tripped through a TEMPORARY IssueLedger (invariant code + location read back).
   Real-file baseline must stay ZERO (no false positives).
4. Document the GUARANTEE BOUNDARY in the auditor docblock + docs: method-local, within the bounded
   statement/control-flow grammar used by WorkflowEngine.php; out-of-grammar obfuscation is a documented
   known-limit (still covered by Phase-1 runtime concurrency tests + human review). Add a documented-limits note.

## Prohibitions (unchanged)
- Only: kernel/Workbench/Audit/WorkflowGuardAuditor.php + tests/workbench_workflow_guard_audit_test.php + docs
  (guarantee boundary) + ikabud only if wiring fix needed. NO WorkflowEngine.php behaviour change; NO
  IssueLedger/schema change; NO DDL; NO MySQL-8 SQL in the auditor; NO ARK/CMS; NO other phases; NO full-suite.
- Auditor stays static + deterministic; must not require live DB.

## Verification (capture RAW to test_results/phase4-evidence-r3.log)
- php -l on changed files; full test output (baseline zero + ALL adversarial fixtures incl. the new bypass set);
  CLI audit output (zero criticals on real file); real-finding IssueLedger round-trip read-back; critical non-zero
  exit demo; existing workbench:* smoke; PHPStan level 6 (no new errors); git diff --check + name/stat INCLUDING
  untracked phase-4 files + Phase-1/2 vs Phase-4 inventory; logs before/after (error.log empty).

## Return format (final message; concise; file:line refs)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
findings_addressed: (#1..#7: how + file:line + which adversarial fixture proves it)
changed:
implementation_summary: (token model, reachability, bound-value comparison, guarantee boundary)
verification: (point to test_results/phase4-evidence-r3.log; summarize counts + proofs)
risks:
unresolved:
recommended_next_state: (REVIEW_REQUIRED if all findings addressed per the chair decision)
Do NOT return empty. Do NOT report success without the raw evidence file complete.
