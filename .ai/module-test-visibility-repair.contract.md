# REPAIR CONTRACT — Module test discovery: make the guards environment-independent

task: module-test-visibility-repair
lane: openai-codex/gpt-5.6-sol, reasoning medium
owner: chair retains review and the gate
status: BLOCKING — PR #135 is RED and must not merge until green

## What went wrong

The previous lane (`fix/module-test-visibility`, commit `d7962df`, PR #135) wired module tests into
`scripts/run-tests.php` and added environment guards. **It is green locally and RED in CI.**

| | Local | CI |
|---|---|---|
| Files | 136 | 136 |
| Passed | 110 | **111** |
| Failed | **0** | **5** |
| Skipped | 26 | 20 |

Five tests fail in CI. Two of them (`post_revision_mutation_test`, `workflow_contract_test`) **pass
locally and fail only in CI**.

Plus `coding-standards` fails: PHP-CS-Fixer wants one call reformatted in
`modules/cms-akira/cms-akira-theme/tests/theme_contract_test.php`.

### The actual cause

The guards were written **against the local environment's shape** — they test for specific tenant
IDs (`994201`, `994101`, …) and for dedicated-database resolution. In CI those conditions do not
hold, so **the guards do not trigger**, the tests run, and they fail on
`capability_authorization_policies` rows that CI never provisions:

```
✗ activation seeds idempotent content type policy for akira.content_type.create@1
✗ content type list carries the active shell read policy
✗ content type policies bind editor+ roles and the shell caller
CapabilityNotFoundException: No permitted capability providers for: akira.content_type.create@1
```

CI: `Total: 136 files — 111 passed, 5 failed, 20 skipped`

The five CI failures:

```
modules/cms-akira/cms-akira-core/tests/content_type_mutation_test.php       11 passed, 6 failed
modules/cms-akira/cms-akira-core/tests/taxonomy_mutation_test.php           11 passed, 6 failed
modules/cms-akira/cms-akira-core/tests/post_taxonomy_mutation_test.php       6 passed, 3 failed
modules/cms-akira/cms-akira-core/tests/post_revision_mutation_test.php       5 passed, 2 failed
modules/cms-akira/cms-akira-workflow/tests/workflow_contract_test.php       12 passed, 2 failed
```

### A second, related defect in the same commit

Several assertions in the previous commit were **calibrated to the local `tenant_54` database
state** rather than to what the test itself establishes — policy counts changed `3 → 4` and
`readPolicies 0 → 1` incorporating rows that exist only locally (from R5). That is why they pass
here and fail in CI. **A test must not depend on ambient local data.**

## The required fix

**1. Derive every skip condition from the state the test actually asserts on.**
Not from a hard-coded tenant id, not from "does tenant 994201 exist". If a test asserts on policy
rows, its guard must check **whether those policy rows are present**, and skip with a specific
reason when they are not. That condition is then true in CI (skip) and false locally (run), with no
environment-specific constants.

**2. Do not skip more than is necessary.** A guard that skips too readily reintroduces the original
defect — invisible coverage. For each of the five failing tests, the guard must skip **only** when
its genuine prerequisite is absent, and the reason must name that prerequisite.

**3. Assertions must be self-contained.** Where an assertion depends on policy rows, it must either
establish them itself or be guarded. It must **not** depend on whatever happens to be in the
developer's database. Restore any count that was calibrated to local state to a form that holds in
a freshly migrated database.

**4. Fix the PHP-CS-Fixer failure** in `theme_contract_test.php`.

**5. Keep the discovery.** `scripts/run-tests.php` discovering `modules/**/tests/*_test.php` is the
point of the slice — do not revert it to make CI pass. If a test cannot be made
environment-independent, it **skips with a reason**; it is not removed from discovery.

## Prohibited

- Do not weaken, delete or relax an assertion to reach green.
- Do not delete a test file, or remove it from discovery.
- Do not hard-code a tenant id, hostname, or database name as a guard condition.
- Do not chmod or otherwise change `storage/` permissions.
- Do not edit the dispatch guard, `GovernanceCensus.php`, `.governance-baseline.json`, templates, or
  `modules/daily-ledger` / `gui-settings`.
- Do not touch `src/helpers/module-registry.php` again. Its change in `d7962df` is accepted and will
  be assessed separately; the repair is test-only.
- Do not commit, push, or branch.

## Acceptance

**G1 — CI-parity proof.** Because you cannot run CI, **simulate it**: the failure mode is "policy
rows absent". Demonstrate each of the five previously-failing tests behaves correctly **both** ways —
skips with a specific reason when the prerequisite is absent, and passes when present. Do not merely
assert the local path works.

**G2 — Local suite unchanged and green.** `composer test` exits 0. Report
`passed / failed / skipped / total` and confirm the accounting sums exactly.

**G3 — The five tests, individually.** For each: the guard condition, the exact `SKIP:` text, and the
evidence that it triggers only when the prerequisite is genuinely absent.

**G4 — No local-state calibration remains.** Re-run the suite after **clearing tenant-scoped policy
state** (or otherwise prove the assertions no longer depend on it) and show it still exits 0. State
plainly how you established this. This is the check that the previous attempt would have failed.

**G5 — Lint.** `php -l` on every touched file, and the PHP-CS-Fixer issue resolved. Note the repo
runs `php-cs-fixer fix --dry-run`; match its expected formatting rather than guessing.

**G6 — Nothing lost.** No test file deleted; no assertion removed or relaxed. Quote before/after for
any assertion you change.

**G7 — The 109 original `tests/` files** are still 100 passed / 0 failed / 9 skipped.

## Verification commands

```
php -l on every touched PHP file
composer test
php scripts/run-tests.php
php tests/read_authority_probe_test.php
php ikabud workbench:governance --all --gate
```

Clear `storage/logs/error.log` before judging any failure — several tests assert it is clean.

**Note on the chair's own errors:** three of the chair's improvised probes produced false alarms
today (a CLI `authorize()` check, a query against a non-existent `kernel_users` table, and a
partial-bootstrap load). **Do not improvise a partial bootstrap to verify anything** — use the real
runner, or the running app over HTTP.

## Result format

```
status:        PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:          module-test-visibility-repair

G1 both_ways:      <per test: absent-prerequisite result, present-prerequisite result>
G2 suite:          passed=<n> failed=<n> skipped=<n> total=<n>  (sums exactly? )
G3 five_tests:     <guard condition + SKIP text + why it triggers only when absent>
G4 no_local_calibration: <how you proved it; result>
G5 lint:           <php -l results; cs-fixer issue resolved?>
G6 nothing_lost:   <no deletions; before/after for any changed assertion>
G7 original_109:   100 passed / 0 failed / 9 skipped?

changed:       <files>
verification:  <commands + outcomes>
scope:         <anything outside the allowed set>
risks:
unresolved:
recommended_next_state:
```
