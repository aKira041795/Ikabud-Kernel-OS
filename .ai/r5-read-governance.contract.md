# CONTRACT — R5: seed read policies, then declare the reads they unlock

task: r5-read-governance
lane: openai-codex/gpt-5.6-sol, reasoning medium (security-sensitive: seeds authorization policy)
owner: chair retains the design, the review, and the gate
status: READY_FOR_IMPLEMENTATION

## Why this slice exists

PR #130 declared one read and **refused seven**. The reason was measured, not guessed: the dispatch
guard's `authorize()` is **fail-closed**, and in tenant 54 only two read capabilities have policy
rows. Declaring a route whose capability has no policy row 403s **every** operator.

So read governance in Akira is blocked on policy data, not on declarations. This slice supplies that
data — carefully, because it writes authorization policy.

The repo already states the boundary: *"Reads (list/get) remain ungoverned until R5 governs reads"*
(`cms-akira-core/helpers.php`).

## The safety criterion — the whole slice in one line

> **A seeded read policy's role set must equal the role set the route's handler already admits.**

Not wider (that grants access nobody had). Not narrower (that 403s people who have it today).

The change is *when* the decision is made — at dispatch instead of inside the handler — not *who*
gets in. If any role's outcome changes, the slice is wrong.

## Measured facts you must use

**The two gates** (`modules/cms-akira/cms-akira-shell/handlers.php`):

| Gate | Admits | Source |
|---|---|---|
| `akiraShellAuthorize()` | any editorial participant | `cawPostLifecycleParticipantRoles()` |
| `akiraShellAuthorizeAdmin()` | `admin`, `administrator`, `superadmin` | `akiraShellAdmin()` |

**The participant role set, as already recorded in tenant 54** for `akira.post.admin.list@1` and
`akira.post.admin.get@1`:

```
contributor,author,editor,admin,administrator,superadmin
```

Use that exact string for any-participant pages. Do not invent a set — these two rows are the
precedent, and they were confirmed to work end-to-end in PRs #129 and #130.

**Per-page gate map (measured — do not re-derive, but do verify):**

| Handler | Gate | Roles the read policy must carry |
|---|---|---|
| `akiraShellDashboard` | `akiraShellAuthorize()` | participant set |
| `akiraShellPostCreateForm` | `akiraShellAuthorize()` | participant set |
| `akiraShellPostEditForm` | `akiraShellAuthorize()` | participant set |
| `akiraShellCategoryList` | `akiraShellAuthorize()` | participant set |
| `akiraShellContentTypeList` | `akiraShellAuthorize()` | participant set |
| `akiraShellPermissions` | `akiraShellAuthorizeAdmin()` | `admin,administrator,superadmin` |
| `akiraShellUsers` | `akiraShellAuthorizeAdmin()` | `admin,administrator,superadmin` |
| `akiraShellCompositions` | `akiraShellAuthorizeAdmin()` | `admin,administrator,superadmin` |
| `akiraShellCompositionEdit` | `akiraShellAuthorizeAdmin()` | `admin,administrator,superadmin` |

**Existing policy row shape** (`capability_authorization_policies`):
`capability_id`, `capability_version`, `provider`, `caller_module` (CSV), `allowed_roles` (CSV),
`provider_activation_required`, `requires_protocol`, `is_active`, `grant_state`.

Match the existing rows' shape for the capabilities you seed — including `provider`
(`cms-akira-core`) and a `caller_module` list that contains `cms-akira-shell`, or the row will not
apply. A row that is present but does not match the call is indistinguishable from no row.

**Mechanism:** modules seed policy rows from PHP via `seedPolicy()`. Use the established path —
find it, follow it, and say which you used. If `capabilities.policy` in `module.json` can express
roles, prefer the declarative form and show why it is equivalent.

## Required work

**Step 1 — Derive the mapping, from source.** For each route in the gate map, read the handler and
find the **read** capability it actually calls. Several handlers call more than one capability, and
some call writes while rendering. Report a table:

```
route | handler | handler's gate | READ capability it calls | handler file:line proving it
```

This table is the deliverable. If a handler calls no capability (renders from something else), say
so, list it as **not declarable**, and leave it alone.

**Step 2 — Seed policies** for exactly those read capabilities, with `allowed_roles` equal to the
handler's gate role set. Nothing else. Do not seed a capability you did not find a caller for.

**Step 3 — Declare the reads** in `cms-akira-shell/module.json`, one per row you seeded, in the
existing map shape:
```json
"GET /cms-akira-shell/<path>": "<read capability>@1"
```
Declare a route **only** if its capability now has a policy row whose role set matches the gate.
Otherwise leave it undeclared and say why.

## Prohibited

- **Do not declare `GET /cms-akira-shell/login`.** Declaring authority on the auth entry point can
  lock every operator out of the product. This has already been refused once; it stays refused.
- **Do not declare `GET /cms-akira-shell/forbidden`.** It is the denial surface; gating it loops.
- **Do not declare the public presentation routes** (`/`, `/posts`, `/posts/{slug}`). They are
  anonymous by design.
- **Do not widen any existing policy.** Do not edit write policies, `allowed_roles` of existing
  rows, or `grant_state`. `seedPolicy()` may narrow but must never widen — if you believe a widening
  is required, **report it and stop**; that is a chair decision.
- Do not edit the dispatch guard, `GovernanceCensus.php` (the write-only `isBusiness()` denominator
  is a separately-reported chair decision), any template, or any handler logic.
- Do not edit `.governance-baseline.json`.
- Do not touch `modules/daily-ledger` or `gui-settings`.
- Do not commit, push, or branch.

## Acceptance

**D1 — The mapping table is derived from source**, with a `file:line` per row proving the handler
calls that read capability. A capability named without a call site is a defect.

**D2 — Role sets match the gate exactly.** For each seeded capability, show its `allowed_roles` and
the gate role set side by side. They must be identical.

**D3 — No role's outcome changes.** This is the core claim. For each declared route, demonstrate
over **real HTTP** that:
- a role **inside** the gate set is still admitted (not 403) — and the page renders;
- a role **outside** it is denied (403) — which it already was, from the handler.

Use two real accounts with different roles if the environment allows. If it does not, state clearly
which half you could not test and why. **A 403 for a role that previously had access is a
regression, not a fix.**

**D4 — Browser proof.** Log in at the tenant host and load every declared page in Chromium. Every one
must render. Extend `tests/browser/read-authority.spec.ts`. This is not optional: PR #125 is the
precedent — a DiSyL defect broke kernel login while every HTTP status check passed.

**D5 — Login and public browsing unaffected.** A fresh anonymous visit to `/cms-akira-shell/login`
still reaches the login form, and the public routes still render anonymously.

**D6 — Gate and suite.** `php ikabud workbench:governance --all --gate` exits 0 with
`.governance-baseline.json` untouched. `composer test` is **fully green** (it is now: 109 files, 100
passed, 0 failed, 9 skipped — anything less is a regression you introduced).

**D7 — Ratio honesty.** The summary ratio will not move, because `isBusiness()` excludes reads. State
the before/after numbers and confirm that outcome rather than implying the slice improved coverage.

**D8 — Tenant scoping.** Policies are per-tenant. State which tenant(s) you seeded and what a fresh
tenant would get. If the answer is "nothing until seeding runs", say so — that is a real operational
finding, not a footnote.

## Verification commands

```
php -l on every touched PHP file
php tests/module_route_authority_test.php
php tests/read_authority_probe_test.php
composer test
php ikabud workbench:governance --all --gate
export PATH=/home/kajagogoo/.local/node-v22.23.2-linux-x64/bin:$PATH
npx playwright test -c /tmp/pw-live.config.js tests/browser/read-authority.spec.ts --reporter=list
```

Note on logs: the probe test asserts `error.log` is clean. Your own diagnostics can pollute it — the
chair hit this exact trap earlier today with a bad `WHERE domain` query. Clear the logs and re-run
before concluding a failure is real.

## Result format

```
status:        PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:          r5-read-governance

mapping:                       # D1 — the primary deliverable
  - route: /cms-akira-shell/...
    handler: <name>
    gate: <akiraShellAuthorize() | akiraShellAuthorizeAdmin()>
    read_capability: <id@1>
    proof: <file:line>

seeded:                        # D2
  - capability: <id@1>
    allowed_roles: <exact string>
    gate_roles: <exact string>
    identical: yes | no

not_declarable:
  - route: <path>
    reason: <no read capability the handler calls>

D3 no_outcome_change:      yes | no   <per-role evidence, or which half was untestable>
D4 browser:                yes | no   <result per declared page>
D5 login_and_public:       yes | no
D6 gate_and_suite:         <numbers; baseline state>
D7 ratio:                  before -> after  (state plainly if unchanged)
D8 tenant_scoping:         <what was seeded where; what a fresh tenant gets>

mechanism_used:            seedPolicy() | capabilities.policy  (and why equivalent)
changed:                   <files>
verification:              <commands + outcomes>
scope:                     <anything outside the allowed set>
risks:
unresolved:
recommended_next_state:
```
