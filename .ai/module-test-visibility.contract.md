# CONTRACT — Module test visibility: replace illusory coverage with honest skips

task: module-test-visibility
lane: openai-codex/gpt-5.6-sol, reasoning medium (verification integrity; must not weaken assertions)
owner: chair retains review and the gate
status: READY_FOR_IMPLEMENTATION

## The finding

`scripts/run-tests.php` scans **only** `tests/` (`$testDir = $root . '/tests'`). CI runs exactly
**one** module test explicitly: `modules/cms-akira/cms-akira-shell/tests/shell_contract_test.php`.

**27 test files live under `modules/*/tests/` and never execute — locally or in CI.** The suite
therefore reports `109 files — 100 passed, 0 failed` while those 27 provide no coverage at all.

Measured 2026-09-12 by running all 27 directly:

```
PASS (exit 0):  7
FAIL (exit 1): 20
```

The failures are **not 20 independent defects**. They cluster:

| Pattern | Files |
|---|---|
| `CapabilityNotFoundException: No permitted capability providers for: …` | 7 — `ai_contract`, `post_read_render`, `media_contract`, `navigation_contract`, `search_contract`, `seo_contract`, `theme_contract` |
| **exactly `N passed, 1 failed`** — one shared cause | **all 6 `*_dedicated_tenant_test`** (`builder`, `media`, `navigation`, `search`, `seo`, `theme`) |
| cache write `RuntimeException: Unable to write fragment cache file` | `post_mutation` |
| other | `builder_contract` (error, no summary), `builder_admin_http_bridge` (4/16), `content_type_mutation` (40/2), `post_composition_override` (5/6), `post_taxonomy_mutation` (28/6), `taxonomy_mutation` (33/2) |

The repo's own `copilot-instructions.md` already warns *"Module tests are **not** discovered by
`scripts/run-tests.php`… Do not assume a module test passes because CI is green."* It documents the
gap but records **no reason** for it. Nothing states which of these are environment-dependent, so the
next person cannot tell test rot from a provisioned-environment assumption.

## The objective

**Make the absence of coverage visible, and stop it being silent.** Not "make them all pass."

A test that cannot run in this environment must say so — `SKIP: <reason>` — and exit 0, per the
convention the repo already uses (`tests/_support/env_guard.php`; `copilot-instructions.md`:
*"Environment-dependent tests must print `SKIP: <reason>` … rather than silently passing"*).

## Required work

**Step 1 — classify all 27, with evidence.** For each file, run it and record: pass/fail, the exact
first failure, and your determination of the cause as one of:

- `ENV-CACHE` — cannot write under `storage/cache/**` (permissions, or a cache dir the CLI user
  cannot create)
- `ENV-TENANT` — needs a tenant/authority context that does not resolve in CLI
- `REAL` — a genuine defect the test correctly caught
- `PASS` — passes as-is

Do not guess: quote the actual error line for each. Where a file has several failures with different
causes, say so.

**Step 2 — add honest environment guards.** For `ENV-CACHE` and `ENV-TENANT` files, make the test
detect the condition and emit `SKIP: <precise reason>` with exit 0 **before** the point of failure.
Follow `tests/_support/env_guard.php` if it fits; if it does not, say why and extend it.

A skip must be **specific** — `SKIP: no tenant resolves in CLI` is useful, `SKIP: environment` is not.
A skip must never swallow a `REAL` failure.

**Step 3 — wire module tests into the runner.** Extend `scripts/run-tests.php` to discover
`modules/*/tests/*_test.php` as well, so these cannot rot again. Keep the existing output format and
the JSON manifest working. If module tests need different handling (working directory, bootstrap),
handle it explicitly rather than by exclusion.

**Step 4 — surface genuine failures.** Every `REAL` determination must be **reported with its
evidence**, not fixed and not hidden. If a real failure is small, clear and unambiguously a defect,
you may fix it — and say so plainly. If you are unsure, report it.

## Prohibited

- **Do not weaken, delete, relax or skip an assertion to reach green.** Deleting a failing assertion
  is the exact failure mode this slice exists to prevent. If a test is wrong, say why; do not silently
  neuter it.
- Do not delete any test file.
- Do not add `@skip`-style blanket skips. A skip needs a real, checkable condition.
- Do not edit the dispatch guard, `GovernanceCensus.php`, `.governance-baseline.json`, or any
  template.
- Do not change `storage/` permissions as a fix. If a cache directory cannot be written by the CLI
  user, that is an environment fact to **report and skip on**, not to chmod around. (The chair has
  been bitten by exactly this: `storage/cache/{compiled,disyl-extends,disyl-sandbox}` are
  `www-data`-owned and unwritable by the dev user.)
- Do not touch `modules/daily-ledger` or `gui-settings`.
- Do not commit, push, or branch.

## Acceptance

**F1 — classification table**, all 27 rows, with the verbatim error line as evidence and one of the
four causes per row.

**F2 — every `ENV-*` file now skips honestly.** For each, show the emitted `SKIP:` line and exit code
0.

**F3 — module tests are discovered.** Show `run-tests.php` including them: the file count must rise
from **109** to include the module tests, and the output must show skip counts separately from
pass/fail.

**F4 — CI stays green.** `composer test` exits 0. The overall numbers must be honest: passed + failed
+ skipped = total. **State the new numbers and confirm nothing was made to pass by weakening it.**

**F5 — `REAL` failures reported.** Each with evidence and your recommended action. State plainly how
many you believe are genuine defects.

**F6 — nothing lost.** `git diff --stat` shows no test file deleted, and no assertion removed. If an
assertion changed, quote before and after and justify it.

**F7 — the pre-existing suite is untouched.** The 109 files under `tests/` still behave exactly as
before: 100 passed, 0 failed, 9 skipped. Any change there is a regression.

## Verification commands

```
php -l on every touched PHP file
composer test
php scripts/run-tests.php            # the runner directly
php tests/read_authority_probe_test.php
php ikabud workbench:governance --all --gate
```

Note: clear `storage/logs/error.log` before judging any failure. Several tests assert that log is
clean, and the chair polluted it twice today with diagnostics — producing false failures in correct
work.

## Result format

```
status:        PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:          module-test-visibility

classification:          # F1 — all 27
  - file: <path>
    verdict: PASS | ENV-CACHE | ENV-TENANT | REAL
    evidence: <verbatim error line, or "passes">
    action: <guard added | none | reported>

F1 total:      pass=<n> env_cache=<n> env_tenant=<n> real=<n>
F2 skips:      <file -> SKIP line, for each>
F3 discovery:  files before=109 after=<n>; how module tests are discovered
F4 suite:      <passed/failed/skipped/total+ , gate exit, honest accounting>
F5 real:       <count + each one's evidence and recommended action>
F6 lost:       <confirm no file deleted, no assertion removed; quote any changed assertion>
F7 pre_existing:<the 109 tests/ files: still 100/0/9?>

changed:       <files>
verification:  <commands + outcomes>
scope:         <anything outside the allowed set>
risks:
unresolved:
recommended_next_state:
```
