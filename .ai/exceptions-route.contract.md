# SLICE G — the missing authority route for existing test files (CD-22's `exceptions:` block)

project: harness-guardrail · status: DIRECTOR_AUTHORISED (CD-44) · revision: 1
repo: `/var/www/html/ikabudsix`
lane: deepseek/deepseek-v4-flash
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "deepseek/deepseek-v4-flash", "--name", "exceptions-route", "<CONTRACT>"]

## THIS CONTRACT WILL BE REFUSED BY `plan`. THAT IS EXPECTED — READ FIRST.

**Authority: owner directive 2026-09-14, verbatim: *"lift the freeze. fix"*** (recorded as CD-44: the freeze is
lifted **for this one change** and **resumes after it lands**). This contract names the trust surface, so `plan`
**must** exit 2. **That refusal is rule 1 working, and it is your first claim.**

## Objective

Give the absolute prohibition on existing test files an **authority route**, so a legitimate correction is
possible without making the matcher's judgement smarter.

**Verified finding (CD-43), from a real run:** `isExistingTestPath()` (`tools/ai-autonomy.php:840-847`) fires on
the **absolute** branch, which is evaluated **before** `isGrounded()` (`:959-968`). So **no contract text, no
`--justify`, and no L4 can authorise a change to an existing test file**, and the only remaining authority is a
director-level trust-surface amendment. Correcting one stale assertion cost the highest-authority change the
system has.

## Architectural constraints

- **DO NOT make the matcher inspect the change.** This is the rejected fix and it is rejected for a recorded
  reason: a matcher able to classify a change as *safe* is a matcher that can be argued into classifying a
  weakening as safe. Counting assertions does not close it — replacing `=== ['completed' => 1]` with
  `!== null` keeps the count and destroys the check. **The prohibition stays absolute.** You are adding a
  **route**, not a judgement.
- **Pre-declared only.** The exception is read from the contract at `plan`/`start`. It must be **impossible** to
  supply one after the run has begun.
- **The route may not reach the verifier.** An exception naming a trust-surface path, or anything in
  `forbidden_scope`, is **refused**, naming the path and the reason. Without this the exception becomes the
  bypass for rule 1 — the same GUARD 1 that already protects the harness-artifact route.
- **Visible and attributed, never silent.** Whenever an exception authorises a change, the record must name
  **which** exception and under whose authority. A change that travelled this route must be auditable
  afterwards.
- **Additive to the contract format.** A contract with no `exceptions:` block behaves **exactly** as today.
- PHP 8.3-compatible. No new runtime dependency. Exit codes unchanged (`0`/`2`/`3`/`4`).

## Files likely affected

- `tools/ai-autonomy.php`
- `tools/ai-run.php`
- `kernel/Workbench/Development/DevelopmentTaskContract.php`
- `tests/ai_autonomy_test.php`
- `tests/ai_run_test.php`

## Deliverables

### D1 — The `exceptions:` block, parsed and validated

An entry carries `what`, `why`, `scope`, `decided_when`, `authority`. Validate **all five**: an entry missing
`why`, `scope`, `decided_when` or `authority` is refused with the missing field named. **`decided_when` must
precede the run** — an entry dated after dispatch has no effect, and that must be asserted, not assumed.

### D2 — The guards

- An exception whose `scope` names a **trust-surface path** → refused (D1 of the guardrail slice is the model).
- An exception whose `scope` lies inside the contract's **`forbidden_scope`** → refused.
- An exception with traversal or an absolute path → refused.

### D3 — The prohibition consults it

`check` on a path under an existing test file returns **RECORD**, not ESCALATE, **only** when the active
contract declares a matching, valid exception — and the verdict must **name the exception** that authorised it.
**With no exception the behaviour is byte-identical to today**: `ESCALATE`, level L4, exit 3. Run-finish scope
conformance (`scopeConformance()`, `tools/ai-run.php:815-849`) must honour the same rule, so an authorised
correction does not block the run.

### D4 — Non-vacuity, all four directions

State which assertion fails when each fix is reverted:
- with a matching exception → **RECORD** (proves the route works);
- **without** one → **ESCALATE / L4 / exit 3** (proves the prohibition is unweakened);
- with an exception naming the **trust surface** → **refused** (proves the route is not a rule-1 bypass);
- with an exception **dated after** dispatch → **no effect** (proves pre-declaration).

## Acceptance criteria

1. **AC1** — AC-D4's four directions demonstrated with real exit codes.
2. **AC2** — a contract with **no** `exceptions:` block behaves exactly as before; paste the same probe with
   and without.
3. **AC3** — the record names the exception and its authority for an authorised change.
4. **AC4** — no existing behaviour is weakened: `phpstan.neon`-class gate changes still escalate; the covering
   scope refusal still fires (`tools/`, `tools/ai-*.php`, `**/*.php` → exit 2) while `docs/` is accepted; the
   exact trust-surface path is still refused; the harness-artifact guards still hold.
5. **AC5** — `php tests/ai_autonomy_test.php`, `tests/ai_run_test.php`, `tests/ai_project_test.php`,
   `tests/ai_loop_test.php`, `tests/ai_contract_lint_test.php` pass with **zero skips**.
6. **AC6** — rule 1 still refuses this contract's own scope.

## Required tests

```
php tests/ai_autonomy_test.php
php tests/ai_run_test.php
php tests/ai_project_test.php
php tests/ai_loop_test.php
php tests/ai_contract_lint_test.php
```

Pure suites only. **Do not run `scripts/run-tests.php`, `composer test`, or anything that bootstraps the CMS
app** — a full run poisons the APCu module-cache and 503s the live tenant. Write logs to `/tmp`, never into the
repository.

## Report format — required

One claim per block. **Do NOT wrap the claim blocks in a code fence** — fenced content is skipped by the
extractor, so claims inside a fence do not bind and the run blocks as `UNVERIFIED`. (That cost a run on
GEN4-R1 S1.)

```
CLAIM: <short assertion>
COMMAND: <exact command, copy-pasteable>
OBSERVED: <verbatim output, including the exit code>
```

Report path: `.ai/exceptions-route.report.txt` (this path **is** inside the contract's scope, unlike a
slice-authored report path — see CD-43's incidental finding).

## Risks

- **A route that becomes the bypass.** The guard against exceptions naming the trust surface is the whole
  design; both halves must be tested, not just implemented.
- **Silent authorisation.** A change permitted by an exception but not *recorded* as such would make the
  exception mechanism invisible — indistinguishable from the prohibition having been loosened.
- **Over-scoping.** An exception must cover the paths it names and no more.

## Forbidden changes

- phpstan.neon
- phpstan-baseline.neon
- .github/workflows/
- scripts/
- modules/
- src/
- .ai/projects/
- .ai/chair-decisions.md
