# Contract — tenant-scoped module loading for CLI capability invocation

```
task:
objective: Let the operator CLI invoke module capabilities in a tenant's scope, so
           governed/audited operations (e.g. theme activation) can be driven from CLI
           instead of bypassing the capability bus.

scope:
  allowed:
    - ikabud                       (CLI: capability:call / shared CLI helpers)
    - src/helpers/module-manager.php   (only if a scoped-load helper is genuinely needed)
    - kernel/Capabilities/AuthorityScopeResolver.php  (only if scope cannot carry tenant)
  prohibited:
    - any HTTP request path behaviour
    - capability policy rows, allowed_roles, grant_state
    - modules/cms-akira/**  (Akira must not need changes to make this work)
    - tests/_support/env_guard.php

constraints:
  - Do NOT weaken or bypass capability authorization. A denied call must stay denied.
  - Do NOT change what `getEnabledModules()` returns during a normal HTTP request.
  - Do NOT enable modules globally to make this pass. Akira modules are deliberately
    `_enabled: false` globally (PR #64) and enabled per-tenant.
  - Tenant isolation is absolute: a CLI call scoped to tenant A must never load
    capabilities or settings resolved from tenant B.
  - Fail closed and loudly. No silent fallback to the ambient/base scope.

acceptance:
  1. `php ikabud capability:call akira.theme.activate@1 '{"theme_slug":"akira-editorial","idempotency_key":"cli-<random>"}' --tenant=54 --with-modules`
     must NO LONGER report `Capability not found: akira.theme.activate@1`.
     It must either execute, or fail with an explicit AUTHORIZATION reason
     (e.g. role not allowed) — never "not found".
  2. Whatever actor/role the CLI asserts for policy evaluation must be EXPLICIT
     (e.g. an `--actor-role=<role>` flag) and must appear in the audit record.
     There must be no implicit privilege elevation: with no actor asserted, the
     call must fail closed with a clear message.
  3. Existing suite severity unchanged: 139 files, 107 passed, 0 failed, 32 skipped.
  4. `php ikabud theme:activate akira-editorial --tenant=54` still works as shipped.

e2e_acceptance:
  - After the change, an operator can run a governed capability from CLI in tenant
    scope and the resulting `kernel.audit.record` row names the asserted actor.

verification:
  php -l ikabud
  php scripts/run-tests.php                                  # 107 passed / 0 failed / 32 skipped
  php ikabud capability:call akira.theme.activate@1 '{"theme_slug":"akira-editorial","idempotency_key":"cli-verify-1"}' --tenant=54 --with-modules
  php ikabud capability:call akira.theme.activate@1 '{"theme_slug":"akira-editorial","idempotency_key":"cli-verify-2"}'            # must fail closed: no actor, no scope
  vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G ikabud   # NOTE: ikabud has no .php extension; run on any .php file you change

risk:
  - getEnabledModules() is `static $cached` — order matters. If it resolves BEFORE the
    tenant context is set, the cache is stale and the fix silently does nothing.
    Any fix must guarantee resolution happens after tenant context is established,
    or must reset that cache. VERIFY this explicitly; a passing probe that skipped the
    ordering could be a false positive.

status: READY_FOR_IMPLEMENTATION
```

## Evidence already gathered (do not re-derive)

The CLI cannot register module capabilities. Measured with a probe under
`bootstrap.php` + `src/helpers/module-manager.php` (the same requires `ikabud` uses):

```
--- WITHOUT tenant scope ---
enabled modules: 1
  gui-settings
cms-akira-theme enabled: NO
handlers fn loaded: NO
CALL FAILED: CapabilityNotFoundException: Capability not found: akira.theme.activate@1

--- WITH scope(54, CLI) ---
enabled modules: 1
  gui-settings
cms-akira-theme enabled: NO
handlers fn loaded: NO
CALL FAILED: CapabilityNotFoundException: Capability not found: akira.theme.activate@1
```

Mechanism:

- `loadModuleRoutes()` (`src/helpers/module-routes.php:134`) registers capability
  handlers by iterating **`getEnabledModules()`** and pulling
  `<modulePrefix>_capability_handlers()` from each module's `helpers.php`.
- `getEnabledModules()` (`src/helpers/module-manager.php:1762`) filters
  `discoverModules()` by `!empty($m['_enabled'])` and is **`static $cached`**.
- Tenant-scoped enablement is resolved through `app()->tenant()->current()`
  (`kernel/TenantResolver.php:131`), which is **NULL in CLI**.
- `AuthorityScopeResolver::withScope(54, CLI, ...)` establishes the *capability
  authority* scope only; it does **not** set the *tenant context*. Hence Akira modules
  stay disabled and no handlers register.
- `TenantResolver::setTenantId(?int $id)` (`kernel/TenantResolver.php:122`) exists and
  is the likely fix point.

## Context

`.ai/akira-theme-cli-fix.md` records the related shipped work: `theme:activate` now
requires `--tenant=`, enforces the activation gate, and verifies its write — but writes
the tenant setting **directly**, skipping the capability's audit row and idempotency
key. This slice closes that gap by making the governed path reachable from CLI.

## Out of scope (do not implement now)

- Changing `theme:activate` to route through the capability. First prove the capability
  is reachable; switching the CLI over is a separate, later slice.
