# CMS Akira Independent-CMS — Phase 4A gate: theme → native `akira.theme.*@1` over ARK

task: Execute the theme re-scope of the APPROVED independent-CMS contract (`.ai/current-task.md` gate 4A). Re-scope
the dormant `cms-akira-theme` (currently LEGACY-CMS residue: `cmsRequireCap`/`cmsActiveTheme`/`cmsRender`/
`cmsAdminContext`/`/cms/admin/customize`/`/cms/admin/themes`) into a NATIVE, table-free Akira theme module that is
the Akira authority over ARK theme resolution + tenant-scoped activation (module setting, NO new table). Phases
0-5B merged (core akira.post.*, shell, install service, editor, navigation, media, seo).

## Current architecture you MUST preserve (read these first)
- `modules/cms-akira/cms-akira-core/handlers.php` → `cacPostThemeSlug()`: returns
  `kernel_request_context_get('active_theme_slug')` when set, else fallback `'cms-akira-posts'`. Core render paths
  (`pageCmsAkiraPosts`, detail) call `app()->arkRenderers()->resolve/render('entity.list.post'/'entity.detail.post',
  $themeSlug)`. This is the ARK de-legacy seam — theme must feed `active_theme_slug` into request context, never
  reintroduce `cmsActiveTheme()`.
- `kernel/Services/ArkRendererResolver.php` (public API `resolve(viewId, themeSlug)`/`render(...)`) + `themesPath`
  = storage themes dir where `storage/cms-themes/cms-akira-posts/` (tracked, P1) lives (theme.manifest.json,
  renderer-registry.json entity.list.post→article-grid, entity.detail.post→article-page, tokens.json, public/*.disyl).
- `kernel/Services/ThemeManifestValidator.php`, `ThemeDefinitionLoader.php`, `DeclarativeThemeCustomizerProvider.php`
  — ARK theme validation/loading surface. Keep them READ-ONLY (Kernel READ-ONLY for this gate).
- `modules/cms-akira/cms-akira-shell/` — the single admin shell (module.json nav). Any admin UI this gate adds must
  be an Akira shell/theme surface, never a legacy-CMS page.
- Sibling native members as structural pattern: `modules/cms-akira/cms-akira-navigation|media|seo/` (module.json
  shape, capability handler maps in helpers, protocol-v2 governed mutations, tests, gitignore tracking).

## Contract specifics (honor exactly — master contract gate 4A)
- Re-scope `cms-akira-theme`: `kind: extension`, `extends: cms-akira-core`, `depends: [cms-akira-core]` only;
  `owns_tables: []`, `reads_tables: []`; migration list keeps ONLY the table-free `001_initial.sql` ledger marker
  (no 002 — no table); NO `nav:` to `/admin/cms-akira-theme`; remove `akira.content.get@1` residue; `_enabled:false`.
- Own capability family `akira.theme.*@1` (freeze names):
  - `akira.theme.resolve@1` — resolve the tenant's ACTIVE Akira theme slug deterministically: module setting first,
    `active_theme_slug` request context second, canonical `cms-akira-posts` fallback; fail closed to the fallback
    only when the theme validates (never an arbitrary/unvalidated slug).
  - `akira.theme.registry@1` — list installed Akira themes visible to ARK (projected-fields-only allowlist), each
    validated (manifest + registry renderers exist); deterministic ordering.
  - `akira.theme.validate@1` — validate a given Akira theme: manifest shape, renderer-registry uniqueness/known
    renderers, DiSyL file presence + `disyl:lint`, traversal protection (no path escaping the theme dir),
    projected-fields-only renderer context_keys, deterministic fallback availability.
  - `akira.theme.activate@1` — GOVERNED v2 mutation (`requires_protocol: v2`, effects.invalidates single canonical
    tag `theme.active`): tenant-scoped activation stored as a module SETTING (canonical key under
    `tenant_module_settings`, mirroring Phase-0 pinned keys — never a new table, never payload-supplied tenant);
    idempotent via kernel.idempotency + durable same-PDO audit via kernel.audit.record@1 (as navigation/media/seo).
    depends: kernel.idempotency.{hash,claim,commit,release}@1 + kernel.audit.record@1 (+ protocol-v2 policy
    seeding via CapabilityAuthorizationRegistry as in navigation/media/seo).
- Tenant separation: setting is tenant-scoped via ModuleDB/tenant context; never take tenant from payload/claims.
  Executable isolation tests (shared + dedicated) since activation is the security-relevant state.
- Injection contract: when the Akira theme module is enabled and active, the resolved `akira.theme.resolve@1`
  slug is what core's `cacPostThemeSlug()` returns — so the module must publish/seed `active_theme_slug` into
  kernel request context on the Akira render path (bridge via a theme resolver the core consults), OR core's
  fallback must consult the theme module setting through a capability call. DO NOT modify core/kernel render logic
  beyond wiring this single seam, and never resurrect `cmsActiveTheme()`. Record the exact seam in README.
- Admin UI: a minimal Akira shell admin surface for theme (list installed themes + active, validate, activate)
  reached under `/cms-akira-shell` (nav in shell) OR the theme module's own kernel-auth admin route under the
  shell guard — never legacy `cmsRender`/`cmsAdminContext`. If a full UI is beyond this gate's minimal surface,
  provide the capability-backed JSON endpoints + a shell health/theme panel that exercises them; record the
  decision. Playwright-tier behavioral evidence on the admin surface is desired if UI shipped.
- Table-free proof: no migration 002; owns/reads empty; activation persistence is a settings write evidenced in
  tests.
- `_enabled:false` retained → TRACK: un-ignore `modules/cms-akira/cms-akira-theme/**` in `.gitignore` (CMS Akira
  block, after seo). Explicit tenant activation via module-install service.
- README: theme authority model (module setting = source of truth; request-context injection seam; ARK fallback),
  capability list, activation/idempotency/audit semantics, validate surface, traversal protection.

## Deliverables
1. modules/cms-akira/cms-akira-theme re-scoped (native module.json akira.theme.* + empty owns/reads + only
   table-free 001 migration; native handlers + helpers + capability handler map; REMOVE all legacy residue),
   README.
2. Tenant-scoped activation setting (+ any kernel request-context seeding seam) with tests.
3. Track via .gitignore; commit source + tests.
4. Tests (mirror sibling layout): resolve (setting→context→fallback, unvalidated rejected), registry
   (projected allowlist, ordering), validate (manifest/registry uniqueness/DiSyL-lint/traversal/projected-fields
   cases incl. hostile theme paths), activate (v2 governed: idempotent, audited, invalidates theme.active, tenant
   isolation shared+dedicated, no-table proof), authority zero on this member, certify.
5. Append result to the contract.

## Verification (do all)
- Theme tests green + dedicated-tenant case; P1 38 + P2 38 + shell 21 + install 23 + editor 25 + navigation 35 +
  media 37 + seo 39 green; authority 18/18; capability audit zero; module:certify --all (incl theme); composer
  full; phpstan + cs-fixer (tracked theme); logs clean; CI 6/6 (branch feat/independent-cms-phase4a-theme, commit,
  push, PR, checks 6/6, merge, checkout main+pull; report PR #; then docs-result PR merged too, as prior phases).
- grep over tracked theme (incl staged ignored): no `cmsActiveTheme`, `cmsRender`, `cmsAdminContext`,
  `cmsRequireCap`, `akira.content.get@1`, `/cms/admin/`; record the git ls-files proof. `php ikabud theme:validate`
  + `php ikabud disyl:lint` on the cms-akira-posts theme clean.
- MySQL 5.7 clean (n/a — no table) and no accidental 002 migration.

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state: (Phase 6 Workflow ready?)

---

## Result (Phase 4A)

status: PASS

task: Re-scope the dormant `cms-akira-theme` into a NATIVE, table-free Akira theme authority
(`akira.theme.*@1`) over ARK, with tenant-scoped module-setting activation and a minimal
shell-guarded admin surface. Kernel ARK services (ArkRendererResolver/ThemeManifestValidator/
ThemeDefinitionLoader) READ-ONLY.

changed:
  - modules/cms-akira/cms-akira-theme/** (module.json, helpers.php, handlers.php, routes.php,
    README.md, database/migrations/001_initial.sql, tests/theme_contract_test.php,
    tests/theme_dedicated_tenant_test.php)
  - .gitignore (un-ignore `modules/cms-akira/cms-akira-theme/**` under the CMS Akira block)
  - src/helpers/module-manager.php (accept the canonical non-entity invalidation tag
    `theme.active` in capability effects alongside entity list/detail tags)
  - src/helpers/module-registry.php (add `tenantWriteModuleSetting()` — the KernelPDO-escalated
    generic tenant-scoped setting upsert table-free modules use, so activation writes bypass
    module-table enforcement legally from src/helpers)

implementation_summary: Native table-free theme authority. `akira.theme.resolve@1` resolves the
tenant active slug deterministically (module setting → `active_theme_slug` request context →
validated `cms-akira-posts` fallback) and rejects unvalidated slugs fail-closed.
`akira.theme.registry@1` lists ARK-visible themes in deterministic slug order with an explicit
projected-field allowlist. `akira.theme.validate@1` checks manifest shape
(ThemeManifestValidator), renderer-registry view-id uniqueness/known renderers, DiSyL presence +
lint (balanced blocks + v4 parser), traversal protection (realpath confinement), projected
`context_keys` (no tenant_id/id/provider/*), and fallback availability. `akira.theme.activate@1`
is governed v2 (`effects.invalidates: ["theme.active"]`), idempotent via
kernel.idempotency.{hash,claim,commit,release}@1, durable same-PDO audit via kernel.audit.record@1,
and persists as the tenant-scoped module setting `active_theme_slug` (module `cms-akira-theme`)
with no new table. Request-context seam: `catSeedActiveThemeRequestContext()` publishes a validated
activation setting as kernel request context `active_theme_slug`, which core's existing
`cacPostThemeSlug()` already reads and passes to `app()->arkRenderers()` — no core render rewrite
and no legacy CMS active-theme helper. Admin surface: plain-HTML shell-guarded `/cms-akira-theme`
plus capability-backed JSON endpoints under `/api/v1/cms-akira-theme/`. `_enabled:false` retained.

verification:
  - Theme tests green: theme_contract_test.php 36/36; theme_dedicated_tenant_test.php 7/7
  - P1 38/38, P2 38/38, shell 21/21, install 23/23, editor 25/25, navigation 35/35, media 37/37,
    seo 39/39
  - authority 18/18; `php ikabud capability:audit --json` → ok, 0 findings
  - `php ikabud module:certify --all` → all pass (cms-akira-theme 13/13)
  - `composer test` → 106/106 passed
  - phpstan (tracked cms-akira members + modified src/helpers) clean; php-cs-fixer (tracked theme
    + modified src/helpers) clean
  - `php ikabud theme:validate cms-akira-posts` clean; `php ikabud disyl:lint
    storage/cms-themes/cms-akira-posts` clean
  - Forbidden-residue grep clean over tracked theme (incl staged ignored); proof:
    `git ls-files modules/cms-akira/cms-akira-theme` → 8 files (previously ignored)
  - Logs clean after success and negative runs
  - CI 6/6 on PR #47 (kernel-contracts, test mysql-8/5.7/mariadb-10.6, static-analysis,
    coding-standards); merged with `gh pr merge 47 --merge --delete-branch`

scope: .gitignore, modules/cms-akira/cms-akira-theme/**, src/helpers/module-manager.php,
src/helpers/module-registry.php

unexpected_files: none

risks: none blocking. Pre-existing `php ikabud architecture:check` still reports the dormant
`cms-akira-profile-standard` (provider `cms`) auth-route contract violation — unrelated to theme,
out of scope, resolved at the profile gate (9B).

unresolved: none

recommended_next_state: Phase 6 (Workflow) ready.
