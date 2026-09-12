# CONTRACT — Extend read authority across the Akira admin reads

task: read-authority-extension
lane: deepseek-v4-flash, low reasoning (mechanical: mirror existing handler calls)
owner: chair retains the architectural decisions and verification
status: READY_FOR_IMPLEMENTATION

## Context

PR #129 proved read authority is a working primitive and declared **one** read route:

```json
"GET /cms-akira-shell/posts": "akira.post.admin.list@1"
```

Verified: a declared GET with no authority is refused at dispatch; an authorised operator still gets
HTTP 200 in a real browser; `--gate` passes; baseline untouched. That slice explicitly did **not**
declare the rest.

This slice declares the remaining **admin** reads in `cms-akira-shell`, mapping each to the
capability its handler **already calls**. You are not inventing authority — you are moving authority
that currently lives only inside handlers up to dispatch, where it belongs.

## The rule

For each route, open the handler and find the capability it already invokes (e.g.
`akiraShellAdminPostList()` calls `akiraShellCall('akira.post.admin.list@1', ...)`). The declaration
must name **that exact capability**. Where a handler calls more than one, declare the one that gates
the page's primary read, and say which you chose and why.

## Declare these

| Route | Find the capability from |
|---|---|
| `GET /cms-akira-shell/posts/new` | `akiraShellPostCreateForm` |
| `GET /cms-akira-shell/posts/{slug}/edit` | `akiraShellPostEditForm` |
| `GET /cms-akira-shell/categories` | `akiraShellCategoryList` |
| `GET /cms-akira-shell/content-types` | `akiraShellContentTypeList` |
| `GET /cms-akira-shell/permissions` | `akiraShellPermissions` |
| `GET /cms-akira-shell/users` | `akiraShellUsers` |
| `GET /cms-akira-shell/compositions` | `akiraShellCompositions` |
| `GET /cms-akira-shell/compositions/{key}/edit` | `akiraShellCompositionEdit` |

## Do NOT declare these — each is a trap

- **`GET /cms-akira-shell/login`** — an authentication entry point. Declaring authority on it can
  lock every operator out of the product. This is the single most dangerous line in the slice.
- **`GET /cms-akira-shell/forbidden`** — the denial surface. It must remain reachable *by design*;
  gating the page that reports denial produces a loop.
- **`GET /cms-akira-shell`** (dashboard) — declare **only if** its handler already calls a read
  capability. If it renders from mixed sources with no single gating capability, leave it undeclared
  and say so. Do not invent a capability to make it declarable.
- **`GET /cms-akira-shell/health`** — an operational probe. Leave undeclared unless its handler
  already calls a capability.
- **Public routes** — `/`, `/posts`, `/posts/{slug}` (`akiraPublic*`). These are **anonymous public
  presentation**, not business operations. Declaring authority on them breaks public browsing.
- **Any POST route** — already declared. Do not touch existing declarations.

If you conclude any route in the "declare" table should also be excluded, **exclude it and say why**.
A justified exclusion is worth more than a declaration that breaks a page you cannot see.

## Prohibited

- Editing `kernel/Workbench/Governance/GovernanceCensus.php`. The `isBusiness()` write-only
  denominator is a **known, reported** finding and an architectural decision for the chair — not a
  fix to bundle into a declaration slice. If you believe it must change, report it, do not change it.
- Editing the dispatch guard in `src/helpers/module-manager.php`.
- Editing any template or handler logic. This slice changes **declarations only**.
- Editing `.governance-baseline.json`.
- Touching `modules/daily-ledger` or `gui-settings`.
- Declaring a capability the module does not expose or depend on — the validator rejects it, and
  papers over a real gap.

## Acceptance

**B1 — Every declaration mirrors the handler.** For each declared route, quote the handler line that
calls the same capability. A declaration naming a capability the handler does not call is a defect.

**B2 — Enforcement is real.** For at least the three most sensitive routes (`/posts/{slug}/edit`,
`/permissions`, `/users`), show that the guard refuses an unauthorised actor. Extend
`tests/read_authority_probe_test.php` rather than writing a parallel test.

**B3 — No operator is locked out.** Log in to the tenant host **in a real browser** and load every
declared page. Each must render (not 403). Extend `tests/browser/read-authority.spec.ts`. This is
non-negotiable: PR #125 is the precedent — a DiSyL defect broke kernel login while every HTTP status
check passed, and only a browser caught it. A 403 here means the slice is a regression, not a fix.

**B4 — Login still works.** Prove `GET /cms-akira-shell/login` is unaffected, and that a fresh
unauthenticated visit to it renders.

**B5 — Gate and suite.** `php ikabud workbench:governance --all --gate` exits 0 with the baseline
untouched. `composer test` reports the same single pre-existing failure
(`disyl_include_root_test`) and nothing new.

**B6 — Report the ratio honestly.** The summary ratio is expected to **not move**, because
`isBusiness()` excludes reads. State the before/after numbers and confirm that outcome rather than
implying the slice improved coverage. Do not describe a non-moving number as progress.

## Verification commands

```
php -l on every touched PHP file
php tests/read_authority_probe_test.php
composer test
php ikabud workbench:governance --all --gate
export PATH=/home/kajagogoo/.local/node-v22.23.2-linux-x64/bin:$PATH
npx playwright test -c /tmp/pw-live.config.js tests/browser/read-authority.spec.ts --reporter=list
```

Tenant host credentials for the browser check are in the spec's env defaults.

## Result format

```
status:        PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:          read-authority-extension

declared:
  - route: <path>
    capability: <id>
    handler_line: <file:line proving the handler calls it>
excluded:
  - route: <path>
    reason: <why>

B1 mirrors_handler:        yes | no   (per route)
B2 enforcement_proven:     yes | no   (which routes, what evidence)
B3 no_operator_lockout:    yes | no   (browser result per page)
B4 login_unaffected:       yes | no
B5 gate_and_suite:         <numbers, and the pre-existing failure named>
B6 ratio:                  before -> after   (state plainly if unchanged)

changed:       <files>
verification:  <commands + outcomes>
scope:         <anything touched outside the allowed list>
risks:
unresolved:
recommended_next_state:
```
