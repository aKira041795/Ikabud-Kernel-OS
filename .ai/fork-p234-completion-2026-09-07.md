# CMS Akira Fork — P2/P3/P4 Completion Record (2026-09-07)

## P2 — Kernel 6.x mutation + invalidation proof: COMPLETE (verified PASS)
- `cms.post.create@1`/`cms.post.update@1` (admin-only) with `requires_protocol:"v2"` +
  `effects.invalidates:["entity.list.post"]` (single tag → `invalidateEntityCache('post', tenant)`).
- Handlers: tenant/actor from kernel context only (payload tenant_id/role/JWT claims rejected); canonical slug;
  status/published_at; caller-managed transaction using `kernel.idempotency.{hash,claim,commit,release}@1` +
  `kernel.audit.record@1` (correlation_id); duplicate=replay, conflict=409, in_progress=425/Retry-After; release only
  on certain pre-publication failure.
- Activation idempotently seeds the admin-only v2 policy via `CapabilityAuthorizationRegistry::seedPolicy()`
  (kernel escalation; no KernelPDO bypass). Named non-GET routes `POST /api/v1/cms-akira/posts` +
  `PUT /api/v1/cms-akira/posts/{slug}` with `app()->csrfEnforce()`.
- Evidence: P2 `post_mutation_test` 26/26 · P1 38/38 · authority 18/18 · composer 105/105 · theme validate clean ·
  logs 0 bytes · policy table = 2 admin/v2/active rows (idempotent).
- The reinforcement loop is proven: `CMS mutation → CapabilityBus (authority, tenant, effects.invalidates,
  evidence) → Entity cache invalidated → next ARK render is fresh`.

## Kernel gates merged to make P2 possible (all 6/6 CI)
#27 ARK renderer-selection runtime · #28 EntityViewResolver @1-fallback symmetry · #31 auditor skips disabled
module dirs · #33 kernel-owned idempotency capability bridge · #34 capability_authorization_policies migration +
requires_protocol propagation.

## P3 — ARK authority ADR: RATIFIED
- `docs/architecture/ark-authority-adr.md` — ARK MAY OWN (renderer selection, tokens, layouts, slots, controls,
  customization schema, builder profiles) / ARK MAY NOT OWN (entities, SQL, authorization, business calc, module
  caps, entity truth, Entity View contracts, kernel governance).

## P4 — DEFERRED MARKER (no build now)
- Builder-as-composition-editor reconnect over the shared ARK engine (existing React/Vite builder repositions to
  edit valid ARK composition — not per-theme engines).
- Re-enable the 13 dormant cms-akira members individually (each must re-pass the authority audit + its own gate
  before activation).
- Real-module production adoption (daily-ledger/mobile seam on the P3 HTTP idempotency surface; CMS/Ecommerce/WMS/
  Guidance/Ledger/HARPP inside the five guarantees) = MAIN-CMS-REPO milestone.
- Recorded follow-ups (infra, not fork blockers): dedicated-tenant provisioning parity (kernel_idempotency_keys
  011, outbox 015, capability_authorization_policies 016, audit) when APP_MULTI_TENANT_ENABLED=1.

## Fork state (working tree — modules/* + storage/cms-themes are gitignored runtime units)
- `modules/cms-akira/cms-akira-core`: content authority (cms.post.get/list/create/update@1), versioned Entity View
  bridges (entity.list.post@1/entity.get.post@1), P1 render routes (/posts, /posts/{slug}) + P2 mutation routes
  (POST/PUT /api/v1/cms-akira/posts[/{slug}]), 13 dormant members `_enabled:false`.
- `storage/cms-themes/cms-akira-posts`: ARK theme (renderer-registry.json: entity.list.post→article-grid,
  entity.detail.post→article-page; tokens; manifest; article-grid/article-page DiSyL).
- The canonical Post path is real end-to-end:
  DB → cms.post.*@1 → entity.*.post@1 (Entity View bridge → DTO) → ArkRendererResolver (renderer-registry.json) →
  article-*.disyl (DiSyL) → HTML; and mutations invalidate the Entity cache so the next ARK render is fresh.
