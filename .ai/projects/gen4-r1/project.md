# PROJECT — gen4-r1: measure the distribution, with the apparatus frozen

status: READY_FOR_IMPLEMENTATION
repo: `/var/www/html/ikabudsix`
revision: 1
references: `.ai/ai-autonomy-harness.contract.md`
authority: owner submission 2026-09-14 adopted as **CD-41** — *close B-F1, freeze the architecture, then measure
10–20 ordinary slices before changing anything else.*

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

## Objective

**Measure what the harness actually does on ordinary work, with the rules held still.**

This project builds nothing. Its product is a **distribution**: how many slices complete unattended, how many
need a repair and at which rung, how many block and on what, how often a claim contradicts, and how much owner
attention the whole thing costs.

The governing constraint, recorded before the first dispatch because a discipline asserted afterwards is a
rationalisation:

> **During GEN4-R1 the harness does not change because a slice failed.** Failures are **data**. Repair resumes
> after analysis, informed by the distribution rather than by the first failure encountered.

The failure mode this prevents, named by the assessment: *"otherwise HARPP will perpetually pass because HARPP
changes after every failure."* That is the same structure as a check that cannot fail, one level up — applied to
the process.

## Why the freeze matters more than it sounds

Today the Chair's habit was *discover defect → fix harness → continue*, and every one of five such fixes was
the right call at the time. **The habit is correct during development and fatal during measurement**, because a
harness that changes after each failure can never fail the same way twice. So the apparatus is frozen at
B-F1-close, and this project's job is to find out where it breaks **without repairing it**.

## What is counted (not fixed)

| observation | source |
|---|---|
| completions / blocks / promotions by rung | `.ai/runs/*.json`, loop event stream |
| claim outcomes, and **every contradiction** | run records' `claim_verification` |
| **interpretive drift** — Chair decisions, which affected acceptance, which were later challenged or reversed | `.ai/chair-decisions.md` |
| cost and tokens, or an explicit `null` with a reason | run records |
| owner attention, in minutes | director-minutes, if logged |

**Interpretive drift is deliberately not solved, only measured.** We do not yet know whether the Chair
reinterpreting a contract is rare or HARPP's dominant failure mode, and building a complicated answer before
knowing would be the expensive mistake.

## Slice provenance — stated because it bounds what this measures

Slices are drawn from work the programme has **already specified** (the `akira-*` briefs and
`authority-*` contracts in `.ai/`), not invented by the Chair. A measurement taken on self-authored busywork
would measure the harness against itself, which is the one thing this project must not do.

## Slices

| id | slice | source | status |
|---|---|---|---|
| **S1** | the stale `runs_by_status` assertion in the metrics suite | CD-41 (left red for want of authority) | pending |
| later | further slices, drawn from the programme briefs | programme briefs | pending |

**Do not write a placeholder slice id in the table above.** CD-34 recorded that the id scanner cannot tell a
declaration from a reference, and it bites a third time here: an earlier draft of this table said "S2+" for the
second row, which the project then reported as a real slice with one obligation. A row that names no id cannot
be mistaken for one.

**S1 is deliberately first because it is expected to fail**, and a predicted failure is the best possible
opening data point: it tests whether the harness can distinguish *"correcting a stale assertion"* from
*"weakening a test"*. CD-31 says the matcher cannot — it fires on the **path**, not the change. GEN4-R1 exists to
find out what that costs under the frozen rules.

## Prediction, recorded before the run so it can be scored

S1 will be **blocked**, not advanced, by the absolute prohibition `disabling, skipping, deleting or weakening an
existing test or gate to get a pass` — because the change edits an existing test file and the matcher cannot see
that the edit corrects an expectation that no longer describes the code. The loop stops the project at the first
blocked slice, so **S1 will also end the run.**

If that prediction is wrong, the matcher is smarter than CD-31 recorded and that is worth knowing too.
