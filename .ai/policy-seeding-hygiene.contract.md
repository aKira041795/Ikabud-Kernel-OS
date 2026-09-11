# CONTRACT — Policy-seeding hygiene (P2 closure, defect slice)

status: READY_FOR_IMPLEMENTATION
owner: implementation agent (Sol, via Pi)
repo: `/var/www/html/ikabudsix` — branch: `fix/policy-seeding-hygiene`
chair: this session · authority: product owner ratified the authority-store decisions 2026-09-11

Read first: `docs/architecture/authority-store-adr.md` (**ratified**) and
`.ai/authority-store-flash.panel.md`. The three defects below were found during that debate, verified,
and recorded there as *"Defects found while deciding (verified, not yet fixed)"*. This slice fixes them.
Do not re-derive them.

## Why

Two of these defects directly contradict decisions the product owner has ratified, and the third
contaminates live data. The ratified D-Q3/D-Q4 say declarations are materialized immutably and that
**widening must require an operator act** — the current code widens a live grant automatically, on
every request. Fixing that is not speculative work; it is completing the ratification.

## Deliverables

### A1 — Seeding must never write to an ambient store

**Verified defect.** 11 call sites across module helpers construct the registry with `app()->db()`
(`modules/cms-akira/*/helpers.php`), and the seed functions are invoked **at file scope** (e.g.
`cms-akira-core/helpers.php:54,83,119,156,191,228,248`). In a web request `app()->db()` is the tenant
database; in CLI with no tenant it is the **kernel** database. Measured consequence: the kernel
authority table holds **46 rows, 44 of them `akira.*`**, against the tenant's 43. Module seeding has
been writing tenant authority into the kernel store.

Fix: policy seeding resolves its target from an **explicit authority scope**, never ambiently. When no
tenant scope exists, seeding **does not write** — it skips and records why. A web request with a tenant
must behave exactly as it does today (seeds into that tenant's store).

Requirements:
- One kernel-side helper resolves the seeding target for the current scope (or reports that there is
  none). Module helpers call it; they do not call `app()->db()` for authority writes.
- No tenant scope → no write, and a recorded reason (reuse `write_log`, no new logging mechanism).
- The 11 call sites are converted. Do not change what they *declare*; only where it is written.
- Tenant provisioning and `php ikabud tenant:migrate` must still seed the tenant store. Prove it.

### A2 — Seeding narrows; it must not widen

**Verified defect.** `seedPolicy()`'s upsert refreshes `caller_module`, `allowed_roles`,
`requires_protocol` and `provider_activation_required` whenever `grant_state = 'granted'`
(`CapabilityAuthorizationRegistry.php:157-165`). Module helpers load per request, so **a deploy that
changes declared roles silently widens a live grant**, and even a no-op request rewrites the authority
table.

Fix: implement the ratified **D-Q4 asymmetry at the seeding layer**:
- **narrowing or unchanged** declared permission set → apply (an audited *system* transition). A
  security fix that removes a role must still reach tenants without waiting for an operator.
- **widening** declared permission set → **do not apply**. Leave the stored grant as it is and record
  it. If you need a state for it, add one idempotently (the `017` ALTER pattern, error 1060 is already
  treated as success by `MigrationRunner`); do not invent a parallel table.
- `grant_state ∈ {suspended, revoked}` → unchanged from today: never touched, never restored.

Widening must be *decidable*: compare the declared set against the stored set per field
(`allowed_roles`, `caller_module`). "Declared ⊄ stored" is widening. Be explicit in your result about
what you chose for `requires_protocol` and `provider_activation_required` and why.

Note: `tests/capability_authorization_policy_migration_test.php` currently pins the *old* behaviour
("seedPolicy upserts its natural key and updates governed fields"). Inverting it is expected and
correct — but say so loudly in your result, and make the inverted assertion state the ratified rule.

### A3 — Cloning must not transiently re-grant

**Verified defect.** `replaceActiveRowRoles()` inserts the N+1 clone through `seedPolicy()`, which
writes `grant_state = 'granted'`; non-granted states are restored afterwards, and the old version is
deactivated last. `resolvePolicyVersion()` selects `MAX(policy_version) WHERE is_active = 1`, so there
is a window in which a suspended/revoked row is live **as granted**. Its "the caller owns the
transaction" contract is documentation only — the method never checks `inTransaction()`.

Fix:
- Assert the transaction requirement in code (`inTransaction()`), failing closed if unmet.
- Remove the window: the clone must be inserted with the correct final grant state, not granted-then-
  corrected. Prefer inserting the new version rows once, in their final state.
- Add a test that would fail if the window reappears.

## Constraints

- MySQL 5.7: no window functions, no CTEs, no `JSON_TABLE`, no enforced `CHECK`. Any DDL idempotent,
  `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`.
- **Do not delete the contaminated rows** in the kernel authority table. Report the count; removal is
  the product owner's call, not this slice's.
- Do not touch `modules/daily-ledger/**` (untracked, live money domain).
- Do not move the authority store, do not add `AuthorityScope` resolution for *reads* — that is the
  next slice. This slice is about **writes**.
- No new dependencies. Reuse kernel primitives.
- Back up `storage/modules.json` before `composer test` and **restore it with its original ownership
  and permissions** (`kajagogoo:www-data`, mode 666) — a plain `cp` restore recreates it 0600 and
  takes the live site down with a 503.
- Do not commit, push or branch. Leave everything in the working tree.

## Acceptance

1. **A1**: with no tenant scope, seeding writes nothing to the authority table — prove it by test, and
   show the kernel row count is unchanged across a CLI run that loads module helpers.
2. **A1**: a tenant-scoped web request still seeds that tenant's store (prove it, don't assert it).
3. **A2**: a widening declaration does **not** change a stored grant; a narrowing declaration **does**.
   Both as tests, with the falsifier stated: the widening test must fail if the guard is removed.
4. **A3**: the clone is inserted with final state (test), and the method rejects a missing transaction.
5. Full suite `0 failed`; `php ikabud workbench:governance --all --gate` PASS.
6. Live tenant unaffected: `/`, `/login`, `/posts` all 200 afterwards, `error.log` empty.
7. Raw command output pasted as evidence for 1–4.

## Deliverable

Result block: status, changed, implementation summary, verification, evidence, scope, risks,
unresolved, recommended next state. If you cannot finish a deliverable, say which and why rather than
weakening a test to make it pass.
