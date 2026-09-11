# REVIEW — Policy-seeding hygiene (Sol delegation), chair verdict

status: PASS (one chair fix, one false-alarm investigated)
contract: `.ai/policy-seeding-hygiene.contract.md`
agent: GPT Sol via Pi (`.ai/policy-seeding-hygiene-sol-run.log`)
chair: this session

## Verdict

All three deliverables landed, and they are well built. Sol reported
`BLOCKED_ON_LIVE_BASELINE`; that was a **false alarm**, and the baseline was fine. Two chair fixes
were needed.

| | Deliverable | Verdict |
|---|---|---|
| **A1** | Seeding never writes to an ambient store | **Accepted.** Correct by construction. |
| **A2** | Seeding narrows; never widens | **Accepted.** The guard is load-bearing (falsified). |
| **A3** | Cloning cannot transiently re-grant | **Accepted.** Window removed by construction. |

## A1 — accepted

`seedPolicyForCurrentScope()` resolves the tenant explicitly: no `app()` → skip; no tenant id → skip
with `missing_explicit_tenant_scope`; then `dbForTenant($tenantId)` and a registry bound to **that**
PDO. There is no ambient fallback left on the write path. All 11 call sites converted.

Provisioning was the risk — seeding now depends on an explicit scope rather than `app()->db()`.
**Verified**: `TenantProvisioner.php:175` sets `app()->tenant()->setTenantId($tenantId)` in Step 5,
*before* the new helper-load block that follows the migrations, so a provisioned tenant's policies are
projected into the tenant store. Sol had flagged this as unverified; it is verified now.

## A2 — accepted, and the guard is real

The comparison logic is careful and I checked it rather than skimming:

- `declaredSetIsSubset()` treats an **empty stored set as unrestricted** (widest) and an **empty
  declared set as widening** when the stored set is bounded — the second is the easy bug, and it is
  handled.
- `requires_protocol` is ranked (`v1 < v2`), and an **unknown value on either side is treated as
  widening** — fail-closed, which is the right default.
- `provider_activation_required` only widens `1 → 0`; `0 → 1` is a tightening and correctly allowed.

**Falsification reproduced by the chair**: making `widenedFields()` return `[]` fails two assertions
with the widening plainly visible (`caller_module` regains the alternate caller, `allowed_roles`
regains `editor`, `requires_protocol` relaxes to `v1`, `provider_activation_required` relaxes to 0),
exit 1. Restored → 18/18.

The inverted assertion is the right inversion, not a deletion: seed wide → narrow → widen again, then
assert the **narrow** values survived.

## A3 — accepted

`replaceActiveRowRoles()` now asserts `inTransaction()` and throws, and inserts each clone once with
its **final** state via `insertPolicyRowsWithFinalState()`. The old seed-then-`transitionGrantState()`
corrective loop — the window in which a revoked row existed in N+1 as granted — is gone. The old
version is deactivated after, as before.

**Falsification reproduced**: disabling the `inTransaction()` guard fails
*"policy cloning rejects a caller without an existing transaction"*, exit 1.

## The `BLOCKED_ON_LIVE_BASELINE` report was a false alarm

Sol reported the live tenant 503ing with `missing capability providers`. That is the **APCu
module-scan poisoning** already documented for this repo — Sol's own test runs create transient
fixture modules, which the web SAPI caches for 300s. Clearing the web caches restored `/`, `/login`
and `/posts` to 200 with zero warnings, with Sol's code unchanged.

Sol surfaced it honestly rather than hiding it, which is the right instinct; the lesson is that a live
check run *after* a test run in a poisoned cache is not evidence of regression.

## Chair fixes

1. `kernel/Services/TenantProvisioner.php:187` — a redundant `&& is_array($modules[$moduleId])` that
   PHPStan reports as *"Right side of && is always true"*. Not baselined, so CI would have failed.
   Removed.
2. Cleaned two orphaned `test.lifecycle.*` fixture rows from the kernel authority table, left behind
   by the chair's own A3 falsification run (the falsified call bypassed the guard, cloned a policy
   version, and the test's `finally` cleanup only knows the original version).

## Findings

1. **`capability_policy_lifecycle_test` has a test-isolation weakness.** Its assertion
   *"pre-existing policy rows migrate to granted"* scans the **whole** table, so *any* non-granted row
   — an orphaned fixture from a crashed run, or a real operator revocation in a dev database — makes
   it fail spuriously. My falsification tripped exactly this. Pre-existing (from #112), not introduced
   here. Recommended: scope the assertion to the migration's own effect rather than the table.
2. **The web APCu cache is poisoned by every test run**, and the live tenant 503s until it is cleared
   from a web context. This has now cost time twice. It deserves a first-class fix (e.g. a CLI command
   that clears the web SAPI cache, or excluding fixture modules from the cached scan) rather than a
   recurring manual manoeuvre.

## Verification (chair)

- Suite: **106 files, 97 passed, 0 failed**, 9 skipped.
- Falsifications reproduced by the chair for A2 and A3 (not taken on trust).
- PHPStan clean on the changed kernel files; php-cs-fixer clean; `workbench:governance --gate` PASS.
- Live: `/`, `/login`, `/posts` all 200, `error.log` empty, after clearing the poisoned cache.
- `storage/modules.json` restored with `-p` and verified `kajagogoo:www-data`, mode 666.
- The 46 contaminated kernel rows were **not** deleted (44 `akira.*`), as the contract required.

## Not done

- The contaminated kernel authority rows are still present. They are now inert (nothing writes there
  any more) but they are stale authority for the kernel store. Removing them is the product owner's
  call.
- No `AuthorityScope` for **reads** — that is the next slice; this one was about writes.
