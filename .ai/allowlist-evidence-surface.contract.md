# SLICE — gen4-r1: admit the two evidence shapes that ordinary work needs

project: gen4-r1 · status: DIRECTOR_AUTHORISED (gen4-r1-d1) · revision: 1
repo: `/var/www/html/ikabudsix`
lane: deepseek/deepseek-v4-flash
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "deepseek/deepseek-v4-flash", "--name", "allowlist-evidence-surface", "<CONTRACT>"]

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

exceptions:
  - what:     add allowlist assertions to the existing runner test
    why:      the new rules must be proven admissible AND still bounded; the runner's own suite is where every existing allowlist assertion lives, so a second file would fork the evidence
    scope:    tests/ai_run_test.php
    decided_when: 2026-09-15T00:00:00+00:00
    authority: gen4-r1-d1

## THIS CONTRACT WILL BE REFUSED BY `plan`. THAT IS EXPECTED — READ FIRST.

**Authority: owner decision `gen4-r1-d1`, answered `widen-and-rerun`** (recorded in `.ai/decisions/`, resumed
2026-09-15). The owner lifted the freeze **for this one change**; it resumes afterwards.

This contract names the **trust surface** (`tools/ai-run.php`), so `plan` **must** exit 2. **That refusal is
rule 1 working, and it is your first claim:** paste the refusal and its exit code before anything else.

## Objective

Admit the **two** evidence shapes that ordinary module work needs, so a slice that changes product code can
produce admissible evidence instead of being blocked with zero claims.

**Verified finding (CD-47), from a real run.** S2's work was correct and shipped; its re-verification extracted
**zero claims** from five clean `CLAIM:`/`COMMAND:` blocks, because every command it declared was outside
`COMMAND_ALLOWLIST`. The admissible surface is currently `php tests/<name>.php` (**root only**), `php -l`,
`php tools/ai-contract-lint.php`, and the Python rules. So:

- **module tests are inadmissible** — the rule is anchored `/^php\s+tests\/…$/`, so
  `php modules/<mod>/tests/<name>.php` binds no claim, and module tests are where product work is proven;
- **the governance census is inadmissible** — so `write_ratio`/`undeclared`, the number the P2 programme is
  defined by, cannot be evidence.

## Architectural constraints

- **Add exactly two rules. Nothing else.** The owner approved bounded read-only commands (the census) and
  module test paths. Anything further is scope expansion and fails this slice.
- **`php -r` stays refused, forever, and the reason is already recorded in the table's docblock.** Inline code
  is arbitrary execution — the Python form of the B-F1 vacuity hole. Do **not** add it, and do not add any rule
  whose shape permits a `;`, `|`, `&`, backtick, `$(` or redirection.
- **Every rule must stay authoritative about its executable.** `argvForCommand()` takes the executable from the
  matched rule, never from the command text. Do not weaken that, and do not add a rule that cannot.
- **The module rule must reuse the existing app-bootstrap screen.** `testFileIsPure()` refuses a test that
  contains `bootstrap.php` or `MODE_INTEGRATION`. Module tests very often bootstrap the CMS app, and running
  one poisons the APCu module cache and 503s the live tenant. A module rule **without** that screen is a
  production hazard, not a convenience.
- **`..` stays globally refused** before any rule is consulted (`matchingAllowlistRule`). Do not move it.
- **Existing rules are untouched, byte for byte.** A rule that changes shape silently re-opens or closes
  something a previous slice proved.
- **The refusal must stay legible.** An unmatched command must continue to yield `null` — never a permissive
  default.

## Files likely affected

- `tools/ai-run.php`
- `tests/ai_run_test.php`

## Acceptance criteria

Every criterion must be shown by a command you declare, in the admissible shapes.

1. **The module test path is admitted.** `php modules/gui-settings/tests/gui_settings_route_authority_test.php`
   matches a rule, and its executable is `PHP_BINARY`.
2. **A module test that bootstraps the app is refused.** A module test whose source contains `bootstrap.php`
   does not match, even though its path does. Name the file you used.
3. **The census is admitted, and only in its bounded shape.** `php ikabud workbench:governance --all --json`
   matches; `php ikabud workbench:governance --all --json; rm -rf /tmp/x` does **not**; a `|`-chained form does
   **not**.
4. **`php -r` remains refused.** Re-assert it explicitly: the B-F1 hole stays closed.
5. **Path traversal remains refused.** `php modules/../tests/x_test.php` (or any `..` form) does not match.
6. **Every pre-existing rule still behaves exactly as before.** Name which you re-checked and how.
7. Say plainly whether this edit **weakens** any existing check. If it does, say so and stop — that judgement
   is the Chair's.

## Required tests

```
php tests/ai_run_test.php
```

It must exit 0 with zero failures and **no new skips**. Add the assertions for criteria 1–5 to it — that file
is where the allowlist's existing assertions live (8 of them), and forking a second file would split the
evidence. Your report's evidence command is this test.

Also run, as regression cover for the touched area:

```
php tests/ai_autonomy_test.php
php tests/ai_project_test.php
```

## Risks

- **Widening a security boundary.** The allowlist is the trusted command boundary; each rule is a new execution
  surface. The screen is what keeps the module rule from becoming an arbitrary-run primitive, and the anchored
  pattern is what keeps the census rule from accepting a chain.
- **A too-permissive census pattern.** `--all --json` is the shape the programme uses; a pattern that also
  accepts free-form arguments turns a read-only census into an argument-injection surface.
- **The APCu hazard is real and local.** This tree serves the live tenant. A module test that bootstraps the
  app can 503 it. If in doubt, refuse the path — a false refusal costs a run, a false admission costs the
  tenant.

## Report format — required

The report is **evidence**: every statement in it must be backable by a command. `parseProseClaim()`
(`tools/ai-run.php:1407`) binds report **prose** as a claim by design, so a numbered list, a bold lead-in or a
plain sentence becomes a claim of its own and binds `UNVERIFIED`, blocking the slice **even when the work is
complete and correct**. Three runs of S1 and one of S2 were blocked exactly that way.

```
CLAIM: <short assertion>
COMMAND: <exact command, copy-pasteable>
OBSERVED: <verbatim output, including the exit code>
```

Nothing before the first `CLAIM:` and nothing after the last `OBSERVED:` block. No headings, no lists, no
narrative — put that in your reply, not in the report. **Declare only the keys your command's own output
prints**; copy them from the output you paste, not from what you know to be true.

## Forbidden changes

- `kernel/` — no engine change; this is a tool-table change.
- `src/` — no application change.
- `modules/` — no product change; this slice is about evidence, not about the module.
- `phpstan-baseline.neon` — never edited to make a gate pass.
- `.github/workflows/` — no CI definition change.
- `tools/ai-autonomy.php` — the driver is not the ledger; only the allowlist table in `tools/ai-run.php`
  changes.
