Produce the authoritative **phased roadmap for the 13 dormant CMS Akira suite members** in Ikabud Kernel OS 6.x
(repo root /var/www/html/ikabudsix, main 8053dc1). Directions are already clear — do NOT debate or re-open
architecture; turn them into an ordered, individually-gated phase plan grounded in the actual dormant manifests.

## Ground truth (verify by reading)
- Active: `modules/cms-akira/cms-akira-core/` (tracked, `_enabled:false`, tenant-activated). Owns `cms_akira_posts`;
  exposes cms.post.get/list/create/update@1 + entity.list.post@1/entity.get.post@1; Post Entity Authority; P1
  render routes (/posts, /posts/{slug}) + P2 mutation routes (POST/PUT /api/v1/cms-akira/posts[/{slug}]); uses
  kernel.idempotency.*, kernel.audit.record@1, effects.invalidates → invalidateEntityCache('post', tenant), ARK
  theme (storage/cms-themes/cms-akira-posts) via ArkRendererResolver.
- Dormant (all `_enabled:false`, gitignored/untracked scaffolding, NO current functionality): cms-akira-theme,
  -navigation, -media, -seo, -workflow, -search-adapter, -editor, -ai, -builder, and profiles
  -profile-minimal/standard/visual/headless. READ every `modules/cms-akira/<member>/module.json` to inventory each
  member's declared exposes/depends/host residue (`host: cms`, `depends cms.menus.*/cms.media.*/cms.seo.*`,
  entry_module/authentication_provider cms on profile-standard, etc.) + read their helpers/capabilities to see what
  they delegate to.
- Kernel context: five guarantees (authority/tenant/execution/consistency/evidence), EntityViewResolver, ARK
  renderer runtime + authority ADR (docs/architecture/ark-authority-adr.md), capability_authorization_policies
  (016), kernel.idempotency.* bridge, kernel ai.* OPTIONAL capabilities, WorkflowEngine. MAIN-CMS-REPO = the repo
  where canonical CMS/Ecommerce/WMS/Guidance modules + theme-studio/tinymce/search live (module adoption milestone).

## Chair classification (the phase skeleton — apply, don't relitigate)
Classify each of the 13 dormant members as ONE of:
- **FORK-NATIVE (buildable in this kernel repo now, over cms-akira-core content authority + kernel caps)** — e.g. a
  workflow provider over kernel WorkflowEngine; an ai provider over kernel ai.*; a seo provider scoped to the fork's
  own Post content metadata. These get concrete build phases here.
- **ADAPTER-TO-MAIN (NOT buildable meaningfully here — they are bridges to canonical owners that only exist in
  MAIN-CMS-REPO: cms.media.*, cms.menus.*, cms.seo.resolve@1 (canonical CMS), theme-studio, tinymce, search
  indexer)** — these STAY dormant/untracked in this repo and become MAIN-CMS-REPO milestones (do NOT invent fake
  canonical owners). For each, state the main-repo dependency it must wait on + what un-blocks it.
- **INSTALL-BUNDLE (profiles)** — only meaningful once their installed member set exists; re-enable/track at the end.
  profile-standard's `entry_module:true` + `authentication_provider:"cms"` must be RESOLVED (in the fork there is no
  cms auth shell — decide: re-scope to a content-only install bundle, or drop/flag as main-repo-only; pick the
  cleanest and state it).
- **BUILDER (cms-akira-builder)** — the P4 marker (ARK composition editor reconnect over the shared ARK engine +
  existing React builder); LAST, own milestone.

## Deliverable
Write `.ai/cms-akira-dormant-roadmap-2026-09-07.md` with:
1. Classification table: member → FORK-NATIVE | ADAPTER-TO-MAIN | INSTALL-BUNDLE | BUILDER → main-repo dependency (if
   any) → evidence (module.json line refs of the residue).
2. Ordered phases (each = own gate with scope/acceptance, mirroring the fork P1/P2 gate style):
   - Phase N+1: the FORK-NATIVE members buildable now (order by cohesion with Post content + kernel caps; each:
     host-residue cleanup, capability/entity-view alignment to the fork model + kernel, authority-audit-zero +
     certify + member tests, then TRACK (un-gitignore) + enable).
   - Phase N+2: INSTALL-BUNDLE profiles over whatever is active (incl. the profile-standard entry/auth decision).
   - Phase N+3 (deferred marker): ADAPTER-TO-MAIN members → MAIN-CMS-REPO milestones (list the exact canonical
     owner each waits on).
   - Phase N+4 (deferred marker): BUILDER reconnect (P4).
3. Acceptance rules every gate must meet (reuse: zero-exception baselines, module:certify, guard clean, logs clean,
   tests green, diff vs pristine = intended deltas only).
4. Risks + recorded follow-ups.

Keep it tight and factual. Do NOT build anything — this is a roadmap/contract only. Report the file path + a 5-8
line executive summary as your final message.
