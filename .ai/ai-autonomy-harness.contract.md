# CONTRACT — Bounded autonomy: unattended scoped phases + structured decision deferral

status: READY_FOR_IMPLEMENTATION
repo: `/var/www/html/ikabudsix` — branch: `main` (work in the tree; do not create or switch branches)
owner: implementation agent (Sol, via Pi)
chair: this session · authority: product owner directive 2026-09-13 — "more autonomy but still bounded
by an approved task; only important decisions deferred to the manager with suggested options"

Read first: `.github/instructions/ai-development-execution-handoff.instructions.md` (§12 Implementation
Autonomy, §13 Bounded Repair Loop, §14 Implementation Result) — this slice adds the missing enforceable
half of those sections. Do not restructure that document; make only the additive edits listed in A5.

## Objective

Make the development harness run the scoped phases of an approved task contract **unattended**, and make
every decision that is genuinely the director's a **structured, machine-checkable artifact with options**
instead of free-form prose buried in run logs. Today `ARCHITECTURE_DECISION_REQUIRED` is only a status
string (`kernel/Workbench/Development/DevelopmentLifecycle.php:29`) and the `harpp_submit_decision` bridge
agents try to call is absent (see `.ai/read-authority-extension.flash-run.log`), so escalations are
unactionable. Close that gap with a policy, a schema, and a driver that fail closed.

## Verified facts — do not re-derive

- `DevelopmentTaskContract::parseCurrentTaskMarkdown()` (kernel/Workbench/Development/DevelopmentTaskContract.php:54)
  is a self-contained, dependency-free parser for the approved contract. It **requires** the headings
  `Objective`, `Architectural constraints`, `Files likely affected`, `Acceptance criteria`, `Required tests`,
  `Risks`, `Forbidden changes`, extracts backticked path tokens from `Files likely affected` into
  `allowed_scope` and from `Forbidden changes` into `forbidden_scope` (each `{path, kind}` with
  `kind` ∈ `file|directory`), and fails closed with `InvalidArgumentException` naming the missing headings.
  `revisionId()` returns 16 hex chars.
- `workbench:task:record` already accepts `--stage=architect --result=architecture_decision_required`
  (`kernel/Workbench/Schemas/development-stage-result.v1.schema.json` result enum). **No kernel change is
  needed to record a deferral**; the driver only has to emit the stage-result envelope.
- `development-release-gate.v1.schema.json` already models deferred work as `conditions[]` with
  `{id, description, owner, evidence_ref, resolved}`. Follow that shape and its fail-closed spirit.
- The Workbench schema directory is only loaded by consumers that name a schema explicitly; adding a file
  there breaks no existing test (`grep -rn "Workbench/Schemas"` matches no PHP).
- `tests/*_test.php` is auto-discovered by `scripts/run-tests.php` (judged by exit code).
  `tests/harness/TestHarness.php` `MODE_PURE` needs no bootstrap; `done()` exits non-zero on failure.
- The working tree already carries unrelated modified/untracked files (previous slices). They are the
  ingest **baseline**, not this task's scope.

## Deliverables

### A1 — Policy: `.github/instructions/ai-autonomy-escalation.instructions.md` (new)

Frontmatter: `description` (one line, states it is the normative autonomy/escalation policy) and
`applyTo: "**/*"`. Body, in this order, ≤ 200 lines, no filler:

1. **Invariant** — an approved task contract *is* the permission envelope. Autonomy is never
   unbounded; it is bounded by the contract's scope, constraints, forbidden changes and acceptance
   criteria. Deferral is a recorded state, not a failure.
2. **Autonomy envelope** — what the sections of the approved contract authorise, mapped 1:1 to
   `DevelopmentTaskContract` field names (`allowed_scope`, `forbidden_scope`, `constraints`,
   `acceptance`, `required_tests`, `forbidden_rules`, `baseline_scope`).
3. **Decision classes** — exactly three, with the trigger lists below, no others:
   - **D0 autonomous** (never ask, never stop): choosing among equivalent in-scope implementations;
     writing/extending tests for changed behaviour; the edit→test→fix cycle inside scope; formatting;
     doc updates inside scope; restoring a self-inflicted in-scope breakage.
   - **D1 notify-and-proceed** (do it, record it, surface it at phase end): in-scope decisions that are
     reversible and contained to the task's own files; adding a missing test that the contract implies;
     choosing a default where the contract is silent and the blast radius is one file.
   - **D2 defer to the director** (stop, file a decision, do not proceed): any path outside
     `allowed_scope`; anything in `forbidden_scope`; schema/DDL/migration change; auth, authorisation,
     policy or security weakening; new runtime dependency; public API/capability contract change;
     cross-module coupling or ownership change; data deletion or irreversible migration; disabling,
     skipping, deleting or weakening an existing test or gate to get a pass; editing a quality-gate
     baseline; a second failed repair attempt on the same failure; any acceptance criterion that cannot
     be met without widening scope; attempt/budget exhaustion.
4. **Phase progression** — the harness runs `architect → implement → review → release-gate` unattended
   and returns from `CHANGES_REQUIRED` to `implement` by itself. Human input is required *only* for D2.
   State that asking for permission for an in-scope D0/D1 step is a defect, not diligence.
5. **Deferred-decision contract** — the required artifact (schema id, the option count, the option
   fields, the mandatory recommendation, `default_if_no_response: stop`, the checkpoint and resume
   command, `already_done`), and the rule that the run must leave the work at a resumable checkpoint.
6. **Answering and resuming** — the director answers with one option id; the harness resumes at the
   recorded checkpoint. An unanswered decision never auto-applies an option.
7. **Prohibited behaviours** — the D2 list restated as prohibitions, plus: no silent scope expansion,
   no "asking a question" without options and a recommendation, no reporting a `SKIP` as a pass.
8. **Relationship to the directive** — one paragraph: this file is normative for §12/§13 of
   `ai-development-execution-handoff.instructions.md`; it does not change role separation.

### A2 — Schema: `kernel/Workbench/Schemas/development-decision-request.v1.schema.json` (new)

JSON Schema draft 2020-12, `$id: urn:ikabud:workbench:development-decision-request:v1`,
`additionalProperties: false` at every object level. Top-level `required`, exact keys:

```
schema                    const "ark.workbench-development-decision-request.v1"
schema_version            const "1.0"
decision_id               string ^[A-Za-z0-9._-]+$
task_id                   string ^[A-Za-z0-9._-]+$
contract_revision         string ^[a-f0-9]{8,64}$
raised_at                 string
raised_by                 object {role, model, harness}  (string|null each, required, additionalProperties false)
decision_class            const "D2"
question                  string
why_now                   string
options                   array, minItems 2, maxItems 4, items object
                          {id ^[a-z0-9][a-z0-9-]*$, label, effect, cost, blast_radius,
                           reversibility enum[reversible, partially_reversible, irreversible]}
                          all required, additionalProperties false
recommendation            object {option_id, rationale} required, additionalProperties false
default_if_no_response    const "stop"
impact_of_no_decision     string
already_done              array of string
checkpoint                object {state, git_head (string|null), resume_command} required, additionalProperties false
evidence_refs             array of string
resolution                OPTIONAL object {chosen_option_id, decided_by, decided_at, note} required all four,
                          additionalProperties false
```

Add a `description` on the root and on `options`, `recommendation`, `default_if_no_response` and
`resolution` explaining the rule they encode (2–4 mutually exclusive options; the recommendation must
name one of them and must say why; the default is always `stop`; `resolution` is written only by the
director's answer).

### A3 — Driver: `tools/ai-autonomy.php` (new)

Standalone PHP 8.2 CLI, `declare(strict_types=1)`, **no bootstrap.php, no DB, no new dependency**.
It `require_once`s only `kernel/Workbench/Development/DevelopmentTaskContract.php`. First line
`#!/usr/bin/env php`. Mirrors the exit-code discipline of the repo's gates.

```
php tools/ai-autonomy.php plan   [--contract=PATH] [--decisions-dir=DIR] [--json]
php tools/ai-autonomy.php check  [<action words...>] [--path=PATH]... [--class=d0|d1|d2]
                                 [--justify=TEXT] [--contract=PATH] [--json]
php tools/ai-autonomy.php defer  --task=ID --question=TEXT --why=TEXT
                                 --option=ID|LABEL|EFFECT|COST|BLAST_RADIUS|REVERSIBILITY
                                 [--option=...] [--recommend=ID] [--impact=TEXT]
                                 [--done=TEXT]... [--evidence=REF]... [--state=TEXT]
                                 [--resume=CMD_WITH_<OPTION>_PLACEHOLDER] [--git-head=SHA]
                                 [--by-role=R] [--by-model=M] [--by-harness=H] [--id=DECISION_ID]
                                 [--contract=PATH] [--decisions-dir=DIR]
php tools/ai-autonomy.php resume <decision-id> --choose=OPTION_ID [--note=TEXT] [--by=ACTOR]
                                 [--decisions-dir=DIR]
php tools/ai-autonomy.php status [--decisions-dir=DIR] [--json]
```

Defaults: `--contract=.ai/current-task.md`, `--decisions-dir=.ai/decisions`, `--state=pre-change`,
`--by-role=implement`, `--by-model=null`, `--by-harness=pi`.

**Exit codes (contractual)**: `0` proceed / recorded / ok; `2` usage error, contract parse failure, or
malformed decision input (fail closed, message names the offending field/value); `3` escalate (D2).
No arguments or `--help` prints usage to stdout and exits `0`.

**`plan`** prints exactly this shape (human mode), then exits `0`:
```
AUTONOMY ENVELOPE — <contract path>
objective: <objective>
contract revision: <revisionId>
allowed scope (N):
  - <path> (<kind>)
forbidden scope (M):
  - <path> (<kind>)
phases (run unattended; only D2 stops the run):
  architect     tools/ai-task "<task>" -> tools/pi-arch-debate.py | tools/pi-arch-review.sh -> .ai/current-task.md
  implement     pi --print --approve '<contract>'
  review        pi-arch-review.sh / Code Reviewer lane; CHANGES_REQUIRED returns to implement
  release-gate  php ikabud workbench:task:record --stage=release-gate --result=... --envelope=...
automatic escalation (D2):
  - <one line per D2 trigger from the policy, in the policy's order>
decisions dir: <dir>
pending decisions: <count of *.json in dir without a resolution>
```
`--json` emits `{envelope:{objective,contract_revision,allowed_scope,forbidden_scope}, phases:[...],
d2_triggers:[...], decisions_dir, pending}`.

**`check`** is the deterministic tripwire. Verdict rules, applied in this order:
1. `--class=d2` → ESCALATE (the agent self-identified a director decision).
2. Any `--path` matching `forbidden_scope` → ESCALATE, reason names the path and the forbidden entry.
3. Any `--path` matching neither `allowed_scope` nor `baseline_scope` (file ⇒ exact match, directory ⇒
   path-prefix match) → ESCALATE, reason "outside the approved scope".
4. Otherwise compute sensitive-class triggers from the paths: `migrations/` or `.sql` → "schema or DDL
   change"; `.github/workflows/` → "CI gate change"; `composer.json|composer.lock|package.json|
   package-lock.json` → "dependency change"; `phpstan-baseline.neon` → "quality-gate baseline change";
   `module.json` → "module manifest/contract change"; `kernel/Capabilities|CapabilityAuthorization|
   SecurityHeaders|auth|JWT|policy` (case-insensitive) → "authority or security path".
   - Trigger present and `--justify=TEXT` absent or **not grounded** → ESCALATE.
   - Grounded = `TEXT` is ≥ 12 characters after whitespace/case normalisation and appears as a substring
     of the contract's objective, or of any `constraints` or `acceptance` line. This is how a contract
     that explicitly authorises e.g. a migration lets the harness proceed; a bare claim does not.
   - Trigger present and grounded → `RECORD`.
5. `--class=d1` → `RECORD`; otherwise `PROCEED`.
Human output prints `VERDICT: <PROCEED|RECORD|ESCALATE>`, `class: <D0|D1|D2>`, the action, the paths, one
`reasons:` bullet per rule fired, and on ESCALATE a final line
`next: file a deferred decision — php tools/ai-autonomy.php defer --task=<task_id> --question="..." --option=...`.
Exit `0` for PROCEED/RECORD, `3` for ESCALATE.

**`defer`** validates first (all failures exit `2` with the offending value named): `--task`,
`--question`, `--why` non-empty; the contract parses (the refusal to file an unbounded decision is
deliberate); 2–4 `--option`s, each exactly 6 `|`-separated non-empty fields, ids unique and
`^[a-z0-9][a-z0-9-]*$`, `reversibility` in the enum; `--recommend` present and one of the option ids.
Then it writes, creating the decisions dir if needed:
- `<dir>/<decision_id>.json` — the A2 schema shape exactly (no extra or missing keys);
- `<dir>/<decision_id>.md` — the director brief: question, why now, a markdown table of options
  (id, label, effect, cost, blast radius, reversibility), the recommendation with its rationale, the
  explicit `default_if_no_response: stop`, impact of no decision, what is already done, evidence refs,
  the checkpoint, and the exact answer command;
- `<dir>/<decision_id>.stage.json` — a `ark.workbench-development-stage-result.v1` envelope with
  `stage: architect`, `result: architecture_decision_required`, this task, the actor, `summary` = the
  question, and one `unresolved_findings` entry (`severity: P1`, the question as `summary`).
`decision_id` defaults to `<task_id>-d<N>` where N = 1 + the number of existing `*.json` files in the dir
for that task. Stdout ends with `DECISION FILED`, the three paths, the ready-to-run
`php ikabud workbench:task:record <task> --stage=architect --result=architecture_decision_required --envelope=<stage.json>`
line, and the `php tools/ai-autonomy.php resume <id> --choose=<OPTION>` line. Exit `0`.

**`resume`** loads `<dir>/<decision-id>.json` (missing file or already-answered decision ⇒ exit `2`),
requires `--choose` to be one of the option ids (else exit `2`), writes `resolution`
`{chosen_option_id, decided_by, decided_at, note}`, and prints the chosen option's label plus the stored
`checkpoint.resume_command` with `<OPTION>` replaced by the chosen id. Exit `0`.

**`status`** lists `<id>  PENDING|RESOLVED  <question>` sorted by id, with `--json` emitting an array of
`{decision_id, task_id, state, question, chosen_option_id}`. Empty dir ⇒ prints the header and
`no decisions`, exit `0`.

Style: small pure functions, no global state, no `eval`, output via `fwrite(STDOUT|STDERR, ...)`,
strict types, docblocks on every function (PHPStan level per `phpstan.neon` must stay clean —
use `@param array<string,mixed>` where an array is passed, or the new code will fail `static-analysis`).

### A4 — Test: `tests/ai_autonomy_test.php` (new)

`TestHarness('ai-autonomy', TestHarness::MODE_PURE)`, requiring
`__DIR__ . '/harness/TestHarness.php'`. Sandbox everything in a `sys_get_temp_dir()` fixture dir and
delete it at the end. Run the tool through `proc_open`/`exec` with `PHP_BINARY . ' ' . basePath() .
'/tools/ai-autonomy.php' --contract=... --decisions-dir=... 2>&1` and capture the exit code. Print the
exact command in the failure detail so a red test is diagnosable. Cases (each one assertion):

1. `plan` on a well-formed fixture contract exits `0` and prints `AUTONOMY ENVELOPE` + `allowed scope`.
2. `plan` on a contract missing `## Risks` exits `2` and names `Risks`.
3. `check --path=<allowed file>` → exit `0`, `VERDICT: PROCEED`.
4. `check --path=<forbidden dir>/App.php` → exit `3`, `VERDICT: ESCALATE`, reason mentions forbidden.
5. `check --path=modules/not-in-scope.php` → exit `3`, reason mentions approved scope.
6. `check --path=<allowed>/migrations/001.sql` → exit `3` with the DDL trigger (an in-scope path that is
   still a D2 concern).
7. same as 6 plus `--justify=<text copied from the fixture's Acceptance criteria>` → exit `0`,
   `VERDICT: RECORD`.
8. same as 6 plus `--justify=todo` (ungrounded) → exit `3`.
9. `check --class=d2 --path=<allowed file>` → exit `3`.
10. `defer` with a single `--option` → exit `2`; `defer` with `--recommend` naming a non-option → exit `2`.
11. valid `defer` → exit `0`, and `<id>.json`, `<id>.md`, `<id>.stage.json` all exist; the JSON
    `default_if_no_response === 'stop'`, `options` count is 2, `decision_class === 'D2'`, `question`
    matches, and **every key in the schema file's `required` list is present in the JSON** (read
    `kernel/Workbench/Schemas/development-decision-request.v1.schema.json`); the stage envelope declares
    `ark.workbench-development-stage-result.v1` with `result: architecture_decision_required`.
12. `status --json` → exit `0`, the id appears with `state: PENDING`.
13. `resume <id> --choose=bogus` → exit `2`; `resume <id> --choose=<valid>` → exit `0`; `status --json`
    then reports `state: RESOLVED` with the chosen id.
14. `resume <id> --choose=<valid>` a second time → exit `2` (a decision is answered once).

The test must fail if the tool stops enforcing scope (cases 4–6) — do not write assertions that pass for
a permissive implementation. Verify it exits non-zero when the tool is missing (run once with a bogus
path during development) and report that evidence.

### A5 — Wiring (additive edits only)

- `tools/ai-task` — append two lines to the `Next commands` block: the `plan` command (comment: autonomy
  envelope + D2 stop conditions) and a `defer` example. Do not restructure the script.
- `.github/AGENTS.md` — inside the existing "Execution Harness — Pi" section, append a short
  `**Autonomy and decision deferral**` subsection (≤ 15 lines) naming the policy file, the driver, and
  three example commands (`plan`, `check`, `defer`). Change nothing else.
- `.github/instructions/ai-development-execution-handoff.instructions.md` — in §12 add one bullet
  pointing to the new policy as normative for the "may / must not autonomously" lists, and in §13 add
  one bullet stating that a D2 condition is filed with `tools/ai-autonomy.php defer` and that a second
  failed repair attempt on the same failure is a D2 stop. ≤ 10 added lines, no restructuring, no edits
  to any other section.

## Architectural constraints

- The approved contract remains the only source of permission; the driver must never widen a scope, and
  must fail closed (exit `2`) rather than guess when the contract cannot be parsed.
- No new dependency, no DB, no bootstrap, no network, no `eval`, no shelling out from the driver itself.
- `kernel/Workbench/Development/DevelopmentTaskContract.php` is imported, never modified.
- Decision artifacts are plain files under `.ai/decisions/`; the Workbench ledger stays the system of
  record, reached only through the printed `workbench:task:record` command.
- PHP 8.2 compatible; `vendor/bin/phpstan analyse -c phpstan.neon` must stay clean for the new files.
- Every new behaviour in A3 is exercised by A4; a green test that would also pass for a permissive
  implementation is a defect.
- Do not edit the other files in `kernel/Workbench/Schemas` — only the new
  `development-decision-request.v1.schema.json` is in scope.
- Do not modify any file already modified or untracked in git at task start (the ingest baseline), other
  than the seven files listed in "Files likely affected"; do not restructure
  `ai-development-execution-handoff.instructions.md` (additive bullets only).
- Do not change HTTP behaviour, module behaviour, capabilities or capability policy rows.
- Do not weaken, skip or delete an existing test to reach a pass.

## Files likely affected

- `tools/ai-autonomy.php` — new; the autonomy driver (A3)
- `tests/ai_autonomy_test.php` — new; driver tests (A4)
- `kernel/Workbench/Schemas/development-decision-request.v1.schema.json` — new; decision artifact schema (A2)
- `.github/instructions/ai-autonomy-escalation.instructions.md` — new; normative policy (A1)
- `tools/ai-task` — two added lines (A5)
- `.github/AGENTS.md` — one added subsection (A5)
- `.github/instructions/ai-development-execution-handoff.instructions.md` — two added bullets (A5)

## Acceptance criteria

- `php tools/ai-autonomy.php plan --contract=.ai/ai-autonomy-harness.contract.md` exits `0` and prints the
  envelope, the phase chain, the D2 trigger list and the pending count.
- An in-scope path reports `VERDICT: PROCEED` with exit `0`; a forbidden or out-of-scope path reports
  `VERDICT: ESCALATE` with exit `3`.
- An in-scope path that is still a D2 concern (DDL) escalates unless the justification is grounded in the
  contract's own acceptance criteria, in which case it records.
- `defer` refuses a malformed decision with exit `2` and writes nothing, and a valid decision produces
  the schema-shaped JSON, the director brief and the stage-result envelope.
- The deferred decision defaults to `stop`: no option is applied without an explicit `resume --choose`.
- `php tests/ai_autonomy_test.php` exits `0` with every case above asserted.
- The policy file states the three decision classes, the D2 trigger list, the phase progression rule, the
  decision artifact contract and the prohibited behaviours.

## Required tests

- `php tests/ai_autonomy_test.php` — exit `0`, all cases asserted.
- `php -l tools/ai-autonomy.php` — no syntax errors.
- `vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G tools/ai-autonomy.php tests/ai_autonomy_test.php` — no new errors (report the real output; if `phpstan.neon` excludes `tests/`, say so instead of claiming a pass).
- `php tools/ai-autonomy.php check "edit the driver" --path=tools/ai-autonomy.php --contract=.ai/ai-autonomy-harness.contract.md; echo $?` → `0`.
- `php tools/ai-autonomy.php check "touch the kernel" --path=kernel/App.php --contract=.ai/ai-autonomy-harness.contract.md; echo $?` → `3`.
- `php tools/ai-autonomy.php defer --task=probe --question=Q --why=W --option=a|A|e|c|r|reversible --contract=.ai/ai-autonomy-harness.contract.md --decisions-dir=/tmp/ai-autonomy-probe; echo $?` → `2` (one option is not a decision).

## Risks

- A too-broad sensitive-path list would nag on legitimate in-scope work; the list above is the ceiling —
  do not extend it.
- Schema drift between A2 and A3: the acceptance test cross-checks the schema's `required` list against the
  written JSON, which is the guard.
- `exec`/`proc_open` availability in the test environment; if it is unavailable the test must `SKIP:` with
  the reason, never silently pass.
- Escalation fatigue is the failure mode of this feature — the policy must keep D0 wide and D2 narrow.

## Forbidden changes

- `kernel/Workbench/Development/` — no edit to these existing classes.
- `phpstan-baseline.neon` — no edit to the quality-gate baseline.
- `composer.json` — no new PHP dependency.
- `package.json` — no new Node dependency.
- `.github/workflows/` — no edit to CI workflow definitions.
- `kernel/App.php` — no edit to kernel runtime classes.
- `git add` — no staging, commit, push, branch creation or switching (not a path; retained as a rule).
