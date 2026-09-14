# SLICE — close the verified fail-opens and make the verifier's trust surface unreachable

project: harness-guardrail · status: READY_FOR_IMPLEMENTATION · revision: 1
repo: `/var/www/html/ikabudsix`
lane: deepseek/deepseek-v4-flash
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "deepseek/deepseek-v4-flash", "--name", "guardrail-trust-surface", "<CONTRACT>"]

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

## This contract deliberately touches the verifier — read this first

**Authority: owner directive 2026-09-14** — *"a strict guardrail that you as chair cannot break."*

CD-21 establishes four rules over the verifier's **trust surface**: unreachable · loud and blocking ·
ambiguity fails closed · director-only authority. **Rule 1 is currently prose, proven by the Chair's own probe**
(CD-23): a valid contract listing `tools/ai-run.php` in scope makes
`check "widen the command allowlist…"` return `RECORD / L2 / exit 0`, while the same action aimed at
`phpstan.neon` returns `ESCALATE / L4 / exit 3`. The verifier's own code is in the only class of path that is
**not** protected.

**This contract is the constituting change.** Before it, no invariant exists to bind the Chair; building it
creates that invariant, performed **once**, under the explicit owner directive above. Once rule 1 is
mechanically effective, **any further change to the trust surface requires the owner** — the Chair may propose,
never perform. This exception must not be cited as precedent, and the implementation must say so in a comment
where the matcher is defined.

**The recursion is disclosed, not hidden** (CD-23): the Chair is building the fence that constrains the Chair.
That is why the acceptance criteria below are adversarial — the fix is only real if the probe that currently
passes now **fails**.

## Objective

Close the four verified fail-opens in the harness's own verification path, and make the verifier's trust
surface **contract-unreachable** rather than merely discouraged.

## Architectural constraints

- **Deterministic first**: extend the existing single `l4Taxonomy()` list — do **not** create a second
  prohibition list. The driver's own comment states there is no second list; preserve that invariant.
- The trust surface is an **enumerated list of paths**, never prose:
  `tools/ai-run.php` · `tools/ai-autonomy.php` · `tools/ai-project.php` · `tools/ai-loop.php` ·
  `tools/ai-contract-lint.php` · `kernel/Workbench/Development/DevelopmentTaskContract.php` ·
  `tools/harpp-bridge/harpp_wake.py`.
- **Fail closed on ambiguity** (CD-21 rule 3): a path that is unclear — a directory covering a trust-surface
  file, a glob that could match one, an unresolved symlink — is treated as **touching** the trust surface.
  Ambiguity resolves toward protection, never toward the executor's preferred reading.
- Do not weaken, skip, delete or relax any existing test or gate. Existing suites must keep passing unchanged.
- Do not add a runtime dependency. PHP 8.3-compatible only (`/usr/bin/php8.3`).
- Keep exit-code semantics: `0` ok, `2` malformed/refused, `3` escalate, `4` filed-but-undelivered.

## Files likely affected

- `tools/ai-autonomy.php`
- `tools/ai-run.php`
- `tools/ai-project.php`
- `tests/ai_autonomy_test.php`
- `tests/ai_run_test.php`
- `tests/ai_project_test.php`

## Deliverables

### D1 — The trust surface is unreachable (CD-21 rule 1)

- A contract that names **any** trust-surface path in its scope is **refused outright** (`plan` → exit `2`,
  message naming the path and stating that the trust surface is not contract-authorisable).
- A `check` whose action or `--path` touches the trust surface returns **`ESCALATE` / L4 / exit `3`**,
  **even when the path is inside `allowed_scope`**. This is the specific defect: scope must not be able to
  legitimise it.
- The matcher lives in `l4Taxonomy()` as an `absolute` entry. It must apply on the path **and** on the
  action text (a bare action naming the verifier without a `--path` must still escalate).
- A comment at the matcher records that it was added under the 2026-09-14 owner directive as the constituting
  change, and that subsequent amendments are director-only.

### D2 — Detection is loud and blocking (CD-21 rule 2)

- Record a **hash of the trust surface** (the seven files, sorted, concatenated, `hash('sha256', …)`) in the
  run ledger at `start`.
- `commit-check` recomputes it and **refuses** on mismatch: `NOT ELIGIBLE`, exit `3`, naming the file(s) whose
  hash moved. A silent widening must become a blocked gate, not a successful run.
- Absent hash (older records) must **not** fail closed into a false block — record `unavailable` and continue,
  because blocking historical runs would make the gate unusable. State this trade-off in a comment.

### D3 — `commit-check` fails closed on unreadable records (verified finding A-F3)

Reproduced by the Chair: a malformed `broken.json` in the runs dir yields `ELIGIBLE` and **exit 0**, while a
genuine in-flight run yields `NOT ELIGIBLE` exit `3`. A corrupt record is dropped from the gate entirely.
- Any unreadable/malformed run record must make `commit-check` **`NOT ELIGIBLE`**, exit `3`, naming the file.
- Rationale to state in a comment: an unreadable record cannot be shown to be non-blocking, so it is blocking.

### D4 — Completion evidence must be bound, not merely well-formed (verified finding A-F4)

Reproduced by the Chair's reading of `ai-project.php:248-266`: `assertRunReDerived()` accepts any readable
record with `status=completed` and `RE_DERIVED` claims. **It never checks the record's `id` matches the run
asked for**, nor the contract revision, nor that the recorded `rev`/`dirty` still describes the tree.
- Refuse unless `record['id'] === $runId`.
- Refuse unless the contract revision recorded at `start` matches the run's own record.
- Refuse unless the recorded `rev` equals the current `git rev-parse HEAD`; treat `dirty: true` as a refusal.
- **Precedent**: `_stage_result_matches()` in `harpp_wake.py:2395-2415` already binds `workflow_id`,
  `stage_name` and `schema_version`. Bring the older PHP gate up to the standard the newer Python gate sets.

## Acceptance criteria

- **AC1 (the adversarial one)** — the exact probe that passes today must fail after the fix. A `/tmp` contract
  listing `tools/ai-run.php` in `Files likely affected` must be **refused (exit 2)** by `plan`, and
  `check "widen the command allowlist to accept python3 evidence" --path=tools/ai-run.php` must return
  **`ESCALATE` / L4 / exit 3**. Paste both real exit codes. **A non-escalating result fails this slice.**
- **AC2** — a malformed run record in a temp runs dir makes `commit-check` exit `3` (was `0`).
- **AC3** — a foreign or stale run record is refused by the `done` transition (was accepted).
- **AC4** — the trust-surface hash mismatch path blocks `commit-check` with exit `3` (demonstrate by mutating a
  copy of a trust-surface file in a temp location, or by an injected hash mismatch — do not edit the real file).
- **AC5** — `tests/ai_autonomy_test.php`, `tests/ai_run_test.php`, `tests/ai_project_test.php` all pass with
  **zero skips**, and every new test **fails without its fix** (say which assertion fails when the fix is
  reverted).

## Required tests

Pure PHP tests only, run as `php tests/<file>.php`:

- `php tests/ai_autonomy_test.php`
- `php tests/ai_run_test.php`
- `php tests/ai_project_test.php`

**Do not run `scripts/run-tests.php`, `composer test`, or anything that bootstraps the CMS application** — a
full run poisons the APCu module-scan cache and 503s the live tenant.

## Report format — required (a slice once blocked because this was unspecified)

Every claim must declare the command that demonstrates it, on the evidence line, so the verifier can
re-derive rather than trust:

```
CLAIM: <short assertion>
COMMAND: <exact command, copy-pasteable>
OBSERVED: <verbatim output, including the exit code>
```

Report path: `.ai/guardrail-trust-surface.report.txt`.

## Risks

- **The fix could be vacuous** — if `check` escalates for an unrelated reason, AC1 passes without the
  protection existing. Mitigate by also asserting that an ordinary in-scope path still returns `RECORD / 0`,
  so the escalation is attributable to the trust surface and not to a general tightening.
- **Over-blocking** — refusing every contract that mentions a trust-surface word would break legitimate
  documentation work. The matcher must match **paths**, not bare substrings, and must be shown not to escalate
  for an ordinary contract.

## Forbidden changes

- `kernel/Workbench/Development/DevelopmentTaskContract.php`
- `tools/harpp-bridge/`
- `phpstan.neon`
- `phpstan-baseline.neon`
- `.github/workflows/`
- `scripts/`
- `modules/`
