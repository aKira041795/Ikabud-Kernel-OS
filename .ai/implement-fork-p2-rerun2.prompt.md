You are the /implement + /review agent for the **CMS Akira fork P2 gate** in Ikabud Kernel OS 6.x
(repo root /var/www/html/ikabudsix, main ad250b3; fork P1 complete in the working tree). Execute P2 per the
authoritative approved contract:

    .ai/contract-fork-p2-2026-09-07.md

READ IT FIRST (plus the fork contract + chair adjudication it references). This is the P2 build gate — the Kernel
6.x mutation + invalidation proof. Feature dev frozen. Do not broaden.

## Work location + state (verify before coding)
- Work target: `/var/www/html/ikabudsix/modules/cms-akira/cms-akira-core/` + `storage/cms-themes/cms-akira-posts/`
  (P1 state present: content authority cms.post.get/list@1, versioned bridges entity.list.post@1/entity.get.post@1,
  view contracts, routes /posts + /posts/{slug}, theme, 13 dormant members _enabled:false).
- Kernel READ-ONLY. All kernel machinery already merged: CapabilityBus effects.invalidates (see
  kernel/Capabilities/CapabilityBus.php — effects.invalidates post-success, mode-aware), Idempotency primitive
  (kernel/Http/Idempotency.php — claim/commit/release/canonicalPayloadHash; WAIT_CAP_SECONDS), EntityViewResolver
  invalidateEntityCache (kernel/EntityContext/EntityViewResolver.php:62-80), Entity Authority registry
  (kernel/EntityAuthority/EntityAuthorityRegistry.php), CapabilityAuthorizationRegistry.
- Pristine fork baseline for diff: /var/www/html/applicationostest/modules/cms-akira. modules/* gitignored (runtime).
- Reference mutation patterns already in this repo: modules/daily-ledger handlers (authorizeBranch + write via
  module()->db()), and the stabilization WorkflowEngine/EventBus use of Idempotency claim/commit/release on a PDO.

## P2 deliverables (contract scope — honor exactly; incl. the R4 preflight recorded BEFORE code)
See the contract. Summary: cms.post.create@1 + cms.post.update@1 (admin-only, CapabilityAuthorizationRegistry +
Entity Authority, kernel-context tenant/actor only); effects.invalidates ["entity.list.post"] (single tag); durable
idempotency single-connection sequence (BEGIN → claim → write + audit(correlation_id) → commit → COMMIT, fail-closed
semantics); R4 preflight naming the audit destination + proving same-PDO/DB topology OR documenting cross-DB
recovery; R7 named non-GET mutation route + kernel CSRF; P2 tests; docs note; append result to the contract.

## Verification (do all)
- php -l every touched file; json parse; theme validate still clean; both logs clean after every run.
- New P2 tests (cache-then-mutate-then-render freshness; idempotency replay/conflict/concurrency/no-invalidate-on-
  failure; authorization/CSRF/tenant-spoof/JWT-injection; audit correlation_id + topology).
- P1 test still green: php modules/cms-akira/cms-akira-core/tests/post_read_render_test.php (38/38).
- php tests/capability_authority_audit_test.php → 18/18; full composer test green.
- diff -rq against pristine = intended P1+P2 deltas only.

## Constraints
- Bounded repair ~3 rounds. If the R4 DB-topology preflight cannot be proven (posts vs idempotency vs audit on one
  connection), STOP → return BLOCKED with the concrete topology evidence + options (do NOT invent a bridge or new
  audit table). If a genuine NEW kernel gap is found, STOP → BLOCKED (do not silently edit kernel).
- Do NOT weaken published-only reads, baselines, or P1 behavior.

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
preflight_recorded: (audit destination named, DB topology proven/documented)
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state:
# P2 RE-RUN ADDENDUM — chair adjudication applied (re-read the amended contract tail)

The P2 contract `.ai/contract-fork-p2-2026-09-07.md` now carries a **CHAIR ADJUDICATION** section (P2 BLOCK #1
resolved). Apply it exactly:

1. **Prove P2 on the repo's actual validated runtime = SINGLE-TENANT app DB** (`APP_MULTI_TENANT_ENABLED=0` in both
   .env + .env.ci; DB_DATABASE = CONTROL_DB_DATABASE = ikabudsix; config/app.php: multi-tenancy optional, NOT the
   default target). Your own preflight confirmed the base DB `ikabudsix` contains ALL THREE tables on one
   connection (cms_akira_posts [add on provisioning] + kernel_idempotency_keys + audit_logs). The same-PDO
   `BEGIN → Idempotency::claim → Post write + audit(correlation_id) → commit → COMMIT` sequence IS provable there.
2. **Do NOT treat the dl1-5 dedicated tenant DBs as the target** — they are daily-ledger's local tenant-local dev
   DBs, not the kernel's default/CI runtime. The tenant-provisioning parity gap (kernel idempotency/outbox + audit +
   capability_authorization_policies absent in dedicated tenant DBs) is a REAL recorded follow-up for the
   tenant-provisioning workstream, NOT a P2 blocker.
3. **Use the legal single-tenant path** you confirmed: mutation handlers run in the guarded module context but the
   kernel allows `app()->db()` under kernel escalation to participate in the caller-managed transaction when the
   tables coexist on the one connection. Wire the P2 mutation to that path. If the guarded module context genuinely
   forbids the idempotency table access even on the single app DB, identify the narrowest legal kernel seam and STOP
   → BLOCKED with that single-tenant-specific evidence (do NOT silently edit kernel).
4. Record the follow-up in the contract preflight (one paragraph) and implement P2 exactly as the (amended) contract
   specifies: cms.post.create@1/update@1 (admin-only, CapabilityAuthorizationRegistry + Entity Authority,
   kernel-context tenant/actor, canonical slug, status/published_at), effects.invalidates ["entity.list.post"],
   durable idempotency + audit(correlation_id), R7 named non-GET mutation route + kernel CSRF, and the full P2 test
   set (cache-then-mutate-then-render freshness; idempotency replay/conflict/concurrency/no-invalidate-on-failure;
   authorization/CSRF/tenant-spoof/JWT-injection; audit correlation_id).

Verification: P2 tests green; P1 test 38/38 still green; php tests/capability_authority_audit_test.php 18/18; full
composer test green; both logs clean; diff vs pristine = intended P1+P2 deltas only.

Report the compact result block. recommended_next_state should say P2-PASS → P3 ADR / P4 marker ready if green.
# P2 ADDENDUM #2 — consume the merged kernel idempotency capability bridge

Merged main 9e49602 (PR #33). The guarded-module blocker is resolved: modules can now participate in durable
idempotency on their OWN caller-managed transaction via kernel-owned capabilities:
`kernel.idempotency.claim@1` / `commit@1` / `release@1` / `hash@1` (kernel provider; exact caller-app-PDO +
current positive tenant enforced; transaction ownership stays with the caller; `Idempotency` primitive unchanged).
Focused test 14/14; composer 104/104.

## Amend the P2 mutation path
1. In `cms-akira-core`'s `cms.post.create@1` / `cms.post.update@1` handlers, do NOT call `Idempotency::*` directly
   (KernelPDO denies module access). Instead call the kernel capabilities via `app()->cap()->call(...)`:
   - `kernel.idempotency.hash@1` { value: <canonical envelope> } → payload hash (single canonicalizer).
   - `kernel.idempotency.claim@1` { key, tenant_id, payload_hash } → new/duplicate/conflict/in_progress.
   - on new → Post write + audit (via kernel.audit.record@1 with correlation_id) on the same transaction →
     `kernel.idempotency.commit@1` { key, tenant_id, outcome } → COMMIT.
   - duplicate → replay stored outcome; conflict → 409; in_progress → 425/Retry-After (documented mapping).
   - release only on certain pre-publication failure (kernel.idempotency.release@1).
   The handler begins the transaction on the app PDO (single-tenant runtime); the kernel capabilities run under
   kernel escalation on that same connection. Never commit/rollback from inside a capability call.
2. Keep everything else from the amended P2 contract + addendum-r4 exactly: cms.post.create@1/update@1 admin-only
   (CapabilityAuthorizationRegistry + Entity Authority), effects.invalidates ["entity.list.post"] (single tag →
   invalidateEntityCache('post', tenant)), R7 named non-GET mutation route + kernel CSRF, tenant/actor from kernel
   context only, canonical slug, status/published_at, P2 tests (cache-then-mutate-then-render freshness;
   idempotency replay/conflict/concurrency/no-invalidate-on-failure; authorization/CSRF/tenant-spoof/JWT-injection;
   audit correlation_id).
3. Single-tenant app DB (ikabudsix) is the target runtime (APP_MULTI_TENANT_ENABLED=0; config: multi-tenant
   optional, NOT default). The dedicated-tenant-DB provisioning parity gap is already recorded as a follow-up.

Verification: P2 tests green; P1 post_read_render_test 38/38 green; capability_authority_audit 18/18; full
composer test green; both logs clean; diff vs pristine = intended P1+P2 deltas only.

Report the compact result block. recommended_next_state: P2-PASS → P3 ADR / P4 marker if green.
