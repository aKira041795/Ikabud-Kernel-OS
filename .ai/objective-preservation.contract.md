# SLICE — Objective preservation: meta-work must not displace object-work

project: akira-completion · status: READY_FOR_IMPLEMENTATION · revision: 1
milestone: 1 · phase: harness behaviour (director directive, 2026-09-16)
repo: `/var/www/html/ikabudsix`
lane: openai-codex/gpt-5.6-sol
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "openai-codex/gpt-5.6-sol", "--name", "objective-preservation", "<CONTRACT>"]

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

authority: the director's directive of 2026-09-16, verbatim: *"Meta-work must not displace object-work."* and *"If HARPP
can perform the next safe, authorized, reversible action toward the contracted outcome, it MUST prefer that action over
further governance analysis."* This slice encodes that rule and nothing else.

## Objective

The harness converted an outcome (*"complete the Akira CMS plan"*) into a different optimisation target (*"prove the
machinery governing completion is sufficiently specified"*). The rules that were written to protect execution began to
compete with it: authority question → contract interpretation → measurement gap → instrumentation → policy question →
director decision → contract amendment → new measurement → the next governance issue. Three verified Akira slices sat
uncommitted while a ledger question was adjudicated (CD-59); a caller-widening gap was escalated rather than recorded
(CD-58); four dispatch attempts died on harness mechanics (CD-46/CE-08).

**Deliver the rule that stops it, in the normative policy the harness reads — not a new pillar, not a new measurement,
not a new mechanism.** The smallest change that alters behaviour.

## What to build

### 1. The policy section (`.github/instructions/ai-autonomy-escalation.instructions.md`)

Insert a new `##` section titled **`## Objective preservation — meta-work must not displace object-work`** immediately
after `## Drift, defined against the contract` (i.e. before `## Chair decisions are recorded, not escalated`). It must
contain, in substance and in this order:

1. **The invariant.** The contracted objective (the product plan) is the object-work. Contract interpretation,
   authority checks, measurement, telemetry and verification are meta-work that exists to support it.
2. **The operative rule, normative:** if the harness can perform the next safe, authorised, reversible action toward
   the contracted outcome, it **MUST prefer that action over further governance analysis**. The governing question is
   *"what is the next thing I am already authorised to do?"* — not *"is everything sufficiently defined for me to
   proceed?"*
3. **The two lists**, as explicit MAY / MUST NOT clauses:
   - meta-work **MAY**: enable execution · protect an invariant · produce required evidence · resolve an actual blocker;
   - meta-work **MUST NOT**: become a substitute for execution · expand merely because a possible governance weakness
     exists · suspend authorised reversible work · turn every discovered ambiguity into director consultation.
4. **Recorded limitation ≠ stop.** A discovered limitation is recorded and the work continues; it becomes blocking
   only when the defect actually prevents safe execution or credible final verification.
5. **The forcing function, normative:** a harness change — new doctrine, pillar, measurement, contract refinement or
   Workbench feature — is justified only when a real project run demonstrates that its **absence blocked execution**.
   Every proposal must answer *"did the project actually stop because this was missing?"* If no, it is backlogged, not
   built. The product plan is the forcing function.

State explicitly, in the section, that it **softens no absolute prohibition** in the file and **moves no L4**.

### 2. The Chair remit (`.ai/ai-autonomy-harness.contract.md`)

In `## Project handover — the Chair's standing remit`, add a compact statement of the same rule (three or four lines)
so every dispatched lane inherits it, with a pointer to the normative section. Change nothing else in that file.

### 3. The guard (`tests/harness_objective_preservation_test.php`, new)

Prose that can be silently deleted is not a rule. Write a root test that fails if it is:

- asserts the section heading exists in the normative file;
- asserts the operative rule sentence (the MUST-prefer wording) is present;
- asserts each of the four MUST-NOT clauses is present;
- asserts the forcing-function question is present;
- asserts every pre-existing **Absolute prohibitions** bullet (all five, verbatim) is still present, so a future edit
  that softens one fails the test rather than passing quietly;
- asserts the compact rule is present in `.ai/ai-autonomy-harness.contract.md`.

Follow the existing root-test style (`tests/ai_autonomy_test.php` is the closest sibling — read it first for the
harness's own conventions: bootstrap, `$check()`/counters, exit code). A test that does not fail when the section is
removed is not a guard — say in the report how you proved it fails (temporarily remove the heading, observe red,
restore).

## Architectural constraints

- **Do not add a mechanism, a pillar, a metric, a Workbench feature or a new contract.** This slice is one policy
  section, one pointer, one guard test.
- **Do not delete or reword any existing rule.** The diff for the normative file must be an insertion only. If you
  believe an existing line must change, **stop and report** with the line and the reason.
- Do not weaken, reorder or reinterpret the authority ladder, the absolute prohibitions, or the deferred-decision
  contract.
- Do not edit `.ai/dispatch-lane.sh` — a shell script being read by a live process must not be edited (bash reads its
  script by byte offset). The lane prompt change is a separate, deliberately deferred slice.
- `php -l` on the new test file. PHP 8.2-compatible syntax. No new dependency.

## Files likely affected

- `.github/instructions/ai-autonomy-escalation.instructions.md` — the new normative section (insertion only)
- `.ai/ai-autonomy-harness.contract.md` — the compact rule in the Chair remit (insertion only)
- `tests/harness_objective_preservation_test.php` — new root test (does not exist at dispatch)

## Acceptance criteria

1. The section exists with that exact heading, immediately after `## Drift, defined against the contract`, and contains
   all five elements listed above.
2. `git --no-pager diff --numstat .github/instructions/ai-autonomy-escalation.instructions.md` shows **0 deletions**.
3. The five absolute-prohibition bullets are byte-identical to their pre-slice content.
4. `.ai/ai-autonomy-harness.contract.md` carries the compact rule in the Chair remit and shows **0 deletions**.
5. The new test passes, and was demonstrated **red** before the section was written (state how).
6. `php tests/ai_autonomy_test.php` passes unmodified — the policy change does not disturb the autonomy driver's own
   contract.
7. Every changed file is inside the approved scope.

## Required tests

**Every command here must be an admissible shape** — `php tests/<name>.php`, `php modules/<mod>/tests/<name>.php`,
`php -l <file>.php` — because an unlisted command binds no claim at all (`tools/ai-run.php:COMMAND_ALLOWLIST`).
Report each on its own `$`-prefixed line with its result lines beneath it, **unchained**.

```
$ php tests/harness_objective_preservation_test.php
$ php tests/ai_autonomy_test.php
$ php -l tests/harness_objective_preservation_test.php
```

## Additional evidence (binds no claim — still required)

```
$ git --no-pager diff --numstat .github/instructions/ai-autonomy-escalation.instructions.md .ai/ai-autonomy-harness.contract.md
$ git --no-pager diff -- .github/instructions/ai-autonomy-escalation.instructions.md
```

Paste the deletion counts and the diff of the normative file. Both files must show `0` deletions.

## Risks

- **Rewriting instead of inserting.** The value of this slice is that nothing existing moved. A "cleaner" rewrite of a
  neighbouring section is out of scope and will be rejected.
- **Writing doctrine the director did not ask for.** Do not add enforcement mechanisms, stop-report extensions,
  telemetry or checklists. If you think one is needed, record it as a **backlog item in the report** — that is exactly
  the behaviour this rule prescribes — and do not build it.
- **A vacuous guard.** A test that asserts a string exists in a file it also just wrote is only useful if it fails when
  the string is removed. Prove the red case.

## Forbidden changes

- `kernel/`
- `src/`
- `modules/`
- `tools/`
- `config/`
- `.github/workflows/`
- `.github/copilot-instructions.md`
- `.ai/dispatch-lane.sh`
- `.ai/chair-decisions.md`
- `.ai/akira-master-plan.md`
- `.ai/akira-completion-plan.md`
- `phpstan-baseline.neon`
