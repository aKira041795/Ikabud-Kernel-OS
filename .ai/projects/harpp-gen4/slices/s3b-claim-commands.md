# SLICE — harpp-gen4 S3b: make the loop usable — claims declare commands, blocked slices can be re-queued

project: harpp-gen4 · status: READY_FOR_IMPLEMENTATION · revision: 1
repo: `/var/www/html/ikabudsix`
lane: deepseek/deepseek-v4-flash
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "deepseek/deepseek-v4-flash", "--name", "harpp-gen4-s3b-claim-commands", "<CONTRACT>"]

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

authority: CD-18 — the loop's first real dispatch stopped on unverifiable claims. The mechanism is right; the
interface and one missing transition are not.
directive: **you hold the decision.** Decide, record, continue (CD-8).

## Objective

Two repairs that make the loop able to complete real work, without weakening the rule that it may never
vouch for evidence it cannot re-derive.

1. **Claims declare their commands** — so a claim can actually be re-derived.
2. **A blocked slice can be re-queued** once its cause is fixed — without erasing the evidence of why it
   blocked.

## Verified facts — measured, do not re-derive

1. S4's run completed (`exit=0`, 5,520-byte report). The loop extracted **5 claims**, all `TEST_RESULT` with
   `re_derivable: true`, and every one returned **`UNVERIFIED`** with `reason: no_command_declared`, because
   `subject.command` is `null`. The loop then **stopped the project** (`loop_exit=3`) and marked S4 `blocked`.
2. The cause is visible in the extractor's own output: the evidence line it bound was
   `"ai_project_test          exit=0  6/6 passed"` (record field `subject.text`, `line: 53`) — it names the
   **result** but not the **command**.
3. **S4's work itself is good** and was verified manually by the Chair: `metrics --project=harpp-gen4` works,
   `tests/ai_project_metrics_test.php` is **16/16 with zero skips**, and `metrics.json` is `tool_written: true`
   with `cost_usd` and `director_minutes` as `null` **plus a stated reason**. So this slice repairs the
   interface, not the deliverable.
4. **There is currently no transition out of `blocked`.** S4 will remain blocked forever, so the project can
   never complete even after the cause is fixed. Confirm this by reading the state machine before changing it.
5. **The extractor lives in `tools/ai-run.php`** (`claims` and `verify`), so binding declared commands requires
   changing it. That path **is** in scope for this slice — but **only** for the binding. `tools/ai-loop.php`
   remains forbidden: the loop consumes claims and must not be taught to interpret them.

> **Read fact 5 before starting.** `tools/ai-run.php` may change **only** where a claim binds to a command. Its
> allowlist, its `completed`/`failed`/`silent`/`abandoned` classification, its `commit-check` and its tree
> binding must behave **exactly** as before. A regression there is worse than the defect this slice fixes, and
> acceptance 1 requires proof that it did not happen.

## Deliverables

### D1 — Claims declare commands (where the fix is permitted)

Whatever surface is in scope, the observable requirement is: **a report claim can be re-derived** because a
command is bound to it, by one of exactly two routes, and the route is recorded:

- **declared** — the evidence line names the command (accepted forms to be defined and documented, e.g. a line
  beginning `$ php tests/…` or containing a `php …` invocation with its result);
- **derived** — the exact-match fallback: a `TEST_RESULT` whose text names a test file under `tests/`, where the
  file **exists** and **passes the purity screen**, may have `php tests/<file>` derived. The claim must record
  `command_source: "derived"` so a reviewer can always tell derived from declared. **Never derive for a missing
  or impure file** — refuse and stay `UNVERIFIED`.

### D2 — A blocked slice can be re-queued

Add to `tools/ai-project.php`:

```
php tools/ai-project.php retry --project=<id> --slice=<id> --reason="<text>"
```

- `blocked` → `pending`, with the **reason recorded** and the **prior run id preserved** (evidence of why it
  blocked is history, not debris — do not erase it).
- Refuse if the slice is not `blocked`, or if no reason is given.
- After a retry, `next` returns that slice again.

### D3 — Document the convention

The runbook must state how a slice reports evidence so its claims are re-derivable, and that derived commands
are labelled. A convention that is not written down will be missed by the next executor — which is how this
slice came to exist.

### D4 — Tests

1. a claim bound from a command-bearing evidence line records `command_source: "declared"`;
2. a `TEST_RESULT` naming an existing pure test yields a **derived** command, labelled as derived;
3. a `TEST_RESULT` naming a **missing** or **impure** file is **refused** and stays `UNVERIFIED` — the
   non-vacuity anchor (if everything derives, the label is meaningless);
4. `retry` moves `blocked` → `pending`, records the reason, preserves the prior run id, and refuses when the
   slice is not blocked or the reason is empty;
5. after a retry, `next` returns the slice.

## Architectural constraints

- **Do not weaken the no-marker rule.** A claim with no bound command remains `UNVERIFIED` and still blocks.
  This slice makes verification *possible*, never optional.
- **Never invent a command silently.** Derivation is exact-match, existence-checked, purity-checked and labelled.
- **Do not edit a forbidden path** — report instead (fact 5).
- Pure tests only: **do not run `scripts/run-tests.php`, `composer test`, Playwright, or anything that
  bootstraps the app or touches the web cache.**
- Existing suites stay green: `ai_project_test` 6/6, `ai_loop_test` 8/8, `ai_run_test` 31/31,
  `ai_autonomy_test` 46/46, `ai_project_metrics_test` 16/16 — all zero skips.
- Never edit `.ai/runs/*.json`. Do not commit, stage or push.

## Files likely affected

- `tools/ai-run.php` — **claims binding only**: declared command, labelled derivation
- `tools/ai-project.php` — `retry`
- `tests/ai_run_test.php` — claim-binding cases and the confinement regression
- `tests/ai_project_test.php` — retry transitions
- `.ai/ai-autonomy-harness.contract.md` — the reporting convention
- `.ai/projects/harpp-gen4/state.json` — via the tool only

## Acceptance criteria

1. State plainly **which surface** binds commands, and paste three regressions proving `ai-run.php` is otherwise
   untouched: the command allowlist still refuses an unallowlisted command without executing it, the
   `completed`/`failed`/`silent`/`abandoned` classification still behaves, and `commit-check` still blocks on a
   non-completed run — and the sentinel-planting test still passes.
2. A claim bound from a command-bearing line is demonstrated as `command_source: "declared"`.
3. A **derived** command is demonstrated and labelled, with the file-existence and purity checks shown.
4. A claim naming a **missing** or **impure** test is refused, shown, and remains `UNVERIFIED`.
5. `retry` demonstrated: the transition, the recorded reason, the preserved prior run id, and the refusals.
6. **The unblock proof:** re-verify S4's existing run
   (`verify --run=harpp-gen4-s4-20260914045055-cd664e`) and show whether its claims now bind to commands, and
   what their statuses become. Report the result honestly — if they still cannot be re-derived without editing
   a forbidden path, say so and stop.
7. All six suites exit `0` with zero skips; no existing case relaxed.
8. The convention is documented in the runbook.

## Required tests

- Every command above with real output and exit codes.
- The six suites with counts and zero skips.
- The refusal demonstration (missing/impure file) and the derivation demonstration.
- `php -l` on changed files; PHPStan under `-c phpstan.neon` for them.

## Risks

- **The seductive shortcut is to bind commands by pattern-matching the test name and calling it declared.** That
  is derivation; label it as derivation.
- **Changing `ai-run.php` carelessly is the real risk here.** It holds the allowlist and the classification that
  make the verifier trustworthy, and it executes commands. Confine the change to binding and re-prove the
  sentinel refusal.
- Do not "unblock" S4 by clearing its state by hand — the retry path exists precisely so the transition is
  recorded rather than erased.

## Forbidden changes

- `tools/ai-loop.php`
- `tools/ai-autonomy.php`
- `tools/harpp-bridge/`
- `tests/ai_loop_test.php`
- `tests/ai_autonomy_test.php`
- `tests/ai_project_metrics_test.php`
- `.ai/runs/`
- `.github/workflows/`
- `kernel/`
- `modules/`
- `src/`
- `phpstan.neon`
- `phpstan-baseline.neon`
- `composer.json`
- `package.json`
