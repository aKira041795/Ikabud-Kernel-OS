# CONTRACT — Repair: the driver must parse the real HARPP bridge response shapes

status: READY_FOR_IMPLEMENTATION
repo: `/var/www/html/ikabudsix` — branch `feat/akira-editorial-and-authority-coverage` (do not switch)
references: `.ai/ai-autonomy-harness.contract.md` (standing harness contract — read it first)
authority: this defect was found by a delegate's verification of
`.ai/harness-acceptance-a-simulated-harpp.contract.md`, which correctly **stopped** rather than
bend a faithful simulator to fit a broken parser. The repair is a defect fix, not a change of design.

## Objective

`tools/ai-autonomy.php` parses HARPP responses using shapes that were **invented**, and
`tests/ai_autonomy_test.php` encodes the *same* invented shapes — so 23/23 passed and the defect
shipped. Make the driver parse the **real** bridge envelope, and make the test assert the real shapes so
the same class of error cannot pass again.

This is why the simulator could not be built: a faithful simulator returns the real shapes, and the
current driver cannot read them.

## Verified facts — do not re-derive, but DO confirm by reading

The API envelope is `{"ok": …, "data": { … }}`:
- `harpp_client.py:107` — `data = response.get("data")`
- `harpp_client.py:254` — `data = response.get("data") if isinstance(response, dict) else None`
- `harpp_wake.py:1730` — `pending.get("data", {}).get("deploys", [])` (nested unwrap precedent)

The list envelope and the row field names come from HARPP's own CLI watch loop (`harpp`, ~line 350):
```python
page = harpp_client.list_decisions(limit=100, …).get("data", {})
rows = page.get("decisions", []) or []
… d.get("decision_key"), d.get("title"), d.get("decision"), d.get("rationale"),
  d.get("decided_by"), d.get("lifecycle_state")
```
So: **`{"data": {"decisions": [ … ]}}`**, and the lifecycle field is **`lifecycle_state`** — not `state`.

`harpp_client.submit_decision` (`:329-343`) returns the raw `api(...)` response, so the new id is at
`data.id`. **Confirm this by reading the client**; if the server-side shape for submit cannot be proven
from the repository, implement extraction tolerant across `data.id`, `id`, `decision_id` and
`decision.id`, and say so in the report rather than asserting one.

Sites to repair in `tools/ai-autonomy.php`:
- `remoteRows()` — lines 166-175: unwraps exactly **one** level. Given `{"data":{"decisions":[…]}}` it sets
  `$value = {"decisions":[…]}` (a map, not a list, no `decision_key`) and returns `[]`.
- `deliverDecision()` — line 399: reads root `id`, root `decision_id`, `decision.id`. With `data.id` the
  transport records `harpp_decision_id = null`.
- State reads — line 474 (`resume --from-harpp` DECIDED match) and line 507 (`status --remote` merge):
  read `state`; the real field is `lifecycle_state`, so the match can never succeed and the merged
  `harpp_state` is always null.

The current test's stub encodes the invented shapes — `tests/ai_autonomy_test.php` ~line 85 emits a bare
JSON list `[['id'=>77,'decision_key'=>…,'state'=>'DECIDED',…]]` and ~line 88 emits `['ok'=>true,'id'=>77]`.

## Deliverables

### R1 — `remoteRows()` parses the real envelope

Unwrap `data`, then `decisions` (nested), and remain tolerant of: a bare list of rows, a single row
object, and a flat `{"decisions":[…]}`. A row is recognised by carrying `decision_key` **or** an integer
`id`. Keep it a small pure function with the existing docblock style.

### R2 — the submitted remote id is captured

Read the id as `data.id` first, then root `id`, `decision_id`, `decision.id`. Record it in
`transport.harpp_decision_id` as today. A real `{"ok":true,"data":{"id":77,…}}` must yield `"77"`.

### R3 — the lifecycle field is `lifecycle_state`

`resume --from-harpp` must match on `lifecycle_state === 'DECIDED'` (accept `state` as a fallback only so
an alternate shape still works). `status --remote` must report the merged `harpp_state` from
`lifecycle_state`. Do not rename the driver's own output field.

### R4 — the test asserts the REAL shapes, and gains regression cases

Correct the stub's shapes to the real ones and add cases that would have **failed before this repair**:

1. `defer` against a stub returning `{"ok":true,"data":{"id":77}}` → exit `0`, `DELIVERY: harpp`, and the
   written JSON has `transport.harpp_decision_id === "77"`.
2. `resume --from-harpp` against a stub returning
   `{"ok":true,"data":{"decisions":[{"id":77,"decision_key":"<id>","lifecycle_state":"DECIDED","decision":"a","rationale":"…"}]}}`
   → exit `0`, resolves, and the stub log shows `decision ack` **then** `decision apply` in that order.
3. A negative case: the same row with `"lifecycle_state":"NOTIFIED"` → `resume --from-harpp` exits `2`
   with a message naming `decision_key` (a not-yet-decided decision must not be applied).
4. `status --remote --json` against the real nested list envelope → the row's `harpp_state` is
   `DECIDED` (this is the assertion that fails today, producing null).

All existing cases must keep passing — **with the corrected shapes**. Do not delete or relax an existing
assertion to accommodate the change; if one now fails, the driver is still wrong.

### R5 — record the before/after honestly

In the report state plainly that the pre-repair driver parsed the nested envelope to zero rows (derived by
reading `remoteRows()` lines 166-175), and that the new cases use exactly that shape. If you can cheaply
demonstrate the old behaviour (e.g. by running the new test against the pre-repair file copied aside),
include that transcript; if not, say it was derived by reading and do not claim a run.

## Architectural constraints

- This changes only response **parsing** and the test's stub shapes. The CLI surface, exit codes
  (`0`/`2`/`3`/`4`), the decision schema, and the delivery semantics are unchanged.
- Tolerance is defensive only: the *primary* assertions must use the real shapes, not a convenient one.
- Do not weaken, delete or skip an existing test case to reach green. A case that used an invented shape
  must be corrected, not removed.
- No new dependency; no network; no `~/.config/harpp` access.
- The standing contract's seven required headings and its path-first bullets must stay intact.

## Files likely affected

- `tools/ai-autonomy.php` — the three parsing sites (R1-R3)
- `tests/ai_autonomy_test.php` — corrected stub shapes and four new cases (R4)

## Acceptance criteria

- A stub returning `{"ok":true,"data":{"decisions":[{…,"lifecycle_state":"DECIDED"}]}}` makes
  `resume --from-harpp` resolve the decision, with `ack` then `apply` in the stub log.
- A stub returning `{"ok":true,"data":{"id":77}}` yields `transport.harpp_decision_id === "77"`.
- `status --remote` reports `harpp_state: DECIDED` from the nested envelope.
- `lifecycle_state: NOTIFIED` is refused by `resume --from-harpp` with exit `2`.
- Every previously passing case still passes; none was relaxed.
- `php tests/ai_autonomy_test.php` exits `0`; report passed / failed / skipped and the case count.

## Required tests

- `php tests/ai_autonomy_test.php` — exit `0`, with the four new cases present.
- `php -l tools/ai-autonomy.php`.
- `vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G tools/ai-autonomy.php tests/ai_autonomy_test.php`
  — report the real output; judge it against the CI-parity path set.
- `php tools/ai-autonomy.php plan --contract=.ai/ai-autonomy-harness.contract.md` → exit `0` (no parser
  regression).

## Risks

- Over-tolerance could mask a genuinely malformed response; keep the failure path loud (exit `2` with the
  offending `decision_key`) rather than silently resolving nothing.
- Teaching the test the real shapes without fixing the driver would make the suite green and the product
  broken — the opposite of the intent. Fix the driver first, then the test.
- The submit-response shape is inferred from the list shape; if it cannot be proven, say so and stay
  tolerant rather than asserting.

## Forbidden changes

- `kernel/Workbench/Development/` — no edit to those classes.
- `kernel/Workbench/Schemas/` — no schema change.
- `phpstan-baseline.neon` — no edit to the quality-gate baseline.
- `composer.json` — no new PHP dependency.
- `package.json` — no new Node dependency.
- `.github/workflows/` — no edit to CI workflow definitions.
- `.ai/harpp-sim/` — do not create the simulator in this slice; it is a separate contract, re-run after
  this repair.
- `git add` — no staging, commit, push, branch creation or switching (a rule, not a path).
