You are the /implement + /review agent for Ikabud Kernel OS (repo root /var/www/html/ikabudsix, main HEAD 57dd86f,
Gate 1 already merged). Execute **Stabilization Gate 2** per the authoritative contract:

    .ai/contract-stabilization-gate2-eventbus-2026-09-06.md

READ THE CONTRACT FIRST — it is small and authoritative. Do not broaden scope. It folds the 5-round multi-model
debate revisions #3 (envelope-less legacy rows never conflict), #4 (exactly ONE canonicalizer), #5 (tenant
validation contract), #8 (baseline = existing durable_idempotency_test + phase63 contract).

## Gate 2 preflight (record INSIDE the contract before writing code)
- fireDurable at kernel/EventBus.php:335-408 is dead: requires absent src/helpers/durable-event-outbox.php. Confirm
  zero production callers (grep fireDurable — only kernel/Contracts/EventBusContract.php:23 + docblock
  EventBus.php:37). Record this.
- kernel_idempotency_keys (migrations/011_kernel_idempotency_keys.sql): tenant_id INT UNSIGNED NOT NULL,
  status ENUM('processing','completed'), unique (idempotency_key_hash, tenant_id), response_json LONGTEXT NULL.
- Idempotency primitive: kernel/Http/Idempotency.php — claim/commit/release/check/store + private observe(),
  encodeEnvelope()/decodeEnvelope(), lock discipline (GET_LOCK/IS_USED_LOCK/RELEASE_LOCK, WAIT_CAP_SECONDS=300,
  LOCK_RETRY_SECONDS=2), db() via app()->db().
- The single existing canonicalizer: WorkflowEngine.php:1196-1231 externalPayloadHash()/canonicalizeIdempotencyValue().
- TenantResolver.php:106 resolve(?array) / :133 current() — both ?int; NO scalar resolver.
- Baseline tests (run BEFORE): tests/durable_idempotency_test.php (30/30), workflow_engine_test, workflow_concurrency_test,
  workflow_lifecycle_test, workbench_workflow_guard_audit_test, workflow_runtime_guard_test, capability_authority_audit_test,
  capability_audit_test. .ai/phase63-durable-idempotency-contract-2026-09-06.md is the phase-63 baseline doc.

## Deliverables (all within contract scope)
1. **New migration** (MySQL 5.7, InnoDB utf8mb4_unicode_ci): `kernel_durable_event_outbox` — integer auto-inc row id,
   tenant_id INT UNSIGNED NOT NULL (type-matched to kernel_idempotency_keys), event envelope + delivery metadata
   columns, created_at index. Register per repo migration convention. Idempotent.
2. **kernel/Http/Idempotency.php**: add the SOLE `canonicalPayloadHash(mixed $value): string` — extracted byte-identical
   from WorkflowEngine (object→get_object_vars, resource rejection, ksort SORT_STRING assoc, array_is_list preserves
   list order, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION,
   sha256 lowercase; int 1 vs float 1.0 distinct). Then REFACTOR WorkflowEngine::externalPayloadHash() to call it —
   exactly ONE canonicalizer in the repo (grep-verifiable).
3. **Idempotency::observe()/decodeEnvelope()** (REVISION #3): envelope-less rows — processing → `in_progress`,
   completed → `duplicate` with decoded plain-JSON outcome; NEVER `conflict`. Regression-scope to WorkflowEngine.
4. **kernel/EventBus.php fireDurable rewrite** (signature + `: ?int` preserved; EventBusContract untouched):
   - Validate opts['tenant_id'] as positive int (cast), agree with TenantResolver::current() (or resolve(current
     user) per tenant mode) — reject before any write on missing/unresolved/mismatch/≤0 (REVISION #5).
   - KEYED (opts['idempotency_key'] present): reject supplied PDO already in a transaction; claim with THAT PDO using
     the caller-supplied key + canonicalPayloadHash of envelope {tenant_id:int, event_name, payload, source,
     actor_id, actor_role, request_id, event_id} (absent optionals as null). new → insert exactly ONE outbox row,
     commit(outcome = outbox row int, same PDO). duplicate → return stored int, no write. conflict/in_progress →
     fail explicitly. NEVER app()->db() for claim/commit/release in keyed path.
   - KEYLESS: insert exactly ONE outbox row, no idempotency claim, never commit/rollback caller transaction.
   - Failure discipline: release only on certain pre-publication failure; ambiguity → leave processing. Log via
     write_log. If the outbox table is missing at runtime, fail closed with a clear log.
5. **Tests** (plain-PHP style like tests/durable_idempotency_test.php): canonicalizer unit (assoc reorder dedup,
   list order significant, int1 vs float1.0 distinct, object handling, resource rejection); keyed fireDurable
   duplicate-replay single-row concurrency; changed-payload conflict; keyless single-row + caller-txn untouched;
   legacy envelope-less check()/store() rows observe in_progress/duplicate never conflict (regression to
   WorkflowEngine); same-PDO vs wrong-PDO lock ownership; keyed open-transaction rejection; tenant isolation + tenant
   rejection; outbox migration applied + row written.
6. **Docs**: workflow-system.md:369 note + .ai roadmap/plan notes record Gate 2 resolution (roadmap five-guarantees
   follow-up (b) → resolved).
7. Append result to .ai/contract-stabilization-gate2-eventbus-2026-09-06.md (status → IMPLEMENTED).

## Verification (do all)
- php -l on every touched PHP file.
- Baseline tests before/after; new Gate-2 tests; full workflow suite; capability_authority_audit_test +
  capability_audit_test (baselines unchanged). `composer test` full at end.
- grep: exactly ONE canonicalizer implementation (only Idempotency::canonicalPayloadHash owns the algorithm).
- BOTH storage/logs/app.log AND storage/logs/error.log clean after every run.
- No PHPStan baseline additions. git diff --stat within allowed scope only.

## Constraints
- Bounded repair: max ~3 rounds. If any preflight item is unprovable (outbox placement/ownership, tenant agreement,
  migration registration, same-PDO DB identity, caller compatibility), STOP → return BLOCKED with evidence. Do NOT
  invent architecture or add a bridge.
- Do NOT weaken the workflow suite or the capability authority baselines to pass.

## Report (compact result block)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
preflight_recorded: (outbox schema, tenant agreement API, migration path, dead-path evidence)
changed:
implementation_summary: (bullets)
verification: (test names + pass counts, log status, single-canonicalizer grep)
scope: unexpected_files
risks:
unresolved:
recommended_next_state:
