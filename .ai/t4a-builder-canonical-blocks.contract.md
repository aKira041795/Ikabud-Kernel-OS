# T4a CONTRACT — Page builder, part 1: bind compositions to the canonical theme block contract + deterministic & public rendering

## Why / where we are
Extensibility ADR (PR #95) gate (a)+(b)+(c) are CLOSED, so T4 proceeds. Decomposition (chair):
**T4a = server model + deterministic render + public published rendering + governance** (this slice);
T4b = schema-driven admin editor UI; T4c = React visual canvas.

`cms-akira-builder` is a REAL governed implementation already (not scaffold) — do NOT rebuild it:
- Capabilities: `akira.builder.{compositions,get,revisions,render,create,update,publish,unpublish,delete,validate}@1`.
- Tables: `cms_akira_compositions` (status, version, published_revision_id) + `cms_akira_composition_revisions`
  (base, change_note, tree). Caps: tree ≤256KB, depth ≤8, blocks ≤500.
- `cabBuilderMutate()` = idempotency hash/claim/commit/release + durable audit inside ONE PDO transaction
  (already correct — keep it).
- `CAB_BUILDER_VIEW = 'entity.detail.composition'`; `CAB_BUILDER_INVALIDATION = 'entity.list.composition'`.
- `cab_builder_cap_render_1()` resolves the active validated theme via `akira.theme.resolve@1` then calls
  `app()->arkRenderers()->render(CAB_BUILDER_VIEW, ['composition' => [..., 'html' => cabBuilderBlocksHtml($tree['blocks'])]], $slug)`
  and throws 422 when the theme registers no such view.
- Routes: `/api/v1/cms-akira/builder/compositions...` (all ops) + shell `/cms-akira-shell/compositions[/{key}/edit]`.
- Tests: builder_contract_test, builder_admin_http_bridge_test, builder_dedicated_tenant_test, builder_profile_visual_install_test.

## Confirmed gaps to CLOSE (T4a)
**G1 — the ACTIVE theme registers no composition view.** Only the legacy `cms-akira-posts` theme registers
`entity.detail.composition` (`public/composition-page.disyl`). `akira-ark` (ACTIVE for tenant 54) registers only
`entity.list.post`/`entity.detail.post` → builder render 422s today.

**G2 — the builder uses a BESPOKE hardcoded block vocabulary**, not the canonical theme blocks.
`cabBuilderBlocksHtml()` switch: `section|heading|paragraph|rich_text|image|button`, emitting PHP-built HTML with
classes (`akira-layout-*`, `akira-button`, `akira-rich-text`) that do NOT match the theme's CSS
(`akira-ark-block`, `akira-ark-hero`, `akira-ark-button`, …); unknown type → throw 500.
Meanwhile T2 established the canonical contract: `storage/cms-themes/akira-ark/block-definitions.json`
(contract_version 1.0.0; blocks `hero,richtext,card-grid,quote,cta`; typed props; `renderer.template`
`blocks/*.disyl`; `context_keys:["props"]`), validated by `catThemeBlockErrors()` and exposed via
`akira.theme.blocks@1`. T4-PRE added the engine primitive that makes this renderable:
`{include <identifier-or-property-path> [key=expression ...]}` (dynamic target + whole-map params, depth ≤20).

**G3 — no public published-composition rendering** (all composition routes are admin `/api/v1/...` or shell) and
**no public page-cache invalidation on publish/unpublish**.

## Work items
1. **akira-ark registers the composition view** — add `entity.detail.composition` to
   `storage/cms-themes/akira-ark/renderer-registry.json` + `entity-view-map.json`, backed by a new
   `entity-views/composition.disyl` that renders the composition's sections through the theme's OWN block
   renderers using the T4-PRE dynamic include, e.g.
   `{foreach sections as section}{include section.template props=section.props}{/foreach}`.
   `context_keys` must be safe/projected (only `composition`-derived keys). The theme must still pass
   `catThemeValidate('akira-ark')` (blocks + declarative_content + disyl_lint + registry/ARK resolve).
2. **Composition tree → theme blocks.** Tree sections become `{block: <theme-block-id>, props: {...}, children?: [...]}`.
   Replace `cabBuilderBlocksHtml()` with a resolver that maps a section's block id to the theme's
   `renderer.template` (from the theme block catalogue, read via `akira.theme.blocks@1` / the theme helper) and
   renders through the theme's DiSyL templates — NO PHP string-built HTML. Unknown/invalid block → skip with a
   reported warning + safe fallback (never fatal, never raw HTML, never partial markup).
3. **Validate/create/update against the ACTIVE theme's block catalogue**: block id must exist; props must match
   the block schema types (incl. nested `array`/`object` for `card-grid.items`); document the unknown-prop rule
   (ignore-with-warning or error) and apply it consistently. Keep depth/size/blocks caps.
4. **Public published rendering**: add a public GET route (published-only, anonymous-open, tenant-scoped) that
   renders ONLY published compositions through the theme's public layout — suggest `/p/{key}` (pick one and
   document it). Missing/unpublished → 404. Drafts must never be publicly reachable; `source=preview` stays
   admin-governed through the existing capability.
5. **Publish/unpublish invalidates the PUBLIC page cache** (reuse the T1/T3 pattern:
   `akiraShellInvalidatePublicCache()` / `pageCacheInvalidateModule()`); keep idempotency + audit as-is so a
   duplicate publish replays with ONE audit row.
6. **Tests** (extend builder tests or add focused ones): theme-block binding; validation errors (unknown block,
   wrong prop type); published-only public render + 404 when unpublished; deterministic render (same tree →
   same HTML in interpreted AND compiled modes); safe fallback for an unknown block; duplicate-publish
   idempotency (one audit); cache invalidation. Update any existing builder test that asserts the bespoke
   vocabulary/classes. Do NOT break the legacy `cms-akira-posts` composition view.
7. **Real-env verification (tenant 54)**: create a composition (hero + card-grid) via the capability/API,
   validate, publish, anon GET the public route → theme-rendered hero + cards; unpublish → 404; unknown block →
   validation error; app.log/error.log clean; publish reflected immediately (cache invalidated).

## Constraints
- Scope: `modules/cms-akira/cms-akira-builder/**` + `storage/cms-themes/akira-ark/**` (+ the cms-akira-theme
  module ONLY if a small read helper is genuinely required). **NO kernel/ edits** — the engine primitive from
  T4-PRE is already there; if you think a kernel change is needed, STOP and report BLOCKED with rationale.
- Keep the governed pipeline (idempotency + one-tenant-tx audit) intact; no new tables.
- Tailwind/Alpine/palette for any UI touched; no bespoke CSS. No HTML-as-source: the tree stays structured JSON.
- CI gates MANDATORY before finishing: `php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php <files>`
  (options BEFORE files; no --path-mode) and `php vendor/bin/phpstan analyse <paths> --memory-limit=1G`
  (MULTILINE docblocks for new iterables; new errors are not baselined). `php -l` every touched file.
- Do NOT commit; leave changes for review.
- **AFTER your run**: clear the web APCu module-scan cache (temporary `public/_apcu_reset.php` → curl with
  `Host: akiracms.test` → `apcu_clear_cache()` → delete the script) and re-verify tenant 54 (`/` 200). Transient
  fixture module dirs poison that cache and take tenants offline.

## Acceptance (verify each; report evidence)
1. `catThemeValidate('akira-ark')` valid:true including the new `entity.detail.composition` renderer.
2. A composition referencing `hero` + `card-grid` validates; an unknown block id and a wrong prop type are
   rejected by validate/create/update with a clear error.
3. Published composition renders publicly through the ACTIVE theme (theme CSS classes present, blocks rendered by
   the theme templates); unpublished → 404. Same tree renders identically interpreted vs compiled.
4. Duplicate publish → one audit row (idempotent replay); publish/unpublish invalidates the public page cache.
5. No live composition data was required to migrate (0 rows pre-change).
6. php -l clean; cs-fixer 0; phpstan 0 new errors; existing builder tests + theme tests green; no kernel edits.

## Result format (final message)
status: PASS|FAIL|PARTIAL|BLOCKED
changed:
implementation_summary: (theme_view / block_binding / validation / public_route / governance)
verification: (theme_validate, validate_errors, public_render, unpublished_404, deterministic_modes,
               idempotent_publish, cache_invalidation, tests, php_lint, cs_fixer, phpstan, logs)
scope: unexpected_files:
risks:
unresolved:
Stop + escalate on repeated failure or any need to edit kernel/.
