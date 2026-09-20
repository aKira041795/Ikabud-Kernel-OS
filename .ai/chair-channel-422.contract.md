# The director channel: send the sixth field, and read back with the right filter

## Objective

Two defects, both diagnosed to a proven cause. Fix both so a real decision actually reaches the
director and the harness can confirm it.

**1. The submit omits a required field.** HARPP rejects every submission with HTTP 422:

```
"Valid title, body, requested_decision, priority, source, and workbench_state are required."
```

`deliverDecision()` in `tools/chair.php` passes five of the six. It never sends `--workbench-state`,
and the bridge's `kw.get("workbench_state", default)` does not apply its default because the key
arrives present holding `None`. Measured: adding `--workbench-state=ARCHITECTURE_DECISION_REQUIRED`
turns the same call into `{"ok": true, "data": {"decision_id": 111, ...}}`.

**2. The read-back queries the wrong window.** `harpp decision list` with default filters does NOT
include a decision submitted under `workbench_state=ARCHITECTURE_DECISION_REQUIRED`. Measured: the
default list shows count 26 and no match, while
`harpp decision list --limit 200 --workbench-state=ARCHITECTURE_DECISION_REQUIRED` returns count 2
including the decision. So a correct read-back MUST pass the same `workbench_state` it submitted
under, or it will report a false absence.

This is the failure mode the contract for the read-back already warned about, now observed: **a
read-back with the wrong filter is the same class of error as trusting the writer, pointed the other
way.** A false absence is as useless as a false claim, and it is more likely to make the harness
abandon a working channel.

## Architectural constraints

- The `workbench_state` value sent and the value filtered on in the read-back must come from ONE
  declared constant, not two string literals that can drift apart. If they disagree, the read-back
  silently reports absence.
- Keep the read-back. Keep suppression checked before success. Keep the local artifact written first.
- The read-back limit must stay large enough that truncation cannot produce a false absence; that is
  already handled and must not regress.
- Do not treat a 422 as an unremarkable failure. It means the harness is sending a malformed request,
  which is OUR bug, not the director being unavailable. The two must be distinguishable in the output
  and in the ledger, because one is "retry", the other is "fix the harness".
- Keep it a single submit followed by a single read-back. No retry loops, no sleeps.

## Files likely affected

- `tools/chair.php`

## Acceptance criteria

- A writer that claims success and persists nothing is still NOT reported as a delivery: `bash tests/chair-delivery-readback-probe.sh`
- The submission carries all six fields the server requires: `php tools/chair.php --self-test`
- The self-test covers that a read-back queries with the same `workbench_state` it submitted under, in both directions — a matching filter finds the decision, a mismatched one does not: `php tools/chair.php --self-test`

## Required tests

- `bash tests/chair-submit-shape-probe.sh`

Also, and they must still pass:

- `bash tests/chair-delivery-readback-probe.sh`
- `php tools/chair.php --self-test`

## Risks

- **Drifting literals.** Two copies of `ARCHITECTURE_DECISION_REQUIRED` is how this breaks again
  silently. One constant.
- **Reporting success from the writer again.** The read-back is the only evidence that counts.
- **Fixing the read-back by widening it until it always matches.** A read-back that cannot fail is not
  a read-back; the mismatched-filter direction must still report absence.
- **Mistaking a 422 for an outage.** They look alike in a log and mean opposite things.

## Forbidden changes

- `kernel/`
- `public/`
- `modules/`
- `tests/browser/`
- `tools/harpp-bridge/`
- Do not weaken, delete or loosen any of the 118 existing self-test checks.
- Do not remove the local-artifact-first write, the suppression check, or the read-back itself.
- Do not delete or edit ledger history.
