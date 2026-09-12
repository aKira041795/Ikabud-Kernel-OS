# CONTRACT — Media admin surface (Akira product surface #1)

task: akira-media-surface
lane: openai-codex/gpt-5.6-sol, reasoning medium (touches authorization + file upload)
owner: chair retains design, review and gate
status: READY_FOR_IMPLEMENTATION

## Why this slice

`cms-akira-media` is a **complete capability provider with no user surface**. It exposes six
capabilities, implements all of them (`cms_akira_media_capability_handlers()`), ships a full helper
API (mime allowlist, content sniffing, tenant-scoped storage, projection), and already seeds its
mutation policies. What it does not have is any way for an operator to **use** it.

Measured 2026-09-12:

- 6 capabilities exposed: `library`, `get`, `resolve`, `upload`, `update`, `delete`.
- **0 declared routes.** It has two working routes (`/api/v1/cms-akira-media/health`,
  `/api/v1/cms-akira-media/stream/{media_key}`) but neither is declared, so both are ungoverned.
- **No admin UI.** `cms-akira-shell` does not depend on a single media capability.
- Write policies **are** seeded: `akira.media.{upload,update,delete}@1` → `allowed_roles: admin`.
- Read policies are **not** seeded: `library`, `get`, `resolve` have **no rows**.

This is the first Track B (product) slice and the product owner named it explicitly.

## Scope

### Step 1 — seed the read policies

Seed `akira.media.library@1` and `akira.media.get@1` with `allowed_roles: admin`, matching the
existing media write policies **exactly**. Follow the established mechanism
(`camSeedMediaMutationPolicies()` in `modules/cms-akira/cms-akira-media/helpers.php` shows the shape
and the call).

**`admin` only.** Do not add `administrator` or `superadmin`.

### Step 2 — declare the shell dependency

Add the media capabilities you call to `cms-akira-shell`'s `capabilities.depends`. Without this the
calls do not resolve.

### Step 3 — the admin surface

Add to `cms-akira-shell`, following the existing post-admin patterns exactly (`akiraShellPage()`,
`akiraShellCsrfField()`, `akiraShellNotice()`, entity-view data attributes):

| Route | Handler | Capability |
|---|---|---|
| `GET /cms-akira-shell/media` | media library list | `akira.media.library@1` |
| `POST /cms-akira-shell/media` | upload | `akira.media.upload@1` |
| `POST /cms-akira-shell/media/{media_key}/delete` | delete | `akira.media.delete@1` |

Add a "Media" nav entry beside the existing links.

Declare authority for all three in `cms-akira-shell/module.json`:
`GET /cms-akira-shell/media` → `akira.media.library@1`, and the two POSTs → their capabilities.

**Gate every route with `akiraShellAuthorizeAdmin()`** — the same gate `/users` and `/permissions`
use. Media write capabilities are `admin`-only, so an `administrator` actor will be refused at the
capability. That is a **known pre-existing inconsistency** (see "Report, do not fix") and fails
closed. Do not paper over it by widening the policy.

### Step 4 — the smallest real UI

- list: filename, thumbnail or mime icon, dimensions if known, alt text, a delete action
- upload: a file input plus optional alt text, `multipart/form-data`, CSRF token
- delete: POST with CSRF, confirm via the existing pattern if one exists

**No image editor. No cropping. No folders. No bulk actions. No gallery picker. No drag-and-drop.**
The owner's own words: *"no image editor, no media-library parity arms race."*

## Prohibited

- **Do not widen any policy.** Media writes are `admin`; read policies you seed are `admin`. If you
  believe `administrator`/`superadmin` should be admitted, **report it and stop** — that is a chair
  decision, not an implementation choice.
- **Do not declare `GET /api/v1/cms-akira-media/stream/{media_key}`.** It serves images to rendered
  pages; gating it can break every image in the product. Leave it undeclared and say so.
- Do not declare `/login`, `/forbidden`, or the public routes.
- Do not edit the dispatch guard, `GovernanceCensus.php`, `.governance-baseline.json`, or any
  existing handler's authorization logic.
- Do not touch `modules/daily-ledger` or `gui-settings`.
- Do not accept an upload without the module's own validation — use `akira.media.upload@1` and let it
  enforce mime allowlist, sniffing and size limits. Do not write files directly.
- Do not commit, push, or branch.

## Acceptance

**E1 — Policies seeded.** Show the rows for `akira.media.library@1` and `akira.media.get@1` with
their `allowed_roles` and `is_active`, in the **active** policy version. State which tenant.

**E2 — Routes declared and enforced.** Show the three declarations. Demonstrate that
`GET /cms-akira-shell/media` is refused at dispatch for a caller with no authority, and proceeds for
one with it.

**E3 — It works end to end in a real browser.** Log in as the tenant admin and: load
`/cms-akira-shell/media`, upload a real file, see it listed, delete it, and see it gone. Extend
`tests/browser/` with a spec for this. Screenshots are not evidence; assertions are.

**E4 — Upload validation actually rejects.** Prove a disallowed type is refused by the capability
(not merely hidden by the UI). The module has a mime allowlist and sniffs content — make it do its
job. Report what you tried and what happened.

**E5 — No operator is locked out.** Every previously-working admin page still renders for the tenant
admin: `/cms-akira-shell`, `/posts`, `/categories`, `/content-types`, `/permissions`, `/users`. And a
fresh anonymous visit to `/cms-akira-shell/login` still reaches the login form.

**E6 — Suite and gate.** `composer test` is **fully green** — it is right now (109 files, 100 passed,
0 failed, 9 skipped), so any failure is one you introduced. `php ikabud workbench:governance --all
--gate` exits 0 with the baseline untouched.

**E7 — Logs.** `storage/logs/error.log` is clean of fatals after your run. The probe test asserts
this, and the chair tripped over it twice today: **your own diagnostics will pollute it.** Clear the
logs and re-run before concluding a failure is real.

## Report, do not fix

Record, with evidence, and leave alone:

1. **Media write policies are `allowed_roles: admin` only** — excluding `administrator` and
   `superadmin` — while every other Akira write policy uses `admin,administrator,superadmin`.
   A kernel `superadmin` therefore cannot upload media today. State whether your reading confirms it.
2. The two existing media routes remain undeclared.

## Verification commands

```
php -l on every touched PHP file
php tests/read_authority_probe_test.php
composer test
php ikabud workbench:governance --all --gate
export PATH=/home/kajagogoo/.local/node-v22.23.2-linux-x64/bin:$PATH
npx playwright test -c /tmp/pw-live.config.js tests/browser/<your spec>.ts --reporter=list
```

Tenant admin: `charlienacario884` / `iKabud6123!#` at `http://akiracms.test`. An `author` account
(`akiraauthor`) exists for negative tests. Passwords default into the existing specs.

## Result format

```
status:        PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:          akira-media-surface

E1 policies:   <capability, allowed_roles, is_active, version, tenant>
E2 declared:   <the three declarations + enforcement evidence>
E3 browser:    <list / upload / delete, each with its assertion result>
E4 validation: <what you uploaded, what was refused, by which layer>
E5 no_lockout: <each admin page's status; login result>
E6 suite:      <numbers; gate exit; baseline state>
E7 logs:       <error.log state>

findings:
  - media_write_roles: <confirmed / refuted / unclear, with evidence>
  - undeclared_media_routes: <state>
  - anything else you found and did not fix

changed:       <files>
verification:  <commands + outcomes>
scope:         <anything outside the allowed set>
risks:
unresolved:
recommended_next_state:
```
