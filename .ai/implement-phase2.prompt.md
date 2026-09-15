# /implement — PHASE 2 — Entity-view render-path cache (Codex Sol)

You are the /implement agent on Codex Sol. Working directory: /var/www/html/ikabudsix (Ikabud-Kernel-OS).
Execute PHASE 2 of the adjudicated roadmap: an OPT-IN fragment cache for entity-view list/detail rendered HTML at
the ComponentRenderer seam, reusing the tenant-partitioned DiSyL FragmentStore. Real edits + real tests.

## Authority & rules (read first)
1. Contract (AUTHORITATIVE): /var/www/html/ikabudsix/.ai/phase2-entity-view-render-cache-contract-2026-09-06.md
   Read scope.allowed / scope.prohibited / constraints / acceptance / verification / risk and follow exactly.
2. Governing: /var/www/html/ikabudsix/.github/instructions/ai-development-execution-handoff.instructions.md
   (/implement sequence, bounded repair loop, result format). /var/www/html/ikabudsix/.github/copilot-instructions.md
   (debug-first, check BOTH storage/logs/app.log + storage/logs/error.log after every run).
3. Scoping research (file:line anchors — read the actual code, do not assume):
   - kernel/DiSyL/Component/ComponentRenderer.php — renderEntityListViaService ~L1999-2108, renderEntityDetailViaService ~L2115-2162.
   - kernel/DiSyL/Cache/FragmentStore.php — tryGet ~L58, put ~L82, invalidate(tags) ~L100; tenant partition.
   - TemplateEngine tenant/fragment seams: kernel/DiSyL/TemplateEngine.php (evaluateCacheBody ~L2505, setTenantId ~L2424);
     App::render sets tenant (~kernel/App.php:1457-1462).
   - FragmentStore/test seams: tests/disyl_v43_cache_exp_test.php, tests/disyl_v44_sandbox_test.php (setFragmentStore).
   - Entity resolution contract to preserve: kernel/EntityContext/EntityViewResolver.php.

## Task (single objective)
Add OPT-IN `cache="<ttl>"` support on entity list/detail component renders with a canonical key
(tenant, source, view, limit, sort, filters, page/cursor, auth scope), tenant-isolated, skipping per-user content
by default unless explicitly opted in; add a kernel invalidator; keep cache SEMANTICALLY TRANSPARENT (byte-identical
HTML, fail-open on store errors); document in docs/kernel/entity-context-system.md.

## Requirements (from contract — implement all)
- Key includes tenant + auth scope; NEVER cache per-user content by default; explicit opt-in distinguishes users.
- tryGet/put on FragmentStore with an entity cache tag (e.g. entity.list.<type> / entity.detail.<type>) + tenant.
- On miss: resolve + render, then put. On store error: fall back to normal render + log (fail-open).
- Invalidator: e.g. EntityViewResolver::invalidateEntityCache(type, tenant) -> fragmentStore()->invalidate().
- No DDL, no MySQL-8 SQL, no capability-dispatch/data-fetch change, no DefaultEntityRenderer behaviour change.
- Tests: new tests/entity_view_render_cache_test.php (plain-PHP pattern; synthetic/fake capability or injected
  store — NO CMS module tables). Cover: miss->store; hit serves identical HTML; invalidate->refresh;
  tenant isolation; user-context opt-in skip; no attr = unchanged. Extend nothing else unless required.
- Doc: docs/kernel/entity-context-system.md (cache contract + opt-in + invalidation contract).

## Hard prohibitions
- Only kernel/DiSyL/Component/ComponentRenderer.php + kernel/EntityContext/EntityViewResolver.php (invalidator
  only) + tests/ + docs/kernel/entity-context-system.md. NO migration/DDL. NO MySQL-8-only SQL. NO ARK/CMS work.
- NO full-suite runs — targeted only.

## Verification (run, capture RAW output)
Write a FULL UNEDITED transcript to /var/www/html/ikabudsix/test_results/phase2-evidence.log (redirect/tee), covering:
- php -l on changed files
- php tests/entity_view_render_cache_test.php (full output)
- the related existing cache/entity tests you rely on (disyl_v43_cache_exp_test.php, etc.) full output
- vendor/bin/phpstan analyse --level=6 --no-progress on changed files
- git diff --check
- wc -c storage/logs/app.log storage/logs/error.log BEFORE and AFTER; report any error-level lines.

## Return format (final message; concise; file:line refs)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:              (files + file:line refs)
implementation_summary: (key construction, opt-in semantics, fail-open behaviour, invalidator, tenant isolation)
verification:         (point to test_results/phase2-evidence.log + summarize counts)
risks:
unresolved:
recommended_next_state: (REVIEW_REQUIRED if done per contract)
Do NOT return empty. Do NOT report success without the raw evidence file existing and complete.
