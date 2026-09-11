# REPAIR 1 — authority scope (PR #118 CI failures)

status: CHANGES_REQUIRED
owner: implementation agent (Sol, via Pi)
repo: `/var/www/html/ikabudsix` — branch: `feat/authority-scope` (work in the tree)
context: `.ai/authority-scope.contract.md` (your original contract) · PR #118

Your slice is accepted in substance — the ambient fallback is genuinely removed and I verified the
falsifier myself. Two defects block it. Both are evidenced from CI run `34560866850`, which failed all
three test-matrix jobs (`mysql-8`, `mysql-5.7`, `mariadb-10.6`) while `kernel contracts`,
`coding-standards` and `static-analysis` passed.

## R1 — BLOCKING production regression: seeding throws instead of skipping

CI error, repeated on all three matrix jobs:

```
UNCAUGHT Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistryUnavailableException:
capability authorization registry seed failed: capability authorization registry database unavailable
  in kernel/Capabilities/CapabilityAuthorizationRegistry.php:257
```

Affected tests (all now `FAIL` in CI): `capability_effect_invalidation_test`,
`durable_idempotency_test`, `entity_view_render_cache_test`, `kernel_idempotency_capability_test`,
`tenant_entry_auth_shell_standard_test`.

Every one of them only calls `requireTenantFixture(<id>)`. They **skip on this workstation** with
`tenant <id> has no resolvable database configuration`, so a local green run cannot see this — CI
(where those tenants resolve) is the oracle. Trace: the fixture creates a tenant and boots modules →
module helpers run their file-scope seed → `seedPolicyForCurrentScope()` resolves a scope (the tenant id
*is* known) → but `dbForTenant()` returns `null` for that tenant → `db()` throws → `seedPolicy()`
rethrows it → uncaught → the test process dies.

**You removed the guard for this.** Before your slice it was:

```php
$db = method_exists($application, 'dbForTenant') ? $application->dbForTenant($tenantId) : null;
if (!$db instanceof PDO) {
    self::logSeedDecision('skipped', ['reason' => 'tenant_authority_store_unavailable', 'tenant_id' => $tenantId]);
    return;
}
(new self($db))->seedPolicy($rows);
```

Restore it, resolving the database once and skipping with a recorded reason:

```php
if (!$scope instanceof AuthorityScope) {
    self::logSeedDecision('skipped', ['reason' => $resolver->failureReason() ?? 'missing_explicit_tenant_scope']);
    return;
}

$db = $resolver->database($scope);
if (!$db instanceof PDO) {
    self::logSeedDecision('skipped', [
        'reason' => $resolver->failureReason() ?? 'tenant_authority_store_unavailable',
        'tenant_id' => $scope->tenantId,
    ]);
    return;
}

(new self($db, $scope, $resolver))->seedPolicy($rows);
```

Notes — read these, they are the boundary:
- A **known tenant id with an unresolvable database** is exactly the case that must skip. Do not
  conflate "no scope" with "no store"; they are different reasons and both are non-fatal **for seeding**.
- Do **not** wrap the whole call in a catch-all, and do **not** make `seedPolicy()` swallow everything.
  The read path is a different matter entirely.
- **The read path must keep failing closed.** `authorize()` throwing when the store is unreachable is
  intended (the ratified contract says an unavailable registry prevents invocation). Do not relax it.

## R2 — BLOCKING: `tests/authority_scope_test.php` is environment-coupled

It hardcodes tenant **54**, asserts the authority database is literally named **`akira`**, and requires
a pre-existing granted policy row. All three are true only on this workstation; CI failed it.

Make it hermetic:
- Create its own tenant through the repo's fixture helpers (`tests/_support/tenant_fixture.php` →
  `ensureTestTenant()`), and clean up in `finally`. Do **not** assume tenant 54 exists.
- Assert the entry points resolve the **same** database and that it is **not** the kernel database —
  without naming `akira` (`SELECT DATABASE()` compared across the entry points is enough).
- Do not depend on a pre-seeded policy: seed the row you need yourself via the registry with the
  fixture PDO.
- If a precondition genuinely cannot be met, print `SKIP: <reason>` and do not fail — that is the repo
  convention (`tests/_support/env_guard.php`). Do not silently pass.

**Keep the falsifier.** The reflection assertion that `db()` must throw without a scope is the point of
the test — I verified independently that restoring the old ambient fallback makes it fail (2 assertions,
exit 1). Do not remove or soften it.

## Constraints

- Do **not** modify `tests/admin_platform_api_test.php`. Your earlier edit there was reverted: I proved
  it unnecessary (the unmodified file passes 13/13 standalone *and* in-suite against your production
  changes, and nothing assigns `$externalReference` in global scope).
- Do not touch `modules/daily-ledger/**`.
- MySQL 5.7 safe. No new dependencies.
- Back up `storage/modules.json` before `composer test` and restore ownership/permissions
  (`kajagogoo:www-data`, mode 666). It is gitignored, so a missing file is not itself a failure.
- **Do not commit, push or branch.**

## Acceptance

1. `composer test` locally: `0 failed`.
2. State explicitly that the 5 tenant-dependent tests **skip locally**, so a local run cannot prove R1 —
   say what you did to increase confidence instead of claiming a green you cannot observe.
3. `tests/authority_scope_test.php` passes locally **and** contains no reference to tenant 54 or the
   literal database name `akira`.
4. Paste the local `Total:` line and the exact text of your R1 guard.
5. Confirm `authorize()` still fails closed when the store is unreachable (do not weaken it to silence a
   failure).

## Deliverable

Result block: status, changed, implementation summary, verification, evidence, scope, risks,
unresolved, recommended next state.
