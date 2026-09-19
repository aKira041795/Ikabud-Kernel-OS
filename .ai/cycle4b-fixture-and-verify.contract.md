# CYCLE 4b — unblock the module test fixture, then complete Cycle 4 verification

status: READY_FOR_IMPLEMENTATION
owner: implementation agent (Sol)
repo: /var/www/html/ikabudsix — current `main` working tree, which **already contains your Cycle 4
implementation, uncommitted**. Do NOT re-implement it; verify it after unblocking the fixture.

## part 1 — the blocker (root cause already identified by the chair, do not re-diagnose)

`modules/cms-akira/cms-akira-builder/tests/builder_contract_test.php` cannot reach its
assertions:

```
CapabilityNotFoundException: No permitted capability providers for: akira.builder.validate@1
```

Chain, verified:

1. `CapabilityBus::applyPolicy()` skips any provider module where
   `moduleIsActive($provider)` is false ("Activation Before Participation").
2. `moduleIsActive()` → `readTenantModuleSettingsForTenant($moduleId, $tenantId)`.
3. That helper does `$db = app()->dbForTenant($tenantId); if ($db === null) return [];`
   → no settings → `_module_enabled` absent → **inactive**.
4. `DatabaseManager::dbForTenant()` only returns the app DB when
   `PHP_SAPI !== 'cli' && currentTenant === $tenantId` — **CLI skips that shortcut**, so it
   needs a row in **`kernel_tenant_db_connections`** (`db_host`, `db_name`, `db_user` …).
5. The test's synthetic tenants (994701 / 994702) have no such row → null → inactive.

**Fix (test-infrastructure only):** add a reusable fixture that makes a synthetic tenant
resolvable and activates a module for it, then use it in the builder contract test's setup:

- New file `tests/_support/tenant_fixture.php` exposing something like
  `ensureTestTenant(int $tenantId, string $moduleId): void` that
  1. makes the tenant resolvable — prefer the **existing writer path** the control plane uses
     (inspect `kernel_tenant_db_connections` columns and `DatabaseManager` /
     `ModuleInstallService` / control-plane services for a save method) over a raw INSERT, and
     remember any password encryption `tenantDbPasswordFromRow()` expects; the connection may
     point at the **same database** the suite already uses (a shared-DB tenant);
  2. activates the module for that tenant through the app's own API
     (`saveTenantModuleSettingsForTenant($moduleId, $tenantId, ['_module_enabled' => true])`)
     and asserts it took effect (`moduleIsActive($moduleId, $tenantId) === true`);
  3. is idempotent and cleans up after itself (delete the activation and, if it created them,
     the connection/tenant rows) so repeated runs stay clean.
- Use it in `builder_contract_test.php` (and any other `cms-akira-builder` test that needs the
  gate) before the first capability call. Do not weaken or delete assertions.

If the writer path turns out to need a second dedicated database (as
`*_dedicated_tenant_test.php` does), do NOT fake it: stop and report
`BLOCKED: FIXTURE_NEEDS_DEDICATED_DB` with what you found. A shared-DB connection row is the
expected outcome.

## part 2 — finish Cycle 4 verification (the code is already in the tree)

Re-read `.ai/cycle4-provenance.contract.md` for the acceptance criteria, then produce the
evidence you marked unresolved:

- `php scripts/run-tests.php` → **0 failed** (skips allowed); report the summary line
- the builder contract test fully green, including the diff / foreign-revision /
  unauthorized-caller assertions you added
- if you added a capability: prove its policy row exists for tenant 54 (command + query output)
  and prove the denial path for an unauthorized caller
- **live HTTP on tenant 54** (`Host: akiracms.test`, login recipe in the contract): the
  provenance timeline for `t4a-page`, and a historical render that differs from the current
  draft when the trees differ
- **browser evidence**: the drawer opens in the composition editor, "View as of" renders the
  chosen revision in the preview iframe, and the diff view is readable — use the browser tools
  if available, otherwise capture the served HTML/JSON and state plainly that browser
  interaction was not possible
- `npm run type-check` + `npm run build` with the regenerated bundle included

## constraints

- Do NOT commit, push, or branch. Leave everything uncommitted.
- Do NOT touch `.ai/**`. Keep the changes read-only for Cycle 4 (no schema/migration changes).
- Back up `storage/modules.json` before running the suite and restore it afterwards
  (gui-settings ON, all `cms-akira-*` ON, `daily-ledger` OFF).
- `storage/cache/compiled` may be unwritable for CLI; the engine degrades to the interpreted
  pipeline with a warning — expected, do not "fix" it.

## deliverable

Result block: what you changed in the fixture, the suite summary, the live/browser evidence,
anything still unverified — with raw command output. Verified evidence only; say plainly what
you could not verify.
