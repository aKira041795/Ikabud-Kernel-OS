# REPAIR 2 — authority scope (PR #118, second CI failure)

status: CHANGES_REQUIRED
owner: implementation agent (Sol, via Pi)
repo: `/var/www/html/ikabudsix` — branch: `feat/authority-scope` (work in the tree)
context: `.ai/authority-scope.contract.md`, `.ai/authority-scope.repair-1.md`, PR #118

**First: I retract part of repair brief 1.** It told you "the read path must keep failing closed; do
not relax it", and you implemented that faithfully. That instruction was wrong, and it is the cause of
this second failure. Failing closed means **denying**, not **crashing**. Do not treat the old brief as
authority on this point.

## Evidence — reproduced locally, matching CI exactly

CI run `34561546538` now fails 4 tests (down from 6; `authority_scope_test` and
`tenant_entry_auth_shell_standard_test` are fixed):

`capability_effect_invalidation_test`, `durable_idempotency_test`, `entity_view_render_cache_test`,
`kernel_idempotency_capability_test`

```
UNCAUGHT CapabilityAuthorizationRegistryUnavailableException:
capability authorization registry version lookup failed:
capability authorization registry database unavailable
```

I reproduced the condition locally (tenant id resolves, its database does not — locally that tenant is
`6401`; all five affected tests only call `requireTenantFixture()` and **skip on this workstation**):

```
scope resolved: yes tenant=6401
store resolvable: NO
resolver failureReason: tenant_authority_store_unavailable
hasPolicyFor:     THREW CapabilityAuthorizationRegistryUnavailableException (version lookup failed)
requiresProtocol: THREW CapabilityAuthorizationRegistryUnavailableException (version lookup failed)
activePolicyRows: THREW CapabilityAuthorizationRegistryUnavailableException (version lookup failed)
authorize:        THREW CapabilityAuthorizationRegistryUnavailableException (version lookup failed)
```

**Why this is fatal in practice.** Module helpers read policies *while the module is loading* (see the
file-scope seed/read call sites). A tenant whose database is not resolvable is a normal, expected state
— the control plane knows the tenant, the connection does not resolve. Throwing there takes the whole
request or test process down. That is not fail-closed; it is a crash. Before this slice, the ambient
`app()->db()` never threw for a read — it silently read the wrong store. The correct replacement is
**safe value + recorded reason**, not an exception.

## R1 — reads must deny, never throw, when the store is unreachable

Replace the boolean `hasDatabaseTarget()` with a reason-returning check, and use it in every public read
method:

```php
/**
 * Why tenant authorization reads cannot proceed, or null when they can.
 *
 * A resolved tenant whose store is unreachable is a DENIAL condition, not a
 * crash: module helpers read policies while loading, so throwing here takes the
 * whole request down instead of failing closed.
 */
private function authorityStoreIssue(): ?string
{
    if ($this->db instanceof PDO) {
        return null;
    }

    if (!$this->authorityScope instanceof AuthorityScope
        || !$this->authorityScopeResolver instanceof AuthorityScopeResolver) {
        return $this->scopeFailureReason;
    }

    if ($this->authorityScopeResolver->database($this->authorityScope) instanceof PDO) {
        return null;
    }

    return $this->authorityScopeResolver->failureReason() ?? 'tenant_authority_store_unavailable';
}
```

Then, in each public read method, before doing any work:

| method | must return | and record the reason |
|---|---|---|
| `authorize()` | the denial it already builds — `allowed => false` with `reason` set to the issue | yes |
| `hasPolicyFor()` | `false` | yes |
| `requiresProtocol()` | `null` | yes |
| `activePolicyRows()` | `[]` | yes |

Requirements:
- **`authorize()` must never return `allowed => true`** on this path. Its `$result` already initialises
  `allowed => false`; keep that and merge the reason, exactly as the existing no-scope branch does.
- Record the reason through the same mechanism the no-scope case already uses, so a denied read is
  diagnosable from the log. Do not invent a new logging channel.
- `db()` may keep throwing as a last-resort invariant. The point is that **no public read method lets it
  escape**.
- `seedPolicyForCurrentScope()`'s guard from repair 1 stays as it is — that one was correct.
- Keep `hasDatabaseTarget()` only if something still needs it; otherwise remove it rather than leaving
  two overlapping checks. Do not leave dead code.

**This does not widen authority.** `CapabilityBus::call()` already converts an absent scope into a
denial *before* any read, and an unreachable store now yields a denial instead of an exception. Nothing
that was denied becomes allowed.

## R2 — invert the assertion that encoded the wrong rule

`tests/authority_scope_test.php` contains an assertion (added for repair 1) that `authorize()` **throws**
when the scoped store is unavailable. That asserted the wrong semantics. Replace it with the correct
one: for a resolving tenant whose store is unreachable, every read returns its safe value and
`authorize()` returns `allowed => false` with the reason recorded.

Make this a **direct, self-contained check** — it must not depend on a tenant fixture being creatable,
because that precondition is exactly what skips locally. Build the resolver with injected closures
(the constructor already takes them) so you can pin "tenant resolves, store does not" deterministically.
If it genuinely cannot be built, print `SKIP: <reason>` — never silently pass.

Keep the ambient-fallback reflection falsifier. I verified it myself twice: restoring the old fallback
in `db()` fails 2 assertions and exits 1. It must stay that way.

## Constraints

- Do **not** modify `tests/admin_platform_api_test.php` (already rejected once).
- Do not touch `modules/daily-ledger/**`.
- Do not commit, push or branch.
- Restore `storage/modules.json` ownership/permissions after `composer test` (it is gitignored).
- MySQL 5.7 safe, no new dependencies.

## Acceptance

1. Re-run my reproduction and paste the output: with a resolving tenant and an unreachable store, **all
   four methods return safe values** (`authorize` → `allowed=false` + reason) and **none throws**.
   The probe is at `/tmp/scope_probe.php`; tell me if you want its content.
2. `composer test` locally: `0 failed`, paste the `Total:` line. State again that the tenant-dependent
   tests skip locally, so CI remains the oracle for them.
3. `authorize()` still returns `allowed => false` when the store is unreachable — show the returned
   array, not just a boolean.
4. `tests/authority_scope_test.php` passes, contains no reference to tenant 54 or the literal database
   name `akira`, and keeps the reflection falsifier.
5. Confirm nothing was weakened to achieve this: in particular that `authorize()` has no path returning
   `allowed => true` without a policy row.

## Deliverable

Result block: status, changed, implementation summary, verification, evidence, scope, risks,
unresolved, recommended next state.
