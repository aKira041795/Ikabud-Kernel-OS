# HARPP returns: a decision's answer must change execution

## Objective

The chair sends decisions to the director and never retrieves the answer. Measured: 6 decisions
delivered, 0 answers consumed, 0 plans changed as a result. `harpp decision list` shows the decision at
state `PENDING`, and nothing in `tools/chair.php` can ask whether it has been answered.

That makes HARPP reliable messaging, not governance. The milestone is a **round trip**:

```
1. Chair encounters a genuine authority boundary
2. Chair sends the decision request
3. Work stops only on the affected branch
4. Director answers
5. Chair retrieves the answer
6. The answer is bound to the originating decision
7. THE CHAIR ALTERS ITS PLAN ACCORDINGLY          <- the load-bearing step
8. Execution resumes
9. The evidence identifies the director decision that caused the change
```

Step 7 is the point. Receiving an answer is not the milestone; **evidence that the answer causally
changed what happens next** is.

## Architectural constraints

- Add a `resume` command that, for a task with a filed decision, retrieves the director's answer and
  reports it. `harpp decision view <id>` returns the decision with a `state` (`PENDING`, `VIEWED`,
  `DECIDED`) and, once decided, the answer. Read the real shape before parsing it; do not assume fields.
- **Bind the answer to the originating decision.** The `decision_key` is the join. An answer that
  cannot be matched to a filed decision is not an answer, and must not be applied.
- **Distinguish "not yet answered" from "answered".** A `PENDING` decision is not a failure and must
  not be reported as one; it means the work stays parked. Exit codes: answered-and-applied is success,
  not-yet-answered is a clean parked state, and a failure to reach HARPP is a harness fault.
- **Record the causal link.** After applying an answer, the ledger must show which decision changed
  which plan, so step 9 is answerable from the record rather than from memory. A future reader must be
  able to ask "why did this change?" and get the decision id.
- **An unanswered decision must not be applied.** Never apply a default. The policy is
  `default_if_no_response: stop`.
- Report the state loudly enough that the chair can act: which decision, what it says, and what the
  chair should now do differently.
- Do not build a polling daemon or a watcher loop. One retrieval per invocation.

## Files likely affected

- `tools/chair.php`

## Acceptance criteria

- A decision can be retrieved by its task and reported without applying a default: `php tools/chair.php resume --task=retired-tool-authority`
- The ledger records which decision caused which change: `php tools/chair.php --self-test`

## Required tests

- `php tools/chair.php resume --task=retired-tool-authority`

Note on the probe: it is red today because the command does not exist (`unknown command: resume`) and it must be green afterwards WITHOUT filing or applying anything. `retired-tool-authority` is a real decision already delivered and still `PENDING`, so a correct implementation reports it as parked and changes nothing. That is the honest baseline: it proves the retrieval path exists, and the apply-on-answer half is proven by the self-test in both directions.

## Risks

- **Applying a default.** If no answer exists, doing something sensible-looking is the worst outcome:
  it fabricates authority. Park, and say so.
- **Matching on the wrong key.** The decision_key is the join; a task id is not.
- **Reporting success for a retrieval that found nothing.** Same error as the delivery claim that
  trusted the writer: a read that returns no answer is not an answer, and must not be treated as one.
- **Building a watcher.** One retrieval per invocation. A loop is a different feature and not this one.

## Forbidden changes

- `kernel/`
- `public/`
- `modules/`
- `tests/browser/`
- `tools/harpp-bridge/`
- Do not weaken, delete or loosen any of the 121 existing self-test checks.
- Do not change the delivery path or its read-back.
- Do not apply any default when a decision is unanswered.
