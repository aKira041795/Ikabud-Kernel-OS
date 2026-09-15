# SLICE B — the harness keeps moving: the repair ladder, cost capture, and an acknowledged block

project: harness-guardrail · status: DIRECTOR_AUTHORISED (CD-28) · revision: 1
repo: `/var/www/html/ikabudsix`
lane: deepseek/deepseek-v4-flash
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "deepseek/deepseek-v4-flash", "--name", "guardrail-ladder-cost-flash", "<CONTRACT>"]

# LANE REALLOCATED 2026-09-14 (CD-30): the Sol lane returned "Codex error: The usage limit has been
# reached" and the first attempt produced no output at all. CD-5: executor exhaustion is reallocation,
# not a stop; owner standing instruction: use flash when sol is unavailable. Contract substance unchanged.

## THIS CONTRACT WILL BE REFUSED BY `plan`. THAT IS EXPECTED AND CORRECT.

**Authority: owner directive 2026-09-14, verbatim:** *"close it then, use sol. then we can test with harpp"*
(recorded as **CD-28**, second and final bootstrap).

This contract names the trust surface, so `plan` **must** exit 2. That refusal is rule 1 working. **Do not
weaken rule 1 to make this plannable.** Include the refusal as your first claim, as in Slice A.

CD-29 adds one item to this slice: **the acknowledged-block route.** A run that structurally cannot demonstrate
scope conformance (Slice A's own run — it created the baseline feature it could not have) is blocked and therefore
blocks `commit-check`. That must have a declared, attributed route, or verified work can never land.

## Objective

Slice A made the harness bind **consequence**. This slice keeps the work **moving**: a bounded repair ladder that
cannot escape its envelope, cost actually derivable from artefacts, and a route for a structurally blocked run
that records the block rather than erasing it.

## Architectural constraints

- **The ladder may change the METHOD, never the VERIFIER.** A rung may change approach, decomposition, order, lane
  or instrumentation. It may **propose** an envelope exception for the Chair to record; it may **not** alter
  acceptance criteria, claim statuses, the command allowlist, `commit-check`, or the trust surface. The A-F2 scope
  gate from Slice A is what must enforce this — the ladder must be *unable* to reach the verifier, not merely
  instructed not to.
- **Promote, never repeat.** A rung is chosen by *how the previous attempt failed*, not by trying the same thing
  harder. A second identical attempt on the same failure is the most expensive pattern in the harness and must be
  visible in the event stream as a promotion, not a retry.
- **Every rung produces new evidence.** A rung that ends with no new information is a failed rung and must be
  recorded as one.
- **Every rung is recorded**: which level, what changed in the approach, what the previous failure was, and the
  run id of each attempt.
- **Cost must come from artefacts, never be estimated.** If the runner exposes no usage, record `null` with the
  reason — do not infer or interpolate (CD-15: the harness must not be the source of its own metrics).
- PHP 8.3-compatible. No new runtime dependency.
- Exit codes unchanged: `0` ok, `2` malformed/refused, `3` escalate, `4` filed-but-undelivered.

## Files likely affected

- `tools/ai-loop.php`
- `tools/ai-project.php`
- `tools/ai-run.php`
- `tools/ai-autonomy.php`
- `tests/ai_loop_test.php`
- `tests/ai_project_test.php`
- `tests/ai_run_test.php`

## Deliverables

### D1 — The S7 repair ladder (CD-20: this is the gap the owner named)

Today a failing slice is blocked and stops; repair is performed by hand. Implement a bounded ladder:

| rung | trigger | action |
|---|---|---|
| **L1** | implementation failure, approach intact | repair in the same lane |
| **L2** | the approach itself failed | change approach and/or lane |
| **L3** | the task decomposition is wrong | re-decompose, then re-dispatch |
| **L4** | cannot proceed without changing the contract | **stop**, file a decision (never auto-amend) |

- Bounded by a configurable `max_repairs`. On exhaustion: **promote to the next level**, do not stop and do not
  retry the same level.
- Each rung's new attempt is a **new run** in the ledger, linked to its predecessor, so the history of a slice is
  a chain of runs and not a single overwritten record.
- **L4 is a stop, not an action.** The ladder may never amend acceptance criteria, and it may never reach the
  trust surface — assert this, do not merely document it.
- The ladder must **refuse to advance** when a rung cannot supply new evidence.
- **Proof required:** a slice whose first attempt fails is repaired at L1 and completes; a slice whose approach
  fails is promoted to L2 (visible in the events as a *promotion*); an exhausted ladder promotes rather than
  replays; an L4 condition stops without amending anything. Real outputs and exit codes for each.

### D2 — Cost and tokens from artefacts (S4's open finding)

The ledger binds no runner session to a run, so cost is underivable and the cost thesis stays rhetorical.
- Bind the runner's session/usage to the run record where the runner exposes it; record `null` with a stated
  reason where it does not.
- Surface it in `metrics` so the project's cost is **derived from artefacts**, never estimated.
- **Forbidden:** inventing a token count, extrapolating from wall-clock, or reporting a placeholder as a figure.
  A `null` with a reason is the correct answer when the data does not exist.
- **Proof required:** the run record carries the binding (or an explicit `null` + reason); `metrics` reports it;
  and a state a comment does not overstate.

### D3 — The acknowledged-block route (CD-29)

```
php tools/ai-run.php commit-check --acknowledge-block=<run-id> --reason="…" --director-decision=<ref>
```
- Refuses (exit `3`) without a resolvable `--director-decision`, and for a run that is **still running**.
- Records the acknowledgement with the **block reason verbatim**, the decision ref and the timestamp.
- Leaves `status: blocked` and `scope_conformance.ok: false` **unchanged in the run record** — history is not
  rewritten. There must be a test asserting the run record is *not* mutated.
- Acknowledges **one named run, once**. It must not become a general unblock, and a *new* blocked run must block
  again.
- **Proof required:** refused without a decision; refused for a live run; succeeds for Slice A's blocked run
  (`guardrail-scope-integrity`) using `--director-decision=CD-29`; the run record still shows `blocked` afterwards;
  and `commit-check` then reports ELIGIBLE. Real output and exit codes.

## Acceptance criteria

- **AC1** — D1: L1 repair completes; L2 reached by promotion (not replay); exhaustion promotes; L4 stops without
  amending. The ladder cannot touch the trust surface (demonstrate the refusal, don't assert it).
- **AC2** — D2: cost derived from artefacts or explicitly `null` with a reason; no invented figure anywhere.
- **AC3** — D3: as in D3's proof list, including the immutable-record assertion and the re-blocking test.
- **AC4** — `php tests/ai_loop_test.php`, `tests/ai_project_test.php`, `tests/ai_run_test.php`,
  `tests/ai_autonomy_test.php` all pass with **zero skips**, and **every Slice A behaviour still holds exactly**:
  covering scopes refused (`tools/`, `tools/ai-*.php`, `**/*.php` → exit 2), `docs/` accepted, exact trust-surface
  path refused, widen-allowlist → ESCALATE/L4/exit 3, benign in-scope path → RECORD/0, out-of-scope write blocks
  a run, in-scope run advances, pre-dispatch change does not block.
- **AC5** — rule 1 is **not** weakened, and rule 2's hash gate still moves and still refuses on mismatch.

## Required tests

Pure PHP only. **Do not run `scripts/run-tests.php`, `composer test`, or anything that bootstraps the CMS
application** — a full run poisons the APCu module-scan cache and 503s the live tenant.

```
php tests/ai_loop_test.php
php tests/ai_project_test.php
php tests/ai_run_test.php
php tests/ai_autonomy_test.php
php tests/ai_contract_lint_test.php
```

## Report format — required

```
CLAIM: <short assertion>
COMMAND: <exact command, copy-pasteable>
OBSERVED: <verbatim output, including the exit code>
```

Report path: `.ai/guardrail-ladder-cost.report.txt`. First claim: the `plan` refusal of this contract.
State for each new assertion what fails when its fix is reverted.

## Risks

- **A ladder that becomes a scope escape.** If a rung can re-dispatch with a widened envelope, it will be used
  that way. The envelope must be fixed for the life of the slice, and a rung proposing a change must produce a
  proposal for the Chair, not a new dispatch.
- **A ladder that replays.** "Try again" is the failure mode this deliverable exists to prevent; promotions must
  be distinguishable from replays in the event stream.
- **A block route that becomes the bypass.** AC3's re-blocking test and the immutable-record test guard this;
  without them the route is indistinguishable from simply clearing the gate.

## Forbidden changes

- `phpstan.neon`
- `phpstan-baseline.neon`
- `.github/workflows/`
- `scripts/`
- `modules/`
- `tools/harpp-bridge/`
