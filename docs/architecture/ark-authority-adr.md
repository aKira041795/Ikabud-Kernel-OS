# ADR — ARK Authority Boundary (ratified 2026-09-07, CMS Akira fork P3)

## Status
Ratified. This ADR locks the ARK responsibility model that the CMS Akira fork already implements (P1 renderer
selection via `renderer-registry.json` + `ArkRendererResolver`; P2 invalidation through the kernel). Any ARK surface
beyond the two ratified Post mappings (article-grid / article-page) must first pass through this boundary.

## Context
ARK accumulated machinery whose purpose became hidden by its implementation. To keep presentation ownership
understandable, ARK is given ONE authority: **visual authority** — deciding, given a presentation contract produced
by Entity View, how a theme expresses it. Everything else belongs elsewhere (Domain/CMS Core = truth, Entity View =
semantic boundary, DiSyL = render runtime, Kernel = governance + execution, Builder = ARK composition editor).

## Decision — ARK MAY OWN
- Renderer selection (entity view → render target via `renderer-registry.json`)
- Design tokens (typography, spacing, colors, breakpoints)
- Theme layouts (page, article, archive, landing)
- Slots (header, hero, main, sidebar, footer)
- Renderer/component presentation metadata (`controls`, `context_keys`)
- Theme customization schema
- Builder profiles (allowed blocks / composition rules — declared by ARK, edited by Builder)

## Decision — ARK MAY NOT OWN
- Business entities (e.g. Post records, orders, products)
- Database queries / SQL
- Authorization decisions / permissions / roles
- Business calculations / workflow decisions
- Module capabilities
- Entity truth / canonical data
- Entity View contracts (field projection, semantic roles, URL projection — that is Entity View's job)
- Kernel governance / execution authority

## Consequences
- ARK consumes only the projected presentation contract (title, subtitle, image, body, metadata, actions, url); it
  never reaches into domain tables or the capability bus for data.
- Renderer selection is a single registry (`renderer-registry.json`), consumed by the kernel
  `ArkRendererResolver`; no second/duplicate selection mechanism.
- Entity View stays the semantic firewall between applications and presentation; ARK is no longer asked to solve
  Entity View's problem.
- Builder is an editor of valid ARK composition over the shared engine — not per-theme builder engines.
- Enforcement: kernel `ThemeManifestValidator` validates ARK manifests/registries; the fork P1/P2 tests assert the
  projected-contract-only boundary (no internal/tenant/provider field leakage reaches ARK/DiSyL).

## References
- Fork contract: `.ai/contract-fork-cms-akira-2026-09-07.md` (ownership ADR section)
- Kernel ARK runtime: `kernel/Services/ArkRendererResolver.php`, `docs/kernel/ark-renderer-selection.md`
- Entity View system: `docs/kernel/entity-context-system.md`
