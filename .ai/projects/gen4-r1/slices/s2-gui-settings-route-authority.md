# SLICE — gen4-r1 S2: declare the last two undeclared write routes in Akira

project: gen4-r1 · status: READY_FOR_IMPLEMENTATION · revision: 2
repo: `/var/www/html/ikabudsix`
lane: deepseek/deepseek-v4-flash
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "deepseek/deepseek-v4-flash", "--name", "gen4-r1-s2", "<CONTRACT>"]

> **Revision 2 — the work is already applied; this run VERIFIES it.** Attempt 1 did the work correctly and was
> then blocked by the scope gate on two **provably false** premises (recorded as CD-46, deliberately not
> repaired because CD-41 freezes the apparatus): the absolute *authorisation-weakening* prohibition matched the
> test **filename** because the word "authority" contains "auth" (`tools/ai-autonomy.php:945`, an unanchored
> `#|auth|#` alternation), and the absolute *existing-test* prohibition matched because `isExistingTestPath()`
> evaluates `file_exists()` **after** the run, so a file the run itself created is judged pre-existing.
>
> **Therefore: do NOT create, rename, or modify any test file in this run.** Not because the test is
> unwelcome — a good regression test already exists at
> `modules/gui-settings/tests/gui_settings_route_authority_test.php` and you are to **run** it, not write it.
> Creating another one cannot pass the gate, whatever it contains, and will cost the run.

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

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

- `modules/gui-settings/module.json`
- `modules/gui-settings/helpers.php`
- `modules/gui-settings/tests/`

## Acceptance criteria

Every criterion below must be shown by a command you declare.

1. **The policy exists, before the declaration is relied on.**
   `php -r '...CapabilityAuthorizationRegistry::hasPolicyFor("gui_settings.apply@1")...'` prints `true`, and the
   active row's `allowed_roles` is exactly `admin`.
2. **The census closes.** `php ikabud workbench:governance --all --json` shows `gui-settings` with
   `undeclared 0` and `write_ratio 100`, and the **akira rollup** with `undeclared 0` and `write_ratio 100`.
3. **The declaration is enforced, not merely present** — by running the existing test, which carries the
   negative control: an identical actor refused on the declared route and proceeding on the undeclared GET
   route, so the declaration alone decides the outcome.
4. **No authority was widened.** State the role set the handlers admit and the role set the policy grants;
   they are identical.
5. Say plainly whether this edit **weakens** any existing test or gate. If it does, say so and stop — that
   judgement is the Chair's.

## Architectural constraints on verification

- Verify by **declared commands**. Do not create or modify any file to produce evidence — the two prohibitions
  in the revision note make a new test file impossible, and that restriction is a measured property of the
  frozen apparatus (CD-46), not a preference of this contract.
- If the work appears **already applied**, that is expected and correct. Your job is to establish that it is
  right, not to change it. A run with `delta=0` that re-derives every claim is a **success**, not a no-op.

## Required tests

```
php modules/gui-settings/tests/gui_settings_route_authority_test.php
```

**Run it; do not edit it and do not write another.** It must exit 0 with zero skips and 14 checks passing. If a
prerequisite is genuinely unavailable, report `SKIP: <reason>` rather than claiming a pass. The file already
exists and is committed — modifying it would re-trip the two prohibitions described in the revision note.

## Report format — required

The report is **evidence**: every statement in it must be backable by a command. This is not stylistic —
`parseProseClaim()` (`tools/ai-run.php:1407`) binds report **prose** as a claim by design, so a numbered list, a
bold lead-in, or a plain sentence describing what you did becomes a claim of its own and binds `UNVERIFIED`,
blocking the slice **even when the work is complete and correct**. Three runs of S1 were blocked exactly that way.

### The report, literally

Copy this shape — nothing before the first `CLAIM:`, nothing after the last `OBSERVED:` block, no headings, no
lists:

```
CLAIM: <short assertion>
COMMAND: <exact command, copy-pasteable>
OBSERVED: <verbatim output, including the exit code>
```

One block per acceptance criterion. Two mechanical rules:

- **Write your claims once, at the top. Never repeat them in an `## Outcome`, `### Evidence`, or summary
  section.** That duplicate section is what bound as a phantom claim and blocked S1.
- **Declare only the keys the command's own stdout prints.** Copy the keys from the output you paste, not from
  what you know to be true. Declaring a true fact the command does not print (`failed:0` when the suite emits no
  such key) cannot re-derive and binds `UNVERIFIED`.

Write the report to **the run's canonical report path** — the harness sets it via `--report`. **Do not invent a
report path:** a slice-authored path is outside the slice's own approved scope and trips the scope matcher.

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
