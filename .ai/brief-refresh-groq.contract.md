# SLICE E — refresh the independent evaluation brief to today's reality

project: harness-guardrail · status: READY_FOR_IMPLEMENTATION · revision: 1
repo: `/var/www/html/ikabudsix`
lane: groq/openai/gpt-oss-120b
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "groq/openai/gpt-oss-120b", "--name", "brief-refresh-groq", "<CONTRACT>"]

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

authority: owner instruction 2026-09-14 — *"update. use groq, so we can test it's capabilities as a model."*
directive: **you hold the decision.** Decide, record, continue (CD-8).

## Objective

`docs/reviews/harness-independent-evaluation-brief.md` is a briefing for an independent senior engineer
judging this governance layer. It was written **before** today's work and is now wrong in ways that flatter it
in some places and undersell it in others. Bring it to the truth.

**This is a documentation task, and the risk in a documentation task is invention.** A brief that misstates its
own measured numbers is worse than no brief, because its entire value is that a stranger can reproduce every
claim. So:

> **Every number in the updated brief must either be re-measured by you, right now, or cited from a named
> artefact with its path.** Copying a number from this contract, from the old brief, or from
> `.ai/chair-decisions.md` prose *without re-running it* is a failure — say so and mark it
> `NOT RE-MEASURED` instead.

## Architectural constraints

- **The honest-limits section must GROW, not shrink.** This is the most important constraint. Closed items move
  to a *closed* state **with the evidence that closed them**; they are never deleted. An updated brief that
  quietly drop its own admissions is the exact failure this document exists to prevent.
- **Preserve the document's culture verbatim.** Keep: *"treat every claim in §2 as false until you reproduce
  it"*, the verdict template, and the glossary definition of the Chair (*"the delegated project authority
  beneath the contract; decides, records, continues"*).
- **Falsifiability is the product.** Every claim in §2 needs a command a stranger can paste. Do not add a claim
  you cannot make executable.
- **Do not overstate.** Where something is demonstrated once, say once — not "proven". Where something is
  designed but unbuilt, say so. Where a number was measured under different conditions, say which.
- **Do not claim independent verification you did not perform.** You are documenting; you are not the
  independent reviewer. Your own measurements are *your* re-measurements and must be labelled as such.
- No new capability is being added. This slice changes **one document**.

## Files likely affected

- `docs/reviews/harness-independent-evaluation-brief.md`

## Sources of truth to read before writing

- `.ai/chair-decisions.md` — the decision record. It now runs **well past CD-11**; read to the end. The
  decisions from CD-21 onward describe the trust-surface invariant, the exception rules, the ladder, and the
  defects found by review.
- `.ai/runs/` — one JSON record per run, plus reports. This is the measurement source for run statistics.
- `.ai/trust-surface-amendments.json` — amendments to the verifier, each with its director decision.
- `git log --oneline` — the commits, including today's.
- `php tools/ai-autonomy.php models`, `plan`, `check` — live behaviour, not description.
- `php tools/ai-run.php commit-check`, `claims`, `verify` — the evidence machinery.
- `php tools/ai-project.php status --project=harpp-gen4` — project state.

## Deliverables

### D1 — Refresh the measured facts

The components table, the suite assertion counts, the CD range, the commits under review, and the §2
expected/measured values are all stale. Re-measure each and update. Where the old brief records a *measured*
value, keep the old and add the new so a reader sees the movement — a bare substitution loses the evidence
that anything changed.

### D2 — Add the capabilities that did not exist when it was written

The brief describes a harness where a contract cannot authorise an absolute prohibition (C2) and where run
state is recorded rather than inferred (C5). Since then the following exist and are **falsifiable**, so each
belongs in §2 as a claim with a real command:

- the **verifier's trust surface** is not contract-authorisable (four ranked rules: unreachable, loud, fail
  closed, director-only);
- a **director route** exists for amending it, and it refuses without a named recorded decision;
- the loop enforces **scope conformance** against the contract envelope, comparing a dispatch-time baseline;
- a **bounded repair ladder** exists (L1 repair / L2 re-lane / L3 re-decompose / L4 stop) that promotes rather
  than replays;
- the command allowlist now executes **more than one language**'s evidence, with the executable taken from the
  matched rule rather than from the command text;
- the harness **declares the files it writes** on a run's behalf, so the scope gate can separate what the
  executor touched from what the harness wrote.

For each: a paste-able command, the expected exit code, and what a **failure** would look like. A claim whose
failure mode is unspecified is not falsifiable.

### D3 — Update the honest limits, keeping every one

§3 is the most valuable section in the document. For each existing limit, state whether it is **open**,
**partially closed**, or **closed by today's work** — with the evidence for a closure. Then **add** the limits
today's work produced, including at least:

- **Repeatability is unmeasured.** One slice ran under the full guardrail set. A completed project is not the
  same evidence as a streak.
- **One vacuity path is open**: a verifier consisting of a command that always exits 0 is accepted as
  evidence, so a vacuous verifier advances a job.
- **The trust surface is defined by file, not by semantic role**, so improving the harness's own plumbing
  repeatedly required director authorisation. Count how many amendments `.ai/trust-surface-amendments.json`
  records and state it.
- **A block must be adjudicated by class**: a block concerning *what the work touched* is the Chair's to
  decide; a block concerning *whether the work proved itself* is not, because completion is a claim and not a
  Chair decision.
- **A model's self-description is not evidence.** Observed while preparing this slice: a lane invoked as one
  model id replied with a different model name. The authoritative record of which lane ran is the `--model`
  argument and the run record's `lane` field — never the model's account of itself. Same class as the
  marker-versus-verifier problem.

### D4 — Refresh §4 and §7

- §4's defects table lists six defects from the earlier session. Add the ones found since — the reviewed
  defects and the plumbing defects — with how each was found. **Include findings the harness made against
  itself and against the Chair**, not only against the executor.
- §7's disposition records an earlier review. Add a dated update covering today: what was built, what the
  HARPP project run demonstrated, and what remains.

## Acceptance criteria

- **AC1** — the brief parses as markdown, the section structure is preserved, and no section was removed.
- **AC2** — every claim in §2 has a paste-able command, an expected exit code, and a stated failure mode.
- **AC3** — §3 contains **every** limit it had before, plus the new ones above. Show the before/after count so
  a reader can see it grew rather than shrank.
- **AC4** — no number appears without either a command you ran in this session or a named artefact path.
  List any number you could not re-measure as `NOT RE-MEASURED`.
- **AC5** — the verdict template, the §0 culture sentence, and the Chair glossary line are present verbatim.
- **AC6** — you changed **only** the brief. Show `git status --porcelain` listing no other modified file.

## Required tests

Documentation, so the "tests" are the claims' own commands. Run the ones you cite. **Do not run
`scripts/run-tests.php`, `composer test`, or anything that bootstraps the CMS app** — a full run poisons the
APCu module-scan cache and 503s the live tenant. Pure suites only, and only if you cite them.

## Report format — required

```
CLAIM: <short assertion>
COMMAND: <exact command, copy-pasteable>
OBSERVED: <verbatim output, including the exit code>
```

Report path: `.ai/brief-refresh-groq.report.txt`. Include a list of every number you could **not**
re-measure, and say which artefact you cited instead.

## Risks

- **Invention.** The dominant risk. If a section cannot be made truthful, say so in the brief rather than
  filling it plausibly.
- **Flattering the system.** The brief's value is that a stranger can disprove it. Softening a limit to make
  the system look better destroys the document's purpose.
- **Silent deletion.** Removing a limit or a defect because it is now inconvenient is the specific failure
  AC3 guards against.

## Forbidden changes

- `.github/`
- `.ai/ai-autonomy-harness.contract.md`
- `kernel/`
- `modules/`
- `src/`
- `tools/`
- `tests/`
- `scripts/`
- `phpstan.neon`
- `phpstan-baseline.neon`
