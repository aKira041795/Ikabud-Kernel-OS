# PHASE 2 — Entity-view render-path cache (adjudicated roadmap) — CONTRACT (2026-09-06)

task: Implement the bounded Phase-2 unit of the adjudicated Kernel-OS roadmap: an OPT-IN fragment cache for
entity-view list/detail rendered HTML at the ComponentRenderer seam, reusing the existing tenant-partitioned
DiSyL FragmentStore (file+APCu, zero MySQL), so repeated {ikb_entity_list}/{ikb_entity_detail} renders skip the
per-request capability resolve + HTML rebuild. This is the MySQL-5.7-safe render-path performance lever.

objective: Give entity-view render output a correct, safe, cacheable fast path without DDL, without MySQL-8-only
SQL, without touching data-fetch/dispatch semantics, and without leaking per-user HTML across tenants or users.

## Grounded seams (verified 2026-09-06)
- `kernel/DiSyL/Component/ComponentRenderer.php` — `renderEntityListViaService()` ~L1999-2108 (resolve ~L2048,
  render ~L2107) and `renderEntityDetailViaService()` ~L2115-2162 are the single dispatch seam for
  `{ikb_entity_list}` / `{ikb_entity_detail}`.
- `kernel/DiSyL/Cache/FragmentStore.php` — disk `storage/cache/disyl-fragments/` + APCu; per-tenant (`_global`
  default); `tryGet` ~L58, `put` ~L82, `invalidate(tags)` ~L100, `flushAll` ~L115; reached via `{cache}` tags in
  `TemplateEngine` (evaluateCacheBody ~L2505, dispatch ~L1851). Tenant set on engine in `App::render()`
  (`kernel/App.php:1457-1462` → `TemplateEngine::setTenantId`).
- Entity render is interpreted-mode (`TemplateEngine.php:4166`, list ~:4176-4183) — caching here is orthogonal to
  the compiled path.
- DefaultEntityRenderer is pure formatting of an already-fetched `$rows` array (no SQL) — the cache skips the
  capability resolve (query) + HTML build on repeat renders; N+1/query-shape tuning is a module/CMS-repo concern,
  OUT of scope.

## scope:
  allowed:
    - `kernel/DiSyL/Component/ComponentRenderer.php` (renderEntityListViaService + renderEntityDetailViaService):
      add an OPT-IN `cache="<ttl>"` attribute (and per-view opt-in) on entity list/detail component calls.
    - Canonical cache key MUST include: tenant id, source, view, limit, sort, filters, page/cursor, and auth scope
      (current_user_role). When per-user identity would alter actions/rowData/editable cells, DO NOT cache unless
      explicitly opted in — never leak user-specific HTML. Default = skip caching under per-user identity.
    - Reuse FragmentStore tryGet/put with an entity cache tag (e.g. `entity.list.<type>` / `entity.detail.<type>`)
      and the active tenant; on miss resolve+render then put.
    - Add a small kernel invalidator (e.g. `kernel/EntityContext/EntityViewResolver::invalidateEntityCache()`)
      calling `fragmentStore()->invalidate()` for a type+tenant, so module write handlers / `{invalidate}` can
      refresh. Document the contract + opt-in in `docs/kernel/entity-context-system.md`.
    - Tests: new `tests/entity_view_render_cache_test.php` following the plain-PHP bootstrap pattern
      (see `tests/disyl_v43_cache_exp_test.php`, `tests/disyl_v44_sandbox_test.php` for the FragmentStore /
      setFragmentStore seams). Cover: miss→store; second render serves identical stored HTML; invalidate→refresh;
      tenant isolation (different tenant = different cache row); user-context opt-in skip (no leak);
      cache attr absent = unchanged behaviour. Synthetic/fake capability or injected-store seam — do NOT require
      CMS module tables.
    - php -l on changed files; PHPStan level 6 (no NEW errors); check BOTH logs after runs.
  prohibited:
    - NO migration/DDL; NO MySQL-8-only SQL (window functions/CTEs/JSON_TABLE/SKIP LOCKED) — file+APCu only.
    - NO change to capability dispatch, data fetch/resolve semantics, EntityViewResolver resolution contract, or
      DefaultEntityRenderer behaviour. Cache must be semantically transparent (same HTML, just served from cache).
    - NO caching that can leak across tenants/users (key must include tenant + auth scope; per-user opt-in only).
    - NO ARK/CMS/renderer-breadth work (main CMS repo). NO other roadmap phases. NO full-suite runs.
    - Do NOT add a new migration or config surface beyond what is needed for the opt-in attr default.

### constraints:
  - MySQL 5.7 Compatibility profile: cache is file+APCu; no new DB columns/queries.
  - Semantically transparent: a cache hit MUST return byte-identical HTML to a fresh render for the same key.
  - Tenant isolation is mandatory; auth-scope in key; per-user content never cached by default.
  - Fail-open: any FragmentStore error must fall back to a normal render (never serve stale/wrong HTML silently as
    authoritative — log the cache failure and render).
  - Kernel style: PSR-12 + PHPStan level 6 (existing baseline tolerated; no NEW errors).
  - Follow repo rules: read the actual code first; check both logs on every issue.

### acceptance:
  - With opt-in cache attr: first render populates; second render (same key) returns the stored HTML without
    re-resolving/re-rendering (prove via a counting fake capability/store).
  - invalidate() for a type+tenant causes the next render to refresh (stale not served).
  - Two tenants with identical entity renders do NOT share cache rows.
  - Under per-user identity without explicit opt-in, no caching occurs (no leak); with explicit opt-in the key
    distinguishes users.
  - Cache attr absent → behaviour identical to today (regression-free).
  - New test file passes; existing cache/entity tests stay green; php -l clean; PHPStan no new errors; both logs
    checked (app.log informational only, error.log empty).

### verification:
  - php -l kernel/DiSyL/Component/ComponentRenderer.php + new test file.
  - Targeted runs: new tests/entity_view_render_cache_test.php + the related existing cache/entity tests
    (disyl_v43_cache_exp_test.php etc. — do NOT run the full suite).
  - Check BOTH logs after runs.
  - Capture RAW output to a test_results/phase2-evidence.log (full transcript, per R3 lesson).

### risk:
  - Stale HTML after data writes if authors forget invalidation — mitigate: document invalidation contract; default
    opt-in OFF so only views that opt in are cached; invalidator is provided.
  - Per-user leakage if auth scope is omitted from the key — mitigate: role in key + skip-by-default under identity.
  - FragmentStore seam must be reachable from ComponentRenderer (verify engine tenant + store injection before
    coding; read disyl_v44_sandbox_test.php setFragmentStore pattern).

### status: READY_FOR_IMPLEMENTATION

---

## Round history + completion (2026-09-06)

```
R1 /implement (sol) → PASS  (cache test 12/12, targeted 92/92)
R1 /review    (sol) → CHANGES_REQUIRED (7: key canonicalization, semantic transparency, principal-based
                         leak protection, observable fail-open, test breadth, unrelated doc edit, evidence)
R2 /implement (sol) → PASS  (all 7 addressed: namespaced query state in key, unsafe-render skip, App::user()
                         principal, FragmentStore failure surfacing, 32/32 cache tests)
R2 /review    (sol) → PASS  ✅ GATE CLEARED
```

- Files: kernel/DiSyL/Component/ComponentRenderer.php, kernel/DiSyL/Cache/FragmentStore.php (minimal
  observability), kernel/EntityContext/EntityViewResolver.php (invalidator), tests/entity_view_render_cache_test.php
  (32/32), docs/kernel/entity-context-system.md.
- Evidence: test_results/implement-phase2.log, review-phase2.log, implement-phase2-r2.log, review-phase2-r2.log,
  test_results/phase2-evidence.log, phase2-evidence-r2.log.
- Final status: COMPLETE. MySQL-5.7-safe (file+APCu, no DDL), tenant-isolated, fail-open, opt-in only.

---

## Phases 3/5 note (main CMS app repo)
ARK authority layer + renderer breadth (Phase 3) and the ARK sliver of Phase 5 target the CMS ARK theme
(storage/cms-themes/ark) — NOT present in this barebones kernel repo. Recorded in
.ai/phase3-ark-main-repo-record-2026-09-06.md. This Phase-2 render-path cache is the hardened Entity-view base
those ARK renderers consume.
