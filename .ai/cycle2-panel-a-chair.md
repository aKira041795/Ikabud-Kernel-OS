# Panel A — Product/UX & CMS Best-Practice Synthesis (Chair)

Position for the debate on Themes / Extensions / Page Builder in CMS Akira, grounded in what popular CMS
actually got right and wrong. Panel B (Sol) attacks from kernel governance; Panel C (contrarian) pressures
scope. This panel owns the "what do the good CMS do and why" evidence.

## 0. The through-line: layered separation is the differentiator

Every durable CMS converges on the same layering, and the ones that last enforce it structurally while the
ones that rot enforce it by convention only:

```
CONTENT   (structured, governed, versioned)
THEME     (presentation: templates + design tokens + schema — declarative, swappable, sandboxed)
EXTENSION (behavior: typed contribution points, capability-scoped, installable)
BUILDER   (composition: structured documents that reference content + theme/extensions blocks)
```

WordPress is the cautionary tale: it HAS these layers but every boundary is a global hook, so themes carry
PHP, plugins can touch anything, and "themes are presentation" is a convention nobody can enforce. Drupal
enforces more (modules vs themes, dependency graph). Shopify enforces the most (constrained extension
points, sections as a shared contract, sandboxed Liquid). For a governed kernel whose identity is
"WordPress form, kernel-governed function," the answer is the Shopify/Drupal discipline — enforced by the
capability bus — not the WordPress free-for-all. Each of the three features is a **boundary to enforce**,
and each should be built as a governed artifact, not as code that "can do anything."

## 1. THEMES — model: declarative presentation package, sandboxed from code

What the good CMS do:
- **Shopify themes**: Liquid templates + `sections/` where every section is a self-describing unit
  (`{% schema %}` = settings schema + allowed blocks). A page is an ordered list of sections with stored
  settings. Theme = presentation + its own section catalogue. Swap themes, keep content.
- **WordPress block themes** (the part worth copying): `theme.json` = centralized design tokens/global
  styles + block templates + template parts. Pure data describing look-and-feel; themes stopped needing
  `functions.php` for most things.
- **Ghost**: one active theme, Handlebars templates + route/template mapping; themes are zip packages you
  activate; swap is clean.
- **Drupal**: themes (Twig) are strictly separate from modules; base/sub-theme inheritance is declarative.

What to REJECT: WordPress `functions.php` as executable theme code (a theme that runs code is a plugin in
disguise — the #1 WP security hole and the exact thing that blurs "theme" vs "extension"); rigid WP
template hierarchy coupled to content-type naming; fragile code-level child themes.

CMS Akira translation (reuse what exists — do not reinvent):
- A theme is the declarative package the kernel ALREADY reads: `theme.manifest.json`,
  `customizer.schema.json`, `safety-policy.json`, `entity-view-map.json`, `renderer-registry.json`,
  `layouts/`, `templates/regions/`, `entity-views/` (DiSyL) — `akira-ark` proves it. It renders ONLY via
  entity views + region templates + a strict renderer whitelist (safety policy). **No PHP execution in a
  theme, ever** — that boundary is what lets the kernel safely accept third-party themes.
- Make it first-class: a theme REGISTRY (enumerate/validate/install/activate/deactivate/rollback) as
  governed capabilities; per-tenant active theme + a "child/customization layer" = a base theme + a
  tenant overlay of tokens + customizer values (the Theme Studio customizer already persists
  customizer_values per tenant — that IS the child layer; formalize it).
- **Themes also ship block/section definitions** (`block-definitions/` with JSON schema per block) — this
  is the single most important addition because it makes the theme the catalogue the builder consumes
  (Shopify's insight). `akira-ark` will grow a small block set (hero, richtext, card-grid, quote, cta…).
- Theme ops are governed mutations: activate/install = capability + audit + page-cache invalidation (the
  exact pipeline PR #89 already wired).

## 2. EXTENSIONS (submodules) — model: typed contribution points + capability-scoped execution

What the good CMS do:
- **Shopify apps/extensions**: an app declares what it wants to mount (theme app extension blocks, admin
  links, etc.) and gets a scoped surface — the host, not the app, owns the rest. This is the strongest
  "constrained extension" model in production.
- **Drupal modules**: declarative `.info.yml`, dependency graph, hooks/plugins/services registered against
  known types, config schema validated at install. Discipline over magic.
- **Strapi plugins**: register content-types/services/controllers + admin contributions + RBAC; plugin =
  self-describing add-on with a visible admin surface.
- **VS Code / browser extensions**: typed `contributes` manifests + activation events; host validates the
  contribution schema and only enables declared surfaces.
- **WordPress plugins**: global `add_action`/`add_filter`, can reach anything — the anti-pattern (no
  sandbox, no typed contract, catastrophic upgrade/conflict surface).

CMS Akira translation:
- The suite manifest contract + module-manager capability-dependency safety ALREADY give the install/
  enable/dependency layer. What's missing (verified gap) is the **runtime Extension-Point Registry** that
  consumes the declared points: `cms.sidebar`, `cms.settings.sections`, `cms.content.processors`,
  `cms.editor.tools`, `cms.dashboard.widgets`.
- Design: each point gets a typed contribution schema + a resolver. Example shapes:
  - `cms.sidebar` item → `{id, label, route, icon?, permission, order}` (already proven by the
    cms-akira-theme Theme Studio deep-link; generalize it).
  - `cms.dashboard.widget` → `{id, title, size, render_capability, refresh_mode}` (render = a governed
    capability call returning data + an entity-view/widget partial).
  - `cms.content.processor` → `{id, stage, capability, priority}` (pure pipeline transform of content
    docs, capability-gated, must be deterministic — this is where SEO/AI/editor extensions plug in).
  - `cms.editor.tool`, `cms.settings.section` similarly typed.
- Registry contract: validated at module enable (schema + capability existence + caller allowlist from the
  v2 policy), deterministic merge (stable order; duplicate id = load error, not silent override), every
  contributed surface renders/executes behind its capability (tenant-scoped, deny-by-default). Extensions
  keep the adapter rule — delegate via capability bus, never foreign SQL/helpers.
- This is what makes the 9 thin scaffold members (seo/ai/editor/search/media/navigation) real without
  letting them become WP-style plugins.

## 3. PAGE BUILDER — model: builder edits structured composition documents; blocks are typed components

What the good CMS do:
- **Gutenberg**: block = schema + render; a page is nested blocks. Weakness: serialization into HTML
  comments couples content to markup; client-authoritative DOM causes re-save bloat. Lesson: keep the
  block/schema idea, drop the HTML-comment serialization.
- **Shopify sections**: a page = ordered sections, each with stored settings + allowed nested blocks.
  Section schema is SHARED between theme and app ecosystem. Cleanest mental model for "page as data."
- **Sanity/Contentful**: content is structured data; presentation fully decoupled; portable-text/block
  arrays as content. Validates "compose = data, render = deterministic function of data."
- **Drupal Layout Builder**: layout sections with regions over entity content; stored as structured
  layout overrides.
- **Elementor/Squarespace**: WYSIWYG canvas + proprietary serialization + lock-in; poor governance —
  reject for a governed kernel (matches the ADR's "not another WordPress ripoff" and its builder principle:
  structured JSON source of truth, never HTML-as-source).

CMS Akira translation (this is the convergence payoff):
- **The block/section schema is the integration contract**: themes ship block definitions; extensions can
  add blocks/widgets; the builder renders whatever typed blocks exist. One schema language shared by all
  three features — exactly Shopify's sections insight, applied to a governed kernel.
- Composition = the existing `compositions` document, upgraded: a structured JSON tree
  `{version, sections:[{block, settings, content_refs, children}]}` where content references resolve to
  governed content (posts/entities) via capabilities at render time (so a block can show "latest posts by
  category" without embedding data — keeps content authoritative and cacheable).
- Mutations are governed: `akira.composition.create/update/publish@1` through the SAME pipeline as posts —
  capability → optional workflow (draft/review/publish) → idempotency + audit in one tenant tx → page-cache
  invalidate. This is what makes the builder "kernel-governed function," not another canvas that writes HTML.
- Rendering: deterministic PHP/DiSyL server renderer (public) + the React canvas (port the PROVEN
  reference builder patterns from the sibling repo — NodeRenderer semantics, source-of-truth JSON) for
  editing; preview = server render of a draft. Client edits, server renders; client is never authoritative.
- Import/export + AI are later additive layers on the same document model (why P6 import-export/AI were
  deferred — they slot on top once the document contract is fixed).

## 4. Borrow / reject ledger

| Borrow | From | Why |
|---|---|---|
| Sections = self-describing block schema; page = ordered sections | Shopify | Shared contract among themes, extensions, builder |
| Theme as declarative package, no code | Shopify/Ghost | Safe third-party themes; clear boundary vs extensions |
| `theme.json`-style design tokens (customizer) | WP block themes | Look-and-feel as data; Theme Studio already does this |
| Typed contribution manifests + host-enforced surface | VS Code / Shopify apps | Extensions are safe by construction |
| Declarative deps, machine names, load-time validation | Drupal | Upgrade/conflict safety the kernel module-manager already wants |
| Structured content as data; compose=data, render=function | Sanity | Content stays authoritative; builder can't corrupt content |
| Block = schema + render | Gutenberg | Proven authoring model |
| Layout regions model | Drupal Layout Builder | Optional advanced layout mode inside a page |
| Reference builder (NodeRenderer, JSON doc) | Sibling modules/cms | Don't reinvent; port governed |

| Reject | Why |
|---|---|
| WP plugin global hooks | Unbounded, unsandboxed; the anti-governance model |
| `functions.php`-style theme code | Blurs theme/extension; security hole |
| WP HTML-comment block serialization | Content coupled to markup |
| Elementor/Squarespace proprietary canvas | Lock-in; client-authoritative DOM |
| Headless-only (no public rendering) | CMS Akira ships a public themed site |

## 5. Risks (products the other panels must pressure)
- Registry ceremony could exceed value if only 1-2 real extensions exist → keep schemas minimal, add
  points only when a real consumer appears (YAGNI guardrail).
- Theme block definitions + builder both growing schema language = drift risk → ONE block-schema module/
  contract, versioned, owned by core.
- Re-skinning existing akira-ark public routes must stay byte-stable → themes override via entity-view map
  + safety policy, not ad-hoc template patching.
- Builder could recreate the "another WP clone" trap if it becomes HTML-authoring → enforce structured-JSON
  documents + deterministic server render.

## 6. Chair recommendation (draft — pending Panel B/C)
1. **Extension-Point Registry first** (foundation, moderate size): wire the 5 declared points to typed,
   capability-bound contributions; prove with cms.sidebar (already half-built) + one dashboard widget from
   an existing member. Turns "suite is extensible" into a real, safe surface.
2. **Themes to first-class second**: theme registry + install/activate/rollback + formalize
   tenant-customization overlay; ADD theme block-definitions (the schema contract) to `akira-ark`.
3. **Page builder last**: it is the biggest build and its value depends on (2)'s block contract; port the
   proven reference builder as governed compositions consuming theme blocks.

Sequencing rationale: registry first because both themes and builder need a safe contribution surface and it
unblocks third-party work; builder last because it's the payoff that consumes the block contract and must
not be built against a schema that doesn't exist yet. P6 add-ons (import/export/AI) remain after.
