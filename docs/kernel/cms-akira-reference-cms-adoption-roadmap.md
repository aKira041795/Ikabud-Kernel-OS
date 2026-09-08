# CMS Akira ← Reference CMS Adoption Roadmap (POC of the governed kernel)

Date: 2026-09-08 · Status: AUDIT COMPLETE — phased adoption next
Owner directive: CMS Akira is the POC proving what the governed Ikabud Kernel (kernel +
DiSyL + Entity Views + ARK + Theme Studio) can deliver. Rescan the reference CMS module,
learn, copy the best implementations, improve. What works stays; what needs updating, fix.
Do NOT reinvent working UI — mirror the reference (Tailwind CDN + Alpine + module palette),
then improve by ingesting the kernel pipeline.

## Reference source of truth
App: `/var/www/html/applicationostest` — module `modules/cms` + `templates/modules/cms`.

Reference feature/helper inventory (the "best implementations" to learn from):
- helpers: 00-bootstrap, 05-permissions, 10-core, 12-user-services, 15-utils, 20-seo-public,
  28-service-tokens, 30-media, 40-theme-settings, 45-block-render, 50-builder,
  55-capabilities, 56-entity-capabilities, 57-entity-contexts, 58-entity-views,
  59-weather-entity, 60-cache, 65-taxonomy, 70-menu, 72-saved-blocks, 74-revisions,
  76-extensions-editor, 77-import-export, 78-public-context, 80-customizer, 81-ark-components,
  85-ai-automation, 86-ai-search-grounding, 99-misc; entity view configs helpers/views/{cms_page,cms_post}.disyl
- admin pages (templates/modules/cms/admin/*): dashboard, content-list, content-editor,
  content-types, categories, media-library, menus, users, permissions, settings, themes,
  theme-customizer, page-builder, react-page-builder, modules, extensions, redirects,
  import-export, report-approvals, ai-automation, weather
- layouts: admin.disyl, public.disyl
- public pages: login/register/forgot/reset, home, entity.list, entity.view, single, page,
  archive, search, 404, weather
- patterns worth copying: entity-view content lists/detail, media library, taxonomy CRUD,
  menu manager, role/permission matrix, theme-customizer (ARK), DiSyL composite pages,
  Tailwind+Alpine admin shell, capability-gated actions, revisions, SEO/redirects.

## CMS Akira today (ikabudsix, POC)
- Suite modules/cms-akira/* (core, shell, editor, theme, navigation, media, seo, workflow,
  search, ai, builder + profiles). Governed: capability bus, entity authority, dedicated
  tenant DB provisioning, module-owned login/shell, Kernel Entity-View posts list.
- Admin pages: Dashboard, Posts list (+/new), Compositions, Module health. Shell styled per
  reference (Tailwind CDN + Alpine + Akira palette, PR #70).
- Missing vs reference: taxonomy, media library UI, menus UI, users/permissions admin,
  content types, revisions UI, import/export, settings UI, SEO/redirects, ARK/Theme Studio
  theming, richer content editor, dashboard analytics, public entity rendering, etc.

## Identity resolution (2026-09-09) — governed content operations, not a WP clone
See docs/architecture/cms-akira-identity-adr.md (multi-model debate: 3 panel + DeepSeek Pro +
GPT Sol — convergent). CMS Akira is the tenant-isolated governed content-operations layer of
Ikabud OS 6: WordPress-familiar in FORM, but every content operation is a schema-declared,
role-governed, idempotent, audited state transition rendered through a deterministic
ARK/entity-view projection.

Collapse condition: any primary UI/API path that creates/publishes/reads/renders content while
bypassing the kernel's authoritative lifecycle, tenant-policy context, or projection contract
makes Akira a WP clone with optional plumbing.

Three non-negotiable commitments: (1) one authoritative lifecycle (workflow; status is a
projection), (2) one governed tenant boundary (no per-module tenant re-derivation), (3) one
declared publication boundary (ARK/entity-view projection contracts).

R1–R7 (decision list, full table in the ADR): R1 workflow-authoritative lifecycle [P0 blocker];
R2 non-admin roles via workflow allowed_actions [P0/P3]; R3 policy row = sole role authority +
bind caller_module [P0/P3]; R4 one kernel governed-write convention (delete per-module CSRF /
policy-seed / tenant re-derivers; remove live `_csrf_token` in cms-akira-theme/helpers.php:728)
[P0 blocker]; R5 govern reads at policy layer [P3/P5]; R6 HTTP-only two-tenant acceptance
journey as release gate (fix DiSyL Alpine parsing at engine) [P0 exit]; R7 roadmap ordering
below [release scope].

## Adoption phases (each = Sol feature branch → PR → CI; verify via Playwright)
NOTE — R7 re-prioritization (differentiation-first, per identity ADR): **no WordPress-shaped
surface merges until R1–R3 + R6 are green (CI differentiation gate).** Media/menus (P2) and
page-builder (P6) are descoped to thin/late until the governance seams are the default path.
- P0 Core editorial: port reference admin content-list + content-editor + dashboard onto the
  Kernel Entity-View pipeline (list/detail/create/update for Post + Composition); styled per
  reference admin shell; capability-gated actions; empty states; toasts. Reuse ref files:
  templates/modules/cms/admin/{dashboard,content-list,content-editor}.disyl + helpers/56..58.
  **P0-repair (blocking):** R1 workflow-authoritative publish + R2 non-admin roles + R3 sole
  policy authority + R4 single kernel governed-write convention + R6 HTTP-only journey.
- P1 Content model: content-types + taxonomy (categories) + tags; revisions. Ref: admin/
  content-types.disyl, categories.disyl; helpers/65-taxonomy.php, 74-revisions.php.
- P2 Media + menus: media-library UI + uploads over kernel media; menu manager over navigation.
  Ref: admin/media-library.disyl, menus.disyl; helpers/30-media.php, 70-menu.php.
- P3 Admin shell completeness: users + permissions (role matrix over kernel capability auth),
  settings, module list. Ref: admin/{users,permissions,settings,modules}.disyl.
- P4 ARK + Theme Studio: bring ARK theme (ref storage/cms-themes/ark) + theme-customizer UI
  into cms-akira via kernel theme services (ThemeCustomizerOrchestrator etc.); theme admin +
  public rendering through ARK entity-view/layout patterns; DiSyL theme tokens.
- P5 Public rendering: home / entity.list / entity.view / single / archive / search over the
  kernel public pipeline (ARK), SEO/redirects. Ref: modules/cms/public/*, helpers/20-seo-public.php.
- P6 Advanced (POC garnish): page-builder surface, import/export, AI automation, report approvals.

## Ground rules for every port
- Copy the working reference implementation first (structure, helpers, templates, styling);
  improve by routing through the kernel: Entity-View pipeline, capability bus + authorization,
  DiSyL compiled templates, per-tenant DB via tenant context. Keep governed provisioning intact.
- Mirror reference styling (Tailwind CDN + Alpine + palette); never hand-roll bespoke CSS.
- Keep what already works (auth, provisioning, capabilities, PR #64-70 outcomes).
