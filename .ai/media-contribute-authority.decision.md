# Architecture decision — media contribution authority (scope expansion)

status: SCOPE_EXPANDED
authority: /architect (chair), 2026-09-12
supersedes: the `allowed` file list in `.ai/media-contribute-authority.contract.md`

## Why the delegate stopped

Sol returned `BLOCKED` / `ARCHITECTURE_DECISION_REQUIRED` on A3. It was correct to stop rather
than widen its own scope. Verified independently:

1. **A second, independent authority gate.** `akiraShellMediaList` (handlers.php:866),
   `akiraShellMediaUpload` (:897) and `akiraShellMediaDelete` (:929) each call
   `akiraShellAuthorizeAdmin()`. Widening the policy row and the nav entry cannot make an author
   upload, because the handler refuses first.

2. **A third gate.** `camMediaActor()` inside the media provider required `role === 'admin'`
   for every mutation. (Sol already handled this correctly.)

3. **A UX defect.** `akiraShellMediaTable()` renders the delete form unconditionally, so the
   moment an author can list media they see a Delete button that 403s.

4. **A stale assertion.** `shell_contract_test.php:237` asserts the *old* nav roles, which is the
   single suite failure sol reported (108/109).

## The principle this decides

**The presentation gate and the policy row must agree.** Today they disagree in both directions:

- `akiraShellAuthorizeAdmin()` admits `admin, administrator, superadmin`; the policy row admitted
  only `admin` — so `administrator`/`superadmin` passed the page and were then refused by the
  capability with a 403. A page that admits a role the capability refuses is a lying surface.
- The Permissions page cannot show media rows at all, so an operator could not fix the mismatch
  even if they noticed it.

The owner's rule — *"any role that has drafting or publishing permissions"* — is **already
implemented** in this codebase as `akiraShellParticipant()` (helpers.php:140-148), which derives
from `cawPostLifecycleParticipantRoles()`. Media is the only surface not using it. So the fix is
reuse, not invention.

## Authorized scope (replaces the contract's `allowed` list)

### 1. `modules/cms-akira/cms-akira-shell/handlers.php`

| handler | line | from | to |
|---|---|---|---|
| `akiraShellMediaList` | 866 | `akiraShellAuthorizeAdmin()` | `akiraShellAuthorize()` |
| `akiraShellMediaUpload` | 897 | `akiraShellAuthorizeAdmin()` | `akiraShellAuthorize()` |
| `akiraShellMediaDelete` | 929 | `akiraShellAuthorizeAdmin()` | **unchanged** |

Only these three guards. Do **not** touch the guards on Compositions, Module Health, Permissions,
Users, or their mutation handlers — those are genuinely administrator surfaces.
Do **not** weaken `akiraShellAuthorizeAdmin()` itself.

### 2. `modules/cms-akira/cms-akira-shell/helpers.php`

`akiraShellMediaTable()` must render the delete form only for an administrator, following the
existing precedent in `akiraShellContentTypeRow(array $row, bool $manager)` — which renders
actions when `$manager` and a `Read only` label otherwise. Use the same shape; do not invent a
new pattern. Update the call site in `akiraShellMediaPage()` (handlers.php:890).

### 3. `modules/cms-akira/cms-akira-shell/tests/shell_contract_test.php`

Line 237 must be **replaced, not deleted**. The current assertion is
`substr_count($handlers, 'akiraShellAuthorizeAdmin()') >= 3` — a whole-file occurrence count over
a 1055-line file that is not scoped to media at all and never was. Replace it with an assertion
scoped to the media handlers: media list and upload use the participant gate
(`akiraShellAuthorize()`), media delete uses the administrator gate
(`akiraShellAuthorizeAdmin()`).

This **strengthens** the test. It must not become satisfiable by deleting the guard, and it must
not be loosened into another global count. Also update any media nav-role assertion that encodes
the old `admin`-only value.

## Still prohibited

- Widening `akira.media.delete@1` (policy) or its guard. Delete stays administrator-only.
- Widening `akira.media.resolve@1` — the public render path stays ungoverned.
- Any migration/seeder/CLI that silently widens an existing `granted` row.
- Weakening `seedPolicy()`'s widening refusal.
- Adding a `cms-akira-workflow` dependency to `cms-akira-media`.
- `chmod` around a `storage/` permission failure.

## Re-run after the expansion

- **A3** — author reaches `/cms-akira-shell/media`.
- **A6** — the empty-policy proof (backup / delete all rows / run the real suite / exit 0 / restore).
  Re-run it; its earlier failure was caused by item 4 above, not by the policy work.
- **A7** — `storage/cache/disyl-fragments` is not writable locally, so the media contract test
  partially runs and then skips. That is an environment fact: keep the precise
  `SKIP: <reason>` guard and do **not** chmod. Report exactly how many assertions ran.
- **A8** — `php -l`, `composer test`, `scripts/run-tests.php`, `workbench:governance --all --gate`.

## Open owner decisions (report, do not fix)

1. `administrator` and `superadmin` still cannot delete media, while a junior `admin` can. That
   inconsistency is pre-existing and outside the owner's instruction.
2. Whether `delete` should follow the contribution set after all.
