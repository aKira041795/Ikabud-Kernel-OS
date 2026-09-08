# Ikabud Kernel OS — Application-First Philosophy

> **Standard (Kernel OS 6.0):** Ikabud is an **application-first, multi-tenant**
> control plane. Every tenant deployment owns its database, entry module, auth
> surface, and administration shell.

The bare-bones installer is more than a deployment package — it is the foundation
for **Ikabud as an application OS**, where every business capability is added
intentionally rather than inherited from legacy assumptions.

## Principles

1. **One control plane, clean tenant boundaries.** The kernel manages isolated
   tenant applications and resolves each tenant by host.
2. **The kernel renders nothing beyond essential administration; everything else
   is a module.** The installer bundles only the `gui-settings` companion so the
   distribution stays clean while remaining immediately usable.
3. **New modules assume database ownership.** Modules own their tables; they do
   not share tables across applications.
4. **Legacy skin is shed.** Deprecated capabilities are explicitly marked and
   documented — never silently carried forward.

## Standard tenancy posture

| Capability | Status | Default |
|---|---|---|
| Multi-tenant control plane (`APP_MULTI_TENANT_ENABLED`) | **Standard** | `1` (on) |
| Host-based tenant resolution (`APP_TENANT_STRATEGY=control_host`) | **Standard** | `control_host` |
| Separate database and entry module per tenant | **Required** | enabled |

The host without a resolved tenant remains the kernel control-plane surface.
Tenant hosts expose only their entry application's login and administration shell.

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
