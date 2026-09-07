You are the /implement + /review agent for the **CMS Akira Independent-CMS Phase 2 gate** in Ikabud Kernel OS 6.x
(repo root /var/www/html/ikabudsix, main adbdf7f — Phases 0+1 merged). Execute Phase 2 per the authoritative
approved contract:

    .ai/contract-independent-cms-phase2-2026-09-07.md   (READ FIRST)
    .ai/current-task.md   (Phase 2 + activation-lifecycle + R2/R6/R7 sections)
    .ai/chair-adjudication-independent-cms-2026-09-07.md (R2 install-persistence, R6 shared-schema, R7 profile/entry)

Phase 2 = NEW `cms-akira-shell` (Kernel-auth, sole entry_module:true) + the DISTINCT module-install service (never
TenantProvisioner) + the MISSING tenantSetModuleActivationState(). Kernel changes allowed ONLY for the new install
service + activation-state (additive; TenantProvisioner unchanged). MySQL 5.7.

## Context anchors (verify, don't guess)
- TenantProvisioner (kernel/Services/TenantProvisioner.php) — whole-tenant pending→provisioning→active CAS with
  entry_module_id; CALLS tenantSetModuleActivationState() (Phase 0 confirmed it does NOT exist — you must define it
  without breaking TenantProvisioner's call sites).
- TenantEntryRouter (kernel/Http/TenantEntryRouter.php) + shouldSkipRewrite() exemptions — the shell's
  login/redirect must integrate with the tenant-entry routing + kernel auth (see how the legacy cms entry module
  provides /cms/login in the MAIN-CMS-REPO? NOT available here — you design the Kernel-auth shell against
  kernel/App.php auth, app()->user(), requireAnyRole, CSRF, JWT).
- cms-akira-core (tracked) exposes akira.post.get/list/create/update/publish/unpublish/delete@1 + entity.list.post@1/
  entity.get.post@1 + Entity Authority. The shell admin CRUD consumes these (capability bus + Entity Views); NO
  legacy cms, NO direct cms_akira_posts SQL from the shell.
- _module_enabled / tenant_module_settings + enableModuleForTenant / isModuleEnabledForTenant (module-registry) —
  the install service activation should integrate with these.
- entry_module_id lives on kernel_tenants (control plane). R7: only cms-akira-shell may be assigned it; profiles
  never routed as entry.

## Deliverables (contract scope ONLY)
1. modules/cms-akira/cms-akira-shell (new, tracked): module.json (sole entry_module:true; routes under
   /cms-akira-shell + /cms-akira-shell/login presentation/redirect; dashboard/nav/module-health/authz-failure
   surfaces; admin Post CRUD via akira.post.* caps + Entity Views) + routes/handlers/helpers/README.
2. Kernel module-install service + tenantSetModuleActivationState(): install-generations persistence (control-plane
   migration: unique tenant/install-generation + per-member steps, locking, retention), the state machine
   install_requested→…→active (+failed_*/pending_rollback) with ONE compensation strategy (staged activation
   promoted only after control-plane success; routing recognizes only committed generations), failure-window tests
   (activation write / entry write / policy seed / migration), shared-schema tenant-PDO + per-tenant lock +
   concurrency prevention, rerun idempotent, uninstall preserves data. CLI entry.
3. Shell tests (login-redirect/dashboard/nav/authz-failure/module-health + admin Post CRUD no-legacy-cms) + install
   service tests (state machine + 4 failure windows + rerun + tenant isolation).
4. Record the nested-module certify CLI defect as a follow-up if still present (use module:certify --all in gates).
5. Append result to the contract.

## Verification (do all)
- P1 (38) + P2/lifecycle (38) green; shell + install tests green; authority 18/18; capability audit zero;
  module:certify --all clean; theme validate; full composer test; phpstan + cs-fixer (tracked shell + kernel
  changes); logs clean; CI 6/6.
- grep: only cms-akira-shell has entry_module:true; no legacy cms in shell/install path; MySQL 5.7 for the new
  migration.

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state: (Phase 3 ready?)
