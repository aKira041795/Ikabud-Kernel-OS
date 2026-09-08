# CMS Akira Independent-CMS — Phase 10A gate: builder composition authority (server) — `akira.builder.*@1`

task: Execute sub-gate 10A of the APPROVED independent-CMS contract (`.ai/current-task.md` gate 10). Re-scope the
dormant `cms-akira-builder` into a NATIVE Akira composition-authority module (SERVER SIDE ONLY at this sub-gate —
the React/Vite admin app + preview transport + profile-visual tracking land in sub-gate 10B). Own
`cms_akira_compositions` + `cms_akira_composition_revisions` (tenant-scoped), expose native `akira.builder.*@1`
(composition get/list/create/update/publish/unpublish/delete/revisions + validate/render), with revision/publish
contracts, optimistic concurrency, idempotency, durable audit, tenant isolation, preview/published separation, and
deterministic render through ArkRendererResolver→DiSyL with an explicitly passed Akira theme slug (no raw
PHP/SQL/include/script/path injection). TRACK + enable (`_enabled:false`) at this sub-gate. Phases 0-9 merged.
Kernel READ-ONLY.

## Reference architecture (read these first)
- `modules/cms-akira/cms-akira-core/` — posts authority: `cms_akira_posts` (id, tenant_id, slug, title, subtitle,
  content TEXT, image, status draft|published, published_at). Builder compositions attach to an Akira entity via
  opaque entity type+key (post slug) — string reference, never a foreign key into another member's table.
- `modules/cms-akira/cms-akira-editor/` — table-free native editor: deterministic sanitize/normalize/render/validate
  over entity.get.post@1 projections; the canonical "no raw HTML/script" content pipeline. Builder must reuse its
  sanitize/normalize semantics for block/HTML content, NOT rebuild a parallel unsafe path.
- `kernel/Services/ArkRendererResolver.php` (resolve/render(viewId, ctx, themeSlug)) + tracked Akira theme
  `storage/cms-themes/cms-akira-posts/` (theme.manifest + renderer-registry entity.list.post/entity.detail.post).
- Sibling native members as structural pattern (module.json shape, governed v2 mutations, tests, gitignore
  tracking): navigation/media/seo (tables + v2 mutations), theme (table-free + ARK slug + module setting),
  workflow (kernel bridge), search (own table + converge + rebuild).
- Do NOT reuse/read legacy `modules/cms/` builder code, tables, templates, or `cms.builder.*` capabilities. This is
  an independent CMS — the composition model is Akira-native.

## Composition contract (honor exactly; see master contract gate 10)
- Own tables (MySQL 5.7 InnoDB utf8mb4_unicode_ci, byte-budget indexes incl. tenant prefix):
  - `cms_akira_compositions`: id, tenant_id, entity_type, entity_key (opaque Akira reference), title, tree (TEXT —
    the JSON composition tree, MySQL 5.7-safe, NO JSON_TABLE; validated on write), status
    ENUM('draft','published') (preview = draft revision; published = published revision), published_revision_id,
    version INT, created/updated; UNIQUE (tenant_id, entity_type, entity_key); idx tenant.
  - `cms_akira_composition_revisions`: id, tenant_id, composition_id (stable, same-member FK), tree TEXT, base
    (parent revision id or NULL — optimistic concurrency chain), author_id, change_note, created_at; idx
    (composition_id, id).
  - Migration per-member files; preserve table-free `001_initial.sql` marker + add `002_create_compositions.sql`.
- Capabilities (freeze names — `akira.builder.*@1`), provider = this module:
  - Read/projection: `akira.builder.compositions@1` (list, allowlisted), `akira.builder.get@1` (single composition +
    current preview tree), `akira.builder.revisions@1` (revision history, allowlisted), `akira.builder.render@1`
    (deterministic render of a composition tree to HTML via ArkRendererResolver→DiSyL with an explicitly passed
    Akira theme slug from `akira.theme.resolve@1` — fail closed if tree invalid or theme/view unregistered).
  - Governed v2 mutations (requires_protocol v2, idempotent via kernel.idempotency, durable same-PDO audit via
    kernel.audit.record@1, effects.invalidates single canonical tag `entity.list.composition`):
    `akira.builder.create@1`, `akira.builder.update@1` (optimistic concurrency: required base revision id; reject
    if stale — 409-style typed result), `akira.builder.publish@1` (promote the current preview tree + bump
    published_revision_id/version), `akira.builder.unpublish@1`, `akira.builder.delete@1` (cascade revisions),
    `akira.builder.validate@1` (tree/block/prop validation, fail closed).
  - depends: cms-akira-core (entity refs via projection), kernel.idempotency.* + kernel.audit.record@1 +
    protocol-v2 policy seeding (as siblings).
- Validation (fail closed): schema-validated tree (allowed blocks/props allowlist), no raw PHP/SQL/include/script/
  javascript: URL / data: URL injection; HTML content goes through editor sanitize semantics; depth/size caps;
  unknown block/prop rejected. `akira.builder.validate@1` + render both enforce.
- Publish contract: publish requires a valid tree + an existing published-capable entity ref; preview/published
  separation proven (rendering published returns published tree; editing preview never mutates published until
  publish). Publish writes to the composition's published state; it does NOT need to rewrite cms_akira_posts.content
  unless the contract's integration seam says so — record the exact seam decision in README (cross-member writes to
  core tables are forbidden; if published HTML must appear on the public Post path, that is a Phase-1-core
  integration seam to document as a recorded decision/prerequisite, NOT a cross-member SQL write).
- Tenant separation: rows tenant-scoped via ModuleDB/tenant context; never take tenant from payload. Executable
  isolation tests (shared + dedicated).
- `_enabled:false` retained → TRACK: un-ignore `modules/cms-akira/cms-akira-builder/**` in `.gitignore`.
- README: composition authority (schema, revision/publish contract, optimistic concurrency, validate allowlist,
  render-through-ARK seam + theme slug, preview/published separation, capability list, recorded integration
  decisions/prerequisites).

## Deliverables (10A only — no React/Vite app yet)
1. modules/cms-akira/cms-akira-builder re-scoped (module.json akira.builder.* + owns/reads + migrations; native
   handlers/helpers + capability handler map; remove scaffold residue), README.
2. Migrations (idempotent, MySQL 5.7).
3. Track via .gitignore; commit source + tests.
4. Tests (mirror sibling layout): compositions/get/revisions CRUD; validate fail-closed (hostile tree: script/js:/
   data:/path/size/depth/unknown block); update optimistic-concurrency (stale base rejected, current base applied);
   publish promotes preview → published + revision bump; unpublish; delete cascades; render through ARK → DiSyL with
   explicit Akira theme slug (registered + unregistered view both handled); preview/published separation; mutation
   idempotency/audit/invalidate; tenant isolation A/B (shared + dedicated); authority zero; certify.
5. Append result to the contract.

## Verification (do all)
- Builder tests green + dedicated-tenant case; all prior member suites green at published counts (P1 38, P2 38,
  shell 21, install 23, editor 25, navigation 35, media 37, seo 39, theme 36, workflow 42, search 39, ai 28,
  profiles certify 13/13); authority 18/18; capability audit zero; module:certify --all (incl builder); composer
  full; phpstan + cs-fixer (tracked builder); logs clean; CI 6/6 (branch feat/independent-cms-phase10a-builder,
  commit, push, PR, checks 6/6, merge, checkout main+pull; report PR #; then docs-result PR merged).
- grep over tracked builder (incl staged ignored): no `cms.builder.*`, no legacy `modules/cms` include/table, no
  `akira.content.get@1`, no raw `eval`/`include` of user tree, no `cmsActiveTheme`, no JSON_TABLE/window fn; record
  git ls-files proof. MySQL 5.7 clean.

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state: (Phase 10B admin UI + preview + profile-visual track ready?)

## Implementation result — 2026-09-08

status: PASS (local gate + CI 6/6 on PR #57, merged 2d7a03c)

task: Re-scope the dormant cms-akira-builder into a NATIVE, SERVER-SIDE Akira composition authority (10A): own
cms_akira_compositions + cms_akira_composition_revisions (tenant-scoped), akira.builder.*@1 capabilities,
revision/publish + optimistic-concurrency + idempotency + durable audit, fail-closed validation, deterministic
render through akira.theme.resolve@1 → ArkRendererResolver → DiSyL with the explicit cms-akira-posts theme slug.
No React/Vite admin app (10B). TRACK + enable (_enabled:false). Kernel READ-ONLY.

changed:
- modules/cms-akira/cms-akira-builder: module.json re-scoped (kind extension / extends cms-akira-core / depends
  cms-akira-core / owns+reads its two tables / migrations 001 marker + 002 / ten akira.builder.*@1 / _enabled:false);
  native helpers.php (capability map, fail-closed allowlist validation, revision/publish/optimistic-concurrency
  mutations, projections, render-through-ARK), handlers.php + routes.php health route, README (schema,
  revision/publish contract, integration seam decision), tests (builder_contract_test 34/34,
  builder_dedicated_tenant_test 6/6). Scaffold residue removed.
- storage/cms-themes/cms-akira-posts: renderer-registry.json + public/composition-page.disyl add the
  entity.detail.composition view that makes the documented render seam deterministic.
- .gitignore: tracked modules/cms-akira/cms-akira-builder/**.
- .ai/contract-independent-cms-phase10a-2026-09-08.md: result appended (this PR).

implementation_summary:
- Native server composition authority owned by the module (tenant-scoped rows; tenant always from Kernel context,
  payload tenant rejected). Create writes initial revision; update requires the exact current base_revision_id
  (stale → typed 409); publish promotes current preview → published_revision_id + version bump; preview edits never
  mutate published until next publish (proven); unpublish clears pointer; delete cascades same-member revisions.
- Governed protocol-v2 ops: Kernel idempotency claim/commit/release, durable same-PDO kernel.audit.record@1 (fail on
  no-audit), correlation id, single effects.invalidates tag entity.list.composition, seedPolicy requires_protocol v2.
- Validation fail-closed: {version:1,blocks:[]} allowlist (section/heading/paragraph/rich_text/image/button) with
  exact prop allowlists; unknown block/prop, script/javascript:/data:/include/PHP/SQL, unsafe URLs, depth>8,
  count>500, and encoded tree>256 KiB all rejected. rich_text must equal akira.editor.sanitize@1 output (no parallel
  unsafe sanitizer).
- render@1 revalidates persisted input, resolves validated theme slug via akira.theme.resolve@1, renders the
  allowlisted composition projection through ArkRendererResolver→DiSyL on the cms-akira-posts theme
  (entity.detail.composition); source=published loads published_revision_id, source=preview current preview;
  unregistered view / missing theme fail closed.
- Cross-member SQL writes to core tables are NOT performed; the public-Post-path seam (if composition output is to
  replace Post body output) is recorded in README as a separately-gated prerequisite consuming akira.builder.render@1.

verification:
- Builder contract 34/34 and dedicated-tenant 6/6 green; module:certify --all cms-akira-builder 13/13.
- Prior member suites green at published counts (P1 38, P2 38, shell 21, editor 25, navigation 35, media 37, seo 39,
  theme 36, workflow 42, search 39, ai 28); profiles certify 13/13 in --all.
- module_suite_compatibility 18/18, manifest_suite_contract 28/28, module_suite_certification 15/15,
  manifest_architecture_policy 7/7, capability authority audit 18/18, capability:audit zero, architecture:check 6/6.
- phpstan (level 6 baseline, zero builder entries) + php-cs-fixer clean on tracked builder; MySQL-5.7 guard clean
  (no JSON_TABLE/window fn/GENERATED ALWAYS); migration rerun converged; logs clean.
- grep over tracked builder (module.json/helpers/handlers/routes/tests): no cms.builder.*, no modules/cms include or
  table, no akira.content.get@1, no cmsActiveTheme, no eval/include of user tree, no MySQL-8 syntax. git ls-files
  proof records the ten builder files tracked; profile-visual absent (held).
- theme:validate cms-akira-posts clean (no warnings) + disyl:lint clean after adding the composition view.
- CI 6/6 on PR #57: kernel-contracts, test (mysql-8), test (mysql-5.7), test (mariadb-10.6), static-analysis,
  coding-standards — merged 2d7a03c.

scope: cms-akira-builder (module.json/source/tests/migrations/README), .gitignore (builder unignore),
  cms-akira-posts theme composition render view, contract append. Kernel untouched.
unexpected_files: none. (A pre-existing local gitignored daily-ledger module trips the strict manifest guard only in
  this working tree; it is absent from the committed repo and does not affect CI.)
risks: none blocking. Builder stays _enabled:false; a real tenant install must also activate core/editor/theme
  providers and seed policies (Phase-10B install path).
unresolved: none.
recommended_next_state: Phase 10B — builder admin UI + authenticated preview transport + asset build + then certify/
  track profile-visual from an empty tenant state.
