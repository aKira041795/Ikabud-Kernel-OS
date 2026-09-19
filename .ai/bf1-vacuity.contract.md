# SLICE F (GEN4-R1 P0) — a verifier that cannot fail is not a verifier

project: harness-guardrail · status: DIRECTOR_AUTHORISED (CD-41) · revision: 1
repo: `/var/www/html/ikabudsix`
lane: deepseek/deepseek-v4-flash
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "deepseek/deepseek-v4-flash", "--name", "bf1-vacuity", "<CONTRACT>"]

## THIS CONTRACT WILL BE REFUSED BY `plan`. THAT IS EXPECTED — READ FIRST.

**Authority: owner submission 2026-09-14, recorded as CD-41** — the external assessment's sequencing
recommendation, adopted: *close B-F1 first, then freeze the architecture, then measure*. The assessment's
reason is decisive and is quoted here so it survives without the surrounding document:

> *"You risk collecting 20 successful runs against a verifier whose evidence semantics you already know contain
> a hole."*

This contract names the trust surface, so `plan` **must** exit 2. **That refusal is rule 1 working, and it is
your first claim.**

**This is the LAST trust-surface change before the freeze (CD-41).** After it lands, the architecture is frozen
for the GEN4-R1 experiment and failures become data rather than repair triggers. So the fix must be the complete
one, not a partial one that the experiment would then be measuring around.

## Objective

A stage whose `verify` command is `true` passes, and its pass is recorded as evidence. **A command whose exit
code is independent of the state carries no information**, so a vacuous verifier advances a job while proving
nothing — the false-confidence path that every other guard this session exists to prevent.

**The governing principle, which is the general fix and not just this bug:**

> **A verifier must be shown capable of failing before its pass counts as evidence.**

That is the same discipline already enforced on tests all session (*"every new assertion must fail when its fix
is reverted"*) — applied one level up, to the verification commands themselves.

## Architectural constraints

- **Additive to the manifest format.** Existing keys keep their meaning; document any new key. Do not silently
  reinterpret `verify`, `marker`, or `evidence`.
- **Do not over-block.** The deny-list targets **constant-true** shapes only. A *weak* verifier (e.g.
  `git diff --check`, which checks patch whitespace rather than correctness) is **not vacuous** — it can fail —
  and must not be rejected by the deny-list. Weakness is answered by the negative control, not by refusal.
- **Unconstructible is a finding, not a pass.** If a negative control genuinely cannot be authored for a stage,
  the stage must be marked explicitly and its pass recorded as **`UNPROVEN`** — visible in the record, never
  silently accepted. An honest "we cannot show this verifier can fail" is worth more than a fabricated control.
- Python 3 and PHP 8.3 compatible as applicable. No new runtime dependency.
- **Do not weaken any existing bridge test.** If a test depends on marker or verify behaviour, a change must be
  justified in these terms and disclosed.

## Files likely affected

- `tools/harpp-bridge/harpp_wake.py`
- `tools/harpp-bridge/workflows/`
- `tools/harpp-bridge/tests/test_harpp_wake.py`
- `tools/harpp-bridge/README.md`

## Deliverables

### D1 — Constant-true `verify` shapes are refused

A `verify` that cannot fail is refused at manifest validation, with a message naming the shape and the reason.
Cover at least: `true`, `:`, `exit 0`, `/bin/true`, a bare `echo …`, and `test -n ""`. The list is a
**deny-list of constant-true shapes**, not a heuristic about weak ones — a shape not on the list still has to
survive D2.

### D2 — A negative control is required for `evidence: required`, and it is load-bearing

Each such stage declares a negative control: something that **must fail**. The runner executes it and requires
a non-zero exit.
- If the negative control exits **0**, the stage does **not** pass; the run records the control as
  `NOT_FALSIFIABLE` and the stage result as `UNPROVEN`.
- If the negative control exits non-zero and the real `verify` passes, the stage passes as today.

**This is the whole fix.** Without D2, D1 only catches the shapes somebody thought to list.

### D3 — The shipped manifests carry working controls

Update the six shipped workflow manifests so every `evidence: required` stage has a negative control that
genuinely fails. Where a control cannot be constructed, mark the stage explicitly and say why — **do not invent
a control that passes for the wrong reason, and do not delete the stage.**

### D4 — Confirm the PHP side is not vacuous, and say how

`tools/ai-run.php` executes an **allowlist of fixed shapes**. For each shape, state what state makes it
**fail** — demonstrating that no shape is constant-true by construction. If any shape *cannot* fail, that is a
finding: report it rather than fixing it here (it is a separate change, and this is the last one before the
freeze).

### D5 — Non-vacuity by revert

For each of D1 and D2, state which assertion fails when the fix is reverted, and paste it.

## Acceptance criteria

- **AC1** — `verify: "true"` (and each other D1 shape) is **refused** or recorded `UNPROVEN`; paste the real
  message and exit code. Reverting D1 must fail a named assertion.
- **AC2** — a stage with `evidence: required` and **no** negative control does not pass.
- **AC3** — a stage whose negative control exits **0** does **not** pass, and the record says `NOT_FALSIFIABLE`
  / `UNPROVEN`. **This is the assertion that proves the control is load-bearing rather than decorative.**
- **AC4 (positive control — as important as AC3)** — a legitimate stage with a real `verify` and a real failing
  negative control **still passes**. A fix that blocks everything is as useless as one that blocks nothing.
- **AC5** — the bridge's test module passes with **zero failures**, and every pre-existing behaviour still holds:
  a marker-only stage still FAILS; `evidence: "none"` still cannot hide a configured `verify`; the manifest
  validators still pass for the shipped set.
- **AC6** — the change is additive: `grep` the manifests before/after and show that no existing key changed
  meaning.

## Required tests

Python for the bridge, run **through the new evidence shapes** where needed, with `PYTHONDONTWRITEBYTECODE=1`.
Pure PHP suites only if you touch PHP. **Do not run `scripts/run-tests.php`, `composer test`, or anything that
bootstraps the CMS app** — a full run poisons the APCu cache and 503s the live tenant. Write logs to `/tmp`,
never into the repository.

## Report format — required

```
CLAIM: <short assertion>
COMMAND: <exact command, copy-pasteable>
OBSERVED: <verbatim output, including the exit code>
```

Report path: `.ai/bf1-vacuity.report.txt`. First claim: the `plan` refusal of this contract.

## Risks

- **A partial fix that reads as complete.** Catching `true` but not `git diff --check`-style weakness would
  leave the hole open while the record claims it closed. D2 exists for this reason; say plainly in the report
  what remains **unproven** rather than implying full coverage.
- **Over-blocking the shipped manifests.** Making every existing stage fail would be a change that "passes" its
  tests while breaking the subject. AC4 guards it.
- **Fabricated controls.** A negative control that passes is worse than none, because it looks like evidence.
  AC3 guards it.

## Forbidden changes

- phpstan.neon
- phpstan-baseline.neon
- .github/workflows/
- scripts/
- modules/
- src/
- kernel/
- tools/ai-run.php
- tools/ai-autonomy.php
- tools/ai-loop.php
- tools/ai-project.php
- .ai/projects/
