You are summarizing the CURRENT capabilities of **CMS Akira** in Ikabud Kernel OS 6.x
(repo root /var/www/html/ikabudsix, main 8053dc1). Produce a concise, accurate, evidence-grounded summary answering:
"What can CMS Akira do NOW?" — based on the actual code, NOT aspirations.

## Ground your answer in the real current state (verify by reading)
- `modules/cms-akira/cms-akira-core/` — the bundled product-core (tracked, `_enabled:false` = bundled but
  tenant-activated, NOT auto-installed). Read module.json (capabilities.exposes: cms.post.get@1/list@1/create@1/
  update@1 + entity.list.post@1/entity.get.post@1; depends kernel.idempotency.* + kernel.audit.record@1;
  owns cms_akira_posts; entities.post authority), routes.php, handlers.php, helpers/*.php, migrations/.
- `modules/cms-akira/cms-akira-core/tests/post_read_render_test.php` (38) + `post_mutation_test.php` (26) — what the
  module PROVES it does.
- `storage/cms-themes/cms-akira-posts/` — the ARK theme (renderer-registry.json mapping entity.list.post→
  article-grid, entity.detail.post→article-page; article-grid.disyl/article-page.disyl).
- Kernel features it runs on (read-only references): EntityViewResolver (entity.list.post@1/entity.get.post@1
  versioned fallback), ArkRendererResolver (app()->arkRenderers()), CapabilityBus (requires_protocol v2,
  effects.invalidates: entity.list.post → invalidateEntityCache('post', tenant)), kernel.idempotency.* capability
  bridge, kernel.audit.record@1, capability_authorization_policies (migration 016, admin-only v2 policy), ARK
  authority ADR (docs/architecture/ark-authority-adr.md).
- NOTE: the 13 other cms-akira members (media/seo/ai/editor/theme/workflow/search/profiles) are DORMANT
  (`_enabled:false`, gitignored, NOT functional) — do NOT claim their capabilities are available.

## Produce
A concise "what CMS Akira can do now" summary:
1. One-line positioning (bundled, tenant-activated product-core for canonical Post content).
2. Capabilities actually available (as a list, each with what it does): the 6 exposed capabilities + routes
   (GET /posts, GET /posts/{slug}, POST/PUT /api/v1/cms-akira/posts[/{slug}]).
3. The proven behaviors (from the tests): canonical Post read/render path through Entity View → ARK renderer
   selection → DiSyL; admin-only idempotent mutation with entity-cache invalidation (fresh next render); tenant
   scoping; audit correlation_id; security (CSRF, payload/JWT rejection, stored-output escaping, slug canonical).
4. What it does NOT do yet (clear boundaries): the dormant providers/profiles/builder are NOT active; no admin UI;
   no multi-tenant dedicated-DB parity yet (recorded follow-up); requires user/tenant activation (not auto-installed).
Keep it tight and factual. Cite file:line where useful. Report the summary as your final message.
