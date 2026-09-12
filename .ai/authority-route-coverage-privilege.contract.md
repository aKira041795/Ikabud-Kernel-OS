# CONTRACT — Route coverage: the three privilege-affecting shell writes

status: READY_FOR_IMPLEMENTATION
repo: `/var/www/html/ikabudsix` — branch: `feat/authority-route-coverage-privilege`
chair: this session · authority: P2 route coverage, the one remaining P2 step
(see `docs/architecture/akira-beyond-the-cms.md`, revised 2026-09-12)

Read first: `docs/architecture/akira-beyond-the-cms.md` §P2, `docs/architecture/authority-store-adr.md`.

## Why these three

P2 route coverage is the last open P2 step: 84 writes are undeclared. Three of them are
privilege-affecting — with no authority check in front of them, they are escalation paths:

```
POST /cms-akira-shell/permissions        -> akiraShellPermissionUpdate
POST /cms-akira-shell/users/{id}/role    -> akiraShellUserUpdateRole
POST /cms-akira-shell/users/{id}/active  -> akiraShellUserSetActive
```

This slice does these three, not all fifteen. Small blast radius, highest security value, and it
establishes the declaration pattern the remaining 81 will follow.

## Verified facts — do not re-derive

- `modules/cms-akira/cms-akira-shell/module.json` has `capabilities.exposes: []` and
  `capabilities.routes: {}` (empty), and **no** `governance` block. Every one of its 15 write routes
  is therefore `undeclared`.
- The capabilities to authorize these routes **already exist** and are exposed by `cms-akira-core`:
  `akira.policy.set_roles@1`, `akira.user.update_role@1`, `akira.user.set_active@1`.
- All three are **already declared in shell's `capabilities.depends`** — nothing new is depended on.
- The handlers reach the bus through a helper, which is why the census could not name the path:
  - `akiraShellPermissionUpdate` (`handlers.php:891`) → `akiraShellCall('akira.policy.set_roles@1', $input)`
  - `akiraShellUserUpdateRole` (`handlers.php:935`) → `akiraShellUserMutation('akira.user.update_role@1', $params)`
  - `akiraShellUserSetActive` (`handlers.php:940`) → `akiraShellUserMutation('akira.user.set_active@1', $params)`
- `cms-akira-core` is the declaration template: its `capabilities.routes` maps
  `"POST /api/v1/cms-akira/posts": "akira.post.create@1"` and so on.
- Each of the three routes is also gated locally by `akiraShellAuthorizeAdmin()`. That is the
  handler-first pattern the thesis criticises; it stays, and the substrate check is added.

## Deliverables

### A1 — Declare the three routes

Add to `cms-akira-shell/module.json`:

```json
"capabilities": {
  "routes": {
    "POST /cms-akira-shell/permissions": "akira.policy.set_roles@1",
    "POST /cms-akira-shell/users/{id}/role": "akira.user.update_role@1",
    "POST /cms-akira-shell/users/{id}/active": "akira.user.set_active@1"
  }
}
```

Keep the existing `depends` list intact. Do not touch `cms-akira-core`'s declarations.

### A2 — Declared means fail-closed, so prove the policy allows the caller BEFORE trusting it

`declared` denies when no policy row resolves. The route path is owned by `cms-akira-shell`, so the
dispatch check will run with that module as caller. Before declaring anything:

- confirm, in **tenant 54's** database (not the base DB — see the ADR), that an active policy row
  exists for each of the three capabilities permitting caller `cms-akira-shell`;
- if a row is missing or does not permit that caller, STOP and report it. Do **not** paper over it by
  widening a policy, and do **not** add an exemption. A missing policy is a finding, not an obstacle.

Report the exact query you used and its result for each capability. Use `app()->dbForTenant(54)` or
`php ikabud` — remember `app()->db()` is the **base** DB in CLI.

### A3 — Update the frozen baseline only after live proof

After A2 passes and A4 is proven live, run `php ikabud workbench:governance --all --update-baseline`.
Shell's undeclared count must fall **15 → 12** and the routed baseline map must otherwise be
unchanged. Show the diff. If the number does not move, the declaration is not taking effect — say so
rather than adjusting the baseline to match.

### A4 — Live HTTP proof on tenant 54 (`akiracms.test`, DB `akira`)

For each of the three routes, on the real tenant:

1. as the tenant admin, the operation still **succeeds** (this is the regression that matters — a
   wrong declaration breaks a working feature by failing closed);
2. the census reports the route as `dispatch_enforced`;
3. a caller without the authority is refused **before the handler body runs**, and the denial is
   recorded. Reuse the C6 method: a role that the policy does not allow gets a refusal, and the
   underlying data is unchanged.

State any step you could not perform, rather than implying it passed.

## Constraints

- **No schema changes, no migrations.** This is a declaration plus verification.
- Do not weaken, bypass or delete `akiraShellAuthorizeAdmin()`, and do not remove the handlers'
  existing capability calls — the substrate check is *added*, not substituted.
- Do not add a `governance.exemptions` entry for any of these three routes. They are authority-bearing
  writes; exemption would be a lie.
- Do not touch `modules/cms-akira/cms-akira-core/**`, `modules/daily-ledger/**`, or the other 12
  shell routes.
- Do not commit, push or branch.
- Live tenant 54: `akiracms.test`, DB `akira`, admin `charlienacario884` / `iKabud6123!#`. Clear the
  web APCu cache from a web context after any test run, or the site will 503 for stale-scan reasons
  unrelated to this change.
- `storage/modules.json` is gitignored; if a test run removes it, restore ownership
  (`kajagogoo:www-data`, mode 666) or leave it absent — a plain `cp` back recreates it 0600 and 503s
  the site.

## Acceptance

1. The three routes are `dispatch_enforced` in `php ikabud workbench:governance --all`; shell's
   undeclared falls 15 → 12. Paste before/after.
2. A2's query and result for each capability — or an explicit STOP if a policy is missing.
3. A4's live proof for all three routes: success as admin, denial for a disallowed caller, and the
   record that the handler body did not run.
4. `.governance-baseline.json` diff showing only shell's number changing.
5. `composer test` `0 failed` with the `Total:` line, and which tests skip locally.
6. `php ikabud architecture:check` pass; PHPStan `[OK] No errors` on any changed PHP (`vendor/bin/phpstan analyse <files> --no-progress`); `composer lint` clean apart from the untracked `daily-ledger`
   files, which CI cannot see.
7. Tenant 54 `/`, `/posts`, `/login` all 200 with `error.log` empty.

## Deliverable

Result block: status, changed, implementation summary, verification, live evidence, scope, risks,
unresolved, recommended next state. If declaring a route breaks a working feature, that is the
fail-closed behaviour working correctly — report it rather than reverting to undeclared silently.
