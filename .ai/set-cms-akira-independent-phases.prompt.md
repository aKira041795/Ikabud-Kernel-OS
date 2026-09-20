Produce the authoritative **phased roadmap to make CMS Akira an INDEPENDENT, self-contained CMS** in Ikabud Kernel
OS 6.x (repo root /var/www/html/ikabudsix, main 8053dc1). Direction is clear — do NOT debate; turn it into an
ordered, individually-gated build plan grounded in the actual manifests.

## THE DIRECTIVE (owner, non-negotiable)
**CMS Akira must be a SEPARATE CMS — an independent module product, NOT an adapter over the legacy `cms`.**
It must be: installable per tenant; with its own builder, themes, ARK support, media, navigation, editor, and the
rest — all SELF-OWNED (kernel-only dependencies). The legacy `cms` module (which lives in MAIN-CMS-REPO) is NOT a
dependency and is NOT copied. Every member that previously "delegated to canonical cms.* owners" is re-scoped as a
native CMS Akira capability owning its own data/behavior over `cms-akira-core`'s content authority + Kernel 6.x.

## Ground truth (verified)
- Active core (tracked, `_enabled:false`, tenant-activated): `modules/cms-akira/cms-akira-core/` — owns
  `cms_akira_posts`, exposes cms.post.get/list/create/update@1 + entity.list.post@1/entity.get.post@1, Post Entity
  Authority, P1 render + P2 mutation routes, uses kernel.idempotency.* / kernel.audit.record@1 / effects.invalidates
  / ARK theme (storage/cms-themes/cms-akira-posts) / ArkRendererResolver. P1 removed the old `akira.content.*`
  adapters.
- Inventory of the 13 dormant members (all `_enabled:false`, gitignored scaffolding; VERIFIED they are all
  non-functional): every extension/adapter depends on `akira.content.get@1` (DELETED in P1) and/or foreign caps:
  - cms-akira-navigation: + cms.menus.get@1, cms.menus.tree@1 (foreign)
  - cms-akira-media: + cms.media.get@1 (foreign)
  - cms-akira-seo: + cms.seo.resolve@1 (foreign)
  - cms-akira-workflow: + workflow.state.get@1 (KERNEL — ok)
  - cms-akira-search-adapter: + search.index.upsert@1 (foreign)
  - cms-akira-editor: editor.render/normalize/sanitize/validate/assets@1 (currently over tinymce — foreign)
  - cms-akira-ai: akira.ai.summary.suggest@1 (kernel ai.* optional caps available)
  - cms-akira-theme: akira.theme.resolve@1 (over ARK — re-point)
  - cms-akira-builder: no exposes yet (needs its own composition/builder capability)
  - profiles: minimal(installs core+editor), standard(entry_module:true, authentication_provider:"cms" — foreign
    entry!), visual(core+editor+theme+navigation+builder+media+seo+workflow+search), headless(core+search+workflow)
- Kernel provides: five guarantees, EntityViewResolver, ARK renderer runtime + authority ADR, migration 016 authz
  policies, kernel.idempotency.* bridge, kernel ai.* optional caps, WorkflowEngine.

## Framework (apply, don't relitigate) — CMS Akira as an independent CMS
Design the build plan around these pillars; classify every member as a FORK-NATIVE milestone (no "adapter-to-main /
stay dormant" category — that framing is REJECTED):
1. **Independent content + shell**: cms-akira-core stays the content authority (extend beyond Post as phases allow).
   CMS Akira needs its own tenant-entry / admin experience ("installable via tenant") — decide WHERE the entry
   shell lives: re-scope `profile-standard` (currently entry_module+auth_provider:cms) into CMS Akira's OWN
   entry/auth posture over the KERNEL auth (not the foreign cms), OR designate a new/dedicated entry member, OR
   make cms-akira-core itself the entry host. Pick the cleanest for a tenant-installable independent CMS and state
   it (auth_owned/id_column/role_column conventions apply if it owns its admin users, or kernel-auth shell if not).
2. **Native feature modules** (each self-owned over the core content authority + kernel): navigation (own menus),
   media (own media library/tables), seo (own metadata over Akira content), workflow (kernel WorkflowEngine),
   search (own indexer contract or kernel), editor (own content-prep/editor contract — NOT tinymce), ai (kernel
   ai.*), theme (ARK theme authority already started).
3. **Builder** = ARK composition editor over the shared ARK engine (its own module + UI), LAST.
4. **Profiles** = install bundles over the independent members once they exist.
Every member: remove foreign residue → self-owned caps/entity-views → authority-audit-zero + certify + member
tests → TRACK (un-gitignore) + enable at its own gate.

## Deliverable
Write `.ai/cms-akira-independent-cms-roadmap-2026-09-07.md`:
1. Pillar/architecture sketch (1 short section): CMS Akira as an independent CMS on Kernel 6.x (content authority,
   entry shell, native modules, ARK themes, builder, profiles — with the Domain→EntityView→ARK→DiSyL model).
2. Member re-scope table: member → current residue (foreign deps + the deleted akira.content.get@1) → target
   self-owned role + the capability/table/entity-view shape it will own → kernel deps it will use.
3. Ordered phases, each an independent gate with scope + acceptance (mirror fork P1/P2 style): entry shell/content
   first, then native modules by cohesion, then profiles, then builder (last). Include the profile-standard entry
   decision + how "installable via tenant" works (activation + migrations + policy seeding per tenant).
4. Cross-cutting rules (zero-exception baselines, certify, guard clean, tests, logs, diff-vs-pristine) + risks +
   recorded follow-ups (e.g., MAIn-CMS-REPO adoption still for the LEGACY cms consumers only if ever needed).

Keep it tight + factual. Do NOT build anything — roadmap/contract only. Report the file path + a 6-10 line exec
summary as your final message.
