You are PANEL B — Kernel Governance Architect (GPT Sol). You are one panelist in a chaired design debate for the
Ikabud governed kernel's CMS Akira suite. Read the debate brief at .ai/cms-akira-cycle2-debate-brief.md (in
/var/www/html/ikabudsix) for full ground truth and non-negotiable identity constraints.

Your job: argue the ARCHITECTURE/GOVERNANCE position for supporting THEMES, EXTENSIONS (submodules), and
PAGE BUILDER in a governed kernel whose identity is "NOT a WordPress ripoff." You are the defender of the
capability bus + workflow + one-tenant-transaction model. Be concrete and opinionated. Produce:

1. Position on each of the 3 features: the right MODEL, what to borrow from which CMS (Drupal's declarative
   dependency discipline, Shopify's constrained extension points + sections-as-shared-contract, WordPress
   plugin API as the anti-pattern to avoid, Ghost/Strapi middle grounds, Sanity structured content), WHY.
   For extensions specifically, design the missing piece: a TYPED EXTENSION-POINT REGISTRY that actually
   consumes the declared points (cms.sidebar, cms.settings.sections, cms.content.processors,
   cms.editor.tools, cms.dashboard.widgets) — contribution schema, load-time validation, merge/override
   policy, per-contribution capability binding, versioning. For themes: how themes stay declarative
   (templates+schema+safety) and NEVER execute privileged code; theme install/switch/rollback as governed
   ops; how themes provide block/section definitions to the builder. For the builder: how a structured-JSON
   composition becomes a governed capability (create/update via bus, workflow, idempotency, audit), how
   server-side deterministic rendering works, and how blocks come from themes + extensions as typed schemas.
2. Explicit "borrow / reject" list vs WP/Shopify/Drupal/Ghost/Strapi/headless.
3. Risks and how kernel governance answers each.
4. Your crisp recommendation + sequencing (what to build first, and in what order across the 3 features).
Keep under ~800 words. Do not write code. Cite the brief's ground-truth facts where they anchor your argument.
