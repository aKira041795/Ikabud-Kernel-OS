# Contract — Bundle content identity: classify by content, not by a transported hash

task:
  id: task-bundle-payload-identity
  objective: Make `cacBundlePlan()` decide an entry's identity from the `payload` it carries,
    falling back to the transported `hash` only when no payload is present.

## Objective

`cacBundlePlan()` classifies a bundle against the tenant's current entries by comparing the bundle
entry's **`hash` field** against the current entry's hash. A bundle entry also carries the `payload` it
was hashed from, and the planner never reads it. Identity is therefore decided by a caller-supplied
string that is never verified against the content travelling beside it.

`bundle.php` states the intended contract in its own comment: *"Both bundle entries and tenant entries
hash through this exact function so a no-op replay classifies as a skip."* A transported hash that is
absent, stale, or computed over a foreign projection breaks that promise. Measured red baseline, 3 of 14
assertions:

```
[FAIL] a payload that matches the tenant SKIPS even when no hash was transported — got update
[FAIL] a STALE transported hash cannot override a matching payload — got update
[FAIL] and the classification is stable across calls
```

This is not cosmetic. `cac_cap_akira_bundle_apply_1()` detects a replayed apply with
`$plan['add'] === [] && $plan['update'] === [] && $plan['skip'] !== []`. Phantom updates make that guard
**unreachable**, so a no-op replay claims an idempotency key and rewrites every post instead of writing
nothing — in a recovery path whose entire selling point is that applying the same bundle twice is inert.

**The fix:** when an entry carries a `payload`, its identity is the hash of that payload, recomputed
here. The transported `hash` is a convenience for entries that carry no payload, and remains their
identity. That is the smallest change that makes the documented promise true.

## Files likely affected

- `modules/cms-akira/cms-akira-core/helpers/bundle.php`

## Architectural constraints

- `cacBundlePlan()` stays **pure**: no transaction, no write, no I/O, inputs unchanged.
- Its **signature and return shape are unchanged** (`add`/`update`/`skip`/`remove`); callers must not
  need edits. `cac_cap_akira_bundle_diff_1` and `cac_cap_akira_bundle_apply_1` are not touched.
- **The projection must not change.** `cacBundlePostPayload()`'s `{title, subtitle, content, image}` is
  a deliberate, documented change-detection projection. An entry differing only outside it is a `skip`
  **by design**; the probe asserts this, so hashing whole rows fails.
- An entry carrying **no payload** keeps its current behaviour exactly: the transported `hash` is its
  identity (matching → skip, differing → update). Do not invent a third state for it.
- `add`, `remove`, the additive refusal, and deterministic input ordering are unchanged.
- PHP 8.3-compatible. No new dependency, no schema change, no new global state, no public API change.
- A small named helper (e.g. `cacBundleEntryHash(array $entry): ?string`) is welcome for readability;
  the probe asserts behaviour only, so the implementation shape is yours.

## Acceptance criteria

1. The chair-authored probe passes in full, including its guards against over-correction:
   `php modules/cms-akira/cms-akira-core/tests/bundle_payload_identity_test.php` → exit 0, `0 failed`.
2. The existing P6 contract is not regressed:
   `php modules/cms-akira/cms-akira-core/tests/bundle_recovery_contract_test.php` → exit 0.
3. No syntax error: `php -l modules/cms-akira/cms-akira-core/helpers/bundle.php` → exit 0.
4. No harness regression: `php tools/chair.php --self-test` → exit 0, `0 failed`.
5. Static analysis clean on the touched file, **with the config**:
   `vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G modules/cms-akira/cms-akira-core/helpers/bundle.php` → exit 0.
6. The planner reads the payload it was already given — a bundle entry that carries a `payload` and **no**
   `hash` at all must classify against the tenant's content:
   `grep -c "payload" modules/cms-akira/cms-akira-core/helpers/bundle.php` → ≥ 3.

## Required tests

```
php modules/cms-akira/cms-akira-core/tests/bundle_payload_identity_test.php
php modules/cms-akira/cms-akira-core/tests/bundle_recovery_contract_test.php
php -l modules/cms-akira/cms-akira-core/helpers/bundle.php
php tools/chair.php --self-test
vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G modules/cms-akira/cms-akira-core/helpers/bundle.php
```

## Risks

- **Over-correction into "always skip".** If identity is rebuilt from content but genuine change is
  mishandled, the recovery path silently stops updating posts — worse than the defect. The probe asserts a
  differing payload is an `update`, including when the transported hash is nonsense.
- **Widening the projection** to "fix" phantom updates would reclassify posts whose non-projectional
  fields differ, turning skips into writes and changing what the tool considers a change. Prohibited.
- **Trusting a transported payload blindly.** A payload is also transported input; the planner must treat
  it as data to hash, never as a claim about the tenant.
- The live end-to-end evidence (a bundle exported from a tenant and diffed back against it must be
  **all skip, zero update**) is chair-owned and gathered after this change, because it needs an
  authenticated tenant session. It is not a lane requirement.

## Forbidden changes

- `modules/cms-akira/cms-akira-core/tests/bundle_payload_identity_test.php` (chair-owned acceptance)
- `modules/cms-akira/cms-akira-core/tests/bundle_recovery_contract_test.php` (existing acceptance)
- `kernel/`
- `src/`
- `migrations/`
- `storage/`
- `tools/`
- `tests/`
- `phpstan-baseline.neon`
- Do not weaken, skip, delete or disable any test or gate to obtain a pass.
- Do not change the additive refusal, nor allow removals: this path never deletes.
