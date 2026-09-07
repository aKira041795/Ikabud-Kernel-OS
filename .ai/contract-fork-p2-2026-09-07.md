# CMS Akira Fork — P2 gate: Kernel 6.x mutation + invalidation proof (own gate)

task: Add the P2 mutation path to the CMS Akira fork (cms-akira-core, P1 already complete at main ad250b3 + working
tree): `cms.post.create@1` + `cms.post.update@1` (admin-only) governed by the kernel, with `effects.invalidates:
["entity.list.post"]` → `invalidateEntityCache('post', tenant)` freshness proof, durable idempotency, and an
independent durable-audit `correlation_id`. NO feature dev beyond this gate.

objective: Prove the Kernel 6.x reinforcement loop the fork exists to demonstrate:
`CMS mutation → CapabilityBus (authority, tenant, effects.invalidates, evidence) → Entity cache invalidated → next
ARK render is fresh`. Zero-exception baselines preserved; P1 canonical READ path stays green.

## Authoritative sources (READ FIRST)
- `.ai/contract-fork-cms-akira-2026-09-07.md` (fork contract — P2 scope + constraints + acceptance)
- `.ai/chair-adjudication-fork-cms-akira-2026-09-07.md` (amendments; P2-applicable = R4 audit destination/topology,
  R7 named mutation endpoint + kernel CSRF; R1/R2/R3/R5/R6 already honored in P1)
- `.ai/fork-p1-completion-2026-09-07.md` (P1 state — cms-akira-core content authority, versioned bridges
  entity.list.post@1/entity.get.post@1, theme storage/cms-themes/cms-akira-posts, 13 dormant members _enabled:false)
- Kernel 6.x as merged: CapabilityBus effects.invalidates (6.4), Idempotency primitive (claim/commit/release +
  canonicalPayloadHash), EntityViewResolver.invalidateEntityCache(type, tenant), ArkRendererResolver.

## P2 scope (from fork contract — honor exactly)
1. **Mutation capabilities** `cms.post.create@1` + `cms.post.update@1`, permitted roles `['admin']` only
   (`editor` deferred). Registered in the Entity Authority registry + governed by a `CapabilityAuthorizationRegistry`
   policy so the bus re-authorizes direct calls. Handlers: validate payload; canonical slug (create) / slug lookup
   (update); status (draft/published) + published_at handling; tenant identity from kernel context ONLY (never
   payload); actor from kernel context; reject tenant-spoofing / JWT-claim injection / unpublished reads stay
   published-only.
2. **effects.invalidates** exactly `["entity.list.post"]` (ONE canonical tag; document the fan-out:
   regex `^entity\.(?:list|detail)\.(\S+)$` captures `post` → `invalidateEntityCache('post', tenant)` invalidates
   both entity.list.post + entity.detail.post fragment tags in one call). Invalidation asserted ONLY after successful
   commit. CapabilityBus invalidation is fail-open → a freshness test PLUS an operational warning/metric on failed
   invalidation are mandatory.
3. **Durable idempotency + audit (R4-corrected)**: explicit single-connection sequence on ONE PDO:
   `BEGIN` → `Idempotency::claim(key, tenant, canonicalPayloadHash(envelope), pdo)` (new → proceed; duplicate →
   ROLLBACK + return stored outcome; conflict → ROLLBACK + 409 semantics; in_progress → ROLLBACK + 425/Retry-After)
   → Post write + durable audit row carrying `correlation_id` → `Idempotency::commit(key, tenant, outcome, pdo)` →
   `COMMIT`. Any exception before COMMIT → ROLLBACK + (release only on certain pre-publication failure); uncertain →
   stays processing, never auto-reclaimed.
4. **R4 preflight (MANDATORY before code)**: NAME the existing audit destination (kernel `audit_logs` via
   `kernel.audit.record@1`, or the kernel durable-event outbox if audit_logs is not same-connection) and PROVE that
   `cms_akira_posts`, the idempotency store, and the audit destination are reachable transactionally on the SAME
   PDO/database for the mutation sequence — OR, if not provable, REPLACE the atomicity claim with an explicit
   documented cross-database recovery design. NO new audit table (one-table DDL limit already used by cms_akira_posts
   in P1). Record the preflight result + the DB topology (tenant DB vs app DB vs control DB) inside the contract
   BEFORE implementation. If topology cannot be proven, STOP → BLOCKED with evidence.
5. **R7 mutation endpoint**: a NAMED non-GET HTTP mutation route on cms-akira-core (e.g. `POST /api/v1/.../posts`,
   `PUT /api/v1/.../posts/{slug}` or the module's canonical mutation path) enforcing the kernel CSRF mechanism for
   session/browser requests, PLUS direct CapabilityBus authorization tests (unauthenticated/unauthorized-role
   denial). Acceptance must not depend on an undefined endpoint — name it here and in code.

## P2 acceptance (from fork contract)
- `cms.post.update@1` through CapabilityBus → effects.invalidates (single `entity.list.post`) →
  `invalidateEntityCache('post', tenant)` → next ARK render fresh (cache-then-mutate-then-render test).
- Durable idempotency: same key = one write; replay returns stored outcome; concurrent same-key = one write;
  payload-hash conflict rejected; failed write does NOT invalidate.
- `correlation_id` present in the durable audit independently of trace-log env.
- Admin-only authorization (denial for non-admin), tenant isolation A/B, CSRF on the mutation route, JWT-claim
  injection ignored.
- P1 path unchanged + green (post_read_render_test 38/38). Capability authority 18/18, Workbench zero,
  `composer test` full green (locally, with the 13 dormant members _enabled:false — kernel audit fix #31 handles
  them). Both logs clean. No kernel edits unless a new genuine kernel gap is proven → then STOP/BLOCKED with
  evidence (do not silently edit kernel).
- P3 ARK authority ADR + P4 deferred marker are OUT of this implement gate (chair handles them after P2 passes).

## Verification
- New P2 tests (repo plain-PHP style under modules/cms-akira/cms-akira-core/tests/): mutation + invalidation
  freshness, idempotency (replay/conflict/concurrency/no-invalidate-on-failure), authorization/CSRF/tenant-spoof,
  audit correlation_id presence + topology.
- Full `composer test`; authority 18/18; theme validate clean; both logs clean.
- Pristine fork diff (vs /var/www/html/applicationostest/modules/cms-akira) = intended P1+P2 deltas only.

## risk
- MEDIUM. DB topology (tenant DB posts vs app/tenant idempotency vs audit_logs) is the main uncertainty — the R4
  preflight must resolve it or return BLOCKED with options. Idempotency + invalidation semantics are already
  kernel-proven (stabilization) — P2 only wires them. Do NOT weaken published-only reads or baselines.

status: BLOCKED (R4 topology preflight failed; no P2 implementation started)

## R4 topology preflight (recorded before code, 2026-09-07)
- **Named durable audit destination:** existing tenant-local `audit_logs`, written through
  `kernel.audit.record@1`. The mutation would require an `ok:true` result and would place `correlation_id` in the
  row's durable JSON payload; optional CapabilityBus trace logging is not audit evidence.
- **Required topology:** the request's tenant-aware `app()->db()` PDO would have to contain all three InnoDB tables:
  `cms_akira_posts`, `kernel_idempotency_keys`, and `audit_logs`. `kernel.audit.record@1` uses that same
  `app()->db()` under kernel escalation, so audit can participate in the caller-managed transaction when the tables
  coexist. The control-plane DB is separate in configured-tenant requests and is not part of this transaction.
- **Observed base/application DB (`ikabudsix`):** one PDO/database contains all three required tables. This does not
  prove the tenant topology because `DatabaseManager::db()` switches configured tenant requests to each tenant DB.
- **Observed configured tenant DBs:** `ikabudsix_dl1`, `ikabudsix_dl2`, `ikabudsix_dl3`, `ikabudsix_dl4`, and
  `ikabudsix_dl5` contain no `kernel_idempotency_keys`; none currently contains `cms_akira_posts`; tenant-local
  `audit_logs` exists in dl1/dl2/dl4/dl5 and is absent in dl3. The Post table would be added when this module is
  provisioned, but `tenantSafeKernelMigrationArtifacts()` includes the `audit_logs` runtime migration and omits
  `migrations/011_kernel_idempotency_keys.sql`, so provisioning cannot establish the required three-table topology.
- **Additional execution evidence:** capability handlers run in an active `ModuleContext`. Calling
  `Idempotency::claim(..., app()->db())` from this module would access the kernel-owned
  `kernel_idempotency_keys` table through guarded `KernelPDO`; `cms-akira-core` cannot claim that kernel table in its
  manifest and direct module escalation is forbidden. There is no declared kernel idempotency capability bridge.
- **Decision:** R4 single-PDO topology and legal access cannot be proven. A cross-database design would not repair the
  missing tenant idempotency store/access path without inventing a bridge/outbox, which this gate expressly forbids.
  Per the contract, implementation stops before mutation code.
- **Unblock options for a separate kernel decision/change:** (1) provision the existing idempotency migration into
  every applicable tenant DB and expose a kernel-owned transaction participant callable on the caller's PDO, while
  preserving module table isolation; or (2) approve and specify a durable cross-DB recovery/outbox design using an
  existing kernel destination. After either option is merged, rerun this preflight and return the gate to
  READY_FOR_IMPLEMENTATION.

---

## CHAIR ADJUDICATION (2026-09-07) — P2 BLOCK #1 resolved: prove on the repo's actual validated runtime

The implementer's R4 preflight is thorough and correct for the configured-tenant (dl1-5) topology. Chair decision
with evidence:

- The repo's DEFAULT + CI-validated runtime is SINGLE-TENANT app-first: `APP_MULTI_TENANT_ENABLED=0` in BOTH `.env`
  and `.env.ci`; `DB_DATABASE = CONTROL_DB_DATABASE = ikabudsix` (one DB); config/app.php documents multi-tenancy as
  "an optional compatibility architecture, NOT the default design target." Every kernel test + CI 6/6 run here.
- The implementer CONFIRMED the base/application DB `ikabudsix` contains ALL THREE tables on one connection
  (`cms_akira_posts` to be added on provisioning + `kernel_idempotency_keys` + `audit_logs`). Therefore the same-PDO
  `BEGIN → Idempotency::claim → Post write + audit(correlation_id) → commit → COMMIT` sequence IS provable in the
  runtime the fork must pass in CI.
- The dl1-5 tenant DBs are daily-ledger's local tenant-local dev DBs, NOT the kernel's default runtime. The kernel
  idempotency/outbox + audit provisioning parity gap for DEDICATED tenant DBs is a REAL pre-existing infra gap but it
  is OUT of the fork P2 scope (multi-tenant is optional per the repo's own config) and must NOT block the fork's
  canonical-path proof.

SCOPE for the re-run (amended):
1. P2's atomic mutation + invalidation path targets the SINGLE-TENANT app DB (the repo's default + CI runtime),
   preserving tenant_id column semantics + kernel-context tenant identity for multi-tenant compatibility.
2. The mutation runs through the module's guarded path exactly as the kernel permits in single-tenant mode
   (kernel escalation for audit via the same connection — verify the ACTUAL legal path the implementer confirmed:
   `app()->db()` under kernel escalation participates in the caller-managed transaction when tables coexist).
3. RECORDED FOLLOW-UP (not a P2 blocker; tenant-provisioning workstream): when APP_MULTI_TENANT_ENABLED=1, tenant
   provisioning must include kernel_idempotency_keys (migrations/011) + kernel_durable_event_outbox (015) + the
   audit path + capability_authorization_policies so dedicated tenant DBs get the same atomic path; and a
   kernel-owned idempotency transaction participant (or declared capability) should be added for guarded modules.
4. If same-PDO atomicity STILL cannot be proven on the SINGLE app DB (ikabudsix) despite all three tables present,
   return BLOCKED with that specific evidence — do not fall back to tenant-DB reasoning.

Re-run P2 with this scope.

---

## P2 re-run R4 preflight result (recorded before code, 2026-09-07)

- **Target runtime and named audit destination:** the validated runtime is single-tenant
  (`APP_MULTI_TENANT_ENABLED=0` in `.env` and `.env.ci`). Application and control DB names are equal within each
  environment (`ikabudsix` locally; `ikabudsix_ci` in CI). The existing durable audit destination remains
  `audit_logs` through `kernel.audit.record@1`, with `correlation_id` carried in its durable JSON payload.
- **Physical topology proven:** on the local application PDO, `SELECT DATABASE()` returned `ikabudsix`, and
  `cms_akira_posts`, `kernel_idempotency_keys`, and `audit_logs` all exist with the InnoDB engine. Thus the three
  stores are physically transaction-capable on one PDO/database in the target topology.
- **Legal guarded-path probe:** from `moduleWithContext('cms-akira-core', ...)`, the same application PDO began a
  transaction, then `Idempotency::claim(..., $pdo, 0)` attempted its first read of `kernel_idempotency_keys`.
  `KernelPDO` failed closed with: `Database access denied: Module 'cms-akira-core' accessed undeclared table
  'kernel_idempotency_keys'. Declare it in module.json owns_tables or reads_tables.` The module cannot legally claim
  a kernel-owned table, and direct module calls to `KernelPDO::kernelEscalationEnter()` are explicitly blocked.
  `kernel.audit.record@1` can legally escalate from kernel code on the same PDO, but no equivalent existing kernel
  idempotency capability/transaction participant exists.
- **Dedicated-tenant follow-up (not the reason for this block):** when `APP_MULTI_TENANT_ENABLED=1`, tenant
  provisioning must include migrations 011/015, the audit path, and `capability_authorization_policies`, plus a
  kernel-owned idempotency transaction participant (or declared capability), so dedicated tenant DBs receive the
  same atomic path. This remains assigned to the tenant-provisioning workstream per chair adjudication.
- **Decision:** **BLOCKED before P2 code.** Physical same-PDO atomicity is proven for the single-app DB, but the
  amended contract explicitly requires stopping if the guarded module context forbids idempotency access there.
  The narrowest legal kernel seam is a kernel-owned idempotency transaction participant that operates on the
  caller-managed application PDO/transaction; adding that seam is a kernel change and is outside this gate. Do not
  add `kernel_idempotency_keys` to module ownership/reads and do not bypass `KernelPDO`.

status: BLOCKED (single-tenant same-PDO topology proven; legal guarded idempotency participant absent)

---

## P2 addendum #2 re-run result (recorded before module code, 2026-09-07)

- **Idempotency blocker resolved:** main `9e49602` provides the kernel-owned
  `kernel.idempotency.hash@1` / `claim@1` / `commit@1` / `release@1` capabilities. Their implementation enforces the
  exact caller application PDO and current positive tenant, escalates only around the kernel idempotency primitive,
  and leaves transaction ownership with the caller. This is the required legal participant for the already-proven
  single-app-DB topology (`ikabudsix`; `cms_akira_posts`, `kernel_idempotency_keys`, and `audit_logs`, all InnoDB).
- **New authorization-registry preflight failure:** both target schemas, `ikabudsix` and `ikabudsix_ci`, lack the
  `capability_authorization_policies` table queried by `CapabilityAuthorizationRegistry`. No repository migration
  creates that table (the tracked kernel migrations end at `015_kernel_durable_event_outbox.sql`; the database
  migrations also contain no definition). Consequently a P2 module migration cannot seed the mandatory admin-only
  v2 policy, and direct CapabilityBus calls cannot be re-authorized by the registry in the default or CI runtime.
  Creating this missing kernel-owned table from `cms-akira-core` would violate the no-kernel-edit/no-extra-DDL and
  table-ownership constraints.
- **Runtime wiring evidence:** module capability registration in `src/helpers/module-routes.php` forwards manifest
  `policy`, `schema`, `effects`, and `origin` metadata, but not an expose entry's `requires_protocol`. Therefore a
  module manifest cannot independently force the registry's v2 path as a fail-closed substitute; bus governance can
  only be activated here by a policy row, whose kernel-owned store is absent.
- **Dedicated-tenant follow-up remains unchanged:** when multi-tenancy is enabled, tenant provisioning must include
  idempotency/outbox/audit/authorization-policy parity. This is not the reason for this re-run's block.
- **Decision:** **BLOCKED before P2 module code due to a genuine new kernel gap.** The narrow repair is a kernel-owned
  migration/provisioning contract for `capability_authorization_policies` (plus propagation of manifest
  `requires_protocol` if that is intended as the fail-closed activation mechanism). After that kernel seam is merged
  and the admin policy can be durably seeded, rerun P2 using the now-available idempotency capabilities. Do not
  weaken the requirement to an in-handler role check or create a module-owned policy table.

status: BLOCKED (idempotency bridge present; mandatory CapabilityAuthorizationRegistry persistence absent in app and CI schemas)


---

## P2 addendum #3 re-run result (recorded after code, 2026-09-07) — P2 PASS

- **Kernel blockers resolved and consumed:** main `9e49602` (PR #33) provides the kernel-owned
  `kernel.idempotency.hash@1`/`claim@1`/`commit@1`/`release@1` bridge (exact caller app PDO + current positive tenant
  enforced; transaction ownership stays with the caller; kernel escalation only around the Idempotency primitive).
  main `63b6ec1` (PR #34) provides `migrations/016_capability_authorization_policies.sql` (registry-exact columns,
  natural upsert UNIQUE key, active-policy index) and propagates manifest `requires_protocol` into CapabilityBus
  provider meta via `moduleCapabilityProviderMeta()`.
- **R4 topology (single-tenant app DB) proven on the validated runtime:** `APP_MULTI_TENANT_ENABLED=0` in both `.env`
  and `.env.ci`; `DB_DATABASE = CONTROL_DB_DATABASE` per env (`ikabudsix` local / `ikabudsix_ci` CI); config/app.php
  documents multi-tenancy as an optional compatibility architecture, NOT the default target. `cms_akira_posts`,
  `kernel_idempotency_keys`, `audit_logs`, and `capability_authorization_policies` are all InnoDB on one PDO/database.
  The mutation runs the guarded module context, begins the caller-managed transaction on `app()->db()`, and the kernel
  idempotency/audit capabilities escalate narrowly onto that same connection. Dedicated dl1-5 tenant DBs are NOT the
  target (daily-ledger local dev DBs); their provisioning-parity gap remains the recorded tenant-provisioning follow-up.
- **Implementation (module-side, no kernel edits):** `cms.post.create@1`/`cms.post.update@1` declare `requires_protocol:
  "v2"` + `effects.invalidates: ["entity.list.post"]` (single canonical tag). Handlers validate the canonical slug,
  status/published_at, reject payload `tenant_id`/role/JWT claims, derive tenant + actor solely from kernel context,
  and run `BEGIN → kernel.idempotency.claim@1 (hash@1) → Post write + kernel.audit.record@1(correlation_id) →
  kernel.idempotency.commit@1 → COMMIT` with fail-closed duplicate/conflict/in_progress semantics and
  release-only-on-certain-pre-publication-failure. Activation idempotently seeds the admin-only protocol-v2 policy via
  `CapabilityAuthorizationRegistry::seedPolicy()` (its own narrow kernel escalation, never bypassing KernelPDO).
  Named non-GET routes `POST /api/v1/cms-akira/posts` and `PUT /api/v1/cms-akira/posts/{slug}` enforce `app()->csrfEnforce()`.
  `effects.invalidates ["entity.list.post"]` fans out via the kernel regex to `invalidateEntityCache('post', tenant)`,
  asserted only after successful commit; invalidation remains fail-open with a kernel operational warning (plus the
  P2 freshness test).
- **Dedicated-tenant follow-up (unchanged, not a P2 blocker):** when `APP_MULTI_TENANT_ENABLED=1`, tenant provisioning
  must include migrations 011/015/016, the audit path, and capability_authorization_policies so dedicated tenant DBs
  get the same atomic path.

## P2 verification evidence (2026-09-07)
- `php -l` clean on all 7 touched module PHP files; `module.json` + theme JSON parse clean (JSON_ERROR_NONE).
- `php ikabud theme:validate cms-akira-posts` → all checks passed, zero warnings/errors.
- P2 gate `tests/post_mutation_test.php` → **26/26** (protocol-v2 + single-tag declarations; Entity Authority;
  idempotent policy seed + admin-only rows; named non-GET routes + kernel CSRF; create/replay-one-write/409 conflict;
  cache-then-mutate-then-render freshness; update outcome; durable audit correlation_id; same-PDO InnoDB topology;
  tenant isolation A/B; payload tenant spoof rejection; registry non-admin + unauthenticated denial; JWT/role-claim
  injection ignored; concurrent same-key → 425 no second write; no-invalidate-on-failure; failed-write rollback +
  claim release; clean logs).
- P1 `tests/post_read_render_test.php` → **38/38** green (canonical read/render path unchanged).
- `tests/capability_authority_audit_test.php` → **18/18** green.
- `composer test` → **105/105** green (kernel_idempotency_capability_test included).
- `storage/logs/app.log` + `storage/logs/error.log` → 0 bytes after runs; DB free of test fixtures; policy table has
  exactly the two admin/v2/active rows (idempotent seed).
- `diff -rq` vs pristine `/var/www/html/applicationostest/modules/cms-akira` → intended P1+P2 deltas only:
  cms-akira-core (module.json, helpers.php, helpers/capabilities.php, helpers/entity-views.php, handlers.php,
  routes.php, README.md, migration 002, removed helpers/providers.php, new tests/) + the 13 dormant submodules marked
  `_enabled: false`. No kernel edits.

status: PASS
