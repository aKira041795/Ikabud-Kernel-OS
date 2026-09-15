# /implement — ROUND 3 (bounded repair) — Phase 1: WorkflowEngine concurrency + idempotency

You are the /implement agent on Codex Sol, working in /var/www/html/ikabudsix.
Round 2 PASSED production review but /review returned CHANGES_REQUIRED on TWO findings (test-proof strength +
evidence transcript). Production code is architecturally APPROVED — do NOT change production logic unless a test
you add exposes a real bug. This is a tight, bounded repair.

## Authority & context (read first)
1. Contract + chair adjudication + round history: /var/www/html/ikabudsix/.ai/kernel-6x-roadmap-contract-2026-09-06.md
2. Round-2 review findings: /var/www/html/ikabudsix/test_results/review-sol-r2.log (2 findings — your directive)
3. Governing rules: /var/www/html/ikabudsix/.github/instructions/ai-development-execution-handoff.instructions.md
   (bounded repair) + /var/www/html/ikabudsix/.github/copilot-instructions.md (check BOTH logs after runs).

## Findings to FIX
1. Deterministic advisory-mutex proof (tests/workflow_concurrency_test.php, empty-range coverage ~L231-330).
   Current empty-range race may pass via ordinary committed-row dedupe even if GET_LOCK were removed, so it does
   not prove the absent-tuple mutex. Add a DETERMINISTIC proof:
   - Open an INDEPENDENT DB connection and acquire the exact same tuple-scoped GET_LOCK name the production
     start() uses for the absent-tuple case, BEFORE any worker invokes start().
   - Synchronize the worker(s) only AFTER their independent connections are ready (barrier), so both are truly
     waiting on the mutex — no sleeps.
   - Assert creation CANNOT proceed while the mutex is held: a concurrent start() must not create/commit a second
     run (it should surface the explicit run_creation_busy/contention result or block on the advisory lock, per
     production semantics — read the production code to assert the ACTUAL deterministic outcome).
   - RELEASE the lock, then let creation proceed; assert exactly one total run row, one active row, identical
     nonzero run IDs returned, and one side effect.
   - Keep the existing two-connection empty-range race test too. Verify the actual GET_LOCK name format in
     kernel/WorkflowEngine.php start() so the test acquires the SAME lock name.
   - Do NOT add sleeps; do NOT add DDL; MySQL 5.7-safe only.
2. Complete raw evidence transcript. pi's own log is a summary, so produce a separate UNEDITED transcript file:
   run a single shell command that captures FULL raw output of every required check into
   /var/www/html/ikabudsix/test_results/implement-sol-r3-evidence.log via redirect/tee (not a summary):
   - php -l kernel/WorkflowEngine.php
   - php -l tests/workflow_concurrency_test.php
   - php tests/workflow_engine_test.php  (full output; must stay 32/32)
   - php tests/workflow_lifecycle_test.php (full output; must stay 12/12)
   - php tests/workflow_concurrency_test.php (full output; must stay >=29/29 + your new deterministic test)
   - vendor/bin/phpstan analyse --level=6 --no-progress kernel/WorkflowEngine.php tests/workflow_concurrency_test.php
   - MySQL 8-only construct grep over the changed files (expect none)
   - git diff --check
   - wc -c storage/logs/app.log storage/logs/error.log BEFORE and AFTER the runs; report any error-level lines.
   Report the exact filename in your final message.

## Prohibitions (unchanged)
- Only tests/workflow_concurrency_test.php (+ docs/kernel/workflow-system.md only if a semantic note is needed for
  the deterministic proof). NO production change unless a real bug is proven. NO migration/DDL. NO MySQL 8 SQL.
- No WorkflowRuntime changes; no other roadmap phases; no .env/config/template changes; do NOT run the full suite.

## Return format (final message; concise; file:line refs)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
findings_addressed: (#1, #2: how + file:line + exact assertions)
changed:
implementation_summary: (mutex proof mechanics + evidence file)
verification: (point to implement-sol-r3-evidence.log; summarize key counts)
risks:
unresolved:
recommended_next_state: (REVIEW_REQUIRED if you believe both findings are closed)

Do NOT return empty. Do NOT report success without the raw evidence file existing and complete.
