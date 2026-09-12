Your BLOCKED return was correct — thank you for stopping instead of widening your own scope. The chair
has verified every claim you made and AUTHORIZED a bounded scope expansion.

Read `.ai/media-contribute-authority.decision.md` IN FULL. It replaces the `allowed` file list in the
contract; everything else in the contract still binds you. Also re-read A5/A6/A7/A8.

The principle the decision rests on: the PRESENTATION GATE and the POLICY ROW MUST AGREE. Today they
disagree in both directions, which is why the Permissions surface cannot be trusted.

The owner's rule — "any role that has drafting or publishing permissions" — is ALREADY IMPLEMENTED in
this codebase as `akiraShellParticipant()` (cms-akira-shell/helpers.php:140-148), deriving from
`cawPostLifecycleParticipantRoles()`. Media is the only surface not using it. So this is REUSE, not a new
abstraction. Do not invent a fourth role helper in the shell.

Authorized, and nothing beyond it:

1. handlers.php — exactly three guard lines: `akiraShellMediaList` (:866) and `akiraShellMediaUpload`
   (:897) move to `akiraShellAuthorize()`; `akiraShellMediaDelete` (:929) STAYS
   `akiraShellAuthorizeAdmin()`. Do not touch the guards on Compositions, Module Health, Permissions,
   Users or their mutations — those are genuinely administrator surfaces. Do not weaken
   `akiraShellAuthorizeAdmin()` itself.

2. helpers.php — `akiraShellMediaTable()` must render the delete form only for an administrator.
   Follow the EXISTING precedent in `akiraShellContentTypeRow(array $row, bool $manager)`, which renders
   actions when `$manager` and a `Read only` label otherwise. Same shape; no new pattern. Update the call
   site in `akiraShellMediaPage()` (~handlers.php:890).

3. tests/shell_contract_test.php — line 237 must be REPLACED, NOT DELETED. It currently asserts
   `substr_count($handlers, 'akiraShellAuthorizeAdmin()') >= 3`, a whole-file occurrence count over a
   1055-line file that was never scoped to media. Replace it with an assertion scoped to the media
   handlers: list and upload use the participant gate, delete uses the administrator gate. This
   STRENGTHENS the test. It must not become satisfiable by removing the guard, and must not become
   another global count. Update any media nav-role assertion still encoding the old admin-only value.

DELETE STAYS ADMINISTRATOR-ONLY, in the policy row, the guard, and `camMediaActor()`. The owner asked
only for upload authority. A role that can upload should not be able to delete media referenced by
published content. Report it as a decision the owner may reverse — do not widen it.

Re-run A3, A6, A7, A8. On A6: your earlier failure was caused by the stale line-237 assertion, not by the
policy work — re-run the full empty-policy proof and report the observed numbers. On A7:
`storage/cache/disyl-fragments` is genuinely not writable locally; keep the precise SKIP guard, do NOT
chmod around it, and report exactly how many assertions ran before the skip.

Do not fold in unrelated repairs. If you find another blocker outside this scope, STOP and return
ARCHITECTURE_DECISION_REQUIRED again — that was the right call the first time.

Return the exact result block. Do not claim success without evidence.
