```
Q3 VERDICT:  SYNTHESIS — A's materialization with B's pinned fingerprint. Store the
             declaration as immutable *data in each tenant DB*, never join executable PHP
             or manifests at dispatch; the grant row carries only a pointer+integrity proof.

             Concrete tenant-DB schema:
             - capability_declarations(capability_id, capability_version, provider,
               revision INT, declaration_hash CHAR(64), caller_module, allowed_roles,
               requires_protocol, provider_activation_required, installed_at) — one
               immutable row per revision, written only at install/activation.
             - grant row: grant_id BIGINT (new identity), capability_id, capability_version,
               provider, declaration_revision INT (readable in SQL), declaration_hash CHAR(64),
               grant_state ENUM(granted,suspended,revoked,declaration_drift), actor, reason,
               granted_at, updated_at. The grant row holds NO roles/callers/protocol.
             - authorize() reads declaration + grant in the tenant DB and fails closed if
               row.declaration_hash != sha256(declaration payload). No runtime code join.

Q4 VERDICT:  SYNTHESIS — B's asymmetry, reconciled on A's materialized projection at
             activation, not on every dispatch.

             A declaration change installs a NEW immutable revision. Reconciliation runs in
             the same tenant-DB transaction as the install and diffs the new permission set
             against each grant's pinned revision:
             - narrowing → auto-apply: write new declaration_revision + audited system
               transition; no operator action.
             - widening → grant_state = declaration_drift (deny); requires audited re-grant
               with a NEW grant_id.
             - suspended → stays suspended on its pin; re-activation takes the stricter of
               {pinned, live}. revoked → terminal, never touched.
             Runtime admits only exact declaration_revision ↔ installed-active-revision match;
             otherwise denies. Effective decision is always the stricter.

MISSED:      1. CLI/cron/queue cannot move grant state, so the lifecycle is web-only, and
                cron *contaminates the control store*. transitionGrantState() requires an
                authenticated actor (CapabilityAuthorizationRegistry.php:215-218), but
                app()->user() is null under `php ikabud work:queue`/`schedule:run`
                (App.php:1749). Worse, queue jobs load module helpers
                (bootstrap.php:1542-1546 → module-manager.php:2606), whose seed functions run
                at file scope (cms-akira-core/helpers.php:54, cms-akira-theme/helpers.php:53,
                seo:47, navigation:54, media:48, builder:937, search:557). seedPolicy() then
                writes app()->db(), which in CLI is the CONTROL DB — so cron silently writes
                declaration/grant rows into the kernel authority table (the measured 1-row
                store), not the tenant's. Any "declaration projection" design that keeps
                seeding on helper-load will reproduce this.
             2. seedPolicy() mutates LIVE declarations on every request, not just at upgrade.
                loadModuleHelpers() require_once's helpers.php on each request/dispatch, and
                seedPolicy() does ON DUPLICATE KEY UPDATE caller_module/allowed_roles/
                requires_protocol where grant_state='granted', plus updated_at=NOW()
                (CapabilityAuthorizationRegistry.php:157-165). So a deployed module change
                silently widens live grants *per request*, and even a no-op request rewrites
                the authority table. This is the load-bearing reason A's projection must be
                written at install/activation, and why "join from contract at dispatch" (B) is
                not implementable while the current contract IS the helper file.
             3. replaceActiveRowRoles()'s "caller owns the transaction" is unenforced, and the
                clone briefly re-grants suspended/revoked rows. The doc (line 305) is the only
                guard; the function never checks inTransaction(). The new version is inserted
                with grant_state='granted' (seedPolicy INSERT, line 159), non-granted states are
                only restored afterwards via transitionGrantState() (lines 344-353), the old
                version is deactivated last (line 356), and resolvePolicyVersion() picks
                MAX(policy_version) WHERE is_active=1 (line 447). Window = suspended/revoked
                rows are live as granted. A future caller that forgets the transaction makes
                that window visible to every concurrent request; even inside a transaction the
                kernel.audit.record@1 dispatch (line 262) runs mid-window.
             4. "Only 2 manifests declare capabilities.policy" is a category error. Those two
                blocks (daily-ledger module.json:204, gui-settings module.json:282) are
                allow_callers provider-selection policy consumed by CapabilityBus::applyPolicy()
                (CapabilityBus.php:401,492-496) — a different schema. The declaration tuple that
                seedPolicy() writes is NOT in any manifest: allowed_roles and caller_module
                appear in zero module.json files; only requires_protocol does
                (e.g. cms-akira-core/module.json:80). So B's "join from contract" needs a new
                manifest schema for roles/callers, not just removal of PHP seeders.

JOINTLY WRONG (if any): The shared Q1/Q2 principle (tenant grants authoritative, control
             plane deny-only) is sound, but the agreed *mechanism* — a live deny-only overlay
             read at dispatch — is not cleanly implementable here and creates a new failure
             mode. Runtime authorization today opens exactly one connection, app()->db()
             (CapabilityAuthorizationRegistry.php:508). Adding a control-DB read per dispatch
             (DatabaseManager::controlDb(), DatabaseManager.php:497-536) doubles connections
             against Bluehost's max_user_connections — a constraint the code already retries
             around (DatabaseManager.php:455,480-482) — and forces a fail-open/fail-closed
             choice: fail-closed lets any control-DB blip deny all tenants; fail-open lets
             loss of control-plane connectivity bypass central suspension. It also forces
             Workbench (already required to show the runtime decision) to read the control DB
             from tenant context — re-creating the context-dependent authority read the ADR is
             fixing. Additionally, "deny-only" is a convention, not a guarantee: the control
             plane reaches tenant DBs with the same kernel_tenant_db_connections credentials
             (DatabaseManager.php:301, 539+), which are equally capable of writing a grant, and
             MySQL 5.7 has no row-level security to make the asymmetry structural. Correction:
             materialize central suspensions *into the tenant authority store* via an audited,
             narrow control→tenant sync (with a per-tenant acknowledged-revision marker), and
             treat "stale vs control revision" as the fail-closed condition — do not add a live
             second authority read to the dispatch hot path. Also, key any central denial on the
             capability contract (capability_id + capability_version + provider), never on
             policy_version/grant_id, or a module upgrade or a Q5 re-grant silently lifts it.

DECISIVE PRECONDITION: The runtime must resolve exactly one authority store per
             (tenant, execution context) from an explicit tenant scope — web, CLI, cron,
             queue, service, and Workbench alike — instead of app()->db(). This is currently
             FALSE: CapabilityAuthorizationRegistry::db() falls back to app()->db() with no
             selector (CapabilityAuthorizationRegistry.php:508), and app()->db() is
             tenant-aware only for web because DatabaseManager::db() derives the target from
             resolveRequestTenant (DatabaseManager.php:412-420); TenantResolver falls through
             to the config default under CLI (TenantResolver.php:414-415). Until an explicit
             authority-store/tenant selector exists, Q3's "one materialized store" and Q4's
             "runtime matches installed revision" cannot be enforced, and the CLI contamination
             in MISSED#1 continues.
```
