# CMS Akira Independent-CMS — Phase 4 gate: navigation → native `akira.navigation.*@1`

task: Execute Phase 4 of the APPROVED independent-CMS contract (`.ai/current-task.md`: native re-scope + naming +
tenant separation). Re-scope the dormant `cms-akira-navigation` into a NATIVE Akira navigation module: own
`cms_akira_menus` + `cms_akira_menu_items` (tenant-scoped), expose `akira.navigation.*@1` (menu get/tree/resolve),
NO `cms.menus.*`, NO `akira.content.get@1` residue; TRACK + enable at this gate. Phases 0-3 merged (core akira.post.*,
shell, install service, editor).

## Contract specifics (honor exactly)
- Remove manifest residue: depends `cms.menus.get@1`/`cms.menus.tree@1` + `akira.content.get@1` → depends
  cms-akira-core only (navigation items may reference content via content-type/key, not foreign SQL).
- Expose Akira-owned: `akira.navigation.menus@1` (list), `akira.navigation.tree@1`, `akira.navigation.resolve@1`
  (resolve a menu location → tree for a request), admin CRUD caps for menu management (governed v2 mutations if
  mutating: idempotent + durable audit + effects.invalidates on the navigation entity-view keys). Freeze family
  names per the naming rule (akira.navigation.*).
- Own tables: `cms_akira_menus` (tenant_id, slug/location unique per tenant, title) + `cms_akira_menu_items`
  (tenant_id, menu, parent_id, label, url/ref, weight, depth guard). MySQL 5.7 (InnoDB utf8mb4_unicode_ci;
  composite unique index byte budget incl. the tenant prefix; FK strategy = stable Akira entity keys, never another
  member's rows). Migration per-member files; Kernel coordinator owns ledger.
- Tenant separation: every row tenant-scoped via ModuleDB/tenant context; isolation tests (shared + dedicated);
  never take tenant from payload.
- Entity View ids for menus if the shell renders them; effects.invalidates single canonical tag.
- `_enabled:false` retained → TRACK (un-ignore modules/cms-akira/cms-akira-navigation/**) at this gate; explicit
  tenant activation via the module-install service.

## Deliverables
1. modules/cms-akira/cms-akira-navigation re-scoped (module.json akira.navigation.* exposes + owns/reads tables +
   migrations; native handlers; remove residue), README.
2. Migrations for cms_akira_menus + cms_akira_menu_items (idempotent, MySQL 5.7).
3. Track via .gitignore; commit source + tests.
4. Tests: menus/tree/resolve CRUD; tenant isolation A/B (shared + dedicated); mutation idempotency/audit/invalidate;
   projection explicit; authority zero; certify.
5. Append result to the contract.

## Verification (do all)
- Navigation tests green; P1 38 + P2 38 + shell 21 + install 23 + editor 25 green; authority 18/18; capability
  audit zero; module:certify --all (incl navigation); composer full; phpstan + cs-fixer (tracked navigation); logs
  clean; CI 6/6.
- grep: no cms.menus.* / akira.content.get@1 in tracked navigation; MySQL 5.7 clean.

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state: (Phase 5A/5B ready?)

## Implementation result — 2026-09-07

status: PASS (local gate; CI confirmation recorded on the Phase 4 branch)

task: Native re-scope, track, and certify `cms-akira-navigation`.

changed:
- `.gitignore`
- `.ai/contract-independent-cms-phase4-2026-09-07.md`
- `modules/cms-akira/cms-akira-navigation/**`

implementation_summary:
- Replaced legacy delegation with nine native `akira.navigation.*@1` capabilities: menus/tree/resolve plus governed menu/item create/update/delete.
- Added tenant-scoped menu/item ownership, stable same-member ASCII keys/FKs, Post type/key references, deterministic fail-closed tree construction, depth guard, explicit projections, protocol-v2 policy seeding, Kernel idempotency, same-PDO durable audit, and one canonical invalidation tag.
- Preserved the historical table-free `001_initial.sql` ledger marker and added MySQL-5.7-safe native schema in `002_create_native_navigation.sql`; retained `_enabled:false` for explicit module-install activation.

verification:
- Navigation shared/CRUD/governance suite: 35/35; dedicated tenant databases: 6/6.
- Regression: P1 38/38; P2 38/38; shell 21/21; install 23/23; editor 25/25.
- Authority audit 18/18; capability audit zero; `module:certify --all` all certified; Composer test 106/106; navigation PHPStan and CS Fixer clean; workbench audit clean; forbidden residue grep clean; logs clean.
- Dedicated tenant #1/#2 migrations converged and live connection identities were distinct from each other and the Kernel/base database.

scope: No Kernel source changed. Unrelated pre-existing/untracked `.ai` files were not added.

risks:
- Repository-wide `composer analyse`, `composer lint`, and `architecture:check` retain pre-existing findings outside this member (not changed under the Kernel-read-only/member-only gate); navigation-targeted static/style checks are clean.

unresolved: None in the Phase 4 navigation member.

recommended_next_state: Phase 5A/5B ready, subject to their individual gates.
