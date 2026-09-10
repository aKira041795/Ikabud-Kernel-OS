# CMS Akira Extensibility — Architecture Decision Record (ADR)

Date: 2026-09-10
Status: **ACCEPTED (architecture)** — T1–T4c implemented and merged (#95–#105); T5 deferred (see roadmap + refusal list)
Product status: **CMS Akira is both the proof that the governed Kernel works and the product** — the
         reference CMS for this kernel and a serious CMS contender. Judge every change as product
         code (UX, error pages, determinism, observability) *and* as a demonstration of the governed
         architecture (capability bus → policy row → idempotency + audit in one tenant transaction →
         cache invalidation).
Scope: CMS Akira suite — THEMES, EXTENSIONS (submodules), PAGE BUILDER support on Ikabud Kernel OS 6
Evidence: three-model panel debate (2026-09-10) — Panel A product/UX best-practice synthesis (chair),
         Panel B kernel-governance architecture (GPT Sol), Panel C contrarian/risk (DeepSeek flash).
         Synthesis: `.ai/cycle2-debate-synthesis.md`.

## Context / Problem

CMS Akira (identity ADR 2026-09-09, R1–R7, mostly shipped) must now support, as first-class governed
features: **(1) themes, (2) extensions (submodules), (3) page builder.** The suite already has:
declarative extension manifests (`product-core` + 9 `extension` members + 4 profiles + shell) with five
declared extension points — but **no runtime registry consumes them** (only `cms.sidebar` has a partial
consumer path); a single hardcoded active theme (`akira-ark`, live on tenant 54) backed by real kernel theme
machinery (`ThemeDefinitionLoader`, `ThemeRegionRenderer`, `ThemeCustomizerOrchestrator`, provider contract,
`theme:validate|inspect`, declarative theme packages, Theme Studio); and a builder member with only
compositions tables + a React scaffold — the proven reference builder lives in the sibling repo.

The open question: **how best to implement themes, extensions, and a page builder so CMS Akira gains
those capabilities without becoming WordPress** — i.e., without themes-as-executable-code, extensions as
ambient global hooks, or a builder that writes HTML and bypasses governance.

## Debate outcome (multi-model, convergent)

All three panels converged on the target model; they disagreed only on sequencing/scope discipline.

**Convergence:** Themes = declarative, sandboxed presentation packages (never executable PHP). Extensions =
typed, capability-bound contribution points (never WP global hooks). Builder = governed structured-JSON
composition with deterministic server rendering (never Elementor blobs / HTML-comment serialization /
client-authoritative DOM). The capability bus enforces every boundary; cross-module access stays
capability-only. The builder is the payoff (last), depending on a block/section schema contract that themes
define first. Theme operations are governed capabilities reusing existing kernel machinery.

**Disagreement:** when to build the extension-point registry — Panel B: now (keystone); Panel C: never
speculatively (ceremony is dead weight until a trust/third-party boundary exists; only theme artifacts are a
real trust boundary today); Panel A: registry is the missing piece but consumer-driven.

**Adjudication:** *contract now, machinery when earned* — commit the architecture + shared block-schema
contract in this ADR, then deliver by live value with consumer-driven gates.

## Decision — Extensibility architecture

> **CMS Akira is extensible by contract, not by convention.** Themes are declarative, sandboxed,
> swappable presentation packages. Extensions (submodules) contribute to **typed extension points** whose
> schemas, ordering, and least-privilege capability binding the kernel validates and enforces at load and at
> runtime. Pages are **structured-JSON composition documents** rendered deterministically server-side from
> validated block definitions. Every theme op, extension contribution, and composition write is a governed
> capability call — one tenant transaction, idempotent, audited, cache-invalidated. A theme, extension, or
> builder page can never act like a WordPress plugin.

### One shared contract — the block/section schema

Themes and extensions both contribute **blocks/sections**; the builder renders them. Therefore the
**block/section schema is the single integration contract** across all three features (Shopify-sections
insight). A block = `{id, schema(typed props), defaults, allowed_nesting, slots, renderer/template,
a11y constraints, contract_version}`. Owned by `cms-akira-core`; versioned; renderers come from theme
`renderer-registry.json` + entity views (reuse, no parallel block registry).

## Decisions to resolve (T-series)

| # | Decision | Recommended answer | Gate | Acceptance (testable) |
|---|----------|--------------------|------|------------------------|
| T1 | Theme lifecycle v1 | Governed install/validate/activate/rollback as `akira.theme.*@1` capabilities; per-tenant active theme + formalized tenant customization overlay; audit + idempotency + page-cache invalidation in one tenant tx; reuse `ThemeDefinitionLoader`/`theme:validate`/`active_theme_slug`/`akira.theme.resolve@1` | **T1 do first** (live value, closes hardcoded-theme gap) | Activate theme B → public renders B with zero code change, audit row written, cache invalidated; rollback restores A; a theme carrying PHP/SQL/migrations is rejected at validate |
| T2 | Theme-provided block definitions | `akira-ark` ships a small block set (hero, richtext, card-grid, quote, cta) with typed schema — the block-schema contract takes real shape | T1 (blocks land with themes) | Block schema validates; a block renders through theme renderer-registry deterministically |
| T3 | Extension-point registry | Wire declared points (generalize `cms.sidebar`; add `cms.dashboard.widgets`, `cms.settings.sections`) to a typed registry: contribution schema per point, deterministic merge (explicit `replaces`, no implicit override), quarantine on invalid contribution, **each contribution binds to its own least-privilege capability** (not the hosting screen's) | T2 (only after a real consumer exists) | A first-party consumer (e.g., cms-akira-seo dashboard widget + settings section) renders via registry; disabling the extension removes the widget; a contribution whose capability is revoked is denied at render with zero code change |
| T4 | Page builder (governed composition) | `akira.composition.create/update/publish@1` through the standard pipeline (capability → optional workflow → idempotency + audit in one tenant tx → cache invalidation); composition = versioned structured-JSON doc `{schema_version, sections:[{block, settings, content_refs, children}]}`; content refs resolve via capabilities at render (content stays authoritative); deterministic DiSyL server render; React canvas (ported from sibling reference, NodeRenderer semantics) edits JSON only; preview = server render of draft | **T4 gated, last**: requires (a) block-schema contract via T2/T3, (b) a documented DiSyL block-engine gap list closed at the engine root, (c) explicit user direction | Publish a composed page → public render deterministic + published-only; draft preview cannot bypass publication policy; duplicate publish idempotent (one transition/audit); block with revoked capability renders safe fallback |
| T5 | Import/export + AI builder add-ons | Layer on the composition document model post-T4 | Deferred (unchanged from ADR R7/P6) | — |

## Explicit refusals (this cycle)

- No marketplace and no **unsigned third-party theme/extension upload** (supply-chain surface; only validated
  packages, first-party this cycle).
- No child-theme or per-route-template-override **sprawl** (a base theme + per-tenant customizer overlay is
  the ceiling for now).
- No WordPress `functions.php`-style theme code and no ambient plugin hooks (the anti-pattern this ADR exists
  to prevent).
- No Elementor-style proprietary canvas, no Gutenberg HTML-comment serialization, no **HTML-as-source**:
  builder source of truth is structured JSON (existing documented principle).
- No client-authoritative rendering: the browser edits JSON; the server renders.
- No generalized registry for all five extension points **without a consumer** — schemas ship per point only
  when a live consumer exercises them.
- No parallel block registry: block renderers reuse theme `renderer-registry.json` + `EntityViewResolver`/
  `DefaultEntityRenderer`.

## Consequences

**Positive:** themes/extensions/builder are safe to make first-party- then third-party-extensible without
reopening the WP attack surface; one block-schema contract prevents schema drift between theme blocks and
builder blocks; every new surface is auditable, tenant-scoped, idempotent, and revocable by policy.

**Negative / accepted:** T4 (builder) is deliberately deferred until the block contract + DiSyL engine gaps
are real; some registry ceremony is deferred until a consumer earns it; operators do not get a visual builder
"now."

**Risks & mitigations:** theme supply chain → no unsigned uploads + validation; extension conflicts → typed
contracts + explicit `replaces` + quarantine; schema drift → versioned block contract owned by core; builder
XSS/a11y → schema validation + escaped DiSyL rendering + approved primitives; partial writes/cache → the
standard one-tenant-tx pipeline (proven by posts); hidden DiSyL engine cost → fix DiSyL at the engine root
first per operating directive, budgeted before T4.

## Roadmap / sequencing (lives in docs/kernel/cms-akira-reference-cms-adoption-roadmap.md)

**T1** theme lifecycle v1 (+ theme block definitions) → **T3** extension-point registry proven by one real
first-party consumer → **T4** page builder (gated) → **T5** import/export + AI. Each slice is a governed
implement→review→CI→merge slice under the standard workflow.
