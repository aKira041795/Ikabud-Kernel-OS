# 6.3 — DURABLE IDEMPOTENCY (shared kernel primitive) — CONTRACT (adjudicated 2026-09-06)

task: Implement the next major Kernel OS 6.x objective (Consistency guarantee): durable end-to-end idempotency as
a SHARED kernel primitive over the existing `kernel_idempotency_keys` table, adopted FIRST by WorkflowEngine as a
caller-supplied external idempotency key with persisted-result reuse and payload-conflict detection. Per the
adjudicated debate, this must be a single kernel contract (claim/commit/release) — NOT a fourth bespoke seam.

objective: Let an external caller run a workflow exactly-once from its perspective: first request executes and
records the outcome; a duplicate request (same key, same payload) returns the SAME run id/result without re-execution;
a conflicting payload under the same key is rejected explicitly. This completes at-least-once transport with
controlled side-effect semantics — the precondition for payments/checkout/inventory/webhooks/AI (HARPP) as governed
WorkflowEngine consumers.

## Grounded seams (verified 2026-09-06)
- `migrations/011_kernel_idempotency_keys.sql` — `kernel_idempotency_keys` (idempotency_key_hash, tenant_id, status
  'processing'|'completed', response_json, created_at; hash = stable key hash). NO new DDL needed.
- `kernel/Http/Idempotency.php` — existing HTTP retry helper (check/store/release) over that table; the closest
  precedent for claim/commit semantics.
- `kernel/WorkflowEngine.php` — `start()` (synthetic step `idempotency_key` is a trace field, ~L638); Phase-1
  guards already prevent in-engine double execution. External-key RESULT REUSE is the master-contract follow-on.
- `kernel/EventBus.php` `fireDurable` carries an `idempotency_key` option; `kernel/IntegrationBridge.php` propagates
  it — these are the future adopters of the same primitive (see scope note).

## scope:
  allowed:
    - NEW shared kernel idempotency primitive over `kernel_idempotency_keys` (e.g. extend `kernel/Http/Idempotency.php`
      into a full claim/commit/release API with structured results, or add `kernel/Services/` primitive that HTTP
      helpers can delegate to). Primitive contract: claim(key, tenant, payloadHash) → {new|duplicate|conflict} atomically
      (processing row insert with unique hash+tenant); commit(key, tenant, outcomeJson) → completed; release(key) on
      failure → back to available. MySQL-5.7-safe (single INSERT ... atomic claim + guarded UPDATE; no window
      functions/CTEs/JSON_TABLE; reuse existing columns — NO DDL).
    - `kernel/WorkflowEngine.php` — `start()` gains an OPTIONAL caller-supplied external key (per-call or on the
      workflow/definition): first call executes + persists run outcome against the key; duplicate (same key + same
      normalized payload hash) returns the stored run_id/result with NO new run; conflicting payload → explicit
      reject result. Must preserve existing callers (no external key = current behaviour unchanged; synthetic step
      key stays a trace field). Return shapes stay additive (ok, run_id, deduplicated, conflict, result...).
    - Tests: new `tests/durable_idempotency_test.php` (plain-PHP pattern; two connections for the concurrent case).
      Cover: first executes; duplicate returns same run_id/result (no second run, one side effect); conflicting
      payload rejected; concurrent duplicates → exactly one run (atomic claim); no-external-key path unchanged
      (regression); tenant-scoped keys do not collide.
    - Doc: `docs/kernel/workflow-system.md` (external-key contract, conflict semantics, follow-on adopters).
  prohibited:
    - NO migration/DDL (reuse `kernel_idempotency_keys`); NO MySQL-8-only SQL.
    - NO change to Phase-1 concurrency/idempotency guards or existing return shapes for keyless callers.
    - NO change to WorkflowRuntime semantics; no other guarantees (6.4/6.5/6.6); no ARK/CMS; no full-suite runs.
    - Do NOT build a bespoke workflow-only key table or bypass the shared primitive.

### constraints:
  - Exactly-once-from-caller semantics: duplicate → same run/result; conflict → explicit reject; never silent.
  - Atomic claim must survive concurrent duplicates (unique hash+tenant claim, guarded on 'processing'/'completed').
  - Fail-open on primitive errors must be explicit (never silently double-execute or silently swallow a conflict).
  - PSR-12 + PHPStan level 6 (no new errors); read actual code first; check BOTH logs after runs.
  - Backward compatible: all existing workflow callers (no external key) behave identically.

### acceptance:
  - First `start(..., externalKey)` executes and commits an outcome; second call with the SAME key+payload returns
    the stored run_id/result, creates NO second run, and executes the capability exactly once (counted via a test
    capability).
  - Same key with a DIFFERENT normalized payload → explicit conflict result (no execution, no overwrite).
  - Two concurrent first calls (two connections) with the same key → exactly one run/execution; the loser receives
    the winner's outcome (deterministic, no sleeps).
  - External keys are tenant-scoped (same key text in different tenants does not collide).
  - No external key → current start() behaviour unchanged (keyless regression covered).
  - New test suite passes; existing Phase-1/Phase-2 suites stay green; php -l clean; PHPStan no new errors; both
    logs clean.

### verification:
  - php -l on changed files; new tests/durable_idempotency_test.php full output; targeted Phase-1 workflow suites
    (engine 32/32, lifecycle 12/12, concurrency 38/38) still green; PHPStan level 6 on changed files; git diff
    --check; logs before/after (error.log empty). Capture RAW output to test_results/phase63-evidence.log.

### risk:
  - Unifying HTTP/EventBus later may reveal contract mismatches — document the primitive so adopters converge
    (HTTP/EventBus adoption is a FOLLOW-ON unless a trivial seam exists; if found, do it and test it).
  - Payload-hash normalization (whitespace/ordering) must be deterministic — define normalization in the doc and use
    it consistently for conflict detection.
  - Concurrent duplicate under a missing unique constraint would double-claim — verify the 011 table has the unique
    (hash, tenant) constraint; if NOT, app-level advisory-lock (GET_LOCK) claim is required (NO DDL).

### status: IMPLEMENTED (Phase 6.3 baseline); Stabilization Gate 2 subsequently converged EventBus and the sole canonicalizer, while HTTP payload-aware adoption remains deferred to Gate 3.
