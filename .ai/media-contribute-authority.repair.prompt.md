PR #136 is red in CI on four checks while your local run was green. Read
`.ai/media-contribute-authority.repair.md` IN FULL — it carries the exact CI evidence and the
reproduction technique. Stay on branch `feat/media-contribute-authority`; your work is committed as
cd5b2bf, so commit the repair on top.

The architecture of the slice is correct and I verified it live on tenant 54 (author GET /media 200,
upload 303, file listed, delete still 403, author sees "Read only" with no delete form, admin keeps the
delete form). Two defects, plus a process failure that matters more than either.

DEFECT 1 — PHPStan, static-analysis check. `modules/cms-akira/cms-akira-media/helpers.php` lines 33 and
34: `is_array($derived) ? $derived : []` and `is_string($role) ? trim($role) : ''`. PHPStan says the
else branch is unreachable because the type comes from a PHPDoc — `cawPostLifecycleParticipantRoles()`
is `@return list<string>`, so both guards are provably dead. Delete the dead branches. Keep the
`function_exists()` guard and the canonical fallback; those are load-bearing. Do NOT touch
phpstan-baseline.neon or phpstan.neon.

DEFECT 2 — all three test jobs. CI runs `media_contract_test.php` and it fails after 15 assertions:
`CapabilityNotFoundException: No permitted capability providers for: akira.media.upload@1`. Locally it
reports 43 passed because it SKIPS on `requireWritableCacheDirectory(...disyl-fragments...)`. On main
this test passed in CI, so this slice regressed it. The test allocates two brand-new tenant ids and
calls `ensureTestTenant($tenantA, 'cms-akira-media')`; the bus then refuses the test's own registered
provider, which means the module is not PERMITTED for that tenant. Inventing a tenant does not make a
module permitted for it. Fix the fixture so the prerequisite actually holds — activate the module for
the fixture tenant, or discover a tenant that already has it — and evidence the permission itself, not
merely that a tenant row was created.

THE PROCESS FAILURE, which I want you to take seriously because I made it too: your
`requireWritableCacheDirectory` guard CONVERTED A REAL LOCAL FAILURE INTO A SKIP. You reported that as
"A7: PASS/SKIP — exactly 0 assertions ran" and I accepted it. We were both wrong. A skip is not
evidence of correctness. A guard that means "this environment cannot run the test" must never become
"this environment fails the test, so we will not run it". Before you added it, 22 assertions passed and
then hit that filesystem condition — that was a real signal, silenced instead of understood.

So you must reproduce before you fix. The directory cannot be chmod-ed (you are not its owner) and
cannot be deleted (its contents are www-data-owned), BUT a rename within the same filesystem needs only
parent write, and storage/cache/ is writable. Hold it aside as `.disyl-fragments-hold`, run the test —
it will then execute instead of skipping — and restore it afterwards. The chair did exactly this and got
43 passed, 0 failed locally, which is why I know the scenario is sound and the failure is in the fixture
setup. Report that you restored the directory.

Then fix, and show `43 passed, 0 failed` locally UNDER THAT SAME CONDITION — with the test actually
running, not skipping.

Scope is two files: `modules/cms-akira/cms-akira-media/helpers.php` and
`modules/cms-akira/cms-akira-media/tests/media_contract_test.php`. If the real fix needs
`tests/_support/` or another module's helper, STOP and say so rather than widening silently.

Still prohibited: widening `akira.media.delete@1` or its guard; weakening seedPolicy()'s widening
refusal; adding a cms-akira-workflow dependency to the media module; reaching green by skipping,
deleting or loosening an assertion.

Report the three numbers (passed / failed / skipped) for both runners and state explicitly which of the
new media assertions RAN rather than skipped. Never report a skip as a pass. Return the exact result
block.
