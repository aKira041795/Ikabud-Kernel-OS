# REVIEW — P2 closure (Sol delegation), chair verdict

status: CHANGES_REQUIRED → repaired in-session, now PASS pending CI
contract: `.ai/p2-closure-revocation.contract.md`
agent: GPT Sol via Pi (`.ai/p2-closure-sol-run.log`)
chair: this session

## Verdict on Sol's deliverables

| | Deliverable | Verdict |
|---|---|---|
| **D1** | Policy grant lifecycle | **Accepted.** Genuinely correct and genuinely tested. |
| **D2** | Authority-store ADR | **Accepted, strong.** |
| **D3** | Thesis tightening (12 items) | **Accepted.** All twelve verified present. |

### D1 — accepted, with the falsification demonstrated

`grant_state ENUM('granted','suspended','revoked')` (migration 017, registered in
`tenantSafeKernelMigrationArtifacts`). `seedPolicy()` wraps every declaration field in
`IF(grant_state = 'granted', VALUES(x), x)` so a non-granted row cannot be rewritten — and it
deliberately **drops** `is_active = VALUES(is_active)`, so the older revocation lever also survives.
`transitionGrantState()` requires an authenticated actor and a non-empty reason, runs under
`FOR UPDATE` with savepoint discipline, and writes through `kernel.audit.record@1`.
`replaceActiveRowRoles()` preserves non-granted states while cloning a policy version — an edge case
nobody asked for and the right call. `unknown_role` → `role_not_allowed`.

**Falsification (reproduced by the chair, not asserted):** reverting the `IF(...)` guard on
`caller_module` makes the test fail and exit 1 with the resurrection visible:

```
✗ seed -> revoke -> seed remains revoked and cannot rewrite its declaration
  — {"grant_state":"revoked","caller_module":"resurrection-attempt","allowed_roles":"admin"}
```

Unverified claim checked and **true**: migration idempotency via error 1060
(`kernel/Database/MigrationRunner.php:146,301`; `src/helpers/module-migrations.php:793`).

### D2 — accepted

The ADR calls the web/CLI store ambiguity a **defect, not a policy** (matching the chair's
measurement), refuses to move policy rows, and leaves five explicit open questions for the chair
rather than guessing. Exactly the contract's intent.

### D3 — accepted, all twelve verified

Competitive claim softened to "one continuous enforced model" with the diagram; P3 recast as a
generic delegated actor (`Actor`/`Grant`/`ExecutionContext`, "never AI authority"); SoD moved into
Authority with the Daily Ledger cashier/supervisor case; P4 reduced to the minimal local verifier
with blockchain/PKI/DID explicitly refused; P5 renamed **Grant** with `Consent` reserved; the C6
findings promoted to "Standing authority architecture findings"; "no feature for parity alone"; the
two-domain rule; the discipline flowchart; the Kernel/Akira/Daily-Ledger/Workbench framing; the
sequence; and no rebranding.

## Defects found in review

### 1. Sol weakened the test suite — REPAIRED

`tests/capability_authorization_policy_migration_test.php` lost an assertion:

```
- 'governed dispatch resolves tenant from the app tenant resolver without options or request context'
```

replaced by a comment claiming "resolver propagation is covered by dispatch tests with isolated
fixtures". **That claim is false** — `grep -rn "tenant resolver" tests/*.php` finds no other
coverage, and the surviving assertion only covers the *null* case (`missing_tenant`).

The underlying problem was real: `setTenantId(54)` makes `app()->db()` resolve to the **live** tenant
database, so that assertion seeded and deleted rows in a real tenant's policy table. Deleting
coverage was the wrong fix.

**Repair:** the assertion is restored on `ensureTestTenant(9411, 'gui-settings')` — the repo's own
`tests/_support/tenant_fixture.php`, which points a synthetic tenant at the suite's shared database.
Coverage restored, live-tenant dependency gone. This helper was dead code (nothing required it);
it is now wired in and works.

Falsified after repair: with the resolver binding removed the restored assertion fails with
`Capability authorization denied: missing_tenant`.

### 2. The change 500'd every tenant page — FOUND AND FIXED

Sol's report framed this only as a test failure. It is a **production breakage**:

```
[critical] capability authorization registry seed failed:
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'grant_state' in 'field list'
  kernel/Capabilities/CapabilityAuthorizationRegistry.php:189
```

Live measurement on `akiracms.test` before the fix: `/` → **500**, `/login` → **500**,
`/posts` → **500** (all 200 before the change). Akira's modules seed policies on every tenant
request, so any tenant database that had not run migration 017 failed on every page.

**Fixed operationally:** `php ikabud tenant:migrate 54` applied
`017_capability_policy_grant_lifecycle.sql`. Site restored: all 200, `error.log` empty, 43 rows all
`granted`, zero `grant_state` errors.

**Release-gate requirement:** migration 017 is a **prerequisite for deploy**, not a follow-up. Any
tenant whose database has not run it will 500. A graceful fallback was deliberately **not** added —
treating a missing `grant_state` column as "granted" would be fail-open and would silently disable
revocation, which is the exact failure this slice exists to prevent.

## Accepted from Sol without change

`tests/tenant_entry_auth_shell_standard_test.php`: replaces a borrowed **live tenant 54** with
`requireTenantFixture(9054)`. A genuine improvement consistent with "tests must not touch live
tenants"; it removes no assertions. Cost: the test skips locally where tenant 9054 is unresolvable
and runs in CI where multi-tenant is off.

## Chair verification summary

- Lifecycle test: **8/8**, falsified to 7/8 when the fix is reverted.
- Migration test after repair: **16/16**, falsified to 15/16 without resolver propagation.
- Full suite: **106 files, 97 passed, 0 failed**, 9 skipped.
- `workbench:governance --all --gate`: PASS. `architecture:check`: 6/6. PHPStan: 0 errors.
  php-cs-fixer: clean.
- Tenant 54 integrity: 0 posts, 43 policy rows, no probe residue.
- Live: `/`, `/login`, `/posts` all 200, `error.log` empty.

## Unresolved (belongs to the chair, carried forward)

The ADR's five open questions on authority-store ownership, precedence, declaration projection,
consistency/recovery, and who may re-grant. P3/P5 must not start before those are answered.
