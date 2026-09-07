# CMS Akira Independent-CMS — Phase 2 gate: shell + module-install service

task: Execute Phase 2 of the APPROVED independent-CMS contract (`.ai/current-task.md` Phase 2 + activation
lifecycle; `.ai/chair-adjudication-independent-cms-2026-09-07.md` R2 install-persistence + R6 shared-schema path +
R7 profile/entry). Phase 2 = the tenant-entry experience: NEW `cms-akira-shell` (Kernel-auth) + the DISTINCT
module-install service (never `TenantProvisioner`) with persistence + compensating states. Phase 1 (akira.post.*
core) is merged on main.

## Contract specifics (honor exactly)
1. **cms-akira-shell** (NEW module under modules/cms-akira/cms-akira-shell, TRACKED after this gate):
   - id unique among the 15 product members; the SOLE `entry_module:true` (no other member may carry it —
     profile-standard keeps its foreign entry/auth fields REMOVED in Phase 9B, but Phase 2 must ensure no OTHER
     member carries entry_module:true except the shell; record that profile-standard still has it until 9B and that
     the arch/auth-route finding is local-only until then).
   - NO `auth_owned`, NO `authentication_provider`, NO Akira user/domain table. Credentials/session handled
     exclusively by Kernel auth. `/cms-akira-shell/login` is presentation/redirect only. `entryLandingPath()`
     resolves from the shell's own routes.php (never hardcoded /cms-akira/login).
   - Admin surface: dashboard + nav + authz-failure + module-health; admin Post CRUD (list/edit/publish/unpublish/
     delete via the akira.post.* capabilities + Entity Views) with NO legacy cms loaded.
   - Tenant isolation proven (shell reads/writes only via cms-akira-core caps + kernel context; no cross-tenant).
2. **Module-install service (Kernel, distinct from TenantProvisioner)** + the MISSING
   `tenantSetModuleActivationState()`:
   - Phase 0 recorded that TenantProvisioner CALLS tenantSetModuleActivationState() but NO implementation exists.
     Define it (activation-state persistence) + the install service state machine:
     `install_requested → dependencies_resolving → migrations_running → policy_seeding → activation_writing →
     active` (+ explicit `failed_*` and `pending_rollback`). Operating on an existing ACTIVE tenant WITHOUT moving it
     to a global pending state.
   - Persistence (R2): a Kernel-owned control-plane schema for install generations — unique tenant/install-generation
     records + per-member steps (owning table + migration + uniqueness key + locking rule + retention). ONE
     compensation strategy (no alternatives): staged activation state promoted only after control-plane success, with
     routing that recognizes only a committed installation generation.
   - Failure-window tests: activation write, entry write (entry_module_id), policy seed, migration — each failure
     window compensated; a failed install leaves the tenant non-routable/pending ONLY for the new closure, never
     globally pending. Rerun converges idempotently; uninstall preserves owned data unless confirmed purge.
   - Shared-schema (R6): how the installer obtains the correct tenant PDO in shared mode, per-tenant install lock,
     prevention of concurrent enable/entry changes. Same dependency-closure/migration/policy/activation sequence in
     both shared + dedicated modes (dedicated-DB Kernel migration 011/015/016 parity = recorded prerequisite, not
     claimed).
   - Profiles (R7): the tenant selects an install PROFILE; ONLY cms-akira-shell may be assigned as
     `kernel_tenants.entry_module_id`; profiles never passed to entry-routing APIs.
3. Admin Post CRUD works in the shell with no legacy cms loaded; `module:certify cms-akira-core` + shell certify
   (use `module:certify --all` if the single-module nested-_path CLI defect is not yet fixed — RECORDED follow-up).

## Deliverables
1. modules/cms-akira/cms-akira-shell (module.json sole entry_module:true + routes/handlers/helpers/README).
2. Kernel: tenantSetModuleActivationState() implementation + the module-install service (install generations
   persistence migration + service + CLI entry) — mirror how TenantProvisioner/tenant provisioning are structured but
   distinct + per-module + compensating.
3. Tests: shell render/login-redirect/dashboard/nav/authz-failure/module-health; admin Post CRUD via akira.post.*
   (no legacy cms); install service state machine + all four failure windows + rerun convergence + tenant isolation.
4. Append result to the contract.

## Verification (do all)
- Fork P1 (38) + P2/lifecycle (38) green; new shell/install tests green; authority 18/18; capability audit zero;
  module:certify (via --all) clean; theme validate; full composer test; phpstan + cs-fixer on tracked shell + kernel
  changes; logs clean; CI 6/6.
- grep: shell is the only entry_module:true; no legacy cms usage in shell/install path.
- Kernel changes are additive + gated (the install service + activation-state are new, TenantProvisioner unchanged).
- MySQL 5.7 for the install-generations migration (InnoDB utf8mb4_unicode_ci; unique (tenant_id, install_generation)
  or similar; idempotent).

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

## Implementation result — 2026-09-07

- Implemented the tracked `cms-akira-shell`, Kernel module-install generation service/control migration/CLI,
  generation-aware entry routing, and the formerly missing `tenantSetModuleActivationState()` without changing
  `TenantProvisioner`.
- Compensation strategy is singular: activation is staged on the tenant PDO, generation + optional shell entry are
  committed in one control-plane transaction, then activation is promoted. Failure restores prior activation and
  entry state; owned data is never dropped by uninstall.
- Added shell contract and activation/install lifecycle tests, including the four named injected failure windows,
  tenant isolation, rerun/idempotency, retention/locking/schema checks, and preservation-only uninstall.
- Gate evidence: P1 38/38; P2 38/38; shell 21/21; install/activation 23/23; authority 18/18; capability audit zero;
  `module:certify --all` clean including shell; themes 2/2; targeted PHPStan and CS Fixer clean; composer tests
  106/106; application/error logs empty.
- Follow-up defect (still reproducible): single-member `php ikabud module:certify cms-akira-core` loses nested
  module `_path`/handler resolution and reports false missing handlers while `module:certify --all` passes. Continue
  using `--all` in gates until the nested-module single-certify path is repaired.
- Local ignored Phase-9B scaffold `cms-akira-profile-standard` still carries its foreign entry/auth fields and causes
  the documented local-only architecture finding. It is not tracked by this gate; in the tracked Phase-2 product
  set, the shell is the sole `entry_module:true`. Phase 9B must remove that ignored scaffold before profile tracking.
