# /implement — 6.3 ROUND 2 (bounded repair) — durable idempotency (Codex Sol)

You are the /implement agent on Codex Sol. Working directory: /var/www/html/ikabudsix.
6.3 review returned CHANGES_REQUIRED (4 findings). Repair per the remediations below. BOUNDED — no scope expansion.

## Authority & context (read first)
1. Contract: /var/www/html/ikabudsix/.ai/phase63-durable-idempotency-contract-2026-09-06.md
2. Review findings: /var/www/html/ikabudsix/test_results/review-phase63.log (4 findings — your directive)
3. Governing: /var/www/html/ikabudsix/.github/instructions/ai-development-execution-handoff.instructions.md
   (bounded repair) + .github/copilot-instructions.md (check BOTH logs after runs).
4. Read the ACTUAL code: kernel/Http/Idempotency.php (claim L23-39, commit L96, release L137),
   kernel/WorkflowEngine.php (external-key path ~L555 + hashing ~L1154-1194), tests/durable_idempotency_test.php
   (two-connection section ~L279-294), migration 011.

## Findings to FIX (with chair guidance)
1. Deterministic loser outcome WITHOUT a workflow-duration cutoff. Today the loser can throw
   idempotency_claim_failed after a fixed 10s GET_LOCK timeout when the winner's workflow runs longer. Fix:
   the loser must deterministically observe the winner's committed outcome. Approach: a bounded retry loop — try
   GET_LOCK; if acquired, read the row (completed → return stored outcome; processing → treat as fresh claim or
   re-check); if not acquired (winner holds it), poll the row for 'completed' and return the stored outcome, else
   retry GET_LOCK. Never throw solely because the winner ran longer. On a documented generous cap still 'processing'
   (genuinely abandoned/uncertain), fail closed with a DISTINCT in-progress result (e.g. idempotency_in_progress)
   — never auto-reclaim, never double-execute. Document the cap.
2. Test proof point: strengthen the two-connection test so it PROVES the loser entered the production claim wait
   while the winner held it — mirror the Phase-1 mutex-proof pattern (tests/workflow_concurrency_test.php): hold the
   exact claim lock / create the 'processing' row on an independent connection, release both contenders only after
   both connections are ready, show the loser is blocked in the claim before releasing, then release and assert the
   loser returns the WINNER's identical outcome, one run, one side effect. No sleeps; real synchronization.
3. Claim OWNERSHIP enforcement: commit() and release() must verify the calling connection owns the claim before
   mutating/deleting the processing row. Use GET_LOCK semantics (RELEASE_LOCK returns 1 only on the owning
   connection; IS_USED_LOCK shows the owner connection id) + a guarded UPDATE/DELETE WHERE status='processing'.
   An erroneous concurrent release from a non-owner must be a no-op/false — never able to remove a live claim and
   permit double execution. No DDL (ownership is proven via lock primitives, not a new column).
4. Evidence: regenerate test_results/phase63-evidence-r2.log as a COMPLETE raw transcript including: exact SQL +
   outputs proving the migration-011 + live-database unique (hash,tenant) index (SHOW INDEX / DESCRIBE +
   SELECT VERSION(), DATABASE() identity), full output of tests/durable_idempotency_test.php + the re-run
   Phase-1 workflow suites (engine/lifecycle/concurrency), PHPStan level 6, lint, git diff --check + name/stat,
   and the FINAL contents of storage/logs/app.log + storage/logs/error.log (error.log must be empty).

## Prohibitions (unchanged)
- Only: kernel/Http/Idempotency.php + kernel/WorkflowEngine.php + tests/durable_idempotency_test.php +
  docs/kernel/workflow-system.md. NO migration/DDL. NO MySQL-8-only SQL. No WorkflowRuntime change. No 6.4/6.5/6.6.
  No ARK/CMS. No full-suite runs.

## Return format (final message; concise; file:line refs)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
findings_addressed: (#1..#4: how + file:line + proof)
changed:
implementation_summary:
verification: (point to test_results/phase63-evidence-r2.log; summarize counts + the ownership + loser proofs)
risks:
unresolved:
recommended_next_state: (REVIEW_REQUIRED if all findings addressed)
Do NOT return empty. Do NOT report success without the raw evidence file complete.
