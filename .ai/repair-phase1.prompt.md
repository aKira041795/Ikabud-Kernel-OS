# /implement — ROUND 2 (bounded repair) — Phase 1: WorkflowEngine concurrency + idempotency

You are the /implement agent on Codex Sol, working in /var/www/html/ikabudsix.
Round 1 returned PASS but /review (GPT sol) returned CHANGES_REQUIRED with 7 findings. Repair per the
chair-adjudicated remediation below, then re-verify. This is a BOUNDED repair — do not expand scope.

## Authority & context (read first)
1. Contract: /var/www/html/ikabudsix/.ai/kernel-6x-roadmap-contract-2026-09-06.md — Phase 1 scope + the appended
   "Review round 1 ... + chair adjudication" section is your directive.
2. Review findings: /var/www/html/ikabudsix/test_results/review-sol.log (7 findings).
3. Governing rules: /var/www/html/ikabudsix/.github/instructions/ai-development-execution-handoff.instructions.md
   (bounded repair loop) + /var/www/html/ikabudsix/.github/copilot-instructions.md (check BOTH logs after runs).
4. Current state is verified green (chair reran: engine 32/32, lifecycle 12/12, concurrency 18/18, php -l clean).
   Do not regress these.

## Findings to FIX (with chair guidance)
1. start() empty-range race: when no active run row exists, two concurrent starts can both insert (no unique
   constraint). Add a MySQL-5.7-safe NO-DDL mutex that serializes creation for an absent (workflow_key, module,
   entity_type, entity_id) tuple — MySQL advisory lock (GET_LOCK/RELEASE_LOCK on the same connection used for the
   run-creation transaction, name scoped to the tuple) is the intended approach. On contention, re-read and return
   the winning active run_id deterministically. Do NOT add DDL/migration/unique constraint.
2. Add a real two-connection concurrency test where two start()/event deliveries race BEFORE any active run
   exists; assert exactly one active run row, one returned run id, one side effect. Extend the existing
   forked/two-connection pattern in tests/workflow_concurrency_test.php (no artificial sleeps).
3. cancel() can currently flip an in-flight 'running' step to 'cancelled' while its capability may still be
   executing, letting a later replay() reclaim it (double execution). Make cancel() take the same run guard
   (transaction + SELECT ... FOR UPDATE on the run row) and NEVER reclaim a step that is mid-flight 'running':
   refuse with an explicit busy/error (like advance/replay do) OR mark the run 'cancel_requested' and have
   advance() honor it only after the in-flight step settles. Choose minimal safe semantics; document.
4. Capability dispatch and completion persistence share one catch block (advance() ~L728-780): if the capability
   SUCCEEDS but the completion UPDATE fails (DB error), the catch marks the step failed/retryable -> the side
   effect runs again. Split the phases:
   - Dispatch phase: capability throws -> side effects did NOT run -> existing failed/retry_pending path is safe.
   - Persistence phase (completion UPDATE + recursion into next step): any failure AFTER a dispatch that may have
     executed MUST fail closed — transition the step to a distinct NON-retryable state (status is VARCHAR(50), so
     e.g. 'interrupted' needs NO DDL) or leave it 'running' with explicit logging; return an explicit error.
     advance()/replay() must never reclaim a step in that state. No silent skip, no double execution.

## Findings with chair rulings (do NOT revert; make visible)
5. Dispatch path -> KEEP `$this->app->cap()->call($capabilityId, $resolvedArgs)` (architect-approved correction:
   CapabilityRegistry has no call(); HEAD's capabilities()->call() would fatal on real dispatch). ADD a code
   comment above the dispatch explaining the canonical CapabilityBus path, and note it in docs/kernel/workflow-system.md.
6. Retention-helper guard -> KEEP the guarded include + function_exists('workflowRecordRunPayloadHash') guard
   (HEAD fatals on load; src/helpers/workflow-retention.php is absent in this repo). ADD a clear comment that the
   helper is absent in this distribution and payload-hash recording stays inert until it is restored (upstream
   sync gap — escalated separately, outside Phase 1).
7. Evidence -> capture RAW command outputs this round. Run each verification command and echo its full tail into
   your final report (not just a self-summary). Also leave a copy at test_results/implement-sol-r2.log if possible.

## Hard prohibitions (unchanged)
- Only kernel/WorkflowEngine.php + tests/ + docs/kernel/workflow-system.md. NO migration/DDL. NO MySQL 8-only SQL.
- No WorkflowRuntime semantics changes (it uses separate workflow_instances rows — leave untouched).
- No other roadmap phases; no .env/config/template changes. Do NOT run the full suite.

## Verification (run, capture real output)
- php -l kernel/WorkflowEngine.php and every touched test file.
- php tests/workflow_engine_test.php  (must stay 32/32)
- php tests/workflow_lifecycle_test.php (must stay 12/12)
- php tests/workflow_concurrency_test.php (existing 18/18 + your new empty-range race test)
- vendor/bin/phpstan analyse --level=6 --no-progress kernel/WorkflowEngine.php tests/workflow_concurrency_test.php
- git diff --check; grep changed code for MySQL-8-only constructs.
- Check BOTH storage/logs/app.log and storage/logs/error.log after runs (report sizes/errors found).
- Review your own git diff for scope compliance.

## Return format (final message; concise; file:line refs; raw test tails where requested)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
findings_addressed: (#1..#7 each: fixed/approved/how + file:line)
changed:            (files + file:line refs)
implementation_summary:
verification:       (real outputs/tails + counts)
risks:
unresolved:
recommended_next_state:  (should be REVIEW_REQUIRED if you believe all findings addressed)

Do NOT return empty. Do NOT report success without verification evidence. Honest status only.
