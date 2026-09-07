# CMS Akira Independent-CMS — Phase 5A gate: media → native `akira.media.*@1`

task: Execute Phase 5A of the APPROVED independent-CMS contract (`.ai/current-task.md`). Re-scope the dormant
`cms-akira-media` into a NATIVE Akira media module: own `cms_akira_media` (tenant-scoped; `cms_akira_media_variants`
only if you ship variant generation — otherwise omit and do NOT ship a half-used table), expose native
`akira.media.*@1` (library CRUD + resolution), NO `cms.media.*`, NO `akira.content.get@1` residue; TRACK + enable
(`_enabled:false`) at this gate. Phases 0-4 merged (core akira.post.*, shell, install service, editor, navigation).

Reference the TRACKED members as the canonical pattern to copy exactly:
- `modules/cms-akira/cms-akira-navigation/` (Phase 4 — closest sibling: native caps + governed v2 mutations +
  owns/reads + migrations + tests). Mirror its module.json shape, capability declaration style, test layout, and
  gitignore tracking approach.
- `modules/cms-akira/cms-akira-core/`, `modules/cms-akira/cms-akira-editor/`, `modules/cms-akira/cms-akira-shell/`
  for entity-view/capability bridge patterns.

## Contract specifics (honor exactly)
- Manifest: remove residue (`cms.media.get@1`, `akira.content.get@1`) → depends `cms-akira-core` only.
  `kind: extension`, `extends: cms-akira-core`, suite `cms-akira`. owns/reads = `cms_akira_media`
  (do NOT read/own legacy `cms` media tables). Migrations per-member files; preserve the table-free
  `001_initial.sql` ledger marker then add `002_create_native_media.sql`.
- Capabilities (freeze names per naming rule — `akira.media.*`):
  - Projection caps: `akira.media.library@1` (list with explicit projection allowlist), `akira.media.get@1`,
    `akira.media.resolve@1` (resolve a media reference → public projection for rendering).
  - Governed admin mutation caps (each `requires_protocol: v2`, effects.invalidates a single canonical tag
    `entity.list.media`): `akira.media.upload@1`, `akira.media.update@1`, `akira.media.delete@1`
    (idempotent via kernel.idempotency + durable same-PDO audit via kernel.audit.record@1, mirroring navigation).
  - depends: kernel.idempotency.{hash,claim,commit,release}@1, kernel.audit.record@1 (protocol-v2 policy seeding
    via CapabilityAuthorizationRegistry as in navigation).
- Own table `cms_akira_media`: tenant_id, stable ASCII media key (unique per tenant), filename, mime_type,
  size_bytes, storage_path/location (never trust client-supplied path; server-derived), alt, width/height
  (nullable), created/updated; soft-delete `deleted_at` column only if lifecycle parity with posts demands it —
  otherwise hard delete via governed cap. MySQL 5.7 (InnoDB utf8mb4_unicode_ci; composite unique index byte budget
  incl. tenant prefix ≤767; stable Akira entity keys, never another member's rows / never legacy cms rows).
- Tenant separation: every row tenant-scoped via ModuleDB/tenant context; never take tenant from payload/claims.
  Executable isolation tests (shared + dedicated tenant DB).
- Media resolution must FAIL CLOSED for missing/deleted/stale references.
- Security (mandatory abuse tests): MIME whitelist + extension sniffing, size cap, path traversal defense
  (server-derived storage path, no client path), authz (tenant isolation; no cross-tenant read), upload idempotency,
  audit trail on mutations. Do NOT wire actual object storage or provider SDK — this gate ships deterministic
  local-filesystem-backed storage under the module's tenant storage dir with cleanup policy documented in README
  (no orphan accumulation; delete removes the file).
- `_enabled:false` retained → TRACK: un-ignore `modules/cms-akira/cms-akira-media/**` in `.gitignore` (place under
  the existing CMS Akira block, in order). Explicit tenant activation via module-install service.
- README: describe media authority, storage layout, cleanup policy, capability list.

## Deliverables
1. modules/cms-akira/cms-akira-media re-scoped (module.json akira.media.* + owns/reads + migrations; native
   handlers + helpers + capability handler map; remove all residue incl. legacy nav `nav:` entries pointing at
   `/admin/cms-akira-media` legacy shell — nav entries must target the Akira shell/editor only if applicable, else
   drop), README.
2. Migrations (idempotent, MySQL 5.7).
3. Track via .gitignore; commit source + tests.
4. Tests (mirror navigation_contract_test.php + navigation_dedicated_tenant_test.php layout):
   library/get/upload/update/delete/resolve; MIME/size/path/authz abuse cases; tenant isolation A/B (shared +
   dedicated); mutation idempotency/audit/invalidate; projection explicit allowlist; stale/missing reference
   fail-closed; authority zero on this member; certify.
5. Append result to the contract.

## Verification (do all)
- Media tests green; P1 38 + P2 38 + shell 21 + install 23 + editor 25 + navigation 35 green; authority 18/18;
  capability audit zero; module:certify --all (incl media); composer full; phpstan + cs-fixer (tracked media);
  logs clean; CI 6/6 (commit + push branch for CI).
- grep (recursive over tracked media path incl. any staged ignored source): no `cms.media.*`, `akira.content.get@1`,
  `cmsActiveTheme`, legacy `cmsRender`/`cmsAdminContext` usage, `legacy` cms table names. Record the command that
  proves ignored source was staged (e.g. `git ls-files modules/cms-akira/cms-akira-media | head`).
- MySQL 5.7 clean (no window fns/CTEs/JSON_TABLE; ENGINE=InnoDB).

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state: (Phase 5B SEO ready?)

---

## Result (Phase 5A)

status: PASS

task: Re-scope the dormant `cms-akira-media` into a NATIVE Akira media authority (`akira.media.*@1`), own
`cms_akira_media`, track the member, and retain `_enabled:false`. Kernel READ-ONLY.

changed:
  - modules/cms-akira/cms-akira-media/** (module.json, helpers.php, handlers.php, routes.php, README.md,
    database/migrations/001_initial.sql + 002_create_native_media.sql, tests/media_contract_test.php,
    tests/media_dedicated_tenant_test.php)
  - .gitignore (un-ignore `modules/cms-akira/cms-akira-media/**` under the CMS Akira block)

implementation_summary: Native tenant-scoped `cms_akira_media` (MySQL 5.7 InnoDB utf8mb4_unicode_ci, composite
unique `(tenant_id, media_key)` = 36 bytes). Exposes fail-closed `akira.media.library@1` / `get@1` / `resolve@1`
projections and governed v2 `upload@1` / `update@1` / `delete@1` mutations (kernel idempotency, durable same-PDO
audit, single canonical `entity.list.media` invalidation). Uploads enforce a MIME allowlist, extension/magic-byte
sniffing agreement, a 5 MiB size cap, and a server-derived storage path (client paths never trusted). Tenant identity
always comes from Kernel context. Local filesystem storage lives under
`storage/private/cms-akira-media/tenant_{tenant_id}/` with documented cleanup; hard delete removes the file. Removed
the legacy `/admin/cms-akira-media` nav entry and all `cms.media.*`/`akira.content.get@1` residue; `_enabled:false`
retained for explicit module-install activation.

verification:
  - Media tests green: media_contract_test.php 37/37; media_dedicated_tenant_test.php 6/6
  - P1 38/38, P2 38/38, shell 21/21, install 23/23, editor 25/25, navigation 35/35
  - authority 18/18; `php ikabud capability:audit --json` → ok, 0 findings
  - `php ikabud module:certify --all` → all modules pass (cms-akira-media 13/13)
  - `composer test` → 106/106 passed
  - phpstan (media, level 6) clean; php-cs-fixer (media) clean
  - Forbidden-residue grep clean over tracked media; tracked proof via `git ls-files modules/cms-akira/cms-akira-media`
  - Logs clean after success and negative runs
  - CI 6/6 on PR #43 (kernel-contracts, test mysql-8/5.7/mariadb-10.6, static-analysis, coding-standards);
    merged with `gh pr merge 43 --merge --delete-branch`

scope: .gitignore, modules/cms-akira/cms-akira-media/**

unexpected_files: none

risks: none blocking. Local-filesystem storage is deterministic; an uncertain-commit upload can leave at most one
orphan file, covered by the documented tenant-dir sweep. No object-storage SDK at this gate.

unresolved: none

recommended_next_state: Phase 5B SEO ready.
