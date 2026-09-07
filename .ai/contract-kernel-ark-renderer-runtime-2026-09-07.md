# Kernel Gate: ARK Renderer-Selection Runtime (Option B) — narrow, additive

task: Add the missing KERNEL runtime consumer for ARK renderer selection: given an active theme and an entity view
identifier, look up the theme's `renderer-registry.json` mapping and resolve it to an executable render target
(DiSyL template under the theme, or a registered component). Additive + opt-in ONLY — existing entity rendering
paths keep their current behavior. This unblocks the CMS Akira fork P1 canonical path
(Entity View → ARK renderer selection → DiSyL → HTML) which is currently BLOCKED because no runtime consumer exists.

objective: A minimal, governed kernel API + an opt-in render path such that a fork module (cms-akira-core) can render
`entity.list.post` → `article-grid` and `entity.detail.post` → `article-page` from the active theme's
renderer-registry.json, while `{ikb_entity_list}`/`{ikb_entity_detail}` inline behavior, EntityViewResolver,
DefaultEntityRenderer, and ThemeManifestValidator remain unchanged. Zero-exception baselines preserved; NO-BROADEN.

## Verified context (main 8c0d5b7)
- Theme root is hardcoded: `storage/cms-themes/<slug>/...` (bootstrap.php:1935-1956 autoloads
  `Ikabud\Themes\<Name>\` from there). `storage/cms-themes` is currently EMPTY in this repo.
- `renderer-registry.json` is consumed ONLY by `kernel/Services/ThemeManifestValidator.php` (validation: renderers
  entry = template path OR `renders_as_component` OR `ark.blocks.*`/`ark.layouts.*` render target, with `controls` +
  `context_keys`). NO runtime consumer exists.
- Theme machinery already present (do not duplicate): `kernel/Contracts/ThemeRenderContext.php`,
  `kernel/Services/ThemeCustomizerOrchestrator.php` (builds ThemeRenderContext from active provider),
  `kernel/Services/ThemeRegionRenderer.php` (renders theme regions from ThemeRenderContext),
  `kernel/Contracts/ThemeCustomizerProvider.php`, `DeclarativeThemeCustomizerProvider`,
  `LegacyCmsCustomizerAdapter`, `kernel/Contracts/ThemeCustomizationScope.php` (used by ThemeRenderContext).
- `active_theme_slug` appears in render context (bootstrap.php:2444). Resolve how an active theme slug is determined
  for a request/tenant; do NOT invent a new activation store — reuse whatever exists (context/customizer provider).
- Entity tags dispatch in `kernel/DiSyL/Component/ComponentRenderer.php`:
  `ikb_entity_list`→renderEntityListViaService(), `ikb_entity_detail`→renderEntityDetailViaService() (both delegate to
  DefaultEntityRenderer); `renderEntityViewConfig()` (L1463) registers views via EntityViewResolver. DiSyL
  template rendering goes through the TemplateEngine.

## scope
  allowed:
    - A NEW narrow kernel service for ARK renderer resolution (e.g. `kernel/Services/ArkRendererResolver.php` or
      `kernel/Ark/ArkRendererSelector.php` — choose after reading the theme stack; keep it one small class).
    - Minimal additive wiring to make the resolution usable (e.g. an `App`-level accessor or a small opt-in
      renderer that a module page can call) WITHOUT altering the default `ikb_entity_list`/`ikb_entity_detail`
      dispatch or DefaultEntityRenderer behavior.
    - A fixture theme under `storage/cms-themes/<fixture-slug>/` for tests (renderer-registry.json + tokens.json +
      minimal templates) — this is a TEST fixture, allowed here because theme root is kernel-fixed.
    - New tests (kernel style) + docs (kernel reference note for the ARK renderer-selection API).
  prohibited:
    - NO change to ThemeManifestValidator validation semantics or renderer-registry schema.
    - NO change to EntityViewResolver / DefaultEntityRenderer / CellRendererRegistry default behavior.
    - NO change to the default `ikb_entity_list`/`ikb_entity_detail` inline render path (must stay byte-compatible).
    - NO new DB tables / migrations / schema (kernel themes are file-based under storage/cms-themes).
    - NO theme-activation store invention (reuse the existing active-theme context/provider resolution; if none is
      reliably resolvable, accept an explicit `theme_slug` parameter with a documented default = the active theme
      when available, null-safe otherwise).
    - NO touching the 13 cms-akira fork submodules, kernel stabilization baselines, or unrelated kernel subsystems.
    - NO forcing all entity pages through ARK selection (must be opt-in so existing pages are unaffected).
    - NO-BROADEN.

## constraints
- Renderer lookup key = the exact entity-view ids the kernel uses: `entity.list.post`, `entity.detail.post`
  (per-entity; generic fallback NOT required in this gate — unknown view → null/graceful, never a '*' generic render).
- Resolution result must distinguish: (a) DiSyL template render target (path resolved under the theme root or via
  the theme autoloader), (b) component render target, (c) ark.blocks.*/ark.layouts.* aliases if the registry uses
  them — resolve to the actual renderer/template the theme declares, per the SAME rules ThemeManifestValidator
  validates (template path must exist in the theme; component must be registered). Do NOT silently render when the
  declared target is missing — return an explicit miss/error the caller can 404 on.
- API shape is yours to finalize (read the code first) but MUST be: small, documented, null-safe on missing
  theme/registry/mapping, and thread-safe (no global mutable state). Prefer a pure resolver returning a small value
  object/array + a thin opt-in render entry that a module template/handler can call.
- PHP 7.4/8.0 compatible (match repo style). Keep `php -l`, PHPStan (no baseline additions), cs-fixer (CI style:
  `): void {` brace-on-same-line).
- All existing tests must stay green (full `composer test`); capability:audit (18) + capability audit (4) +
  Workbench guards zero; both logs clean.

## acceptance
- With a fixture theme `storage/cms-themes/<slug>/renderer-registry.json` mapping `entity.list.post`→`article-grid`
  and `entity.detail.post`→`article-page` (each resolving to an existing template/component), the new API returns the
  correct resolved render target for both ids; unknown id (e.g. `entity.list.product`) → null/graceful miss.
- Missing theme root / missing renderer-registry.json / malformed registry → graceful miss (never fatal, never a
  generic '*' render).
- Default entity rendering unchanged: existing `ikb_entity_list`/`ikb_entity_detail` tests pass byte-identical.
- A small opt-in proof (unit/integration test) shows a page CAN render through the ARK-selected target when it opts
  in, and that NOT opting in leaves the legacy path intact.
- `php ikabud theme:validate <fixture-slug>` style check or the kernel ThemeManifestValidator reports the fixture
  clean (no errors; may have allowed warnings only if pre-existing pattern).
- Full suite green; capability:audit + Workbench baselines ZERO; logs clean; no PHPStan baseline additions;
  `git diff --stat` shows only the allowed kernel/files + fixture + tests + docs.

## verification
- New unit/integration tests for the resolver (mapping resolve, template path exists, component registered, missing
  theme/registry/mapping, malformed registry, unknown view).
- Opt-in render proof test.
- `composer test` full; the audit + Workbench guard suites; both logs.
- Document the new API in docs/kernel (renderer-selection reference) so the fork P1 implementer can consume it.

## risk
- MEDIUM (kernel change). Mitigations: purely additive + opt-in; no schema; default paths byte-compatible; full
  suite + baselines as gate. Scope creep toward a full ARK authority engine is the main risk — prohibited list +
  acceptance keep it to selection-resolution only (tokens/customizer/region rendering already exist elsewhere).

## status: READY_FOR_IMPLEMENTATION (chair-approved Option B; resolves the P1 BLOCKED architecture decision)

## implementation result (2026-09-07)

- Result: PARTIAL — implementation and targeted gates pass; the full repository suite is blocked by pre-existing,
  out-of-scope `modules/cms-akira/*` capability-authority findings (17/18 authority assertions pass).
- Added `ArkRendererResolver`, exposed only through `App::arkRenderers()`. Exact entity-view keys resolve to an
  existing theme-relative DiSyL template, a registered component, or the validator-defined `ark.blocks.*` /
  `ark.layouts.*` paths. All missing or malformed inputs return `null`; `*` is never consulted.
- Added the `ark-renderer-fixture` theme, 17-assertion resolver/render test, and kernel API note. Default
  `ikb_entity_list`, `ikb_entity_detail`, `EntityViewResolver`, and `DefaultEntityRenderer` files/dispatch remain
  unchanged.
- Passing gates: touched PHP lint; targeted PHPStan; PHP CS Fixer dry-run; fixture `theme:validate`; new test 17/17;
  default entity test 17/17; DiSyL engine 312/312; capability audit 4/4; both Workbench guard suites; logs zero bytes.
- Full `composer test`: 101/102 files pass. Sole failure is `capability_authority_audit_test` against ignored,
  pre-existing `modules/cms-akira/*` fork contents; those files are prohibited by this contract and were not edited.
