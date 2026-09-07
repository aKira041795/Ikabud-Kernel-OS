# P1 ADDENDUM — consume the merged ARK Renderer-Selection Runtime (apply on top of .ai/implement-fork-p1.prompt.md)

The kernel now provides the Option-B runtime (merged main c014cdf, PR #27). P1 MUST consume it; do NOT build a
second selection mechanism. Read `kernel/Services/ArkRendererResolver.php`, `App::arkRenderers()` accessor
(kernel/App.php), and `docs/kernel/ark-renderer-selection.md` FIRST.

## Changes to the P1 prompt
1. THEME PLACEMENT (resolves the earlier blocker): the ARK theme must live at the kernel-fixed root
   `storage/cms-themes/<slug>/` (NOT inside modules/) so it is discoverable/activatable + `php ikabud
   theme:validate <slug>` passes. P1's minimal fork theme = `storage/cms-themes/<slug>/` with
   `renderer-registry.json` mapping `entity.list.post`→`article-grid` + `entity.detail.post`→`article-page`,
   `tokens.json`, `theme.manifest.json`, and the DiSyL templates `article-grid.disyl` + `article-page.disyl`.
   This is now an ALLOWED delta (kernel-fixed theme root; the previous modules-only prohibition is superseded for
   the theme only). Define + record the theme slug (e.g. `cms-akira-posts`) and its deployment lifecycle note.
   Do NOT commit the theme's compiled/public artifacts beyond the theme source.
2. ROUTES consume the resolver: `cms-akira-core` `GET /posts` + `GET /posts/{slug}` handlers resolve the render
   target via `app()->arkRenderers()->resolve('entity.list.post', <themeSlug>)` /
   `app()->arkRenderers()->resolve('entity.detail.post', <themeSlug>)`. If resolve returns null (missing
   theme/mapping) → graceful 404/error (fail-closed; never a generic '*' render). Render the resolved DiSyL target
   (article-grid/article-page) with the projected presentation contract from the `entity.list.post`/`entity.get.post`
   bridge DTO. Keep the FAIL-CLOSED registration guard (contract) BEFORE resolve as well.
3. Theme slug resolution: pass the active theme slug when available (kernel context `active_theme_slug` per
   bootstrap.php:2444); otherwise the fork uses its canonical `cms-akira-posts` slug as the documented default for
   P1 (record which).
4. Restores the local authority baseline: P1 aligning `cms-akira-core` (remove `depends:["cms"]`, `akira.content.*`
   adapters, and the cms.content.*/cms.themes.* capability deps; expose only cms.post.get/list@1 + entity.list.post/
   entity.get.post) is what returns the local `capability_authority_audit_test` to 18/18 (currently 17/18 solely due
   to the unaligned fork). P1 MUST verify: after alignment, `php tests/capability_authority_audit_test.php` → 18/18
   AND the remaining 13 cms-akira submodules do NOT re-introduce findings in P1 (they stay present but their
   findings are P1-known; record them — do not enable them).

## Everything else in .ai/implement-fork-p1.prompt.md + the two authoritative contracts still applies
- `.ai/contract-fork-cms-akira-2026-09-07.md`, `.ai/chair-adjudication-fork-cms-akira-2026-09-07.md`
  (P1-applicable revisions R1 source_schema/field_contracts, R2 two-layer bridges via CapabilityBus, R3 no inactive
  mutation caps, R5 tenant provisioning, R6 stored-output security; R4/R7 are P2).
- Kernel is READ-ONLY (the ARK runtime is already merged; do not modify kernel/).
- Pristine fork baseline for diff: /var/www/html/applicationostest/modules/cms-akira. Module files are gitignored
  (runtime units) — use `diff -rq` to see your delta.
