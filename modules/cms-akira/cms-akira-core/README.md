# CMS Akira Core

cms-akira-core is a CMS Akira submodule.

## Responsibility

Core content orchestration and provider-boundary contracts.

## Bundling + Tenant Activation (Ikabud-Kernel-OS)

cms-akira-core is **bundled** in the kernel repo (tracked in git) but is a
**tenant-selected module — NOT auto-installed**. Its module.json carries
`"_enabled": false`, so it stays inactive until a user/tenant activates it:

- CMS Modules page → Activate, or
- `php ikabud module:enable cms-akira-core` (global), or
- per-tenant: `enableModuleForTenant('cms-akira-core', $tenantId)` / the tenant
  module-selection gate.

Activation seeds its admin-only v2 authorization policy idempotently and runs
its migrations (`cms_akira_posts`).

## Suite Placement

- Module path: modules/cms-akira/cms-akira-core
- Templates path: templates/modules/cms-akira-core

## Quick Start

1. Run kernel migrations (including `016_capability_authorization_policies.sql`).
2. Run module migrations: `php ikabud migrate cms-akira/cms-akira-core`.
3. Activate the module for the tenant (see above), then use its canonical
   content routes: `GET /posts`, `GET /posts/{slug}` (render) and
   `POST /api/v1/cms-akira/posts`, `PUT /api/v1/cms-akira/posts/{slug}`
   (mutation).

## Validation

- Module scaffold test: php tests/cms_akira_core_module_test.php
- Core/suite checks:
	- php tests/cms_akira_core_adapter_contract_test.php
	- php tests/cms_akira_provider_boundary_health_test.php
	- php tests/cms_akira_deploy_readiness_test.php
	- php ikabud architecture:check

## P2 governed Post mutations

- `POST /api/v1/cms-akira/posts` calls `cms.post.create@1`.
- `PUT /api/v1/cms-akira/posts/{slug}` calls `cms.post.update@1`.
- Both require a kernel-authenticated `admin`, kernel CSRF, and an
  `Idempotency-Key` request header. Tenant and actor identity always come from
  kernel context; body identity/JWT claims have no authority.
- Activation idempotently seeds protocol-v2 `CapabilityAuthorizationRegistry`
  policies with `allowed_roles=admin`. The manifest also claims Entity Authority
  for `post` and declares exactly one effect tag: `entity.list.post`.
- The mutation transaction is caller-managed on the single-tenant application
  PDO: begin, kernel idempotency claim, Post write, `kernel.audit.record@1`
  (including `correlation_id`), idempotency outcome commit, PDO commit. Duplicate
  outcomes replay; conflict maps to 409 and in-progress to 425 with
  `Retry-After: 2`. A certain pre-publication failure is rolled back/released;
  uncertain commit publication remains processing and is never reclaimed.
- CapabilityBus expands the single `entity.list.post` tag to
  `invalidateEntityCache('post', tenant)`, invalidating list and detail fragments
  after success. Invalidation is deliberately fail-open; the kernel emits
  `Capability entity-cache effect invalidation failed open` as an operational
  warning and the P2 freshness test proves the normal path.

The validated P2 topology is the default/CI single-tenant app DB. Dedicated
multi-tenant databases still require provisioning parity for migrations 011,
015, 016 and audit before adopting this atomic path; that is a separate
provisioning workstream.

## Notes

- Public reads remain published-only.
- Keep dependencies explicit in `module.json`.
- For suite overview, see `modules/cms-akira/README.md`.
