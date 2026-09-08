# CMS Akira Independent-CMS — Phase 7 gate: search → native `cms-akira-search` + `akira.search.*@1`

task: Execute Phase 7 of the APPROVED independent-CMS contract (`.ai/current-task.md` gate 7). Re-scope the dormant
`former Akira search adapter` into a NATIVE Akira search member renamed **`cms-akira-search`**: own
`cms_akira_search_documents` (tenant-scoped), expose native `akira.search.*@1` (document build/upsert/delete/query),
deterministic rebuild, allowlisted projections, and an index that converges on Phase-1 post lifecycle operations via
a tenant-local transactional outbox (or a RECORDED Kernel prerequisite). NO `search.index.*`, NO
`akira.content.get@1` residue. TRACK + enable (`_enabled:false`) at this gate. Phases 0-6 merged (core akira.post.*,
shell, install service, editor, navigation, media, seo, theme, workflow). Kernel READ-ONLY.

## Rename scope (mandatory)
- Git-move `modules/cms-akira/former Akira search adapter/` → `modules/cms-akira/cms-akira-search/`.
- module.json id/name: `former Akira search adapter` → `cms-akira-search`; `kind` `adapter` → `extension` (it is now a
  native first-class member, no external backend); keep `extends: cms-akira-core`, suite `cms-akira`.
- Update profile dependency references (`installs` + `depends` arrays) in the dormant profile manifests:
  - `modules/cms-akira/cms-akira-profile-headless/module.json`
  - `modules/cms-akira/cms-akira-profile-visual/module.json`
  → replace `former Akira search adapter` with `cms-akira-search`. (Profiles are still gitignored/dormant until Phase 9,
  but their LOCAL manifests must reference the new id so certification/architecture scans stay consistent. Do NOT
  track the profiles in this gate.)
- grep repo-wide for the old id after the move; only the two dormant profiles may still be edited locally and
  neither may retain `former Akira search adapter` afterward.

## Contract specifics (honor exactly — master contract gate 7)
- Manifest: depends = cms-akira-core only; owns/reads = `cms_akira_search_documents`; migrations = table-free
  `001_initial.sql` marker + `002_create_native_search_documents.sql` (idempotent, MySQL 5.7 InnoDB
  utf8mb4_unicode_ci, byte-budget composite unique incl. tenant prefix); remove `akira.content.get@1` +
  `search.index.upsert@1` residue; drop legacy `/admin/former Akira search adapter` nav entry; `_enabled:false`.
- Own table `cms_akira_search_documents`: tenant_id, document_key (stable Akira entity key e.g. post slug — opaque
  ASCII reference, never another member's FK), entity_type, title, body/summary (searched text), status, meta JSON
  (MySQL 5.7-safe text/json-as-text — no JSON_TABLE), indexed_at, created/updated; UNIQUE (tenant_id,
  entity_type, document_key).
- Capabilities (freeze names — `akira.search.*@1`):
  - `akira.search.document.build@1` — build/normalize a search document projection from an Akira entity key +
    allowlisted fields (fail closed on unknown entity/type).
  - `akira.search.upsert@1` + `akira.search.delete@1` — governed v2 mutations (requires_protocol v2, idempotent via
    kernel.idempotency + durable same-PDO audit via kernel.audit.record@1, effects.invalidates single canonical tag
    `entity.list.search-document`; mirror navigation/media/seo).
  - `akira.search.query@1` — allowlisted search over the tenant's documents (term/type filters, paged, deterministic
    ordering; projection allowlist only; fail closed).
  - depends: kernel.idempotency.{hash,claim,commit,release}@1 + kernel.audit.record@1 + protocol-v2 policy seeding
    via CapabilityAuthorizationRegistry (as siblings).
- Lifecycle convergence: index converges on Phase-1 post lifecycle ops (publish → upsert doc; unpublish/delete →
  remove doc). Implement via the same event/effect seam the tracked members use (subscribe to core lifecycle
  events with idempotent replay-safe consumers) OR via a tenant-local transactional outbox — whichever is
  deterministic and testable in this repo. If true outbox infra is absent in the Kernel, do NOT build new kernel
  infra: record the Kernel-prerequisite classification explicitly in the README + report (mirror how Phase 6
  recorded the workflow-outbox prerequisite) AND still make convergence deterministic through the documented
  seam + a `rebuild@1`/deterministic rebuild command capability tested end-to-end.
- Deterministic rebuild: a rebuild path that reconstructs documents for all of a tenant's published posts
  (idempotent, tenant-scoped), tested for convergence after a simulated drift.
- Tenant separation: rows tenant-scoped via ModuleDB/tenant context; never take tenant from payload. Executable
  isolation tests (shared + dedicated) as siblings.
- `_enabled:false` retained → TRACK: un-ignore `modules/cms-akira/cms-akira-search/**` in `.gitignore`.
- README: search authority (table, capabilities, converge-on-lifecycle seam or recorded prerequisite, rebuild,
  query allowlist), the rename record, capability list.

## Deliverables
1. Git-move + re-scope to `cms-akira-search` (module.json akira.search.* + owns/reads + migrations; native
   handlers/helpers/capability map; residue removed), README.
2. Profile dep updates (headless + visual local manifests).
3. Track via .gitignore; commit source + tests (include the rename in the commit).
4. Tests (mirror sibling layout): build/upsert/delete/query; lifecycle convergence (publish/unpublish/delete →
   doc present/absent) incl. drift → deterministic rebuild; allowlisted results + fail closed; tenant isolation A/B
   (shared + dedicated); mutation idempotency/audit/invalidate; authority zero on this member; certify.
5. Append result to the contract.

## Verification (do all)
- Search tests green; P1 38 + P2 38 + shell 21 + install 23 + editor 25 + navigation 35 + media 37 + seo 39 +
  theme 36 + workflow 42 green; authority 18/18; capability audit zero; module:certify --all (incl search);
  composer full; phpstan + cs-fixer (tracked search); logs clean; CI 6/6 (branch feat/independent-cms-phase7-search,
  commit incl. git-move, push, PR, checks 6/6, merge, checkout main+pull; report PR #; then docs-result PR merged,
  as prior phases).
- grep repo-wide (tracked tree): no `former Akira search adapter` in ANY tracked file; no `search.index.*` /
  `akira.content.get@1` in tracked search; record git ls-files proof of the rename. Confirm 002 migration + unique
  index present; MySQL 5.7 clean.

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state: (Phase 8 AI ready?)

---

## Result (Phase 7)

status: PASS

task: Rename and re-scope the dormant external search adapter as native `cms-akira-search`, with tenant-owned search documents, native search capabilities, lifecycle convergence seam, and deterministic rebuild.

changed:
  - `.gitignore` (tracks `modules/cms-akira/cms-akira-search/**`)
  - `modules/cms-akira/cms-akira-search/**` (native manifest/runtime, two migrations, README, shared and dedicated tests)
  - tracked historical suite references updated to the stable search id
  - dormant headless/visual profile manifests updated locally in both `installs` and `depends`; profiles remain ignored and untracked until Phase 9

implementation_summary: Native search exclusively owns/reads `cms_akira_search_documents`, uniquely keyed by `(tenant_id, entity_type, document_key)` with byte-budgeted ASCII identity and MySQL-5.7-safe text metadata. It exposes fail-closed allowlisted document build and query capabilities; governed protocol-v2 upsert/delete/rebuild mutations use Kernel idempotency, same-PDO durable audit, correlation, and exactly `entity.list.search-document` invalidation. Query is tenant-derived, paged, wildcard-safe, allowlisted, and deterministically ordered. The replay-safe committed Post lifecycle event consumer performs publish upsert and unpublish/delete removal through governed capabilities. Core Phase 1 does not yet transactionally emit that event, so guaranteed automatic outbox publication is explicitly recorded as a Kernel prerequisite. Rebuild pages only through `akira.post.list@1` and deterministically repairs missed delivery, stale rows, and drift for the current tenant.

verification:
  - search contract 39/39; dedicated tenant topology/isolation 7/7
  - P1 38/38, P2 38/38, shell 21/21, install 23/23, editor 25/25, navigation 35/35, media 37/37, SEO 39/39, theme 36/36, workflow 42/42
  - navigation/media/SEO/theme dedicated regressions 6/6, 6/6, 6/6, 7/7
  - capability authority 18/18; capability audit zero; workbench workflow guard zero
  - `module:certify --all` all pass, including search 13/13
  - `composer test` 106/106; search-scoped PHPStan and PHP CS Fixer clean; application/error logs clean
  - migration rerun converged; InnoDB `utf8mb4_unicode_ci`; unique index columns evidenced in shared and two dedicated databases; MySQL 5.7 CI clean
  - tracked grep: old adapter id absent repository-wide; foreign search-index and deleted content capability names absent from tracked search
  - tracked path proof: old path absent; new path contains README, migrations, runtime, manifest, routes, and both tests
  - implementation PR #51 merged after CI 6/6: Kernel contracts, coding standards, static analysis, MySQL 8, MySQL 5.7, MariaDB 10.6

scope: `.gitignore`, native search member, and tracked references required to eliminate the former id.

unexpected_files: none. Existing unrelated untracked `.ai/*` and ignored runtime module/profile files were not staged; only the two mandated dormant profile manifests were edited locally.

risks: Core does not write a committed Post lifecycle event to Kernel's durable tenant outbox inside the Post mutation transaction. Search therefore does not claim automatic delivery until that Kernel/core prerequisite lands. Dedicated databases likewise depend on Kernel provisioning parity for idempotency, audit, and durable outbox infrastructure.

unresolved: Recorded Kernel lifecycle-outbox and dedicated provisioning prerequisites; no Phase 7 member blocker.

recommended_next_state: Phase 8 AI ready.
