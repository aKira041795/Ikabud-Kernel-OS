# Ikabud Kernel OS — Application-First Philosophy

> **Declaration (Kernel OS 6.0):** Ikabud is now **application-first**. Multi-tenancy
> becomes an **optional compatibility architecture**, not the default design target.

The bare-bones installer is more than a deployment package — it is the foundation
for **Ikabud as an application OS**, where every business capability is added
intentionally rather than inherited from legacy assumptions.

## Principles

1. **One application, clean boundary.** The kernel ships as a single-application
   kernel optimized for product development, not for SaaS multi-tenancy by default.
2. **The kernel renders nothing beyond essential administration; everything else
   is a module.** The installer bundles only the `gui-settings` companion so the
   distribution stays clean while remaining immediately usable.
3. **New modules assume database ownership.** Modules own their tables; they do
   not share tables across applications.
4. **Legacy skin is shed.** Deprecated capabilities are explicitly marked and
   documented — never silently carried forward.

## Legacy compatibility (deprecated, not deleted)

| Capability | Status | Default |
|---|---|---|
| Shared-database / multi-tenant (`APP_MULTI_TENANT_ENABLED`) | **Deprecated — compatibility only** | `0` (off) |
| Control-plane tenancy (`APP_TENANT_STRATEGY=control_host`) | Compatibility | `control_host` (only active when multi-tenant enabled) |

When multi-tenancy is enabled, the older SaaS hosting model is available for
backward compatibility. New development targets the single-application kernel.

## Repository identity

- Source repo: `aKira041795/Ikabud-Kernel-OS` (default branch `main`)
- The historical `aKira041795/Ikabud-CMS-Kernel` repo is preserved as legacy lineage.
- The kernel updater reads `APP_UPDATES_GITHUB_REPO` / `APP_UPDATES_GITHUB_BRANCH`
  (defaults: `aKira041795/Ikabud-Kernel-OS`, `main`).

## Enforcement

- CI gates every change: kernel contracts, DB matrix (`test (mysql-8)`,
  `test (mysql-5.7)`, `test (mariadb-10.6)`), `static-analysis` (PHPStan),
  `coding-standards` (PHP-CS-Fixer).
- Branch protection on `main` requires all gates to pass before merge.

## Related

- [Kernel OS 6.x implementation status](kernel-os-disyl-roadmap-status.md)
- [Tenancy roadmap (legacy compatibility)](tenancy-roadmap.md)
