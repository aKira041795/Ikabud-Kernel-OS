# CMS Akira Fork — Architectural Reset Debate (round 3)

## Context (verified facts, do not contradict without evidence)

Repo: Ikabud Kernel OS 6.x (ikabudsix, main 8c0d5b7). Stabilization complete: capability:audit baseline ZERO,
EventBus fireDurable on shared Idempotency primitive, HTTP idempotency adoption additive, single canonicalizer,
Workbench guards zero-exception, NO-BROADEN standing, Kernel 7 deferred.

What the kernel NOW provides (the "kernel updates" the fork must align to):
- CapabilityBus five guarantees: authority, tenant, execution, consistency (effects.invalidates post-success
  invalidation, idempotency), evidence (correlation_id trace/audit).
- Entity Context: EntityViewResolver (registerView + invalidateEntityCache(type,tenant) → invalidates
  entity.list.{type}/entity.detail.{type}; resolve → capability ids entity.list.{type}@1/entity.detail.{type}),
  DefaultEntityRenderer, cell renderers, ContextRegistry, CustomizerSchemaBuilder.
- Entity Authority registry + Sync contract registry.
- DiSyL engine + ComponentRegistry (semantic/theme-aware components, design tokens) + ThemeRenderContext.
- ThemeManifestValidator: validates ARK theme renderer-registry.json (renderers → template | ark.blocks.* |
  ark.layouts.* render targets, controls, context_keys) + safe-fallback checks.
- Reference entity-view pattern: modules/daily-ledger/helpers/entity-views.php (registerView with explicit fields)
  + helpers/views/*.disyl. Prior ARK analysis in .ai/ark-* (north star: ARK = reference theme + module-dev
  toolkit; P0: EntityViewResolver/DefaultEntityRenderer safe-fallback doctrine NOT enforced — '*' field default can
  render internal fields).

The fork: modules/cms-akira (14 suite members, 572K) was just copied into ikabudsix/modules/cms-akira from the
full application repo. The legacy `cms` module was INTENTIONALLY NOT copied. Therefore:
- cms-akira-core declares depends ["cms"] and capability-depends on cms.content.get/list/create/update@1 +
  cms.themes.list@1; its akira.content.* adapters delegate to cms.content.*. With cms absent in ikabudsix, the whole
  suite is INERT (dependency safety checks fail; nothing can enable).
- Providers (media/menus/seo/theme/workflow/search/ai/editor/builder) delegate to cms.*, theme-studio, tinymce,
  search module — all absent here.
- No Post entity/table, no entity.list.post/entity.detail.post registration, no ARK theme + renderer-registry.json,
  no article-grid/article-page DiSyL templates, no /posts routes in the fork.

## Decisions already made (do not re-litigate)
1. Fork CMS Akira as an ARCHITECTURAL RESET / CLARIFICATION branch — NOT another rewrite, NOT feature dev. Freeze
   feature development in the fork.
2. First milestone = "CMS Akira Presentation Contract Reset": prove ONE canonical Post path end-to-end:
   domain/CMS-core truth → capability → Entity View (semantic presentation boundary) → ARK (visual authority
   renderer selection) → DiSyL → HTML, with NO ambiguous ownership and NO duplicate registries.
3. Do NOT migrate all 14 submodules, do NOT start Theme Studio / page builder / 50 blocks. Start with one Post,
   two entity views (entity.list.post, entity.detail.post), two ARK renderer mappings (post list → article-grid,
   post detail → article-page).

## Target ownership model (the fork's north star — judge everything against this)
DOMAIN / CMS CORE = content authority (owns truth). ENTITY VIEW = semantic presentation firewall: translates
domain-owned data into a normalized presentation contract (title, subtitle, image, body, metadata, actions, url);
MAY NOT become a theme system, page builder, content model, generic query engine, authorization layer, layout
engine, or ARK registry. ARK = visual authority: decides, given a presentation contract, how the theme expresses it
(tokens, layouts, renderer selection, slots, builder profiles, theme customization schema); MAY NOT own business
entities, DB queries, authorization, business calculations, module capabilities, or entity truth. DiSyL = render
runtime (executes HOW). Builder = editor of valid ARK composition (shared engine across ARK profiles, not per-theme
engines). KERNEL = governance + execution authority.

## Gaps the fork contract MUST resolve (found by inspection)
A. Content authority: cms-akira-core currently delegates content truth to the absent cms module. The fork must make
   its content authority SELF-OWNED and minimal — the canonical Post domain (table + cms.post.get/list/create/
   update@1 owned by the fork, or a clearly named fork-owned content core) replacing the cms.content.* delegation.
   Decide the module-id + capability-id naming so the target model "CMS Core = content authority" is real, not a
   rename of the old delegation.
B. Entity View: register entity.detail.post + entity.list.post with EXPLICIT allowlisted fields/roles
   (title/body/image/subtitle/actions/url) enforcing the safe-fallback doctrine (no '*' leak), and renderer path
   wired to the kernel EntityViewResolver.
C. ARK authority + theme: minimal ARK theme in the fork with renderer-registry.json mapping
   entity.list.post→article-grid and entity.detail.post→article-page, design tokens, and a crisp ARK authority ADR
   (MAY OWN / MAY NOT OWN lists) recorded BEFORE more ARK implementation.
D. DiSyL renderers: article-grid.disyl + article-page.disyl render targets (template or component) reachable via the
   renderer registry; no duplicate registry.
E. Kernel alignment (Phase 2): a post MUTATION capability (cms.post.update@1) that exercises the Kernel 6.x path —
   CapabilityBus authority/tenant/evidence, effects.invalidates → EntityViewResolver.invalidateEntityCache('post',
   tenant) → next ARK render is fresh. Idempotency + audit on the mutation.
F. Explicitly DEFER (record as later phases, do not build now): media/seo/ai/workflow/search/editor providers,
   builder reconnect (Phase 4), Theme Studio, profiles beyond what Phase 1 needs, non-Post entities.

## The debate must produce
An APPROVED, individually-gated CMS Akira fork contract (status READY_FOR_IMPLEMENTATION) containing:
- task/objective for the fork reset (presentation contract reset; one Post canonical path).
- scope.allowed / scope.prohibited for Phase 1 (exactly A–E above; nothing else; NO-BROADEN into kernel/modules).
- the resolved content-authority naming decision (A) with rationale.
- ownership/ADR section: Entity View and ARK responsibility boundaries (B/C) as recorded decisions.
- ordered phases: P1 canonical Post path; P2 kernel-6.x invalidation proof; P3 ARK authority ADR (lock the MAY/MAY
  NOT model); P4 builder-as-composition-editor reconnect (deferred marker).
- acceptance for Phase 1: /posts and /posts/{slug} render through entity list/detail view → ARK renderer selection →
  DiSyL with explicit-field output and no cross-boundary leakage; kernel dependency checks pass; capability:audit
  and Workbench baselines stay ZERO-exception.
- verification commands and risk register.

Constraints: NO second Entity View model, NO second canonicalizer, NO bypassing the kernel EntityViewResolver/
CapabilityBus, NO copying the legacy cms module, NO DDL beyond the fork-owned Post table, MySQL 5.7 compatible,
tenant-scoped. The fork lives inside ikabudsix/modules/cms-akira as a governed suite.

Deliver an agreed fork contract. If the content-authority naming (A) or the ARK authority boundary (C) cannot be
agreed with evidence, return REVISIONS listing exactly what must change.
