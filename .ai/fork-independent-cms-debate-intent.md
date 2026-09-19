Debate and converge the **CMS Akira Independent-CMS roadmap** into an APPROVED, individually-gated build contract for
Ikabud Kernel OS 6.x (repo root /var/www/html/ikabudsix, main 8053dc1).

## The owner directive (non-negotiable)
CMS Akira must become a **SEPARATE, independent CMS** — a self-contained module product on Kernel 6.x, NOT an adapter
over the legacy `cms` (which lives in MAIN-CMS-REPO and is NOT copied). It must be **installable per tenant**, and
own its own: content authority, admin/tenant entry experience, themes + ARK support, media, navigation, editor,
search, workflow, ai, builder, and install profiles. Kernel-only dependencies. The 13 dormant suite members are all
currently non-functional scaffolding (verified: they depend on the DELETED `akira.content.get@1` + foreign
cms.menus.*/cms.media.*/cms.seo.*/search.index.upsert@1) and must be re-scoped as native Akira capability owners.

## Baseline draft to adjudicate
`.ai/cms-akira-independent-cms-roadmap-2026-09-07.md` (Codex Sol draft — read it). It proposes: a dedicated
`cms-akira-shell` (kernel auth) for the tenant entry/admin experience; removal of profile-standard's legacy
`cms` entry/auth posture; every dormant member re-scoped native (tables/capabilities/entity views/kernel deps
defined); ordered individually-gated phases (core + shell → native modules → profiles → builder LAST); per-tenant
migration + policy seeding + activation/failure behavior; zero-exception audit/certify/test/log/diff gates.

## The debate must verify against the actual code (do NOT trust the draft blindly)
- Kernel auth + tenant-entry conventions (auth_owned / id_column / role_column, kernel auth shells, how the legacy
  cms entry module provides /cms/login; how a NEW tenant-installable shell must register; TenantResolver,
  kernel_tenants, tenant module settings `_module_enabled`, enableModuleForTenant).
- Whether `cms-akira-shell` as a NEW module is right vs making an existing member the shell host vs cms-akira-core
  hosting its own admin — pick with evidence (id collisions with the 13 members, manifest schema, entry-module
  contracts, contribution host must be an installed admin shell).
- Native re-scope feasibility per member: does each need its own DB tables (own migration, MySQL 5.7), capabilities
  (naming: avoid `cms.*` prefix confusion? akira.* or cms-akira.*?), entity views, effects.invalidates; which members
  genuinely need a real canonical owner (media library, menus) vs can defer.
- Builder as ARK composition editor: shared ARK engine (ArkRendererResolver + the P4 marker) + React/Vite — scope for
  an independent CMS's builder.
- "Installable via tenant": exact mechanics (per-tenant activation seeds migrations + authz policy; single-tenant
  default vs APP_MULTI_TENANT_ENABLED=1 parity — kernel idempotency/outbox/authz-policy provisioning parity is a
  recorded follow-up; do not over-promise dedicated-DB parity in this kernel repo).
- Acceptance rules every phase gate must meet (authority-audit zero incl. the dormant-untracked handling, certify,
  guard clean, logs clean, tests, diff vs pristine).

## Deliverable
An APPROVED contract written to `.ai/current-task.md` (the debate tool does this): the converged independent-CMS
build plan with task/objective/scope/constraints/acceptance/verification/risk + the phased gates + the shell +
native-module + builder + profile decisions corrected against evidence. If the draft's shell choice or any re-scope
is wrong/unsupported, return REVISIONS listing exactly what must change (do not rubber-stamp). 3 rounds max.
