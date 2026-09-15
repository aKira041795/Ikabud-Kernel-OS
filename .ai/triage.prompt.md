# TRIAGE CONTRACT — restore the test gate (22 falsely-green tests)

status: READY_FOR_IMPLEMENTATION
branch: `fix/test-integrity-and-triage` (already contains `fix(bootstrap): exit non-zero on uncaught CLI exceptions`)
owner: implementation agent (Sol, low reasoning)

## context

The bootstrap exception handler used to print the error page and exit 0, so tests that
died during bootstrap were counted as PASS. That is already fixed on this branch. With
honest verdicts the suite reports **92 passed / 22 failed**.

One product fix is already applied (uncommitted, do NOT revert):
`kernel/DiSyL/Exceptions/TemplateOutputTooLargeException.php` +
`kernel/DiSyL/TemplateEngine.php` — the compiled renderer now only aborts on the
output-size guard and otherwise falls back to the interpreted pipeline, so an unwritable
compiled cache can no longer break rendering. That fixed `kernel_hardening_test`,
`module_login_rate_limit_test` and `nested_module_discovery_test`.

Additionally `fix(disyl)` theme include-root is already merged to main.

## buckets (verified causes — do not re-diagnose from scratch)

### A. Quarantine — depend on modules that never existed in this repo (11)
`modules/cms` and `modules/users` have **zero commits in all history**. These tests cannot
pass here and only "passed" because of the exit-0 bug.

- `tests/disyl_document_renderer_test.php` (`modules/cms/helpers/10-core.php`)
- `tests/entity_context_registry_test.php` (`modules/cms/*`)
- `tests/entity_context_runtime_bridge_test.php` (`modules/cms/*`)
- `tests/infrastructure_test.php` (`modules/cli-test-tmp/handlers.php`)
- `tests/integration_bridge_validation_test.php` (`wms.stock.reserve@1` — module absent)
- `tests/manifest_settings_defaults_test.php` (ai, anti-spam, cms, contact-form, ecommerce, guidance, sms, ticketing)
- `tests/page_cache_smoke_test.php` (`modules/cms/helpers.php`)
- `tests/platform_tier1_operational_test.php` (`ecOutboundWebhookDeliverJob()`)
- `tests/render_context_contracts_test.php` (`ecPublicRenderContext()`)
- `tests/request_dispatch_integration_test.php` (`modules/cms/helpers.php`)
- `tests/trigger_validation_test.php` (`modules/users/helpers.php`)

Action: `git mv` each to `tests/_retired/<same-name>.php.retired` (**renamed so the runner's
`*_test.php` discovery skips them**), keep the file contents untouched, and add
`tests/_retired/README.md` stating why each is retired, that they are kept for reference,
and how to restore one (rename back once the module/capability exists again).

### B. Test rot — fix properly (2)
- `tests/disyl_assoc_test.php`: calls `new TemplateEngine()` with no arguments; the
  constructor now requires `(string $templateDir, string $cacheDir, ...)`. Construct it the
  way `tests/disyl_block_engine_readiness_test.php` does (temp dir + cache dir + explicit
  `enableCompiledMode`). Keep the assertions intact.
- `tests/tenant_entry_auth_shell_standard_test.php`: calls undefined `akiraShellPage()`.
  Load the shell module helpers so the function exists (mirror how other module tests
  `require_once` their module's helpers), or use the current API if it was renamed. Do not
  weaken assertions.

### C. Environment guards — loud SKIP, never silent green (5)
Create `tests/_support/env_guard.php` exposing:
- `requireWritableCompiledCache(): void` — SKIP when `storage/cache/compiled` is missing or
  not writable by the current process (probe by writing a temp file).
- `requireTenantFixture(int $tenantId): void` — SKIP when tenant-settings (multi-tenant)
  mode is enabled and that tenant has no resolvable DB configuration.
Both must print exactly one line starting `SKIP: ` with a human reason and `exit(0)`.

Apply:
- `requireWritableCompiledCache()` → `tests/error_page_contract_test.php` (it asserts "zero
  engine warnings", which cannot hold when the cache is unwritable; it passes in CI where
  the test user owns the cache).
- `requireTenantFixture(<the id each test uses>)` → `tests/capability_effect_invalidation_test.php`,
  `tests/durable_idempotency_test.php` (63001), `tests/eventbus_durable_outbox_test.php` (62001),
  `tests/kernel_idempotency_capability_test.php` (953331).

`tests/_support/env_guard.php` must NOT match `*_test.php`.

### D. Runner must surface skips — `scripts/run-tests.php`
When a test exits 0 but its captured output contains a line matching `/^SKIP:/m`, count it in
`$skipped`, print `SKIP` in the summary table with the reason, and keep it out of the passed
count. Preserve the existing `load_test` / `RUN_E2E_SHARED` opt-in behaviour.

### E. Investigate — `tests/entity_view_render_cache_test.php` (real, do not paper over)
It reports `DEBUG capability not invoked; rendered=<div class="ikb-entity-error ...`.
Determine whether the cause is (i) the capability simply not being registered in a CLI
harness (then guard it with a `requireCapability(string $id): void` helper that SKIPs) or
(ii) a genuine regression in the entity-view render path (then STOP and report it as
`BLOCKED: REAL_BUG` with the evidence instead of adding a guard). Say explicitly which.

## constraints
- Do NOT `git commit`, `git push`, or switch branches. Leave changes uncommitted.
- Do NOT touch `.ai/**`, `storage/cms-themes/**`, `kernel/**` (outside the already-applied
  TemplateEngine change), or any module code.
- Never `git add -A`.
- `scripts/run-tests.php` **deletes `storage/modules.json`** — back it up first and restore
  afterwards (must end with gui-settings ON, all `cms-akira-*` ON, `daily-ledger` OFF).

## acceptance
- `php scripts/run-tests.php` → **0 failed**; SKIPs visible; the retired tests are not discovered.
- `php scripts/run-tests.php --dir=tests/_retired` → discovers nothing (exit 1 "No test files").
- No file content changed beyond: the two rot fixes, the guard additions, the runner change,
  the new `tests/_support/env_guard.php`, the new `tests/_retired/README.md`.
- `php -l` on every touched PHP file; `php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php <files>`
  (options BEFORE files) → "Fixed 0 of N files"; `php vendor/bin/phpstan analyse <files> --memory-limit=1G`
  → `[OK] No errors`.

## deliverable
Report per bucket (A–E), the final suite summary line, the exact list of moved files, the
entity-view investigation verdict, and any unexpected finding. Raw command output as evidence.

---
EXECUTION NOTES (chair):
- You are on branch fix/test-integrity-and-triage. Do NOT commit, push, or branch. Leave everything uncommitted.
- An uncommitted engine fix (kernel/DiSyL/Exceptions/TemplateOutputTooLargeException.php + TemplateEngine.php) is already applied and verified - keep it.
- Back up storage/modules.json before running scripts/run-tests.php and restore it after (gui-settings ON, all cms-akira-* ON, daily-ledger OFF).
- Paste raw command output as evidence for every acceptance item.
