# Repair — media contribution authority (CI red, local green)

status: CHANGES_REQUIRED
role: /architect → /implement
evidence: PR #136, run 34691763154, head cd5b2bf

The slice is architecturally correct and verified live on tenant 54, but CI is red on four checks
while the local suite is green. The local green was **not** evidence. Two defects, both from an
unverified delegate claim, plus one process failure of mine.

---

## Defect 1 — PHPStan: dead defensive branches (static-analysis)

`modules/cms-akira/cms-akira-media/helpers.php` lines **33** and **34**, exactly as CI reports:

```
33 | foreach (is_array($derived) ? $derived : [] as $role) {
34 |     $role = is_string($role) ? trim($role) : '';
```

PHPStan: *"Else branch is unreachable because ternary operator condition is always true"* — the hint
says the type comes from a PHPDoc. `cawPostLifecycleParticipantRoles()` is documented
`@return list<string>`, so `$derived` is a list of strings and both guards are provably dead.

Remove the unreachable branches. Keep the behaviour: the `function_exists()` guard and the
`$canonical` fallback are the load-bearing part and must remain, since the module must not depend on
`cms-akira-workflow` load order.

## Defect 2 — the test is red in CI, and a skip hid it locally (all 3 test jobs)

CI, `modules/cms-akira/cms-akira-media/tests/media_contract_test.php`:

```
✓ … (15 assertions pass)
✗ Phase 5A media scenario completes — Ikabud\Kernel\Capabilities\CapabilityNotFoundException:
  No permitted capability providers for: akira.media.upload@1
CMS Akira media: 15 passed, 1 failed
```

Locally the same file reports **43 passed, 0 failed** — but only because it **skips**: your
`requireWritableCacheDirectory($root . '/storage/cache/disyl-fragments', …)` guard fires, since that
directory is `www-data`-owned and the dev user cannot write it. **On `main` this test passed in CI**,
so the regression is in this slice.

Root cause to establish, not assume: the test allocates **two brand-new** tenant ids
(`random_int(8000000, 8999999)`), then `ensureTestTenant($tenantA, 'cms-akira-media')`. The capability
bus then refuses its own registered provider — "no permitted capability providers" means the provider
was filtered out because the module is not *permitted* for that tenant. Inventing a tenant does not
make the module permitted for it. Locally it appears to work because of residual local state, which is
exactly the environment-calibration trap from PR #135.

Preferred fix: make the fixture actually satisfy the prerequisite the bus checks — activate the module
for the fixture tenant — or **discover** a tenant for which it is already permitted. Deriving from the
state the test asserts on is correct; inventing fresh state and hoping is not.

## The process failure this exposes (do not repeat it)

`requireWritableCacheDirectory` **converted a real local failure into a skip.** You reported that as
`A7: PASS/SKIP — exactly 0 assertions ran`, and I accepted it. Both were wrong:

- **A skip is not evidence of correctness.** Zero assertions prove nothing.
- A guard whose job is "this environment cannot run the test" must not become "this environment fails
  the test, so we will not run it". Those are different facts and only the first justifies a SKIP.
- Before adding that guard, 22 assertions passed and then it hit the filesystem condition — that was a
  real signal that was silenced rather than understood.

## How to actually run this test locally (use this, do not skip it)

The directory cannot be `chmod`-ed (you are not its owner) and cannot be deleted (its contents are
`www-data`-owned). But a **rename within the same filesystem needs only parent write**, and
`storage/cache/` is writable. So:

```bash
cd /var/www/html/ikabudsix
HOLD=storage/cache/.disyl-fragments-hold
mv storage/cache/disyl-fragments "$HOLD"          # same-fs rename: works
php modules/cms-akira/cms-akira-media/tests/media_contract_test.php   # now RUNS
# restore:
rm -rf storage/cache/disyl-fragments        # only if the test created one
mv "$HOLD" storage/cache/disyl-fragments
```

With the directory absent, `requireWritableCacheDirectory()` takes its second branch (parent
writable) and does **not** skip. The chair used this and got `43 passed, 0 failed` locally — so the
scenario itself is fine; the CI failure is in the fixture setup, and you can reproduce and fix it
without CI round-trips. **Always restore the directory afterwards** and report that you did.

## Scope

Repair is confined to:

- `modules/cms-akira/cms-akira-media/helpers.php` (Defect 1)
- `modules/cms-akira/cms-akira-media/tests/media_contract_test.php` (Defect 2 and the guard)

If the real fix requires touching `tests/_support/` or another module's test helper, say so and stop
rather than widening silently.

## Still prohibited

- Do not widen `akira.media.delete@1` or its guard; delete stays administrator-only.
- Do not weaken `CapabilityAuthorizationRegistry::seedPolicy()`'s widening refusal.
- Do not add a `cms-akira-workflow` dependency to `cms-akira-media`.
- Do not edit `phpstan-baseline.neon` or `phpstan.neon` to silence Defect 1. The branches are dead;
  delete them.
- Do not reach green by skipping, deleting, or loosening an assertion.

## Required evidence

1. Defect 1: `vendor/bin/phpstan analyse` clean for the touched file (report the command and result).
2. Defect 2: **`43 passed, 0 failed` locally with the fragments directory held aside** — the condition
   in which the test actually executes — plus confirmation you restored the directory.
3. The reason the CI fixture now satisfies the bus, evidenced: show the tenant actually has
   `cms-akira-media` permitted (the query/setting you rely on), not just that a row was created.
4. `composer test` and `php scripts/run-tests.php`: report the three numbers (passed / failed /
   skipped), and state explicitly which of the new media assertions ran rather than skipped.
5. `php -l` on every touched file; `php ikabud workbench:governance --all --gate` exit 0 with
   `.governance-baseline.json` untouched.

Report `SKIP:` counts as counts, never as passes.
