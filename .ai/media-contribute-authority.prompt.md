Your binding task contract is `.ai/media-contribute-authority.contract.md` in the Ikabud Kernel OS at
/var/www/html/ikabudsix, on branch `feat/media-contribute-authority`, created from main. Read it IN FULL
first, then follow every pointer it names. Read the files it cites before editing anything.

Why you: this slice changes AUTHORIZATION POLICY. It is security-sensitive, so the chair is delegating it to
the precision lane rather than the mechanical one.

The slice reduces to one sentence: a role's authority to CONTRIBUTE MEDIA follows the role set that already
governs DRAFTING AND PUBLISHING content. The owner's words: "admin and authors and any role that has
drafting or publishing permissions can upload images, attachments and etc".

Three things will tempt you and all three are wrong:

1. The canonical role set ALREADY EXISTS. Do not invent a fourth copy of the concept. It is
   'contributor,author,editor,admin,administrator,superadmin' — seeded on akira.post.create/update and the
   post admin reads, and DERIVED by cawPostLifecycleParticipantRoles() from the workflow transitions. Use the
   guarded-call precedent at cms-akira-shell/helpers.php:146 (function_exists) so cms-akira-media does NOT
   gain a dependency on cms-akira-workflow.

2. `akira.media.delete@1` STAYS `admin`. Widening destructive authority was not requested. A role that can
   upload should not be able to delete media referenced by published content. Leave it, and report it as a
   decision the owner may reverse. `akira.media.resolve@1` also stays untouched — it is the public render path.

3. The hidden blocker is A4. `cac_cap_akira_policy_list_1` filters to providers
   ['cms-akira-core','cms-akira-workflow'], so MEDIA ROWS ARE INVISIBLE in the Permissions surface. No
   operator can see or change them today. The decision is not operable until that filter is widened. Fix the
   cause, not the page.

THE GUARD YOU MUST NOT WEAKEN: `CapabilityAuthorizationRegistry::seedPolicy()` deliberately REFUSES a wider
`allowed_roles` declaration on an existing `granted` row (widening_refused / operator_regrant_required). Your
new defaults therefore apply to FRESH installs only. Do NOT add a migration, seeder, or CLI that widens an
existing granted row automatically — an existing tenant is widened deliberately by an operator through the
Permissions surface, audited, with a reason. Prove the refusal still holds (A5), do not merely leave it alone.

ENVIRONMENT INDEPENDENCE (learned the hard way on PR #135): the first attempt at that slice was green locally
and RED in CI because its skip guards were written against the local environment's shape and its assertions
were calibrated to the local tenant database. So: no hard-coded tenant ids, no assertions calibrated to an
existing tenant's row counts, derive every guard from the state the test actually asserts on. And run the
required proof — back up the policy table, delete every row, run `php scripts/run-tests.php`, confirm exit 0,
restore — and report what you observed.

Also run the measurement at the end of the contract (the fixed policy_version in
camSeedMediaMutationPolicies versus the active-version join in camSeedMediaReadPolicies) and REPORT it with
evidence. Fix it only if it prevents A1-A4. Do not fold an unrelated repair in without saying so.

Run every acceptance check A1-A8 and return the exact result block from the contract: status, task, changed,
implementation_summary, verification, playwright (or explain why not applicable), scope.unexpected_files,
risks, unresolved, recommended_next_state. Do not claim success without evidence. If you cannot satisfy a
check, say so plainly rather than adjusting the check.
