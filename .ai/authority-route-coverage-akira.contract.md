# CONTRACT — Route coverage: close Akira (25 remaining writes → 0)

status: READY_FOR_IMPLEMENTATION
repo: `/var/www/html/ikabudsix` — branch: `feat/authority-route-coverage-akira`
chair: this session · authority: P2 route coverage, the last open P2 step
(`docs/architecture/akira-beyond-the-cms.md`, revised 2026-09-12)

Read first: `docs/architecture/akira-beyond-the-cms.md` §P2,
`.ai/authority-route-coverage-privilege.contract.md` (the slice that proved this method on 3 routes).

## Objective

Declare authority for the remaining **25 undeclared write routes** across `cms-akira-*` and
`gui-settings`, taking Akira from **5/33 to 33/33** dispatch-enforced. `daily-ledger` is explicitly
out of scope — it is a separate campaign, not a slice.

Current state (verified 2026-09-12):

| module | undeclared writes remaining |
|---|---|
| `cms-akira-shell` | 12 (3 already declared) |
| `cms-akira-builder` | 6 |
| `cms-akira-theme` | 4 |
| `cms-akira-workflow` | 1 |
| `gui-settings` | 2 |

## Verified facts — do not re-derive

- **Every one of these modules already exposes the capability its route needs, and none of them
  declares any route.** `capabilities.routes` is `{}` in all five; no module has a `governance` block;
  **no `governance.exemptions` entry exists anywhere in the repository.**
- `cms-akira-builder` is a verified clean 1:1: each of its six write handlers calls exactly one
  capability, and the module exposes it — `akiraBuilderApiValidate → akira.builder.validate@1`,
  `…ApiCreate → akira.builder.create@1`, `…ApiUpdate → akira.builder.update@1`,
  `…ApiPublish → akira.builder.publish@1`, `…ApiUnpublish → akira.builder.unpublish@1`,
  `…ApiDelete → akira.builder.delete@1`.
- `cms-akira-theme` exposes `akira.theme.activate@1` / `akira.theme.customize@1`;
  `cms-akira-workflow` exposes `akira.workflow.transition@1`; `gui-settings` exposes
  `gui_settings.apply@1`. Match them to the right routes yourself and show the evidence.
- Shell's handlers reach the bus through helpers (`akiraShellCall`, `akiraShellUserMutation`) — the
  indirection is why the census could not name the path. The three already declared are
  `/permissions → akira.policy.set_roles@1`, `/users/{id}/role → akira.user.update_role@1`,
  `/users/{id}/active → akira.user.set_active@1`.
- **Empty `caller_module` means ANY caller is permitted**, not none:
  `if ($allowedCallers !== [] && !in_array($callerModule, $allowedCallers, true))` at
  `kernel/Capabilities/CapabilityAuthorizationRegistry.php:138-140`. The builder's six policy rows
  have empty `caller_module`; use this fact, and still state each row's actual value.

## Deliverables

### A1 — Establish the capability each route already uses, with evidence

For every remaining undeclared write route, read the handler and record the capability id it already
calls, as `route → handler @ file:line → capability`. This is the mapping you will declare; do not
invent capabilities that the handler does not call.

**If a handler calls no capability at all, STOP on that route and report it.** Such a route cannot be
declared (there is nothing to declare against) and must not be exempted — whether it needs a new
capability or a declared exemption is a product decision, not an implementation one.

### A2 — Pre-flight every route against the tenant DB before declaring (declared fails closed)

A wrong declaration breaks a working feature. For each route, in **tenant 54's own database** (not the
base DB — see the authority-store ADR), confirm and record:

1. an **active** policy row exists for the capability (`is_active = 1`, `grant_state = 'granted'`);
2. its `caller_module` is either empty (any caller) **or** names the owning module;
3. its `allowed_roles` **covers the role set of the handler's local gate**.

Point 3 is the one that silently narrows access. Shell's local gate `akiraShellAdmin()` accepts
`['admin','administrator','superadmin']`. If a shell capability's policy allows only `admin`, then
declaring that route **denies `administrator` and `superadmin` users who work today**. Report any such
route instead of declaring it. Do not widen the policy to make it fit, and do not add an exemption.

State plainly which routes passed all three checks and which did not.

### A3 — Declare, per module, keeping everything else intact

Add `capabilities.routes` entries to each module's `module.json`. Do not rename, reorder or remove any
existing key; do not touch `exposes`, `depends`, `migrations`, `routes`, `nav` or `_enabled`. Do not
change any handler or helper in any module.

### A4 — Live verification on tenant 54

- The census must report **zero undeclared writes for Akira** and `dispatch_enforced` equal to the
  module's write-route total.
- Prove enforcement actually runs, using the method already validated: `route.authority.allowed` is
  logged **only** for `state === 'declared'`, so its presence proves the declaration is live at runtime
  rather than merely present in the manifest. Show at least one allowed and one denied event from
  `app.log`, with the corresponding route.
- Denial without a second credential: send only `PHPSESSID` (drop the JWT cookie) with a valid
  `_token` → CSRF passes, the actor is absent, and the guard denies before the handler body runs.
- Live-test the consequential routes specifically: builder `publish`, `unpublish`, `delete`, and theme
  `activate`. A success on each as the tenant admin is the regression that matters.

### A5 — Move the baseline only after live proof

`php ikabud workbench:governance --all --update-baseline`. Akira's undeclared counts must go to **0**
for all five modules. Show the diff. If a count does not move, that declaration is not taking effect —
say so rather than adjusting the baseline to match.

## Constraints

- **No schema changes, no migrations, no new capabilities, no handler or helper edits.**
- **No `governance.exemptions` entry.** Every one of these routes has a real capability to declare
  against; an exemption would be a lie.
- Do not touch `modules/daily-ledger/**`, `cms-akira-core`'s existing declarations, or any
  `allowed_roles` value.
- Do not commit, push or branch.
- Operational: `app()->db()` is the **base** DB in CLI — use `app()->dbForTenant(54)`. Tenant 54 is
  `akiracms.test`, DB `akira`, admin `charlienacario884` / `iKabud6123!#`. For live HTTP use
  `curl --resolve akiracms.test:80:127.0.0.1` (against plain `127.0.0.1` the JWT cookie is silently
  dropped); log in via `POST /api/v1/auth/login` (JSON, no CSRF) and read `_token` from a hidden input
  on a shell admin page. A curl cookie jar prefixes HttpOnly rows with `#HttpOnly_` — do not
  `grep -v '^#'` when extracting a session id.
- After any test run the web APCu cache may be stale and the tenant may 503 for reasons unrelated to
  this change; clear it from a web context. `storage/modules.json` is gitignored and a test run
  unlinks it; if you restore it, restore ownership `kajagogoo:www-data` mode 666 or the site 503s.
- **Do not clear `storage/logs/app.log` before you have reported your evidence** — the previous run
  did, which destroyed its own proof and forced the chair to re-derive it.

## Acceptance

1. A1's full mapping table: route → handler @ file:line → capability, for all 25 routes.
2. A2's per-route pre-flight result in tenant 54: policy exists / caller permitted / roles cover the
   local gate. Any route that failed a check, reported not declared.
3. Akira `undeclared = 0` across all five modules in `workbench:governance --all`, with
   `dispatch_enforced` = write-route total per module. Paste before/after.
4. Live proof: at least one `route.authority.allowed` and one `route.authority.denied` from `app.log`,
   plus admin success on builder `publish`/`unpublish`/`delete` and theme `activate`.
5. `.governance-baseline.json` diff, with only the five Akira modules' numbers changing.
6. `composer test` `0 failed` with the `Total:` line, and which tests skip locally.
7. `php ikabud architecture:check` pass; PHPStan `[OK] No errors` on changed files; `composer lint`
   clean apart from the untracked `daily-ledger` files CI cannot see.
8. Tenant 54 `/`, `/posts`, `/login` all 200 with `error.log` empty.

## Deliverable

Result block: status, changed, mapping, verification, live evidence, scope, risks, unresolved,
recommended next state. If declaring a route would narrow access for a working user, that is a finding
to report — not a problem to engineer around.
