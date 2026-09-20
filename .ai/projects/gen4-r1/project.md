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
| **S1** | the stale `runs_by_status` assertion in the metrics suite | CD-41 (left red for want of authority) | **done** — advanced on the 4th attempt, commit `02a4559` |
| **S2** | declare the last two undeclared write routes (both in `gui-settings`), 45/47 → 47/47 | `docs/architecture/akira-beyond-the-cms.md` §P2, via `.ai/authority-route-coverage-akira.contract.md` | work **complete and shipped** (census 47/47); run **blocked** on evidence admissibility — see CD-46/CD-47 |

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

## Results — S1

**The prediction was WRONG, and worth the price of being wrong.** S1 was **advanced**, not blocked, on its 4th
attempt. The absolute prohibition never fired on the path — the pre-declared `exceptions:` entry (`CD-44`,
`scope: tests/ai_project_metrics_test.php`) was consulted and the correction went through the CD-22 route.

That is a real result about the apparatus: **the prohibition is not a wall, it is a gate with an authority
route**, and the route works for the exact case CD-31 said the matcher could not see. It does mean S1 did not
measure what it was chosen to measure — the block it produced was evidentiary, not authoritative.

**What blocked it instead, three times, was contract authoring.** No attempt ever contradicted a claim or
violated scope: `SCOPE OK delta=0` and every claim that was declared re-derived. Each block came from the
report's *shape*:

| attempt | block | cause |
|---|---|---|
| 1 | 3 claims, 1 unbacked | the criterion demanded a non-vacuity demonstration and supplied no command for it |
| 2 | 2 claims, 1 unbacked | a numbered `### Evidence` item bound as a phantom claim via `parseProseClaim` |
| 3 | 2 claims, 1 `UNVERIFIED` | the phantom claim declared `failed:0` — a key the suite's stdout never prints |
| 4 | **advanced** | criterion supplies the command; report shape given literally; only emitted keys declared |

**The distribution so far, at n=1 slice: 1 completion, 0 blocks on authority, 3 blocks on evidence shape, 4 runs,
1 lane, 2223s wall clock.** Contract-authoring cost every failure; the apparatus caused none. Recorded in
`chair-errors.json` as CE-01…CE-07 rather than left implicit.

**Finding that touches this project's own premise: the programme briefs are partly stale.**
`.ai/akira-mutation-cache-invalidation.contract.md` describes work that is **already merged** —
`modules/cms-akira/cms-akira-core/helpers/capabilities.php:787-791` already has the post-commit,
`function_exists`-guarded, fail-open invalidation D1/D2/D3 ask for, with a test beside it. Slice provenance
therefore needs a **measurement, not a reading**: candidates are screened against the census/gate that judges
them before a run is spent. S2 was chosen that way — the census named the gap, not the brief.

