# CMS Akira Cycle 2 — Debate Synthesis & Adjudication (chair)

Date: 2026-09-10
Feature: THEMES · EXTENSIONS (submodules) · PAGE BUILDER
Panels: A = Product/UX + CMS best-practice (chair); B = Kernel governance architecture (GPT Sol);
        C = Contrarian / scope + risk (flash). Full positions: `.ai/cycle2-panel-a-chair.md`,
        `.ai/cycle2-panels-bc.log`.

## Where the panels CONVERGE (this is the strong signal)

1. **Target model is shared by all three.** Themes = declarative presentation packages (manifest +
   customizer.schema + safety-policy + entity-view map + DiSyL templates), sandboxed, **never executable
   PHP** (Shopify/Ghost/Drupal). Extensions = typed, capability-bound contribution points against declared
   extension-point IDs with schemas + least-privilege binding (Shopify apps / Drupal / Strapi / VS Code) —
   explicitly **not** WordPress global hooks. Builder = governed **structured-JSON composition** with a
   deterministic server renderer (Sanity/Shopify sections/Drupal Layout Builder), explicitly **not**
   Elementor proprietary blobs, not Gutenberg HTML-comment serialization, not client-authoritative DOM.
2. **The capability bus enforces every boundary** — the "WordPress shape, kernel-governed function"
   identity means themes/extensions/builder are governed artifacts, never privileged code that bypasses the
   bus. Cross-module access stays capability-only (existing adapter rule).
3. **Builder is the payoff, not the foundation** — all three panels rank it last; it depends on a
   block/section schema contract that does not exist yet and must be defined by themes first.
4. **Theme operations must be governed capabilities** (activate/rollback = capability + one-tenant tx +
   audit + page-cache invalidation + validated package). Theme infra already exists in the kernel —
   reuse, don't rebuild.

## Where the panels GENUINELY disagree

| Question | Panel B (governance) | Panel C (contrarian) | Panel A (product, chair) |
|---|---|---|---|
| When to build the Extension-Point Registry | NOW — it is the keystone; unblocks safe third-party themes/extensions and typed builder slots | NEVER speculatively — ceremony is dead weight at the first-party boundary; only the theme artifacts are a real trust boundary today | Registry is the missing piece, but keep schemas minimal; add points only when a real consumer appears |
| Scope this cycle | All three, registry-led | Theme lifecycle ONLY; defer registry generalization + builder | Registry → themes → builder, but builder clearly last |
| Risk the others under-weight | Supply chain / conflicts / schema drift | Second-system builder, migration debt from the sibling repo, **hidden DiSyL engine cost**, multi-tenant theming sprawl | Schema drift between theme blocks and builder blocks |

## Chair adjudication — "contract now, machinery when earned"

The disagreement is about **sequencing and scope discipline**, not the target architecture. Resolution:

1. **Commit the architecture now (cheap, prevents drift):** an ADR fixes the target model, the shared
   **block/section schema contract** (the one contract all three features depend on), and the refusal list.
   This is the decision record; it is not a promise to build speculative machinery.
2. **Deliver by live value + consumer-driven gates** (Panel C is right that the user is one operator and
   there is no third-party boundary yet — the registry's cost is only justified at a trust boundary):
   - **T1 — Theme lifecycle v1 (do first).** Governed install/validate/activate/rollback as
     `akira.theme.*@1` capabilities, audit + idempotency + page-cache invalidation in one tenant tx;
     formalize per-tenant customization overlay. Closes the real gap (hardcoded active theme), touches live
     traffic, reuses `ThemeDefinitionLoader` / `theme:validate` / `active_theme_slug` / `akira.theme.resolve@1` —
     no new registry machinery. **Adds theme-provided block/section definitions** (hero, richtext, card-grid…)
     — this is the block-schema contract taking its first real shape.
   - **T2 — Extension-point registry, proven by one real first-party consumer.** Wire the declared points
     (cms.sidebar already half-built → generalize; add cms.dashboard.widgets and cms.settings.sections) to a
     typed registry where every contributed surface binds to its own capability. Ship ONE real consumer
     (e.g., cms-akira-seo dashboard widget + a settings section) so the registry **earns its design with a
     live consumer** before any third-party trust. Only points with a consumer get schemas (no speculative
     generalization of all five).
   - **T3 — Page builder (gated, last).** Green-lit only when: (a) the block-schema contract exists via
     T1+T2, (b) a **documented DiSyL block-engine gap list is closed at the engine root** (Panel C's hidden
     line item — the operating directive already says fix DiSyL first, never template bandaids), (c) explicit
     user direction. Port the proven reference builder patterns as governed compositions (structured JSON,
     deterministic DiSyL server render, React canvas edits JSON only, preview = server render of draft);
     never invent a parallel block registry — reuse renderer-registry.json + EntityViewResolver.
3. **Explicit refusals this cycle** (Panel C, accepted): no marketplace, no unsigned third-party theme/extension
   upload; no child-theme or per-route-override sprawl; no Elementor-style canvas; no HTML-as-source; no
   client-authoritative rendering; no generalized registry for all five points without consumers; no parallel
   block registry.

## Net position
Build themes + extensions + page builder in that order, each as a governed artifact, against one shared
block-schema contract, gated by real consumers — and refuse every mechanism that would let a theme or
extension or builder page act like a WordPress plugin. That is how CMS Akira gets the three capabilities
without losing the "kernel-governed function" identity.
