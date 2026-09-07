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
