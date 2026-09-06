# Stabilization Gate 2 — EventBus `fireDurable` → shared Idempotency primitive + sanctioned outbox

task: Repair the currently non-functional `EventBus::fireDurable` (kernel/EventBus.php:335-408) and converge
keyed durable events onto the SHARED `Idempotency` claim/commit/release primitive over tenant-local
`kernel_idempotency_keys`, with ONE sanctioned durable-event outbox table and exactly ONE canonicalizer.

objective: fireDurable executes for keyed AND keyless calls against the single Kernel-owned outbox; keyed calls are
payload-conflict idempotent through the shared primitive; envelope-less legacy rows never conflict; WorkflowEngine
regression stays green; capability:audit/Workbench baselines stay zero-exception.

Baseline (REVISION #8 — NOT greenfield):
- `tests/durable_idempotency_test.php` passes 30/30 at main 57dd86f.
- `.ai/phase63-durable-idempotency-contract-2026-09-06.md` encodes the primitive+WorkflowEngine contract.
- Run both before and after implementation.

scope:
  allowed:
    - kernel/EventBus.php — rewrite fireDurable body (signature/return preserved: `: ?int`)
    - kernel/Http/Idempotency.php — add sole `canonicalPayloadHash()`; fix `observe()` envelope-less semantics
    - kernel/WorkflowEngine.php — refactor to call the single canonicalizer (revision #4)
    - migrations/ — NEW MySQL 5.7 migration: `kernel_durable_event_outbox` (sole sanctioned durable-event store)
    - tests/ — new/updated tests for outbox + convergence + legacy rows + concurrency
    - docs — roadmap/plan notes record Gate 2 resolution; workflow-system.md:369 note updated
    - .ai — this contract + phase63 contract reconciled as baseline
  prohibited:
    - NO second idempotency table, claim algorithm, canonicalizer, or derived claim key
    - NO use of the outbox AS an idempotency store (idempotency lives only in kernel_idempotency_keys)
    - NO cross-module/DB access, no cross-tenant connection, no app()->db() in KEYED fireDurable claim/commit/release
    - NO automatic takeover, NO worker/dispatcher beyond preserving required delivery metadata
    - NO conflict-state schema churn on kernel_idempotency_keys (status ENUM stays processing/completed)
    - NO unrelated EventBus/HTTP/capability/module changes. NO-BROADEN.

constraints:
  - REVISION #4 (ONE canonicalizer): add `Idempotency::canonicalPayloadHash(mixed $value): string` EXTRACTED
    byte-identical from the EXISTING `WorkflowEngine::canonicalizeIdempotencyValue()` + hash step
    (kernel/WorkflowEngine.php:1196-1231: object→get_object_vars, resource rejection, ksort SORT_STRING for assoc,
    array_is_list preserves order, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|
    JSON_PRESERVE_ZERO_FRACTION, sha256 lowercase). Then REFACTOR WorkflowEngine::externalPayloadHash() to CALL
    `Idempotency::canonicalPayloadHash(...)` so exactly one canonicalizer exists. Pre-existing stored payload_hash
    values must remain byte-compatible (no conflict misclassification).
  - REVISION #5 (tenant contract): NO scalar tenant resolver exists (TenantResolver::resolve() is user-scoped,
    current() is ambient — kernel/TenantResolver.php:106,133). In fireDurable, validate `opts['tenant_id']` as a
    positive int, cast to int, and require agreement with `TenantResolver::current()` (or `resolve(current user)`
    per tenant mode). claim() already rejects ≤0. Missing/unresolved/mismatched → reject BEFORE any claim/write.
  - REVISION #3 (legacy envelope-less rows): legacy `check()`/`store()` write rows with NULL/plain-JSON
    response_json (no `_kernel_idempotency` envelope → decodeEnvelope() returns []). Current observe() would return
    `conflict` for them. Change observe()/decodeEnvelope() so envelope-less `processing` rows observe as
    `in_progress` and envelope-less `completed` rows observe as `duplicate` with their decoded plain-JSON outcome;
    their unknowable fingerprint NEVER conflicts. Regression-scope this to WorkflowEngine (start/commit/release/
    conflict/retry must stay green — durable_idempotency_test + workflow_engine_test + workflow_concurrency_test).
  - Dead-path repair evidence: fireDurable has ZERO production callers (only EventBusContract.php:23 and the
    docblock example EventBus.php:37). The absent `src/helpers/durable-event-outbox.php`/`writeDurableEventOutbox()`
    helper is NOT restored as a parallel store. Record this evidence in the contract.
  - Durable store: new `kernel_durable_event_outbox` (MySQL 5.7: ENGINE=InnoDB utf8mb4_unicode_ci, integer auto-inc
    row id, `tenant_id INT UNSIGNED NOT NULL` matching kernel_idempotency_keys type, delivery metadata + event
    envelope columns, created_at index). Confirm migration registration/location and physical DB per repo convention
    (kernel migrations run on app/tenant DBs appropriately) before writing. Keyless AND keyed both write ONLY this
    table for the event row; kernel_idempotency_keys holds only the claim/outcome.
  - Keyed contract: claim key = caller-supplied `opts['idempotency_key']`. Payload hash = canonicalPayloadHash of
    envelope {tenant_id:int, event_name, payload, source, actor_id, actor_role, request_id, event_id}, absent
    optionals as null.
  - Keyed execution: reject supplied PDO already in a transaction (MySQL 5.7: check via
    `inTransaction()`/`SELECT @@in_transaction` per connection); claim with THAT PDO; on `new` insert exactly ONE
    outbox row and `commit($key, $tenantId, <outbox row id int>, $samePdo)`; on `duplicate` return stored int
    WITHOUT writing; `conflict`/`in_progress` fail explicitly (throw/return without writing). Preserve EventBusContract.
  - Keyless execution: write exactly ONE outbox row without any idempotency claim; preserve caller-owned transaction;
    never commit or roll back the caller transaction.
  - Connection/failure discipline: keyed claim/commit/release use the IDENTICAL supplied PDO (app()->db() default
    forbidden). Release only after a certain pre-publication failure with zero possible outbox rows. Connection loss,
    timeout, uncertain execute, successful insert, or possible publication → leave processing for reconciliation.
  - Schema-availability guard: if the outbox table does not exist on the supplied PDO's DB at runtime, fail closed
    with a clear logged error (no silent keyless write) — but migrations must be applied by CI/test bootstrap.

acceptance:
  - fireDurable keyed: concurrent duplicate calls create ONE outbox row and replay the committed integer row id;
    reordered associative payloads deduplicate; changed payload → conflict; list order + int-vs-float(1 vs 1.0)
    distinctions remain significant.
  - fireDurable keyless: exactly one outbox row, no idempotency claim, caller transaction untouched.
  - Envelope-less processing/completed rows observe in_progress/duplicate (never conflict) — legacy check/store rows
    do not break claim()/observe(); WorkflowEngine start/commit/release/conflict/retry + legacy-row regressions pass.
  - Same-PDO lock ownership: commit/release with a DIFFERENT PDO is rejected; wrong-PDO test present.
  - Tenant isolation: tenant A cannot observe tenant B (kernel_idempotency_keys + outbox both tenant-scoped);
    unresolved/mismatched/≤0 tenant rejects before any write.
  - Migration valid MySQL 5.7 (no CTEs/window functions/JSON_TABLE; InnoDB; FK/type consistency); migration
    idempotent + registered per repo convention.
  - Tests green: durable_idempotency_test (30), workflow_engine_test, workflow_concurrency_test, workflow_lifecycle_test,
    workbench_workflow_guard_audit_test, workflow_runtime_guard_test + NEW outbox/convergence/legacy/concurrency tests.
    capability:audit (18) + functional audit (4) still pass. Zero findings in both logs.
  - Exactly ONE canonicalizer implementation in the repo (grep-verifiable); WorkflowEngine calls Idempotency's.
  - No PHPStan baseline additions; php -l clean.

verification:
  - `php tests/durable_idempotency_test.php` (baseline BEFORE + AFTER)
  - new tests: tests/eventbus_durable_outbox_test.php + canonicalizer unit + legacy-row + concurrency + wrong-PDO
  - `php tests/workflow_engine_test.php` + `workflow_concurrency_test.php` + `workflow_lifecycle_test.php`
  - capability_authority_audit_test + capability_audit_test (unchanged baselines)
  - grep for duplicate canonicalizer: only Idempotency::canonicalPayloadHash may contain the algorithm
  - `composer test` full at end; BOTH logs clean after each run

risk:
  - MEDIUM. Adds a new table (first DDL since stabilization began) — explicitly sanctioned by the approved contract;
    outbox is durable-event storage only, idempotency stays in kernel_idempotency_keys. WorkflowEngine refactor to
    the shared canonicalizer is the main regression surface — mitigated by the byte-identical extraction + full
    workflow suite. Legacy envelope-less semantics change only what was previously an incorrect `conflict` result.

unresolved:
  - outbox table column set / delivery metadata + migration registration path + exact tenant agreement API
    (current() vs resolve(current user) under APP_MULTI_TENANT_ENABLED) → implementer records these in a preflight
    note inside the contract BEFORE writing code; STILL BLOCKED if not provable.

## Implementation preflight (recorded before code, 2026-09-06)
- **Dead path/callers:** repository-wide `grep fireDurable` finds no production invocation: only
  `kernel/Contracts/EventBusContract.php:23`, the `kernel/EventBus.php:37` docblock example, and the method itself
  (other matches are planning/test-result prose). `src/helpers/durable-event-outbox.php` is absent, so the current
  unconditional require makes the path non-functional. There is no caller return handling, tenant representation,
  or duplicate-exception dependency to preserve.
- **Existing idempotency store/primitive:** migration 011 defines tenant-local `kernel_idempotency_keys` with
  `tenant_id INT UNSIGNED NOT NULL`, `ENUM('processing','completed')`, nullable `LONGTEXT response_json`, and unique
  `(idempotency_key_hash, tenant_id)`. `Idempotency` owns claim/commit/release/check/store, envelope encoding,
  observation, and the connection-scoped `GET_LOCK`/`IS_USED_LOCK`/`RELEASE_LOCK` discipline (300-second cap,
  two-second retries). Its optional PDO parameter is the proven same-connection seam; keyed EventBus calls will pass
  the caller PDO explicitly for every primitive operation, and direct outbox SQL will use that identical object.
- **Tenant agreement API:** no scalar resolver exists. `TenantResolver::resolve(?array): ?int` is user-scoped and
  `current(): ?int` is the ambient resolved identity. `fireDurable` will cast the supplied option to int, require it
  to be positive, and require exact agreement with `app()->tenant()->current()` before schema checks, claims, or
  writes. Missing application/tenant context, unresolved current tenant, and mismatch fail closed.
- **Migration registration/physical ownership:** numbered SQL files directly under `migrations/` are auto-discovered
  by `MigrationRunner` as `_kernel`; `migrateAll()` runs them first, and `TenantProvisioner` runs those kernel
  migrations against each supplied tenant PDO. Therefore migration 015 is the registered tenant/application-DB
  location shared by direct supplied-PDO access to both idempotency and outbox tables; no manifest bridge is needed.
- **Sanctioned outbox schema:** `kernel_durable_event_outbox` is Kernel-owned InnoDB/utf8mb4_unicode_ci with
  `BIGINT UNSIGNED` auto-increment `id`, type-matched `tenant_id INT UNSIGNED NOT NULL`; envelope columns
  `event_name`, `payload_json`, `source`, nullable `actor_id`, `actor_role`, `request_id`, `event_id`, and
  `idempotency_key`; delivery metadata `status`, `attempts`, `available_at`, `delivered_at`, `last_error`,
  `created_at`, `updated_at`; and a created-at index (plus a pending-delivery index). It is event storage only;
  `kernel_idempotency_keys` remains the sole keyed claim/outcome store.
- **Canonicalizer/baseline:** the only existing algorithm is `WorkflowEngine::externalPayloadHash()` plus
  `canonicalizeIdempotencyValue()` at the contracted lines and will be moved byte-for-byte to `Idempotency`.
  Pre-change baseline passed: durable idempotency 30/30, workflow engine 32/32, concurrency 38/38, lifecycle 12/12,
  Workbench workflow guard 35/35, runtime guard 11/11, capability authority 18/18, and capability audit 4/4; both
  logs had zero warning/error/critical findings (informational workflow records only) and were cleared afterward.

## Implementation result (2026-09-06)
- Added registered migration 015 and the sole Kernel durable-event outbox; migration application and repeat-run
  idempotence are covered by the Gate-2 test.
- `fireDurable` now validates ambient tenant agreement before access, writes keyed/keyless envelopes on the supplied
  PDO, rejects keyed open transactions, and uses that exact PDO for caller-key claim/commit/release. Keyed duplicates
  replay one integer row ID; conflicts/in-progress fail explicitly; uncertain publication leaves processing.
- Canonicalization now has one owner (`Idempotency::canonicalPayloadHash`), with WorkflowEngine delegated to it.
  Legacy envelope-less processing/completed rows now observe as in-progress/plain-outcome duplicate, never conflict.
- Verification: canonicalizer 5/5; Gate-2 outbox/concurrency/legacy/tenant/ownership suite 22/22; durable baseline
  30/30; workflow engine 32/32, concurrency 38/38, lifecycle 12/12, Workbench guard 35/35, runtime guard 11/11;
  capability authority 18/18 and functional capability audit 4/4. Full `composer test`: 100/100 files. Touched PHP
  lint and targeted PHPStan pass; no baseline additions; canonicalizer grep has one implementation; both logs have
  zero warning/error/critical findings and `error.log` is empty.

status: IMPLEMENTED
