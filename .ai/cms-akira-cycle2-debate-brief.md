# CMS Akira Cycle 2 Debate — Themes / Extensions / Page Builder

## Charge
CMS Akira must support, as first-class governed features: (1) THEMES, (2) EXTENSIONS (submodules),
(3) PAGE BUILDER. Debate the best way to implement, grounded in best practices of other popular CMS
(WordPress/Gutenberg, Drupal, Ghost, Shopify, Strapi, Sanity/headless, Elementor/Squarespace), and in
the Ikabud governed-kernel reality. Produce positions, trade-offs, and a recommendation. Outcome feeds an
ADR + roadmap contract.

## Non-negotiable identity constraints (from prior ADR — the "not a WordPress ripoff" thesis)
- Every mutation is a governed capability call: capability bus v2 + policy-as-sole-authority (versioned
  policy rows), workflow-authoritative lifecycle (post status = workflow state projection), idempotency +
  audit in ONE tenant transaction, page-cache invalidation. Themes/extensions/builder must NOT bypass this.
- Module boundaries: kernel provides routing/auth/hooks/capabilities; modules provide business features.
  Cross-module access is capability-only (adapter rule: delegate, never re-implement, never foreign SQL).
- Tenant-scoped (dedicated DB per tenant), DiSyL templating + EntityViewResolver/DefaultEntityRenderer,
  ARK theme services. Design language: Tailwind CDN + Alpine; NO bespoke CSS.
- "If DiSyL doesn't support it, fix DiSyL at the engine root" — engine first, not template bandaids.

## Current state ground truth (verified 2026-09-10)
### Suite / extensions
- modules/cms-akira suite: `cms-akira-core` (kind product-core, declares extension_points:
  cms.sidebar, cms.settings.sections, cms.content.processors, cms.editor.tools, cms.dashboard.widgets),
  9 extension members (ai, builder, editor, media, navigation, search, seo, theme, workflow — kind
  extension, extends cms-akira-core; most are thin scaffolds), 4 profiles (minimal/standard/visual/
  headless — install/enable bundles), `cms-akira-shell` (standalone-application, auth owner).
- Suite Extension Contract v1 exists: suite/kind/extends/contributes/extension_points/
  admin_contributions/compatibility/uninstall fields in module.json; `php ikabud make:module X --suite=cms-akira`.
- GAP: the declared extension_points are NOT consumed by any runtime registry. Only cms.sidebar has an
  admin_contributions consumer path (cms-akira-theme deep-links Theme Studio; shell renders it).
  No typed contribution schema, no merge/override policy, no per-contribution capability, no versioned
  contribution contract, no dashboard-widget/settings-section/editor-tool/content-processor consumers.

### Themes
- Kernel: ThemeDefinitionLoader, ThemeRegionRenderer, ThemeCustomizerOrchestrator,
  DeclarativeThemeCustomizerProvider + ThemeCustomizerProvider contract, docs/kernel/
  theme-owned-customizer-architecture.md, CLI `theme:validate <slug>` / `theme:inspect <slug>`.
- Theme dirs are declarative artifacts: theme.manifest.json, customizer.schema.json, safety-policy.json,
  entity-view-map.json, renderer-registry.json, layouts/ + templates/regions/ + entity-views/ (DiSyL).
- Runtime themes under storage/cms-themes/: `akira-ark` (active tenant 54, live public site),
  plus fixtures ark-renderer-fixture, cms-akira-posts. Active theme selected per tenant (active_theme_slug).
- cms-akira-theme module hosts Theme Studio UI (/admin/theme-studio) + akira.theme.customize@1
  (customizer_values persisted in tenant_module_settings). Public render path: anon GET / and /posts via
  entity views, published-only.
- GAPS: single hardcoded active theme, no theme install/upload/list/switch/enable UI, no child/base
  concept, no per-route/template theme override, blocks/sections not theme-provided yet, no theme
  versioning/rollback, no theme marketplace/packaging story.

### Page builder
- cms-akira-builder module: compositions migrations (001/002) + admin-ui/ React scaffold (committed
  node_modules), README. Shell has Compositions nav (list only). No editing canvas yet.
- Reference builder (modules/cms + builder-ui React, Vite+TS, NodeRenderer.tsx, builder-renderers.php,
  PHP server renderers) lives in the SIBLING repo (/var/www/html/applicationostest). Docs in THIS repo:
  docs/page-builder/page-builder-technical-spec.md + docs/page-builder/page-builder-engineering-breakdown.md.
- Principle already documented: builder source of truth = structured JSON documents; avoid HTML-as-source.
- ADR R7 descoped P6 (page-builder/import-export/AI) pending user direction. This cycle reopens it.

## Research anchors — what popular CMS do (be concrete, cite patterns)
- WordPress: themes = PHP template hierarchy + functions.php hooks (dangerous, code=theme); plugins =
  global add_action/add_filter + activation hooks (powerful but unbounded, can touch everything, security
  nightmare); block themes = theme.json + block templates + template parts; Gutenberg = block = editor JS
  + save/render, blocks serialized in HTML comments; plugins register block types. Lesson: separation by
  CONVENTION only; no real sandbox/permission model; the plugin API is why WP is hackable-by-design.
- Shopify: strongest model of CONSTRAINED extension. Sections (schema + block defs) live in themes;
  apps mount into declared extension points (theme app extension blocks, admin links) with scoped API
  tokens; liquid = safe template language. Sections are shared contract between themes and apps.
- Drupal: modules (.info.yml) + hooks + plugins/services + strict dependency graph; themes (Twig)
  separate from modules; regions/blocks; Layout Builder = layout sections over entities, stored as
  structured layout overrides. Strongest *declarative + dependency + separation* discipline.
- Ghost: themes = Handlebars templates + routes; apps = constrained API surface; one active theme;
  editor = cards (structured). Clean but limited extension story.
- Strapi: plugins register content-types/controllers/services + admin panel contributions; hook system;
  RBAC. Good middle ground.
- Sanity/Contentful/Builder.io: content = structured data; presentation decoupled (headless); visual
  builders edit structured documents mapped to components (block/portable-text model). Lesson: builder =
  structured document + deterministic renderer; separation of content vs layout vs code.
- Elementor/Squarespace/Wix: full WYSIWYG canvas, proprietary serialization, vendor lock, poor
  accessibility/governance — anti-patterns for a governed kernel.

## Debate panels (independent positions)
- Panel A — Product/UX & CMS best-practice synthesis (chair).
- Panel B — Kernel governance architecture (delegated: GPT Sol) — how each feature integrates with
  capability bus / workflow / entity views / DiSyL / ARK, security boundaries, typed extension registry.
- Panel C — Contrarian/risk (cheap lane) — attacks: is 3-feature build over-ambitious, ordering,
  YAGNI vs roadmap, WP-lock-in risk of building a builder at all, migration cost from reference app.

## Required outputs per panel
1. Position on each of the 3 features: WHAT the right model is, WHAT to borrow from which CMS, WHY.
2. Explicit "borrow / reject" list vs WP/Shopify/Drupal/Ghost/Strapi/headless.
3. Risks + how kernel governance answers them.
4. A crisp recommendation (best-of-breed synthesis) + suggested sequencing (what to build first).
Keep each position under ~700 words. Be concrete and opinionated, not generic.
