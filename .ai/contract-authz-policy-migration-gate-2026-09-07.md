# Kernel Gate: capability_authorization_policies migration + requires_protocol propagation (6.5 completion)

task: Ship the missing persistence for the kernel's CapabilityAuthorizationRegistry (6.5 authority guarantee) and
verify/repair `requires_protocol` propagation so module capabilities can be durably governed with bus
re-authorization. Currently `CapabilityAuthorizationRegistry` (kernel/Capabilities/CapabilityAuthorizationRegistry.php)
INSERTs into and SELECTs from `capability_authorization_policies` (L140-149, L228-261) but NO repository migration
creates that table — the table is absent from both `ikabudsix` and `ikabudsix_ci`. This blocks CMS Akira fork P2
(admin-only `cms.post.create@1`/`update@1` governed by the registry).

objective: A MySQL 5.7 kernel migration for `capability_authorization_policies` matching the registry's exact
schema, plus confirmation that a module capability declaring the governed policy (roles admin, requires_protocol v2)
is re-authorized by the bus. Kernel 6.5 authority completion; zero-exception baselines preserved.

## Verified context (main 9e49602)
- Registry INSERT (CapabilityAuthorizationRegistry.php:140-149) columns:
  `policy_version, capability_id, capability_version, provider, caller_module, allowed_roles,
  provider_activation_required, requires_protocol, is_active, updated_at` + `ON DUPLICATE KEY UPDATE` → a UNIQUE key
  must exist for the upsert (determine the natural key: policy_version+capability_id+capability_version+provider —
  verify against seedPolicy callers). Params include `requires_protocol` default 'v1'.
- Registry SELECT (L228) reads by policy_version + is_active=1.
- Registry reads/writes run through kernel escalation on app()->db() (L278 note). Single-tenant default + CI =
  app DB `ikabudsix(_ci)`. Next migration number = 016 (max is 015_kernel_durable_event_outbox).
- `requires_protocol` in CapabilityBus.php:491: `if (strtolower(trim($meta['requires_protocol'] ?? '')) === 'v2')`
  — bus uses capability meta `requires_protocol`. Fork P2 implementer reports module loading does NOT propagate
  `requires_protocol` metadata from the manifest/registration → verify where module capability meta is built
  (module-manager / capability registration) and propagate `requires_protocol` so the bus re-authorization path
  engages for governed module capabilities. Fix only if the gap is real (the fork implementer verified it).

## scope
  allowed:
    - migrations/ — NEW `016_capability_authorization_policies.sql` (MySQL 5.7, ENGINE=InnoDB utf8mb4_unicode_ci,
      columns per the registry INSERT, id PK, created_at/updated_at, the UNIQUE key the upsert needs, index on
      (policy_version, is_active)).
    - kernel/Capabilities/CapabilityBus.php + src/helpers/module-manager.php (or wherever module capability meta is
      built) — propagate `requires_protocol` from module manifest/capability declaration into the capability meta so
      the bus's v2 re-authorization engages (verify first; only the minimal propagation fix if the gap is real).
    - config/app.php — kernel capability registration mode if the registry policies seeding needs a hook (choose
      after reading how seedPolicy is invoked at boot — VERIFY whether any boot path seeds policies; if none exists,
      note it and do NOT invent a seeding mechanism beyond what modules declare).
    - tests/ — a focused test: after migration, a governed module capability (roles admin) with requires_protocol v2
      is re-authorized/denied correctly by the bus; migration idempotent + rerun converges.
    - docs — kernel reference note if applicable.
  prohibited:
    - NO change to CapabilityAuthorizationRegistry semantics or its SQL.
    - NO new policy-seeding subsystem beyond propagating what modules already declare (if modules currently declare
      policies nowhere, the gate only ships the migration + propagation + a fixture test proving the mechanism;
      actual fork policy seeding is P2's job via the merged mechanism).
    - NO module edits, NO-BROADEN.

## constraints
- MySQL 5.7-safe (no CTE/window/JSON_TABLE/generated-column edge syntax; ENGINE=InnoDB utf8mb4_unicode_ci; the
  UNIQUE key chosen so `ON DUPLICATE KEY UPDATE` upserts the intended row).
- Migration idempotent + registered per repo convention; runs on the app DB (single-tenant default). Dedicated-tenant
  parity is already a recorded follow-up (with kernel idempotency/outbox) — note it here too.
- requires_protocol propagation must be additive: existing module capabilities without it keep current behavior.
- php -l, phpstan (no baseline additions), cs-fixer CI style.

## acceptance
- After `php ikabud migrate` (or the repo's migration runner), `capability_authorization_policies` exists on the app
  DB with the registry-compatible schema; rerun converges (idempotent).
- seedPolicy() INSERT + ON DUPLICATE upsert works against the new table (focused test seeds a row twice → one row,
  updated).
- A governed module capability (roles admin, requires_protocol v2) is re-authorized by the bus: admin allowed,
  non-admin denied — via the real CapabilityAuthorizationRegistry path (proves propagation + table wiring).
- Full `composer test` green (CI 6/6); capability authority 18/18; both logs clean; durable_idempotency/workflow/
  outbox/ark suites green.

## verification
- Migration applied + rerun test; focused authz test; full `composer test`; CI 6/6.
- Grep: requires_protocol propagated from module capability declaration → bus meta.

## risk
- LOW-MEDIUM. Ships a missing kernel migration (no schema behavior change to existing tables) + a narrow additive
  meta propagation. Care: the UNIQUE key must match what seedPolicy/upsert expects; the focused test proves it.

status: PARTIAL (implementation and scoped verification pass; repository-wide CI is blocked by unrelated, pre-existing
untracked module-fleet additions)

## implementation result (2026-09-07)
- Added `migrations/016_capability_authorization_policies.sql` with the registry-compatible fields, natural UNIQUE
  key `(policy_version, capability_id, capability_version, provider)`, active-version index, and MySQL 5.7-safe
  InnoDB/utf8mb4 DDL. The kernel migration runner applied and registered it; repeated runs converge.
- Added additive module expose metadata construction in `src/helpers/module-routes.php`; `requires_protocol` now flows
  from each expose declaration to the provider metadata inspected by `CapabilityBus`. Missing declarations remain the
  empty legacy value and do not activate v2 governance.
- Added `tests/capability_authorization_policy_migration_test.php`: migration registration/rerun, duplicate-key policy
  update, module metadata propagation, legacy behavior, and real registry-backed bus allow/deny coverage all pass
  (10/10). The registry is application-scoped and has no tenant policy column, so its tenant guarantee is presence of
  a non-empty tenant context; the focused denial test covers the missing/invalid tenant context without changing
  registry semantics.
- Verified there is no non-test boot caller of `CapabilityAuthorizationRegistry::seedPolicy()`. This gate does not
  invent policy seeding; fork P2 must seed through the existing registry API. Dedicated-tenant schema parity remains
  the already-recorded follow-up.
- Verification passed: touched-file `php -l`, targeted PHPStan with no baseline changes, targeted PHP-CS-Fixer CI
  style, focused test, full `composer test` (105/105), capability authority (18/18), durable idempotency (30/30),
  workflow suites, outbox (22/22), Ark runtime (17/17), migration rerun, propagation grep, and clean app/error logs.
- Repository-wide CI cannot be reported 6/6 in this worktree: strict manifest guard fails on unrelated untracked
  `daily-ledger`/`cms-akira` fleet state; full PHPStan and full CS-Fixer likewise report existing out-of-scope module
  findings. No such finding is in a changed gate file.
