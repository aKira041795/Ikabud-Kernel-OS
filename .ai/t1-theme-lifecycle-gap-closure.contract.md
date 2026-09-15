# T1 CONTRACT — Theme lifecycle gap-closure (NOT greenfield)

## Situation (verify before editing — much already exists)
`modules/cms-akira/cms-akira-theme` ALREADY implements most of "theme lifecycle v1":
- Theme registry + validate: `catThemeRegistryRows()`, `catThemeValidate()`, `catThemeLintDisyl()`; capabilities
  `akira.theme.registry@1`, `akira.theme.validate@1`; GET `/api/v1/cms-akira-theme/themes`,
  `/api/v1/cms-akira-theme/themes/{slug}/validate`.
- Governed activate: `catThemeMutateActivate()` — idempotency (hash/claim/commit/release) + ONE PDO transaction +
  durable audit (`kernel.audit.record@1`, action `akira.theme.activate`, old_data/new_data active_theme_slug) +
  `pageCacheInvalidateModule('cms-akira-shell')` + tenant-scoped `active_theme_slug` write in
  `tenant_module_settings` (const CAT_THEME_SETTING_ACTIVE). Capability `akira.theme.activate@1`.
- Theme Studio admin page: `catThemeAdminPage()` at `/cms-akira-theme` — shows active theme + customizer
  (schema/values via `akira.theme.customizer.schema@1`/`values@1`) + an "Installed themes" list with per-theme
  Activate POST forms (CSRF + idempotency_key) + validate state. Role-gated (admin).
- Per-tenant active resolution via `catThemeResolveActive()` + request-context seeding
  (`catSeedActiveThemeRequestContext`), used by public rendering.
- Tests: modules/cms-akira/cms-akira-theme/tests/theme_contract_test.php + theme_dedicated_tenant_test.php.
- Runtime themes under `storage/cms-themes/` (akira-ark live; ark-renderer-fixture + cms-akira-posts fixtures —
  storage/ is gitignored, untracked).

## Confirmed gaps to CLOSE (T1 = gap closure; do NOT rebuild what exists)
### G1 — Theme Studio unreachable from the CMS Akira shell sidebar
Shell nav builder is in `modules/cms-akira/cms-akira-shell/helpers.php` (~lines 165-185): a `$links` map
(e.g. `posts => ['/cms-akira-shell/posts','Posts']`, gated `permissions`/`users` entries appear only for
admins). Theme Studio is ONLY reachable by direct URL `/cms-akira-theme`. Add an admin-gated nav entry
"Theme Studio" -> `/cms-akira-theme` (match the style/gating of the permissions/users entries). Do not move or
restyle the Theme Studio page.

### G2 — No governed rollback-to-previous theme
Activation records the previous slug only in audit `old_data`. Add:
- Persist `previous_theme_slug` in the same tenant `tenant_module_settings` write at activation time (only when
  the slug actually changes; A->A should not clobber previous). Keep writing `active_theme_slug` as today.
- A governed rollback action: re-activate the previous slug THROUGH THE SAME `catThemeMutateActivate` /
  `akira.theme.activate@1` pipeline (validate -> idempotency -> one tx -> audit action
  `akira.theme.rollback` or reuse activate with an explicit rollback marker -> page-cache invalidation). Add a
  "Rollback to previous" button on the Theme Studio Installed themes list when a previous slug exists and
  differs from active; wire a POST form with CSRF + idempotency_key. If previous is missing/equal, hide/disable.
- Do not bypass the governed pipeline for rollback; rollback must be audit-visible and idempotent.

### G3 — Validate does not reject executable/source files in a theme tree
`catThemeValidate()` lints `.disyl`, checks traversal/manifest/renderer-registry/context_keys/ARK resolution —
but does NOT reject a theme tree that ships `.php`, `.phtml`, `.phar`, `.sql`, `.sh`, `.htaccess`, etc.
Add a tree scan (reuse the traversal-safe directory walker pattern already used for `.disyl` linting in
`catThemeLintDisyl` / `ThemeManifestValidator`) that FAILS validation when a theme contains files with
disallowed extensions; allow only declared theme content (`.disyl`, `.json`, `.css`, `.js`, images, fonts,
`.svg`, etc.). Surface the offending file path(s) in validation errors.

## Constraints
- Scope: ONLY `modules/cms-akira/cms-akira-theme/**` + the shell nav line in
  `modules/cms-akira/cms-akira-shell/helpers.php` + tests. Do NOT touch kernel theme services
  (ThemeDefinitionLoader/ThemeManifestValidator/etc.) unless strictly required — if you believe a kernel change
  is unavoidable, STOP and report BLOCKED with rationale instead of editing kernel/.
- Preserve module boundary + governed pipeline (no new bypass). Tailwind/Alpine/palette styling only, no bespoke
  CSS. Match existing helper/capability conventions (cat* functions, capability handlers map, CSRF field, caller
  module `cms-akira-theme`). PHP 8.5, keep diff minimal.
- No marketplace, no theme upload/install of arbitrary zips, no child themes, no per-route overrides (ADR refusals).
- Tests: add/extend `theme_contract_test.php` for G2 + G3 (use a temp theme tree under a temp themes root so the
  suite is hermetic; follow existing test conventions in the module's tests dir). Keep existing tests green.
- Do NOT commit the working-tree changes; leave them for review. Run `php -l` on every touched file.

## Acceptance (verify all; report each)
1. G1: `/cms-akira-shell` sidebar (admin) shows "Theme Studio" -> `/cms-akira-theme`; non-admin does not see it.
2. G3: `catThemeValidate` on a theme tree containing a `.php` file returns valid=false with the offending path;
   a clean tree still validates.
3. G2: HTTP (authed admin on tenant 54 host akiracms.test): activate theme B (a second valid theme — for LOCAL
   acceptance only, derive a variant of `akira-ark` under `storage/cms-themes/` e.g. `akira-ark-demo` with a
   distinct brand color/token so the switch is observable; storage/ is untracked so this is a local runtime
   artifact, do not commit it) -> anon GET `/` renders with B's identity + a `akira.theme.activate` audit row
   exists + page cache invalidated; "Rollback to previous" restores `akira-ark` -> anon GET `/` renders A again
   + audit row for the rollback. Report status codes/times and the audit rows (grep app.log or the audit table).
   If deriving a second theme is impractical for HTTP, demonstrate G2 via the module contract test with a temp
   theme pair and state clearly that HTTP switch was validated with the derived theme (or why not).
4. Existing theme tests + shell tests still pass (run the two theme test files + the cms-akira-shell test file;
   full `composer test` only if quick — remember it clobbers storage/modules.json: restore gui-settings ON,
   all cms-akira-* ON, daily-ledger OFF afterward).

## Result format (return in final message)
```
status: PASS|FAIL|PARTIAL|BLOCKED
changed: <files>
implementation_summary:
verification:
  php_lint:
  theme_tests: <cmd + result>
  shell_test: <cmd + result>
  g1_http:
  g2_http: <activate + rollback evidence>
  g3_test:
scope:
  unexpected_files:
risks:
unresolved:
```
Stop + escalate (no further edits) on repeated failure, on any need to edit kernel/, or if scope must expand.
