# Decision + repair 2 — media fixture prerequisite (CI unmasked a dead test)

status: CHANGES_REQUIRED
evidence: PR #136 @ 32d70a5, run 34692593550

## What changed in the picture

On `main` (#135 CI, job 103538660542) this test did not run at all:

```
[SKIP] modules/cms-akira/cms-akira-media/tests/media_contract_test.php (85ms)
       — tenant 994201 does not activate required CLI module cms-akira-media
```

It depended on a **provisioned** tenant 994201 that CI does not have, so it had **zero CI coverage**.
Replacing that fixed tenant with dynamically allocated ones — the right instinct, following the #135
lesson — removed that skip condition. The test now runs in CI and fails. So this is **not** a regression
of a passing test; it is a dead test being exposed. That is the honest outcome and worth keeping.

## What is fixed already (verified)

- `static-analysis` now **passes** — the PHPStan dead branches are gone.
- The fixture-activation assertion is a genuine strengthening and should stay.
- The fragment-cache guard is **legitimate** and has been restored by the chair: without a writable
  root the test dies with `Unable to create fragment cache directory: …/disyl-fragments/<id>`.
  Do not remove it again. A skip is not evidence of correctness, but the guard itself was correct.

## What still fails (all three test jobs)

```
✗ Phase 5A media scenario completes — CapabilityNotFoundException:
  No permitted capability providers for: akira.media.upload@1
CMS Akira media: 15 passed, 1 failed
```

**Activation was not the missing prerequisite.** `tenantSetModuleActivationState()` makes
`moduleIsActive('cms-akira-media', $tenant)` true — your new assertion proves that — yet the bus still
refuses. That message comes from `kernel/Capabilities/CapabilityBus.php:204`:

```php
$providers = $this->applyPolicy($capabilityId, $providers, (string)($caller['module'] ?? ''));
if (empty($providers)) { $this->logDenied($capabilityId, $caller, 'caller_policy');
    throw new CapabilityNotFoundException("No permitted capability providers for: {$capabilityId}"); }
```

So `$providers` was non-empty and **every provider was removed by `applyPolicy()`**. The test calls with
`'caller' => ['module' => 'cms-akira-media', …]`. Establish what removes it:
read `applyPolicy()` fully (~lines 394–545) — both the kernel-only provider-selection path
(`allow_providers`/`deny_providers`) and the per-provider caller path (`allow_callers`/`deny_callers`,
read from `$provider['meta']['policy']`). The media manifest declares no
`capabilities.policy` (`grep policy modules/cms-akira/cms-akira-media/module.json` is empty), and the
test's own stub meta has no `policy` key — so find out which registration *does* carry one, and why it
differs from your local run. **Instrument it if needed**; do not guess.

Note `$registry->has($id)` guards your stub registration: if the real module registered the capability
first, your stub is never used. That is a likely fork in behaviour between environments.

## Rule for resolving it

**Prefer making it run.** A prerequisite your code can satisfy is better than a skip.

If — and only if — you establish that CI genuinely cannot provide the prerequisite, then make the test
emit a **precise `SKIP: <reason>`** in the established repo style, like its siblings:

```
[SKIP] navigation_contract_test.php — tenant 994101 does not activate required CLI module cms-akira-navigation
[SKIP] post_mutation_test.php     — DiSyL fragment cache for tenant 0 cannot be created by the current process
```

That is acceptable because on `main` this test skipped in CI too — parity is preserved and the feature is
unaffected.

**But the skip must be a prerequisite check, NOT a catch.** It must ask "is a tenant available for which
the bus permits this capability?" — never "did the call fail, so let us skip". If you attempt
provisioning and the bus still refuses, that is a finding: report it, do not wrap it in a skip.

## Scope

- `modules/cms-akira/cms-akira-media/tests/media_contract_test.php`
- `modules/cms-akira/cms-akira-media/helpers.php` (only if the root cause is genuinely there)

If the cause is in `kernel/Capabilities/CapabilityBus.php` or `src/helpers/module-manager.php`, **STOP**
and return `ARCHITECTURE_DECISION_REQUIRED` with the evidence. Those are kernel files and are out of
scope for this slice — a kernel change here would need its own contract.

## Required evidence

1. The mechanism, evidenced: which registration carries the policy that removes the provider, and why
   your local run differs from CI. Quote the code or log line.
2. `CapabilityBus::logDenied` writes `reason: caller_policy` to `storage/logs/app.log` — capture that
   entry (it names the capability and caller) rather than describing it.
3. Outcome: either the test passes when it runs, or the precise prerequisite SKIP — with the reason
   naming the specific unavailable prerequisite.
4. Local, with a writable fragment root (hold the www-data directory aside as `.disyl-fragments-hold`,
   then `mkdir` your own; restore afterwards and say so): report whether the test now runs fully.
5. `php -l`; `composer test` and `php scripts/run-tests.php` — report passed / failed / skipped and say
   explicitly which of the new media assertions RAN. Never report a skip as a pass.

Do not touch `kernel/`, `phpstan-baseline.neon`, `phpstan.neon`, or `.governance-baseline.json`.
Do not widen `akira.media.delete@1`. Do not weaken `seedPolicy()`'s widening refusal.
