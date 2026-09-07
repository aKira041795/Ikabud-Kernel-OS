You are the /implement + /review agent for Ikabud Kernel OS (repo root /var/www/html/ikabudsix, main 8c0d5b7).
Execute the **ARK Renderer-Selection Runtime (Option B)** kernel gate per the authoritative contract:

    .ai/contract-kernel-ark-renderer-runtime-2026-09-07.md

READ THE CONTRACT FIRST — it is small and authoritative. It is a KERNEL change (governed repo) but deliberately
narrow + additive + opt-in. Do not broaden.

## Context anchors (verify, don't guess)
- Theme root hardcoded: bootstrap.php:1935-1956 maps `Ikabud\Themes\*` → `storage/cms-themes/<slug>/src/...`;
  `storage/cms-themes` is empty here. `active_theme_slug` appears in render context (bootstrap.php:2444).
- `renderer-registry.json` is validated ONLY by kernel/Services/ThemeManifestValidator.php (renderers entry =
  template path | renders_as_component | ark.blocks.*/ark.layouts.* target; controls + context_keys). NO runtime
  consumer. Read validateArkContracts()/validateRendererRegistry() (~L376-775) for the exact accepted shape.
- Theme stack already present — reuse, don't duplicate: kernel/Contracts/ThemeRenderContext.php,
  kernel/Services/ThemeCustomizerOrchestrator.php, ThemeRegionRenderer.php, ThemeCustomizerProvider contract,
  DeclarativeThemeCustomizerProvider, LegacyCmsCustomizerAdapter.
- Entity tags: kernel/DiSyL/Component/ComponentRenderer.php `ikb_entity_list`→renderEntityListViaService(),
  `ikb_entity_detail`→renderEntityDetailViaService() (DefaultEntityRenderer); renderEntityViewConfig() L1463.
- You choose the exact new files/seam after reading; the contract pins the WHAT + acceptance, not the WHERE.

## Deliverables (contract scope ONLY)
1. NEW narrow kernel service that, given (entity view id like `entity.list.post`/`entity.detail.post`, optional
   theme slug), resolves the active theme's renderer-registry.json mapping to an executable render target (DiSyL
   template under the theme root OR registered component OR ark.blocks/layouts target resolved per the SAME rules
   ThemeManifestValidator validates). Missing theme/registry/mapping/malformed → graceful null/miss, NEVER fatal,
   NEVER a generic '*' render. Unknown view → null.
2. Minimal additive accessor/render entry so a module page can OPT IN to render through the ARK-selected target.
   Default `ikb_entity_list`/`ikb_entity_detail` dispatch + DefaultEntityRenderer MUST stay byte-compatible (no
   change to those paths).
3. Test fixture theme under storage/cms-themes/<fixture-slug>/ (renderer-registry.json mapping
   entity.list.post→article-grid + entity.detail.post→article-page + tokens.json + the two minimal DiSyL templates
   so targets exist) + tests.
4. Tests: resolver mapping resolution (both ids → correct target), template-exists + component-registered checks,
   missing theme/registry/mapping, malformed registry, unknown view → miss; opt-in render proof; default-path
   byte-compat proof (existing ikb_entity tests pass unchanged).
5. docs/kernel renderer-selection API note (short) so the fork P1 implementer can consume the new API.
6. Append result to the contract file.

## Verification (do all)
- php -l every touched file; phpstan targeted clean (no baseline additions); cs-fixer CI style (`): void {`).
- Your new tests pass; `composer test` FULL green; capability_authority_audit_test (18) + capability_audit_test (4)
  + Workbench guard suites unchanged; BOTH storage/logs/app.log + error.log clean.
- `git diff --stat` within contract scope only.

## Constraints
- Bounded repair max ~3 rounds. If the active-theme resolution is not reliably available and an explicit
  theme_slug parameter is insufficient for a clean API, STOP → return BLOCKED with the options (do not invent an
  activation store or DB schema).
- Do NOT weaken baselines; do NOT alter ThemeManifestValidator/EntityViewResolver/DefaultEntityRenderer semantics.

## Report (compact result block)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state:
