# T2 CONTRACT — Theme-provided block definitions (the block-schema contract's first real shape)

## Context
Extensibility ADR (docs/architecture/cms-akira-extensibility-adr.md, merged PR #95): the block/section schema
is the single integration contract shared by THEMES, EXTENSIONS and the future PAGE BUILDER. T2 gives that
contract its first concrete instance: the active theme `akira-ark` ships a small typed block catalogue.
Read the ADR's "One shared contract — the block/section schema" section + the theme files before coding.

Current akira-ark theme (storage/cms-themes/akira-ark, untracked runtime + tracked layout files — see git):
- theme.manifest.json, customizer.schema.json, safety-policy.json, entity-view-map.json, renderer-registry.json
  (entity.list.post / entity.detail.post -> entity-views/*.disyl), slots.json (header.main/content/footer.main,
  accepts component|entity_list|entity_detail), tokens.json, layouts/public.disyl, templates/regions/*.disyl,
  entity-views/*.disyl, public/.
- Theme lifecycle module modules/cms-akira/cms-akira-theme: registry/validate (catThemeValidate + G3 deny-list
  content scan from T1 PR #96), akira.theme.*@1 capabilities, Theme Studio. T1 made catThemeValidate reject
  executable files and lint every .disyl under the theme dir (catThemeLintDisyl).

## Task (scope)
1. DEFINE the canonical block contract (one authoritative schema, versioned). A block:
   { "id": "hero", "label": "Hero", "category": "layout|content|media", "schema": { "props": {
     "title": {"type":"string","label":"Title","default":""}, ... } }, "defaults": {...},
     "slots": [] | [{"name":"body","accepts":["content"]}], "renderer": {"template":"blocks/hero.disyl",
     "context_keys":["props"]}, "contract_version":"1.0" }
   Use simple, typed props mirroring the existing renderer-registry context_key discipline + customizer schema
   style (no free-form HTML; strings are auto-escaped; no JS). Put the contract validator in ONE canonical
   place (recommend a new small helper/class in modules/cms-akira/cms-akira-theme, e.g.
   catThemeBlockErrors(string $themeDir): list<string> mirroring catThemeTreeContentErrors, called from
   catThemeValidate as a new check `blocks`), so T3/T4 can reuse it.
2. THEME PAYLOAD: akira-ark ships `block-definitions/` (one JSON per block, or one block-definitions.json —
   pick the cleaner option) for at least: hero, richtext, card-grid, quote, cta. Each has typed props
   (title/subtitle/eyebrow/items/quote/cite/cta label+href etc. as appropriate), defaults, no executable
   content, and a DiSyL RENDERER TEMPLATE under akira-ark `blocks/*.disyl` that renders the props using the
   theme's existing design tokens/CSS classes (look at akira-ark entity-views/post-list.disyl + the inline
   <style> in layouts/public.disyl for the house style; you may add a small number of block CSS rules to that
   <style> block — PR #94 made style blocks safe in compiled mode). Renderers are deterministic, self-contained
   DiSyL (context = the block props object) so T4 can drop them into compositions later.
3. VALIDATE: catThemeValidate must validate block definitions (shape/type/duplicate-id/renderer template exists
   & is a theme .disyl/context_keys safe like the existing renderer checks). Blocks/*.disyl must pass
   catThemeLintDisyl (already recurses theme .disyl). akira-ark MUST remain valid under T1 G3 (no .php/etc).
4. READ SURFACE (thin): expose the active theme's validated block definitions. Add capability
   `akira.theme.blocks@1` (mode first, read, provider cms-akira-theme) returning {theme_slug, blocks:[...]}
   for the active theme, + a GET route /api/v1/cms-akira-theme/blocks (authed admin like the other theme JSON
   routes). Optionally a minimal server-side render proof: /api/v1/cms-akira-theme/blocks/{block}/render with
   JSON props (authed) that renders ONE block through its DiSyL template deterministically (escaped) — this
   proves renderers work and de-risks T4. Keep it read-only + deterministic.

## Constraints
- Scope: modules/cms-akira/cms-akira-theme/** + storage/cms-themes/akira-ark/** + routes.php. Do NOT touch
  kernel/ (if a kernel change feels unavoidable, STOP and report BLOCKED). No module.json capability map change
  outside cms-akira-theme. No builder/pages rendering (T4 later), no parallel registry (reuse the theme
  registry + renderer pattern), no client JS in blocks.
- Blocks are declarative: no PHP/SQL/executables, no arbitrary HTML, strings auto-escaped via existing
  esc_html/esc_attr filters in templates. Follow akira-ark house style (CSS vars + utility classes already in
  the theme; NO bespoke CSS framework additions, NO Tailwind CDN in the theme — it currently uses plain CSS).
- Version numbers match /^\d+\.\d+\.\d+$/.
- Theme files under storage/cms-themes are the runtime theme; akira-ark layout/region files may be tracked in
  git (check `git ls-files storage/cms-themes/akira-ark`) — commit only files that are ALREADY tracked; new
  block-definition + blocks templates under akira-ark should be committed ONLY if akira-ark itself is tracked
  (match its tracking status; if untracked, they are runtime artifacts verified locally but not committed).
- Tests: add/extend theme_contract_test.php or a new focused test file under
  modules/cms-akira/cms-akira-theme/tests that (a) validates a block-definitions catalogue structurally
  without needing the full synthetic-tenant scenario (prefer testing catThemeBlockErrors + catThemeValidate on
  the real akira-ark theme dir, and a hostile block carrying a bad renderer/duplicate id/prohibited content),
  (b) is hermetic. NOTE: theme_contract_test.php currently ends with a PRE-EXISTING synthetic-tenant
  DB-resolution failure (documented in T1 PR #96) — do NOT try to fix that here; put new assertions where they
  run (before that terminal scenario) or in a new focused test file that bootstraps like it but only exercises
  validate/block logic with a local synthetic tenant id and no policy-requiring capability calls.
- Leave working tree changes for review; run php -l on touched files. Report.

## Acceptance (verify each)
1. akira-ark block catalogue (>=5 blocks) validates: catThemeValidate('akira-ark') -> valid:true including a
   new `blocks` check; a hostile block (duplicate id, missing renderer, renderer template not .disyl /
   escaping theme dir, prop type not allowed) -> valid:false with the offending block/path.
2. akira.theme.blocks@1 returns the typed definitions for the active theme; the GET route works authed.
3. Block render proof: rendering e.g. hero with JSON props returns escaped, well-formed HTML from the theme's
   DiSyL renderer (no errors in app.log/error.log).
4. Existing theme validate/lifecycle still green (akira-ark valid under T1 G3; activate/rollback unaffected).
5. php -l clean; no kernel/ edits; no new log errors during HTTP checks.
6. shell/theme tests you can run stay green (run the theme test file you extend + shell_contract_test; full
   composer test only if quick — it clobbers storage/modules.json: restore gui-settings ON, cms-akira-* ON,
   daily-ledger OFF).

## Result format (return in final message)
status: PASS|FAIL|PARTIAL|BLOCKED
changed:
implementation_summary:
  block_contract:
  theme_blocks:
  validation:
  read_surface:
verification:
  php_lint:
  block_validate: <akira-ark valid? hostile rejected?>
  blocks_api:
  render_proof:
  tests:
  logs:
scope:
  unexpected_files:
risks:
unresolved:
Stop + escalate on repeated failure or any need to edit kernel/.
