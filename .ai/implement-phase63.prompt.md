# /implement — 6.3 DURABLE IDEMPOTENCY (shared kernel primitive) — Codex Sol

You are the /implement agent on Codex Sol. Working directory: /var/www/html/ikabudsix (Ikabud-Kernel-OS).
Execute objective 6.3 of the adjudicated five-guarantee roadmap: durable end-to-end idempotency as a SHARED kernel
primitive over kernel_idempotency_keys, adopted first by WorkflowEngine as a caller-supplied external idempotency
key with persisted-result reuse + payload-conflict detection. Real edits + real tests.

## Authority & rules (read first)
1. Contracts (AUTHORITATIVE): /var/www/html/ikabudsix/.ai/phase63-durable-idempotency-contract-2026-09-06.md
   and /var/www/html/ikabudsix/.ai/kernel-6x-five-guarantees-roadmap-2026-09-06.md. Follow scope/constraints/
   acceptance/verification/risk exactly.
2. Governing: /var/www/html/ikabudsix/.github/instructions/ai-development-execution-handoff.instructions.md
   (bounded repair, result format) + .github/copilot-instructions.md (check BOTH storage/logs/app.log and
   storage/logs/error.log after every run).
3. Read the ACTUAL code (never assume): migrations/011_kernel_idempotency_keys.sql (verify the unique constraint on
   hash+tenant; if absent you MUST use an app-level GET_LOCK advisory claim — NO DDL), kernel/Http/Idempotency.php,
   kernel/WorkflowEngine.php start()/advance() (Phase-1 guards + synthetic step idempotency_key),
   kernel/EventBus.php fireDurable, kernel/IntegrationBridge.php idempotency propagation, tests/workflow_engine_test.php
   + tests/workflow_concurrency_test.php (patterns).

## Task (single objective)
1. Build the SHARED kernel idempotency primitive over kernel_idempotency_keys: atomic claim(key, tenant, payloadHash)
   → {new|duplicate|conflict}; commit(key, tenant, outcomeJson); release(key) on failure. Reuse existing columns,
   NO DDL. MySQL-5.7-safe. Where the primitive lives: prefer extending/extracting kernel/Http/Idempotency.php into a
   full claim/commit/release API (structured results) so HTTP can delegate later — do NOT fork a bespoke workflow key
   table. Verify the 011 unique constraint; if missing, implement the claim with GET_LOCK advisory serialization.
2. WorkflowEngine::start() gains an OPTIONAL caller-supplied external key: first call executes + commits outcome to
   the key; duplicate (same key + same NORMALIZED payload hash) returns the stored run_id/result with NO new run and
   NO re-execution; conflicting payload → explicit conflict result. Deterministic payload-hash normalization
   documented and used consistently. NO external key → current behaviour unchanged (backward compatible).
3. Keep the synthetic step idempotency_key as a trace field. Return shapes additive.
4. Tests: new tests/durable_idempotency_test.php (plain-PHP; two connections for concurrent duplicate):
   first executes; duplicate same key+payload → same run_id/result, one side effect, no second run; conflict
   (different payload) → rejected, no execution; concurrent duplicates → exactly one run; keyless path unchanged;
   tenant-scoped keys don't collide. Do NOT use artificial sleeps; real two-connection claim.
5. Doc: docs/kernel/workflow-system.md (external-key contract, conflict semantics, normalization, follow-on adopters:
   HTTP Idempotency + EventBus fireDurable unify onto the primitive — implement+test ONLY if a trivial seam exists,
   else document as follow-on).

## Prohibitions (unchanged)
- NO migration/DDL. NO MySQL-8-only SQL. NO WorkflowRuntime semantics change. NO other guarantees (6.4/6.5/6.6).
  NO ARK/CMS. NO full-suite runs. Only: the primitive file(s) + kernel/WorkflowEngine.php + tests/ + docs/kernel/workflow-system.md.

## Verification (capture RAW to test_results/phase63-evidence.log)
- php -l on changed files; full output of tests/durable_idempotency_test.php; re-run targeted Phase-1 workflow
  suites (tests/workflow_engine_test.php 32/32, tests/workflow_lifecycle_test.php 12/12,
  tests/workflow_concurrency_test.php 38/38) full output; vendor/bin/phpstan analyse --level=6 --no-progress on
  changed files; git diff --check; git diff --name-only/--stat; logs before/after (error.log empty).

## Return format (final message; concise; file:line refs)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:              (files + file:line refs)
implementation_summary: (primitive claim/commit/release, unique-constraint finding, workflow external-key path,
                         normalization, conflict semantics, backward compatibility)
verification:         (point to test_results/phase63-evidence.log; summarize counts)
risks:
unresolved:
recommended_next_state: (REVIEW_REQUIRED if done per contract)
Do NOT return empty. Do NOT report success without the raw evidence file complete.
