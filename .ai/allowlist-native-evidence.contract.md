# SLICE C — the verifier learns the subject's language

project: harness-guardrail · status: DIRECTOR_AUTHORISED (CD-33: *"A is approved"*) · revision: 1
repo: `/var/www/html/ikabudsix`
lane: deepseek/deepseek-v4-flash
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "deepseek/deepseek-v4-flash", "--name", "allowlist-native-evidence", "<CONTRACT>"]

## THIS CONTRACT WILL BE REFUSED BY `plan`. THAT IS EXPECTED — READ FIRST.

**Authority: owner decision 2026-09-14, verbatim: *"A is approved"*** (recorded in `.ai/chair-decisions.md` as
CD-33). This contract names the trust surface, so `plan` **must** exit 2 with *"the trust surface is not
contract-authorisable"*. **That refusal is rule 1 working, and it is your first claim.** Do not weaken rule 1 to
make anything plannable.

## Objective

The verifier can execute three PHP shapes and nothing else, so **any correct work whose evidence is written in
another language cannot be verified at all** — which is why slice S5 is blocked despite its work being verified
and committed (`58888a5`). Teach the allowlist the subject's native evidence shapes, under **exactly** the
discipline the existing table already uses.

**This is not a general escape hatch.** Every rule added is attack surface; add the shapes legitimate evidence
needs and **not one more**. If a shape can express arbitrary code, it must not be added.

## Architectural constraints

- **Match the existing discipline exactly.** The allowlist is **data, not conditionals**
  (`COMMAND_ALLOWLIST`, `tools/ai-run.php:84`): anchored patterns, one entry per shape, readable in one place and
  refused consistently. Extend the table — do not add branching logic beside it.
- **`argvForCommand()` hardcodes `str_starts_with($command, 'php ')`.** Generalise it **without loosening it**:
  the executable must still be selected by an allowlisted rule, never taken from the command text.
- **Refuse every inline-code form.** `python3 -c "…"` is arbitrary execution and is the Python form of the
  vacuity hole already logged as B-F1 (where `verify="true"` advanced a job). It must be **refused**, with a test.
- **Chaining and traversal stay refused**: `;`, `&&`, `|`, backticks, `$(…)`, and `..` anywhere.
- **No bytecode artefacts.** Running Python can create `__pycache__`/`*.pyc`. Verification must not make the tree
  dirty — set `PYTHONDONTWRITEBYTECODE=1` for Python shapes, **and show that a verification run creates no new
  path the scope gate can see**. If `__pycache__` were to surface as an out-of-scope path, every Python-evidence
  run would block, so prove this rather than assuming it.
- **Timeouts on the new shapes**, matching whatever bound the existing ones use.
- PHP 8.3-compatible. No new runtime dependency. Exit codes unchanged (`0`/`2`/`3`/`4`).

## Files likely affected

- `tools/ai-run.php`
- `tests/ai_run_test.php`

## Deliverables

### D1 — The native evidence shapes

Add allowlist rules covering, and no broader than:
- **syntax check** — `python3 -m py_compile <path>.py`
- **module test** — `python3 -m unittest <dotted.module>`
- **file test** — `python3 tools/harpp-bridge/tests/<name>.py`, screened for existence like the PHP
  `pure_test` rule screens `tests/<name>.php`

Each pattern anchored (`^…$`), charset-restricted, and refusing `..`. Generalise `argvForCommand()` so these
become argv arrays executed with `bypass_shell => true`, exactly as the PHP shapes are.

### D2 — The refusal path, extended to the new shapes

The existing sentinel test proves a chained command is refused **and never executed**. Extend it to the new
shapes: each of these must be refused **and** leave the sentinel file absent.
- `python3 -m py_compile tools/harpp-bridge/harpp_wake.py && touch <sentinel>`
- `python3 -m py_compile ../../etc/passwd`
- `python3 -c "import os; os.system('touch <sentinel>')"`
- `python3 -m unittest os`
- `python3 evil.py`
- `python3 -m py_compile tools/harpp-bridge/harpp_wake.py | tee <sentinel>`

### D3 — Exactly one real re-derivation

Prove the extension actually works rather than merely being permitted: take a **real** command from the shape
list, run it through the verifier's re-derivation path against a real file in this repo, and show the claim
reaching `RE_DERIVED`. A test that only asserts `commandIsAllowlisted()` returns `true` is **not** sufficient —
the point of this slice is that evidence becomes *executable*.

## Acceptance criteria

- **AC1** — `python3 -m py_compile <real path>.py` is allowlisted, **executes**, and its claim re-derives to
  `RE_DERIVED`.
- **AC2** — every D2 form is **refused** and the sentinel file does **not** exist afterwards (paste `ls`).
- **AC3** — `python3 -c` is refused; state in a comment *why* (arbitrary execution / vacuity hole B-F1).
- **AC4** — a verification using a Python shape leaves the working tree unchanged: show `git status --porcelain`
  before and after, identical.
- **AC5** — `php tests/ai_run_test.php`, `tests/ai_autonomy_test.php`, `tests/ai_project_test.php`,
  `tests/ai_loop_test.php`, `tests/ai_contract_lint_test.php` all pass, **zero skips**, and **every existing
  behaviour still holds exactly**: rule 1 refuses this contract; covering scopes refused (`tools/`,
  `tools/ai-*.php`, `**/*.php` → exit 2) while `docs/` is accepted; `check` on `tools/ai-run.php` → ESCALATE/L4/exit
  3 while a benign in-scope path → RECORD/0; the loop blocks an out-of-scope write and advances an in-scope run.
- **AC6** — every new assertion **fails when its fix is reverted**; state which assertion fails for each.

## Required tests

Pure PHP only. **Do not run `scripts/run-tests.php`, `composer test`, or anything that bootstraps the CMS app** —
a full run poisons the APCu cache and 503s the live tenant. Python may be run **only** through the new shapes and
with `PYTHONDONTWRITEBYTECODE=1`.

## Report format — required

```
CLAIM: <short assertion>
COMMAND: <exact command, copy-pasteable>
OBSERVED: <verbatim output, including the exit code>
```

Report path: `.ai/allowlist-native-evidence.report.txt`. First claim: the `plan` refusal of this contract.

## Out of scope — do NOT attempt

**Do not re-queue or touch slice S5.** S5's block has a second cause that this slice cannot fix: its report
predates the claim-command convention, so its only extracted claim reads
`"command": "git diff --check           PASS"` — the status word is inside the command, so there is no
re-derivable command to run. The Chair rewrites S5's contract and re-queues it separately. Your job is the
verifier's capability, not S5's resume.

## Risks

- **A rule wider than the need.** `python3 -m unittest <anything>` accepts any importable module, including ones
  with side effects. Keep the charset restricted and consider whether the dotted form can be bounded to the
  subject's own package.
- **Bytecode pollution reaching the scope gate** (see constraints) — prove AC4 rather than assuming it.
- **Loosening `argvForCommand`.** Generalising a hardcoded `php ` prefix is where a bypass would hide; the
  executable must come from the matched rule.

## Forbidden changes

- `phpstan.neon`
- `phpstan-baseline.neon`
- `.github/workflows/`
- `scripts/`
- `modules/`
- `tools/harpp-bridge/`
- `.ai/projects/`
