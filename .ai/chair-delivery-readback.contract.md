# Delivery must be corroborated by a read-back, not believed

## Objective

`chair.php decide --escalate` reported a delivery that never happened. Measured 2026-09-19:

```
[L4] task=... key=... artifact=...
[DELIVERED] key=...
exit 0, ledger: "delivered": true
```

An independent read-back of HARPP (`harpp decision list --limit 50`) contained **no such key**. The
director was never notified, and the harness said otherwise. That is silent non-delivery: the exact
invariant this code exists to enforce, defeated by its own acknowledgement rule.

The cause is one question asked wrongly. `deliveryAcknowledged()` asks *"did the writer say it
succeeded?"* — it accepts a JSON body with `ok: true` and exit 0. The only question that matters is
***"can I read it back?"*** A writer's own claim is not evidence, in this file as much as in a probe.

Fix the delivery path so a claim of delivery requires corroboration.

## Architectural constraints

- **Corroborate by reading back.** After a submit, query HARPP for the `decision_key` and claim
  delivery only if it is present. If the read-back cannot confirm it, treat the decision as FILED
  LOCALLY AND NOT DELIVERED: print `DELIVERY: local-only — director NOT notified`, print the retry
  command, write `delivered: false` to the ledger, and exit 4.
- **Read the whole list, not the default window.** Measured while diagnosing this: the default
  `harpp decision list` returns **25** entries and a real decision was the 26th, so a read-back with
  the default limit reports a false absence. `--limit` is a supported flag (see
  `harpp decision list --help`). Use a limit generous enough that a false negative cannot occur, and
  say in a comment why the number is what it is.
- **Keep the suppression check, and keep it BEFORE the success check.** The bridge returns
  `{"ok": true, "suppressed": true}` when notifications are disabled — `ok` is true and nobody is
  notified. That trap is already handled and must not regress.
- **Log the writer's response.** Right now the response is captured but only shown on failure, and it
  was uninformative when this broke. Record what the writer actually said so the next failure names
  its own cause instead of requiring an afternoon of archaeology.
- **The local artifact is still written first, always.** A decision that is filed locally and not
  delivered is a filed decision; a decision that is neither is a lost one. Do not reorder this.
- **Do not delete or rewrite existing ledger history.** A false `delivered: true` for
  `retired-tool-authority-20260919-152225` is already in the ledger, with an appended `phase:
  correction` entry superseding it. Leave both. Append-only is the point.
- **A real delivery must still work.** The fix must not make delivery impossible: against the real
  HARPP, a decision that IS persisted must be reported as delivered. Do not "fix" this by always
  reporting failure — that would be a different defect wearing the same clothes.

## Files likely affected

- `tools/chair.php`
- `tests/chair-delivery-readback-probe.sh`

## Acceptance criteria

- A writer that claims success and persists nothing is NOT reported as a delivery (probe: the read-back script).
- The harness exits 4 and prints the undelivered line in that case (probe: the same script).
- The self-test still passes in full: `php tools/chair.php --self-test`
- The suppressed-response trap is still rejected: the self-test covers it.

## Required tests

- `bash tests/chair-delivery-readback-probe.sh`

Also run, and they must still pass:

- `php tools/chair.php --self-test`

## Risks

- **Trusting the reader as blindly as the writer.** A read-back with a limit too small reports a false
  absence, which is the same class of error pointed the other way. It must not be possible for a real
  delivery to be reported undelivered because the list was truncated.
- **Always reporting failure.** The safe-looking fix is to never claim delivery. That would satisfy
  the probe and break the feature. The probe's converse — a genuine delivery being confirmed — must be
  preserved, and the self-test must prove both directions.
- **Parsing the wrong field.** Do not assume the queue's JSON shape; read it and handle absence
  explicitly rather than letting a missing key silently mean "not delivered".
- **Slowing the happy path into uselessness.** A read-back costs a round trip; that is acceptable, but
  do not let it become a retry loop or a sleep.

## Forbidden changes

- `tests/browser/`
- `kernel/`
- `public/`
- `modules/`
- Do not weaken, delete or loosen any of the 117 existing self-test checks.
- Do not remove or reorder the local-artifact-first write.
- Do not delete ledger history, and do not edit the existing correction entry.
