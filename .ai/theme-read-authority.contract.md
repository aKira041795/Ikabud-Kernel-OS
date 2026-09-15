# SLICE — Akira module work, start to finish: give `cms-akira-theme` read authority

project: akira-authority · status: READY_FOR_IMPLEMENTATION · revision: 1
repo: `/var/www/html/ikabudsix`
lane: deepseek/deepseek-v4-flash
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "deepseek/deepseek-v4-flash", "--name", "theme-read-authority", "<CONTRACT>"]

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

authority: `docs/architecture/akira-beyond-the-cms.md` §P2 (route coverage) and the standing contract's
Evidence-admissibility rules. This contract **authorises** the authority change it asks for — new read
capability, seeded policy, declared routes — which is otherwise contract-relative L4.

## Objective

A **complete module change**, not a manifest line.

`cms-akira-theme` has **six** GET routes with **no authority declared and no capability reachable from the
handler**, measured by the census. Give them real authority: implement the read capability, seed its policy,
declare the routes, and prove it.

Exact routes, from `php ikabud workbench:governance --all --json` (module `cms-akira-theme`):

```
GET /api/v1/cms-akira-theme/health              -> cms-akira-theme:catThemeHealth
GET /api/v1/cms-akira-theme/resolve             -> cms-akira-theme:catThemeResolveJson
GET /api/v1/cms-akira-theme/themes              -> cms-akira-theme:catThemeRegistryJson
GET /api/v1/cms-akira-theme/blocks              -> cms-akira-theme:catThemeBlocksJson
GET /api/v1/cms-akira-theme/themes/{slug}/validate -> cms-akira-theme:catThemeValidateJson
GET /cms-akira-theme                            -> cms-akira-theme:catThemeAdminPage
```

The census states the reason for each: *"no authority declared and no exemption declared; no executable
capability bus call reachable from the handler"*. So a declaration alone is **not** the work — there is nothing
to declare against.

## What to build

1. **A read capability** exposed by `cms-akira-theme` (e.g. `akira.theme.read@1`), with a handler that returns
   the theme registry/blocks data the JSON routes already serve. Follow the in-repo pattern: `capabilities.
   exposes` in `module.json`, handler functions in `helpers.php`, and a `*_capability_handlers()` map — copy
   the shape from `cms-akira-core` (the established template) and from this module's existing
   `akira.theme.activate@1` / `akira.theme.customize@1`.
2. **A seeded policy** for it, activation-time and idempotent, following
   `cacSeedPostMutationPolicies()` in `cms-akira-core/helpers.php` — including the detail that matters: join
   the **currently active** policy version rather than pinning version 1 (the permissions UI clones the active
   set into a new version, so a version-1 seed lands inactive).
3. **Declarations** in `cms-akira-theme/module.json` under `capabilities.routes` — a **map** keyed
   `"<METHOD> <path>" => "<capability>"`, **not** a list of objects — for the JSON read routes.
4. **The admin page** (`GET /cms-akira-theme`) is the one judgement call. `akiraShellAuthorizeAdmin()`-style
   admin pages are presentation, and the repo already leaves some routes undeclared **with a reasoned
   `exemptions` entry** rather than forcing a capability. Either declare it against the read capability if the
   handler admits the same role set, or add a reasoned exemption. **Say which you chose and why** — do not
   leave it silently undeclared.

## The safety invariant — the one thing that matters

**A declared route whose capability has no active policy row 403s every operator.** Dispatch authority is
fail-closed; that was measured (PR #132). So: **seed the policy first, verify it, then declare.** And the role
set must **EQUAL** what each handler already admits — not wider (granting access nobody has today), not
narrower (403ing someone who has it today). The change is *when* the decision is made, not *who* gets in.

If you cannot verify the policy, **remove the declaration** and report — the fail-safe direction is today's
behaviour, never a 403.

## Architectural constraints

**Scope corrected 2026-09-15.** The first revision omitted `handlers.php`; the executor edited it, correctly —
that is where this module's request handlers live and a capability handler belongs beside them. The manifest
change is required and authorised by this objective: declaring read authority for six routes needs a capability
to declare against, and the census states none was reachable from those handlers.

- **Do not touch the census, its baseline, or `.governance-baseline.json`.** The measurement is the acceptance.
- **Do not weaken any existing gate, test or authority.** Adding authority is the point; removing it is a
  failure.
- **Keep it pure where it must be.** See the evidence rules below.
- Do not commit, stage or push. Logs to `/tmp`, never into the repository.

## Files likely affected

- `modules/cms-akira/cms-akira-theme/module.json`
- `modules/cms-akira/cms-akira-theme/helpers.php`
- `modules/cms-akira/cms-akira-theme/handlers.php`
- `modules/cms-akira/cms-akira-theme/tests/`

## Acceptance criteria

Only these evidence shapes bind.

`php tests/<name>.php` (ROOT tests/), `php modules/<mod>/tests/<name>.php` (must not reach the application
database unguarded), `php -l <file>`, and `php ikabud workbench:governance --all --json`. Anything else — `php
-r`, `grep`, `git`, chained forms — binds **no claim at all**.

1. **The module's read routes now report as governed.** `cms-akira-theme` shows the six GET routes no longer
   `undeclared` for dispatch, and the census's `write_ratio` for the account is unchanged.
2. **The capability exists and is reachable**, asserted from the manifest and the handler map.
3. **The policy seed pins the role set the handlers admit** — assert the seed's `allowed_roles` exactly, and
   that it joins the active policy version.
4. **The census is asserted INSIDE a test**, not offered as a standalone claim: a declared census command binds
   but `verify` returns `nothing_to_compare`, so it can never re-derive (measured). Run it by subprocess and
   assert the numbers.
5. **No route was left silently undeclared.** Every one of the six is either declared or carries a reasoned
   exemption, and you name which for each.
6. Say plainly whether this change **weakens** any existing test or gate.

## Required tests

```
$ php modules/cms-akira/cms-akira-theme/tests/<your new test>.php
$ php -l modules/cms-akira/cms-akira-theme/helpers.php
$ php -l modules/cms-akira/cms-akira-theme/module.json   # (use php -l only on .php files)
```

Write your test **genuinely pure**: no `bootstrap.php`, no `MODE_INTEGRATION`, and **no unguarded database
access**. The module screen refuses a test that reaches `app()->db()` without calling
`requireNotLiveTenantDatabase()` — because in CLI `app()->db()` falls back to the configured database, which on
this checkout **is the live tenant**, and module tests have destroyed live tenant posts before. Reading the
manifest and the source text is pure and is what this slice needs. Do **not** hand-roll a PDO connection to dodge
the screen; that is evasion by wording and fails the slice.

Also run, and report, the two suites that cover this area:

```
$ php tests/module_route_authority_test.php
$ php tests/workbench_governance_census_test.php
```

## Report format — required

Write a **shell transcript** to the path the harness sets via `--report`, and nothing else: a `$`-prefixed
command line, its real output beneath, then the next command.

**Do not write `CLAIM:` / `COMMAND:` / `OBSERVED:`.** Those labels are **inert** — the extractor has no marker
handling (`parseCommandLine()` strips only `>` and `$`), so a labelled report binds claims with no command and
`verify` returns `no_command_declared` for every one. Measured; recorded as CD-49.

Declare only keys the command's output actually prints. Prose belongs in your reply, not the report.

## Risks

- **A declared route with no policy row 403s every operator** — the invariant above is how that is prevented.
- **Widening the role set** is an authority expansion and fails this slice.
- **`caller_module` inverts meaning**: an empty value means ANY caller is permitted, not none.
- **The theme module's handlers may already be reachable in ways the census could not see** — the census says
  *"no executable capability bus call reachable"*, so check the handler bodies before assuming.

## Forbidden changes

- `tools/` — the harness is not this slice's concern.
- `tests/` — do not edit an existing root test; add your own under the module.
- `kernel/` — no engine change.
- `phpstan-baseline.neon` — never edited to make a gate pass.
- `.github/workflows/` — no CI definition change.
- `modules/daily-ledger/` — untracked, out of scope, separate campaign.
