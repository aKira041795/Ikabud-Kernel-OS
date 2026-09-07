You are the /implement + /review agent for the **CMS Akira fork Phase 1 (P1)** in Ikabud Kernel OS 6.x
(repo root /var/www/html/ikabudsix, main 8c0d5b7). Execute P1 per the authoritative approved contract:

1. `.ai/contract-fork-cms-akira-2026-09-07.md`  (baseline contract — READ FIRST, task/objective/scope/constraints/acceptance/verification)
2. `.ai/chair-adjudication-fork-cms-akira-2026-09-07.md`  (8 amendments; P1-applicable = R1 source_schema vs field_contracts,
   R2 two explicit capability layers, R3 no inactive mutation caps in P1, R5 tenant provisioning, R6 stored-output security.
   R4 + R7 are P2 — do NOT build them now.)

READ BOTH FILES FIRST. They are authoritative. Do not broaden scope. This is a CONTRACT (the debate already decided
the architecture); P1 is the ONLY build phase — the canonical Post READ/RENDER path. Feature dev frozen.

## Work location + repo facts
- Work target: `/var/www/html/ikabudsix/modules/cms-akira/` (the fork, 14 members, currently an exact copy of the
  pristine suite). P1 edits ONLY `cms-akira-core` + adds a minimal ARK theme + DiSyL renderers under the fork.
- PRISTINE review baseline: `/var/www/html/applicationostest/modules/cms-akira/` — identical to the fork as copied;
  `diff -rq` against it shows your P1 delta for review.
- `modules/*` is GITIGNORED in this repo (kernel repo tracks only gui-settings). Module files are runtime units:
  do NOT rely on `git status`/`git diff` to show your changes; use `diff -rq applicationostest/modules/cms-akira
  modules/cms-akira` to see your delta. Do NOT try to commit module files to the kernel repo.
- KERNEL IS READ-ONLY for P1. Do NOT edit kernel/**. P1 must work on the kernel as-is (EntityViewResolver,
  DefaultEntityRenderer, CapabilityBus, ThemeManifestValidator, DiSyL, ModuleManager). If P1 requires a kernel change,
  STOP → return BLOCKED with evidence; do not invent a kernel edit.
- Reference entity-view wiring pattern in this repo: `modules/daily-ledger/helpers/entity-views.php`
  (`$views->registerView('daily_ledger_entry','compact',[fields/actions/limit/...], $moduleId)`) and its
  `helpers/views/*.disyl`. Entity view CONFIG files (.disyl `ikb_entity_view`) load via
  `TemplateEngine::loadViewConfigs()`; kernel `EntityViewResolver` is at kernel/EntityContext/EntityViewResolver.php
  (registerView/invalidateEntityCache/registeredViewContracts/resolve/resolveDetail) and `resolve()` invokes
  capability id `entity.list.{type}` (falls back to @1); `resolveDetail()` invokes `entity.get.{type}` (NO @1
  fallback). ComponentRenderer `{ikb_entity_list}`/`{ikb_entity_detail}` render them.

## P1 deliverables (contract scope P1 — read the contract's exact wording, esp. TENANT STORAGE, PROJECTION BOUNDARY,
FAIL-CLOSED, BRIDGE NAMING, INVALIDATION deferred-to-P2)
1. **Content authority (gap A, DECIDED):** `cms-akira-core` = fork content authority. Edit its module.json:
   - REMOVE `depends: ["cms"]` and the capability-depends `cms.content.get/list/create/update@1` + `cms.themes.list@1`.
   - REMOVE the `akira.content.*` adapter capability declarations (they delegated to the now-absent legacy cms).
   - ADD `owns_tables` + `reads_tables` = ["cms_akira_posts"] (both currently empty).
   - EXPOSE in P1 ONLY: `cms.post.get@1`, `cms.post.list@1`, plus the view-bridge ids `entity.list.post` +
     `entity.get.post` (R3 — do NOT expose/declare create/update anywhere in P1 manifests or registrations; their
     names are reserved in the ADR only). Provider for all = `cms-akira-core`.
   - Keep the suite/product/extension_points fields; drop nothing else.
2. **Post storage migration (gap A + R5):** new migration in `cms-akira-core`'s `migrations` slot creating
   `cms_akira_posts` ONLY (one-table DDL limit): `tenant_id INT UNSIGNED NOT NULL`, `slug VARCHAR(191) NOT NULL`,
   `title`, `subtitle/excerpt`, `content` (TEXT), `image` (nullable), `status` ENUM('draft','published') default
   'draft', `published_at DATETIME NULL`, timestamps. `UNIQUE KEY uq_tenant_slug (tenant_id, slug)`,
   `KEY idx_tenant (tenant_id)`. MySQL 5.7-safe: ENGINE=InnoDB, utf8mb4_unicode_ci (NOT 0900), inline indexes, no
   CTE/window/JSON_TABLE/generated-column edge syntax. No FK to kernel_tenants (control DB). Tenant-scoped table;
   document that it is migrated in EVERY tenant DB before that tenant activates routes (R5: rely on the repo
   migration ledger, not IF NOT EXISTS; test partial provisioning + rerun).
3. **Domain read capabilities (P1):** `cms.post.get@1` + `cms.post.list@1` handlers. Public calls return ONLY
   `status='published'`; tenant identity from kernel context only (never payload). List: allowlisted sortable
   `published_at|created_at|title`, direction `asc|desc`, `limit` 1..50, `offset>=0`; caller `status`/filters
   rejected/ignored. Reads do NOT need role gating beyond published-only in P1.
4. **Entity View bridge (gap B + R2):** register handlers under the EXACT unversioned ids `entity.list.post` and
   `entity.get.post` (single provider `cms-akira-core`, single handler each). Each bridge MUST call
   `cms.post.list@1` / `cms.post.get@1` through `CapabilityBus` (`app()->cap()->call(...)`), then project the result
   into a FRESH allowlisted DTO. It maps `slug`→`url` (`/posts/{slug}`), keeps ONLY the presentation contract keys
   (list: title, subtitle, image, metadata, actions, url; detail: + body), and DROPS id/tenant_id/status/all internal
   and undeclared keys. Transport-only lookup keys (slug) are consumed inside the bridge, never ARK-visible. NO direct
   `cms_akira_posts` SQL in the bridges.
5. **Entity View contracts (gap B + R1):** `registerView('post','list', …)` + `registerView('post','detail', …)`,
   provider `cms-akira-core`. Explicit `fields` allowlist per view (no '*'); `actions:['view']`; `key_field:'slug'`;
   `action_urls` for `/posts/{slug}`. CRITICAL R1: `source_schema.fields` declares only PRIMITIVE types
   (string/int/float/bool/json/date/datetime/reference); the semantic role metadata (title/subtitle/image/body/
   metadata/actions/url) goes in `field_contracts` (or the kernel-supported key). Do NOT invent keys. Verify against
   EntityViewResolver schema handling.
6. **ARK theme (gap C):** record the ownership ADR (contract constraints) is already done in the contract; ship a
   MINIMAL fork theme with `renderer-registry.json` mapping `entity.list.post` → `article-grid` and
   `entity.detail.post` → `article-page` (template-or-component + controls + context_keys per ThemeManifestValidator)
   + `tokens.json`. Determine the correct theme root location from the kernel's theme conventions
   (kernel/Services/ThemeManifestValidator.php + docs) — theme-root resolution is UNVERIFIED; discover and record it.
   Validate clean via the kernel theme validator (exact CLI UNVERIFIED — find it; doc-claimed in .ai/ark-contract.md).
7. **DiSyL renderers (gap D):** `article-grid.disyl` + `article-page.disyl` reachable from renderer-registry.json;
   they consume ONLY the projected presentation contract. Render explicit fields; escape context-appropriately
   (R6 — HTML/attr/URL escaping; slug canonicalization when building /posts/{slug}).
8. **Routes (P1):** `GET /posts` + `GET /posts/{slug}` on cms-akira-core routes, with the FAIL-CLOSED registration
   guard BEFORE resolve (contract constraint): verify the exact `post.list`/`post.detail` registration exists owned
   by `cms-akira-core` (registeredViewContracts) before resolving; missing/wrong-provider → 404/error, NEVER generic
   fallback; `'*'`/wildcard never reaches either route.
9. **Tests (repo style, plain-PHP bootstrap like tests/durable_idempotency_test.php or integration style):**
   projection boundary negative tests (list + detail: extra internal columns absent from DTO + HTML); registration
   guard (missing/wrong-provider fixture → 404/error, no fallback); bridge activation (`entity.list.post` +
   unversioned `entity.get.post` both resolve to cms-akira-core single handler; `entity.get.post@1` NOT relied on);
   explicit-field-only output; tenant isolation A/B; unpublished not returned; R6 stored-XSS negatives
   (title/subtitle/body/metadata/image/url payloads) + slug canonicalization; P1 dependency/activation check passes
   (legacy cms / cms.content.* / cms.themes.* gone — fork-owned cms.post.* remain) (R8 wording). Plus capability:
   audit + Workbench baselines stay ZERO-exception.

## Constraints (from contract — honor all)
- NO kernel edits; NO copying/restoring legacy cms; NO DDL beyond cms_akira_posts; NO non-Post entities; NO
  media/seo/ai/workflow/search/editor providers; NO enabling the other 13 submodules; NO create/update capability
  activation in P1; NO second Entity View/canonicalizer/renderer registry; NO bypass of kernel EntityViewResolver/
  DefaultEntityRenderer/CapabilityBus; NO-BROADEN. Renderer registry = single ARK authority; EntityViewResolver =
  single view authority.
- Fail-closed + projection + tenant rules exactly as the contract states.

## Verification (do all)
- `php -l` every touched PHP; module.json parse; migration valid MySQL 5.7.
- Your new tests pass (list/detail render through capability → EntityViewResolver → ARK renderer selection → DiSyL,
  explicit fields only).
- `diff -rq applicationostest/modules/cms-akira modules/cms-akira` shows ONLY intended P1 deltas (core module.json +
  migration + handlers/helpers + entity views + theme + renderers + routes + new tests under the fork).
- BOTH storage/logs/app.log + error.log clean after runs. capability:audit + Workbench baselines ZERO.
- Record theme-root resolution + validator CLI discovered (preflight note inside the contract file).

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
preflight_recorded: (theme root, validator CLI, tenant provisioning evidence)
changed: (files under modules/cms-akira vs pristine)
implementation_summary:
verification: (tests + counts, log status, baseline status)
scope: unexpected_files (must be none outside modules/cms-akira)
risks:
unresolved:
recommended_next_state: (P2 ready?)
