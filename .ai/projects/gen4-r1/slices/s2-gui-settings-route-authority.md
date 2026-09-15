# SLICE — gen4-r1 S2: declare the last two undeclared write routes in Akira

project: gen4-r1 · status: READY_FOR_IMPLEMENTATION · revision: 3
repo: `/var/www/html/ikabudsix`
lane: openai-codex/gpt-5.6-sol
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "openai-codex/gpt-5.6-sol", "--name", "gen4-r1-s2", "<CONTRACT>"]

> **Revision 3 — the evidence pass.** The declaration and its policy seed are **already committed and
> verified**; the census reads 47/47. What was missing was *admissible evidence*, and two harness repairs
> have since changed what that means (CD-48):
>
> - the **governance census** is now an allowed command, and
> - a run may now **CREATE** a test file — `isExistingTestPath()` decides from the dispatch baseline, so a
>   file absent at dispatch is an *addition*, not a modification.
>
> So your job is to write **one new PURE root test** that holds the declaration's evidence, and to run it.
> Do not change `modules/` — that work is done.

> **The app-bootstrap screen is a production safety rule, and you must not dodge it.** A test that
> bootstraps the CMS app poisons the APCu module cache and 503s the live tenant, so both admissible shapes
> refuse one. A test that hand-rolls a PDO connection, or otherwise reaches the database, **specifically so
> that it does not contain the string `bootstrap.php`** is an evasion of that screen by wording — it does
> the forbidden thing while satisfying the check. That is prohibited and fails this slice. **Write a test
> that is genuinely pure**, or say plainly that the assertion cannot be made purely.

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

exceptions:
  - what:     extend the new declaration test with the census assertion
    why:      a declared census command binds as a claim but verify returns nothing_to_compare, so the census must be asserted INSIDE the test to be re-derivable; that test was created by the previous evidence pass and therefore exists at dispatch, which makes this an edit to an existing test file and absolutely prohibited without an authority route
    scope:    tests/gui_settings_route_authority_declaration_test.php
    decided_when: 2026-09-15T02:20:00+00:00
    authority: CD-48

authority: CD-41 (measure ordinary work with the apparatus frozen) + `docs/architecture/akira-beyond-the-cms.md` §P2
(route coverage). This contract **authorises the authorization-semantics change** it asks for — declaring routes
and seeding their policy — which is otherwise contract-relative L4.
directive: **you hold the decision.** Decide, record, continue (CD-8).

## Objective

Take Akira's write-route coverage from **45/47 to 47/47 dispatch-enforced** by declaring the last two undeclared
write routes, both in `gui-settings`.

## Verified facts — measured, do not re-derive

1. The census (`php ikabud workbench:governance --all --json`) reports, today:

```
gui-settings   dispatch_enforced 0  undeclared 2  write_total 2  write_ratio 0
akira rollup   dispatch_enforced 45 undeclared 2  write_total 47 write_ratio 95.7
```

   Every `cms-akira-*` module is already `write_ratio 100, undeclared 0`. **`gui-settings` is the whole of the
   remaining gap.**

2. `modules/gui-settings/module.json` exposes `gui_settings.apply@1`, and `capabilities.routes` is **`null`** —
   undeclared. Its two write routes are, from `modules/gui-settings/routes.php`:

```
POST /api/v1/admin/gui-settings        -> gui-settings:apiSaveGuiSettings
POST /api/v1/admin/gui-settings/reset  -> gui-settings:apiResetGuiSettings
```

3. Both handlers gate **exactly** `$user['role'] === 'admin'` (`modules/gui-settings/handlers.php:81` and
   `:179`). Not `administrator`, not `superadmin`.

4. **No policy row for `gui_settings.apply@1` exists anywhere** (a grep for `gui_settings` across every `*.sql`
   returns nothing, and `CapabilityAuthorizationRegistry::hasPolicyFor('gui_settings.apply@1')` is the check).

## The safety invariant — this is the whole risk

Dispatch authority is **fail-closed**: *"Declaring a route whose capability has no policy row 403s every
operator"* (measured, PR #132). So:

> **Never leave a declared route whose capability has no active policy row.** Seed the policy **first**, verify
> it, and only then declare. If you cannot verify the policy row, **remove the declaration** and report — the
> fail-safe direction is today's behaviour (no dispatch check), never a 403 for operators who work today.

**The role set must EQUAL what the handler already admits.** The rule from PR #132, which is what made read
governance safe: not wider (that grants access nobody has today), not narrower (that 403s people who have it).
The handlers admit `admin` and only `admin`, so `allowed_roles` is **`admin`**. Widening it to
`admin,administrator,superadmin` would be an **authority expansion** and fails this slice; narrowing it below
`admin` would break the page.

## Deliverables

1. **Seed the policy.** Add a seeding function to `modules/gui-settings/helpers.php` following the existing
   in-repo pattern exactly — `cms-akira-core/helpers.php:35-60` is the template (a `$rows[]` builder then
   `CapabilityAuthorizationRegistry::seedPolicyForCurrentScope($rows)`, invoked at file scope on include).
   Fields to mirror: `policy_version 1`, `capability_id 'gui_settings.apply@1'`, `capability_version '1'`,
   `provider 'gui-settings'`, `caller_module` (see below), `allowed_roles 'admin'`,
   `provider_activation_required true`, `requires_protocol 'v2'`, `is_active true`.
2. **Declare the two routes** in `modules/gui-settings/module.json` under `capabilities.routes` — the format is
   a **map** keyed `"<METHOD> <path>" => "<capability>"`, **not** a list of objects:

```json
"routes": {
    "POST /api/v1/admin/gui-settings": "gui_settings.apply@1",
    "POST /api/v1/admin/gui-settings/reset": "gui_settings.apply@1"
}
```

3. **A focused test** under `modules/gui-settings/tests/` proving the declaration resolves and is enforced, with
   a **negative control** — the pattern of `tests/read_authority_probe_test.php` (which proved the guard proceeds
   for an *undeclared* route and refuses a *declared* one for a null actor, differing only by the declaration).

**`caller_module` is a judgement you must make and justify.** Note the trap: an **empty** `caller_module` means
**ANY caller is permitted**, not none. The routes are the module's own, so match the convention its own routes
imply — and say which you chose and why.

## Architectural constraints

- **Do not widen or narrow the admitted role set.** `admin` only, because that is what the handlers admit.
- Do not touch the census, its baseline, or `.governance-baseline.json`. The measurement is the acceptance, not
  something to adjust.
- Do not change the handlers' existing `role === 'admin'` gates in this slice.
- Do not declare the GET route `/admin/gui-settings` — it is a read, and reads are a separate campaign (Akira
  reads are 16/57; declaring them is not this slice).
- Pure tests only. **Do not run `scripts/run-tests.php`, `composer test`, or anything that bootstraps the CMS
  app** — a full run poisons the APCu module-cache and 503s the live tenant. Run only the test you write.
- Do not commit, stage or push. Write logs to `/tmp`, never into the repository.

## Files likely affected

- `tests/gui_settings_route_authority_declaration_test.php` (new, PURE)

Nothing else. `modules/gui-settings/` is already changed, committed and verified — do not touch it.

## Acceptance criteria

Every criterion below must be shown by a command you declare, and **only the admissible shapes** count:
`php tests/<name>.php` (ROOT tests/), `php -l <file>`, `php tools/ai-contract-lint.php`, and
`php ikabud workbench:governance --all --json`. Anything else binds **no claim at all**.

1. **The declaration is real and load-bearing.** Your new pure test asserts, from the manifest itself, that
   `modules/gui-settings/module.json` maps **both** write routes to `gui_settings.apply@1` and does **not**
   declare the GET route. It must be capable of **failing** if a declaration were removed — show its shape, do
   not edit the module to prove it.
2. **The policy source admits exactly `admin` and nothing wider.** Assert that the module's seed pins
   `allowed_roles` to exactly `admin` — the set both handlers already admit (`handlers.php`:
   `$user['role'] === 'admin'`). Not wider (that would grant access nobody has today), not narrower (that
   would 403 a working operator), and that `caller_module` names the route dispatcher.
3. **The census closes, and the assertion lives INSIDE the test.** `gui-settings` must report `undeclared 0`
   and `write_ratio 100`, and the **akira rollup** `undeclared 0` and `write_ratio 100`.

   **Assert this from inside `tests/gui_settings_route_authority_declaration_test.php`, by running the census
   in a subprocess and parsing its JSON.** Do not offer the census as a standalone claim: measured on the
   previous attempt, a declared `php ikabud workbench:governance --all --json` binds as a claim but `verify`
   returns **`nothing_to_compare`** — the verifier has no observation to compare a census against, so a declared
   census can never re-derive and would block the slice. Asserting it in the test makes it re-derivable, because
   the test's own `passed`/`failed` counts are what the verifier compares.
4. **State the residual gap explicitly, in your reply.** The live policy ROW in a tenant database is **not**
   proven by this run, because both admissible shapes refuse an app-bootstrapping test and that screen must not
   be dodged. It was verified separately (tenant 54: `hasPolicyFor` true, `allowed_roles=admin`,
   `grant_state=granted`) and is recorded as **Chair** evidence, not as a claim of yours. Do not claim it.
5. Say plainly whether this edit **weakens** any existing test or gate. If it does, say so and stop.

## Architectural constraints on verification

- **Verify by declared commands**, and only the four admissible shapes above.
- **Write a genuinely pure test.** No app bootstrap, no database, no PDO, no filesystem mutation. If an
  assertion cannot be made purely, say so rather than contorting the test to slip past the screen.
- If the work appears **already applied**, that is expected: `modules/` is committed. This run's product is the
  **evidence**, not the change.

## Required tests

```
$ php tests/gui_settings_route_authority_declaration_test.php
$ php -l tests/gui_settings_route_authority_declaration_test.php
```

Your new test must exit 0 with zero skips and zero failures. **Do not edit** any existing test file, and do not
modify `modules/gui-settings/tests/gui_settings_route_authority_test.php` (it bootstraps the app and is
therefore refused by the screen — that is correct behaviour, not a problem to solve).

## Report format — required (CORRECTED 2026-09-15, CD-49)

**The `CLAIM:` / `COMMAND:` / `OBSERVED:` template previously printed here is INERT and is withdrawn.** The
extractor has no handling of those markers: `parseCommandLine()` strips only a leading `>` or `$`, so a line
labelled `COMMAND: php tests/x.php` classifies as nothing and **binds no claim**. The previous revision of this
slice produced a report whose 8 claims all bound with `command_source: null`, and `verify` returned
`no_command_declared` for every one — the work was complete and correct and the evidence was unreadable.

Write a **shell transcript**. A `$`-prefixed command line binds the claim; the output lines beneath it merge
into that claim:

```
$ php tests/gui_settings_route_authority_declaration_test.php
passed: 5
failed: 0
skipped: 0
exit code: 0

$ php -l tests/gui_settings_route_authority_declaration_test.php
No syntax errors detected in tests/gui_settings_route_authority_declaration_test.php
```

The census is asserted **inside the test**, not offered as a separate claim — see acceptance criterion 3.

Rules that are mechanical:
- **The command must be its own line, prefixed with `$`** (or `>`, or bare). Never inside a sentence, and never
  behind a label.
- **Declare only the keys your command's actual output prints** — copy them from what you ran.
- No headings, no numbered lists, no narrative sections: `parseProseClaim()` binds report prose as a claim by
  design, so a numbered list or a bold lead-in becomes a claim of its own and blocks the slice.
- Anything you want to explain goes in your reply to me, not in the report file.

## Risks

- **A declared route with no policy row 403s every operator.** This is the one failure that matters; the safety
  invariant above is how it is prevented. Seed, verify, then declare.
- **`caller_module` semantics invert.** Empty means ANY caller, not none.
- **This tree is served live.** The local tenant runs from this working tree, so the manifest change takes effect
  immediately for that tenant. If any acceptance step cannot be completed, revert to the pre-slice state rather
  than leaving a half-declared module.

## Forbidden changes

- `modules/gui-settings/handlers.php` — the existing `role === 'admin'` gates stay exactly as they are.
- `modules/gui-settings/routes.php` — the routes already exist; this slice declares them, it does not create them.
- `tools/` — the harness, the ledger and the census are **frozen** under CD-41; the measurement is not adjusted.
- `kernel/` — no engine, registry or guard change; the primitives are sufficient (verified in PR #132).
- `.governance-baseline.json` — never edited to make a gate pass.
- `scripts/` — never touched, and never run.
