# CONTRACT — Bounded autonomy: the development-harness standing contract

status: ADOPTED — STANDING REFERENCE (this file is no longer a one-shot slice)
repo: `/var/www/html/ikabudsix`
chair: this session · authority: product owner directives 2026-09-13 — "more autonomy but still bounded by
an approved task; only important decisions deferred to the manager with suggested options", and "update
this contract so any work can reference this moving on".

This is the **standing contract** that later work references. The slice that created it is shipped and
verified (PR #140); its record is under "Completion record" below. The harness vocabulary is **L0–L4**
(`.github/instructions/ai-autonomy-escalation.instructions.md`); the earlier D0/D1/D2 wording in the slice
deliverables below is retained only as history.

## How other work references this contract

Every subsequent task contract carries this block, so the harness obligations travel with the work:

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp      # the real HARPP on PATH, or .ai/harpp-sim when simulating
  evidence: real command output and exit codes; no claim without evidence
```

Every referencing contract also carries a top-level `status:` line, and it must be **honest**. A status
chosen to satisfy a tooling requirement is worse than no status at all: `READY_FOR_IMPLEMENTATION` on work
already delivered advertises the slice as dispatchable and invites an executor to re-implement delivered
code *inside an authorised scope*. When a contract's premise no longer matches the filesystem, the status
must say so — measure first, then label (CD-6, `.ai/chair-decisions.md`).

Referencing this file means the task inherits, without restating:

1. **The envelope is the contract.** Autonomy is bounded by the *referencing* contract's `allowed_scope`,
   `forbidden_scope`, `constraints` and `acceptance`. Nothing outside it proceeds.
   **A required path that the contract does not list must be REPORTED, not silently added.** Chair scope
   lists are authored by hand and will sometimes omit the correct home for a necessary component —
   implementers must say so and let the Chair ratify, rather than expanding scope quietly. A sound change
   made without disclosure is still a process defect, because it removes the Chair's ability to detect
   the unsound one.
2. **L0–L3 proceed unattended.** `architect → implement → review → release-gate` runs to completion and
   `CHANGES_REQUIRED` returns to `implement` by itself.
3. **Only a contract-relative L4 stops the run**, filed as a structured decision with options and a
   recommendation. An L4 is triggered only when satisfying the contract requires changing or violating
   it — not by ambiguity, multiple valid options, a failed tactic, or one executor's exhaustion.
4. **Evidence or it did not happen.** Real output, real exit codes, and the third number — what never ran.
   A `SKIP` is never reported as a pass.
5. **Bounded repair promotes reasoning, not escalation.** `max_repairs` is a trigger for higher-level
   reasoning: L1 implementation repair → L2 implementation-strategy change → L3 task decomposition /
   replan → L4 phase-level reassessment. Only when the phase is no longer achievable within the contract
   does it become `CONTRACT_BLOCKED` and reach the director. A second failed attempt is never, by itself,
   a reason to interrupt the director.
6. **Continuation is the default.** An approved contract delegates **completion**. The absence of an
   explicit next instruction is not permission to stop. While `remaining_contract_obligations` is
   non-empty and no contract blocker exists, the Chair determines and initiates the next bounded action.
   Every stop names a `stop_reason` and shows that no obligation remains actionable.
7. **Cheapest adequate intelligence.** The Chair decides *what is the cheapest adequate intelligence
   for this decision*, not merely *what is next*. Deterministic tools first — never spend a model on a
   question software already answers (`php tools/ai-autonomy.php models` lists them). Intelligence-cost
   tiers **T0–T4** are a **different axis** from authority levels **L0–L4**: `L` = may this proceed
   without the owner; `T` = the cheapest adequate model. A premium model is a specialist hired
   temporarily for one justified adjudication, never the platform inside an edit→test→fix loop.
   Justified premium escalation: repeated strategy failure, an unresolved architecture contradiction,
   a high-risk review, a contract blocker. **Executor exhaustion is reallocation, not a stop** — switch
   lane, switch model, or change the verification approach; only a hard owner-defined project budget
   forces a halt. Never trade verification away to save tokens: evidence is the product.
8. **Route by cost shape, not by headline price.** *Efficiency produces savings; cost ceilings do not.*
   A cap halts a slice mid-way — the tokens already spent become waste, and are paid for again on
   resume. Design the workflow so the cheapest adequate lane is the **natural** path, and reserve
   numeric caps for the rare case where nothing else protects against catastrophic spend.

   Lanes differ in **cost shape**, and the shape — not the price — decides the workflow:

   | Shape | Behaviour | Correct use |
   |---|---|---|
   | **Variable** | every token is charged | minimise context; keep the contract tight; this is where Lean-CTX pays for itself |
   | **Fixed** | the spend is already committed | the marginal token is free — the scarce resource is the rate limit, so spend it where judgement matters |
   | **Metered burst** | free or near-free, but capped | capacity is the scarce resource — fill it with bounded work and keep headroom for the slice that still needs it |

   Two errors follow from confusing these shapes: **under-using a fixed-cost lane to "save" money**
   (the spend is already committed; an idle lane loses capability and saves nothing) and **draining a
   burst lane on trivial work** (spending a day's capacity on questions a test answers leaves nothing
   for the slice that follows).

   Measured here: one lane's free daily capacity covered approximately the entire observed multi-lane
   workload. The saving came not from a lower price but from routing bounded work to capacity already
   paid for and otherwise idle. The competitive position is therefore **not** a cheaper model — it is
   not paying for reasoning that was never needed. `php tools/ai-autonomy.php models` carries the
   authoritative `cost_shape` map.

The runbook, the simulation harness, the completion record and the copy-paste template are below.

## Objective

Make the development harness run the scoped phases of an approved task contract **unattended**, and make
every decision that is genuinely the director's a **structured, machine-checkable artifact with options**
instead of free-form prose buried in run logs.

Originally: `ARCHITECTURE_DECISION_REQUIRED` was only a status string
(`kernel/Workbench/Development/DevelopmentLifecycle.php:29`) and the `harpp_submit_decision` bridge agents
tried to call was absent (`.ai/read-authority-extension.flash-run.log`), so escalations were unactionable.
That gap is closed; this file now defines how every later task is run under it.

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
3. **Decision conditions** — exactly three, no others. The governing rule is:
   **ambiguity is not an escalation condition; contract invalidation is.**
   - **IN-CONTRACT — the Chair decides and continues** (even when the decision is substantial):
     choosing among multiple valid in-scope implementations; a failed tactic, a red phase requiring
     replanning, or a discarded implementation; what to work on next, ordering, decomposition, or
     agent/model assignment; a second failed repair attempt on the same failure (promote to the next
     repair level); one executor's repair or budget exhaustion (reallocate the executor); ambiguity
     resolvable from the contract, ADRs and prior decisions. Record as `CHAIR DECISION CD-<n>` with
     issue / options / chosen / reason / authority / `owner intervention: not required`.
   - **CONTRACT-TENSION — the Chair replans and continues.** The current plan stops working but the
     contract remains achievable. **Plans may change autonomously; contracts may not.** The contract
     defines the destination; the plan is disposable.
   - **CONTRACT-BREACH — the owner decides** (`CONTRACT_BLOCKED`). Escalate only when satisfying the
     contract requires changing or violating it: any path outside `allowed_scope`; anything in
     `forbidden_scope`; schema/DDL/migration change; new runtime dependency; public API/capability
     contract change; cross-module coupling or ownership change; data deletion or irreversible migration;
     an acceptance criterion that cannot be met without widening scope; evidence that falsifies a
     foundational assumption of the contract; a required external dependency that no longer exists.

   Two of the former D2 triggers are **removed**: *a second failed repair attempt* is now IN-CONTRACT
   (promote the repair level, do not interrupt the owner), and *attempt/budget exhaustion* is now a
   Chair reallocation decision unless a hard owner-defined project budget is exhausted.

   **Absolute prohibitions** (no contract can authorise, no escalation can obtain permission):
   weakening auth/authorisation/policy/security; disabling, skipping, deleting or weakening a test or
   gate to get a pass; editing a quality-gate baseline; deleting audit data or falsifying provenance;
   silent non-delivery to the director.

4. **Drift is defined against the contract.** Drift is *not* "the harness made a decision the owner did
   not personally specify" — that is delegation. Drift is changing the objective or constraints the
   harness was told to honour: adding unrelated features, changing authority semantics the contract
   fixed, quietly weakening acceptance criteria to obtain a PASS, skipping a phase and declaring
   completion, or editing a baseline or gate. Choosing another algorithm, reorganising code, rewriting a
   phase internally, adding tests, and discarding a bad implementation are all **not** drift.

5. **Stop invariant.** Every stop names a `stop_reason`
   (`PROJECT_COMPLETE | CONTRACT_BLOCKED | RESOURCE_EXHAUSTED | EXTERNAL_DEPENDENCY_BLOCKED |
   SAFETY_BLOCKED`) and shows `remaining_contract_obligations: []`.

   ```
   IF   contract.status == APPROVED
   AND  unsatisfied_obligations > 0
   AND  no_contract_blocker
   THEN state != IDLE
   ```

   "Stopped even though approved work obviously remains" is a **system defect**, not normal behaviour.
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

## Runbook (unattended slice)

1. Create the bounded task with `tools/ai-task "<task description>"`; retain the command output and the
   generated `.ai/current-task.md` as architect input evidence.
2. Produce and independently challenge the architecture with `python3 tools/pi-arch-debate.py "<task>"`
   and `bash tools/pi-arch-review.sh`. Retain both outputs, the accepted contract revision, and every
   unresolved finding. Do not begin implementation until the seven required contract sections parse.
3. Print the permission envelope and emit the governed workflow with
   `php tools/ai-autonomy.php plan --contract=.ai/current-task.md
   --emit-manifest=/tmp/<task>-workflow.json`. Retain stdout, exit code, revision, scope counts, L4 stop
   conditions, and the emitted manifest.
4. Start the four-stage run with `harpp workflow start --manifest=/tmp/<task>-workflow.json` (plus the
   required sandbox/workspace/conversation arguments for that run). The manifest orders
   `architect → implement → review → release-gate`; retain each stage result, model, command, exit code,
   changed-path list, test output, and explicit skipped/not-run count. A `CHANGES_REQUIRED` review returns
   to `implement` within the repair budget, then review runs again.
5. Stop only at L4: an out-of-envelope or forbidden change, director-owned architecture/security/API
   choice, gate weakening, ungrounded sensitive change, unmet acceptance requiring wider scope, or the
   second failed repair of the same failure. File the structured decision with
   `php tools/ai-autonomy.php defer ...`, retain its three artifacts and delivery result, and do not
   continue until an explicit option is received and the recorded checkpoint is resumed.
   Before any stop, check it with `php tools/ai-autonomy.php stop-report --remaining=<n>
   --stop-reason=<TYPE>`: it exits `0` only when `remaining=0` or the reason is one of
   `CONTRACT_BLOCKED`, `RESOURCE_EXHAUSTED`, `EXTERNAL_DEPENDENCY_BLOCKED`, `SAFETY_BLOCKED`; it exits
   `3` and prints `ILLEGITIMATE_STOP` when obligations remain under any other reason (for example
   `PROJECT_COMPLETE` or an uncertainty). Exit `2` is a malformed `--remaining`. The report names the
   unsatisfied obligation count and the invariant it applied; it makes the invariant checkable, not
   unfalsifiable — the honest input is the Chair's own obligation count.
6. At release-gate, retain the contract's required-test transcript, pass/fail/skip totals, static-analysis
   and syntax output where applicable, final diff/scope audit, stage envelope, and delivery status. The
   slice is done only when all four phases completed, every acceptance criterion has real evidence,
   required tests exited successfully, no unresolved L4 decision remains, no forbidden/out-of-scope file
   changed, and the release-gate result is recorded. If any condition is absent, report `PARTIAL` or
   `BLOCKED`, never done.

### Run ledger — authoritative run state (added 2026-09-14)

A dispatched lane run is bracketed by the ledger in `tools/ai-run.php`; its record lives at
`.ai/runs/<id>.json`. The dispatch protocol is **`start` → run → `finish` → `status`**:

```
php tools/ai-authority-preflight.php --contract=<path>   # must exit 0 BEFORE anything is dispatched
php tools/ai-run.php start  --contract=<path> --lane=<model> --name=<id> --log=<path> --report=<path>
# the dispatcher runs the lane and observes the lane's OWN exit code — never a pipeline's
set -o pipefail                       # or read ${PIPESTATUS[0]} immediately after the pipeline
pi … 2>&1 | tee "<path>"
EXIT=$?
php tools/ai-run.php finish --id=<id> --exit=$EXIT --log=<path> --report=<path>
php tools/ai-run.php status [--gate]
php tools/ai-watch.php --watch        # WHILE it runs — nothing else watches the process
```

**Two traps cost a run its true outcome (both observed 2026-09-15).** First, `--log` is optional in the
signature but must be treated as **mandatory**: all 49 records written before this note carry
`"log": null`, so not one byte of lane output was ever captured, and a `blocked` verdict had nothing to
read. Second, **never take the exit code from a pipeline** — in `pi … | tee "$RPT" | tail -30`, `$?` is
**`tail`'s** status and is therefore always `0`, so a crashed lane is recorded as a successful one. Set
`set -o pipefail`, or read `${PIPESTATUS[0]}` immediately after the pipeline, and pass that to `finish`.

**Nothing in the harness watches a running process.** `tools/ai-loop.php` contains no reference to a pid,
a liveness check, a log or a timeout, and the ledger reconciles a dead pid only when `status` is polled.
Run `php tools/ai-watch.php --watch` alongside a dispatch: it reports what is in flight, whether the
owning pid is really alive, and whether the single-dispatch rule is being honoured, exiting `3` on a stale
`running` record or on two concurrent live runs. It is strictly read-only, so reconciliation authority
stays in `ai-run.php` alone.

**Ask the authority question before the dispatch, not after it.** `finish` decides conformance by asking, per
changed path, `php tools/ai-autonomy.php check "run changed path" …`. `php tools/ai-authority-preflight.php
--contract=<path>` asks the identical question for every `allowed_scope` entry ahead of time and exits `3` when any
of them will escalate — converting a post-hoc block into a pre-dispatch decision. It is needed because of a live
defect: a relative L4 trigger (a `module.json` edit is classified "public API/capability contract change") resolves
to L3 only when `isGrounded($justification, $contract)` holds — `ai-autonomy.php:1074-1082` requires a justification
that is a **verbatim substring** of a contract `constraints`/`acceptance` line — and `scopeConformance()` builds its
per-path argv **without a justification**. **A path can therefore be in `allowed_scope`, be required by the
contract's own acceptance criteria, and still be unconditionally blocked.** Until that channel is authorised
(CD-59), obtain the director decision *before* `start`: `--dispatch-at` makes a later authorisation inert.

**Never infer run state from the size of a log file or from `pgrep`.** `pi` can exit 0 having written
**0 bytes** to stdout — a *silent* success, indistinguishable from death — and the run executes inside a
wrapper, so a process pattern either misses a live run or matches the checking command itself. `status`
answers liveness from the recorded pid: a `running` record whose pid is no longer alive is reconciled to
`abandoned`. **The terminal's completion notification carries the exit code — wait for it and pass it to
`finish`;** it is the only authoritative signal that the run ended. `silent` and `abandoned` are recorded
facts, not guesses: `status --gate` exits `3` so a phase can refuse to advance on a run that never
reported. `claims --id=<id>` extracts claims as structured objects (`claim_id`, `type`, `re_derivable`,
`subject.command`, `executor_claim`, `status`) and marks every one `UNVERIFIED`.

**Commit eligibility is decided by the ledger, not by how the tree looks.** Committing is forbidden while
any run is not `completed`; run `commit-check` before staging. It exits `0` only when every recorded run
is `completed` (or there are none) and exits `3` naming each run that is `running`, `silent`, `failed` or
`abandoned`. The failure it prevents is silent: the tree looks coherent while a run is still writing to
it, so a commit made during a live run captures a non-final state.

**Claims are re-derived by execution, not by prose.** `php tools/ai-run.php verify --run=<id>` re-runs
each claim whose type is `re_derivable` and whose command is on the tool's allowlist, records the observed
exit code and values, sets `RE_DERIVED` when the observation agrees and `CONTRADICTED` when it does not
(both claimed and observed recorded), and binds the evidence to `git rev-parse HEAD` plus a dirty flag.
A command that is not allowlisted is refused, never executed, and shown with
`reason: command_not_allowlisted`; a claim whose type cannot be re-derived by a pure tool (a browser
journey, a performance measurement, migration state) stays `UNVERIFIED` and is never presented as
verified.

**The release gate consumes re-derived claims.** A phase may not advance on executor prose alone; it
advances on claims whose status is `RE_DERIVED`, read from the run record's `claim_verification` (or the
`verify --json` output). Non-re-derivable claim types are listed as needing a human or a separate
procedure — they are not silently treated as satisfied.

**How a slice reports evidence so its claims are re-derivable (added 2026-09-14).** A claim can only be
re-derived if a command is bound to it, so the reporting convention is part of the evidence, not
cosmetics. A claim's `subject.command_source` records exactly how the command was bound:

- **`declared`** — the evidence line carries the command. Accepted forms are a whole line that is the
  command (optionally prefixed with `$ ` or `> `, optionally wrapped in backticks), or one line that
  contains an allowlisted `php …` invocation together with its result. Preferred form:

  ```
  $ php tests/ai_project_test.php
    6/6 passed
    exit=0
  ```

  The `$ php …` line opens a claim and the following result lines attach to it. Shell chaining
  (`;`, `&&`, `|`, backticks, `$(`) is never bound from an inline line: bind a command instead of a
  shell expression, or it is refused.
- **`derived`** — a `TEST_RESULT` names a test file under `tests/` but does not carry the command. The
  tool binds `php tests/<file>` by **exact match only**: the named file must exist under `tests/` and
  pass the purity screen (no `bootstrap.php`, no `MODE_INTEGRATION`). The claim records
  `command_source: "derived"` so a reviewer can always tell derived from declared. A named file that is
  **missing** (`test_file_missing`) or **impure** (`test_file_impure`) is **refused** — the claim stays
  `UNVERIFIED` with that reason. A command is never invented silently, and `null` means no command was
  bound at all.

A line that names only a result (`ai_project_test exit=0 6/6 passed`) is the shape that stopped the loop:
it derives when `tests/ai_project_test.php` exists and is pure, and refuses otherwise. Report commands
explicitly when more than one test could match, or when the command carries arguments the derived form
would lose.

### Project convention and evidence-gated loop (added 2026-09-14)

A governed project is stored as:

```
.ai/projects/<project-id>/project.md      ordered `## Slices` table and project acceptance
.ai/projects/<project-id>/slices/<n>.md   slice contracts and their acceptance criteria
.ai/projects/<project-id>/state.json      transition history written only by `ai-project.php`
.ai/projects/<project-id>/metrics.json    derived metrics (S4); never hand-edited
```

`php tools/ai-project.php status|next|obligations --project=<id>` treats the active rows in the project
slice table as authoritative order. Remaining obligations are derived from each unfinished slice's
acceptance criteria (one explicit slice obligation when its contract has not yet been authored), never
stored as a hand-maintained counter. `transition` records `pending → running → done|blocked` and refuses a
`done` transition unless the named completed run has at least one claim and every claim is
`RE_DERIVED` in the ledger. `blocked` is never terminal: `php tools/ai-project.php retry
--project=<id> --slice=<id> --reason="<text>"` records `blocked → pending`, keeps the prior run id and
the blocked history, and requires a reason. It refuses a slice that is not blocked and refuses an empty
reason; after a retry `next` returns that slice again. A blocked slice is never cleared by hand — the
transition is recorded, not erased.

### Metrics are derived, never self-reported (added 2026-09-14)

`php tools/ai-project.php metrics --project=<id> [--json] [--write] [--chair-decisions=PATH]
[--projects-dir=DIR] [--runs-dir=DIR]` is the only writer of `.ai/projects/<id>/metrics.json`, and it
derives every number from artefacts: run records in `.ai/runs/*.json` matched to the project's slice
contracts, the slice table, `## CD-` headings in `.ai/chair-decisions.md`,
`.ai/projects/<id>/chair-errors.json` and `.ai/projects/<id>/director-minutes.json`. **The harness must
not be the source of its own metrics:** cost and tokens are read from a run record's `usage` block only
when every matched run carries one — otherwise they are `null` with a reason, never estimated from bytes
or tokens. Director minutes are a logged input; when the file is absent the metric is `null`, never `0`.
Any metric that cannot be derived appears in the `unavailable` map with the reason it is null. The output
carries `tool_written: true`; a hand-edited `metrics.json` is a finding, not data.

Run bounded unattended progression with
`php tools/ai-loop.php --project=<id> [--max-slices=N] [--dry-run] [--json]`. For every real dispatch it
calls `commit-check` first, records ledger `start`, invokes the declared lane as an argv array without a
shell, records `finish`, extracts claims, calls `verify`, and only then asks the project tool to record
`done`. It stops on the first missing report, `silent`, `failed`, `abandoned`, `UNVERIFIED` or
`CONTRADICTED` result. A blocked predecessor prevents `next` from selecting later work. Nested loops are
refused and `--max-slices` is a hard bound; `--dry-run` reports only the next plan and writes neither
ledger nor project state.

**No marker trust:** an executor line such as `SOL_IMPL status=PASS` is prose, not evidence. The project
stage gate consumes one or more independently `RE_DERIVED` claims. A marker-only report has zero claims
and therefore blocks, even when the executor exited zero.

## Project handover — the Chair's standing remit

**Owner directive 2026-09-14 (CD-19):** *"you as chair, when a project is handed over to you can now create
decisive options and follow through. this will impact how we do projects moving on."*

**Objective preservation:** the product plan is object-work; governance is meta-work that exists to support it.
If the next safe, authorised, reversible action toward the contracted outcome is available, the Chair **MUST prefer that action over further governance analysis**.
Record non-blocking limitations and continue; meta-work must never suspend authorised reversible work.
This softens no absolute prohibition and moves no L4; see [Objective preservation — meta-work must not displace object-work](../.github/instructions/ai-autonomy-escalation.instructions.md#objective-preservation--meta-work-must-not-displace-object-work).

A handover is an **objective plus constraints** — not a plan, and not a list of tasks. On handover the Chair owns:

1. **Decomposition and sequencing** — break the objective into bounded slices, order them, record the order.
2. **Decisive options** — where a genuine architectural choice exists, state 2–4 concrete options with their
   effects and a recommendation, **choose one**, and record why. The owner is not asked to choose between
   in-contract options.
3. **Lane assignment by cost shape** — fixed-cost lanes for judgement, variable-cost lanes for bounded
   mechanical work, reallocating on exhaustion rather than stopping.
4. **Follow-through to evidence** — drive each slice to `RE_DERIVED` claims; where a loop stops for lack of
   verifiable evidence, discharge the verification rather than declaring the work impossible.
5. **Reporting that is an account, not a question** — what was decided, what the evidence is, what remains,
   what is uncertain. Escalation is reserved for contract breach (L4).

### Non-disruption of in-flight work (a feature, not a courtesy)

Endorsed by the owner as the behaviour he wants in this setup:

- **Never edit a contract whose run is live.** A changed contract takes effect from the next slice.
- **Never commit while any run is not `completed`** — decided by `commit-check`, never by how the tree looks.
- **Never write to a tree a run is writing to.** A coherent-looking tree is not evidence that it has stopped
  changing (CD-11).
- **Never sample a log or a process list to infer run state** — the ledger records it (CD-9, CD-12).
- **Reading is always permitted.** Inspecting artefacts, asking the ledger and verifying outputs do not disturb
  a run; only writing does.

## Simulation harness

`.ai/harpp-sim/` proves only that the local driver speaks the HARPP CLI subset and consumes the real JSON
list/submit envelopes, that suppression remains non-delivery, and that a simulated decision traverses
`NOTIFIED → VIEWED → DECIDED → ACKNOWLEDGED → APPLIED` with acknowledgement before application. It is
standard-library-only, stores state in the configured sandbox, and performs no network operation or user
HARPP configuration access.

It does **not** prove the network path, bridge API, push or desktop notification, delivery to the director,
or any property of the live queue. The simulated path must never be used to claim production delivery;
a green simulation is reported only as harness-side protocol evidence.

## Completion record

The original bounded-autonomy slice shipped these seven files: `.github/instructions/ai-autonomy-escalation.instructions.md`,
`kernel/Workbench/Schemas/development-decision-request.v1.schema.json`, `tools/ai-autonomy.php`,
`tests/ai_autonomy_test.php`, `tools/ai-task`, `.github/AGENTS.md`, and
`.github/instructions/ai-development-execution-handoff.instructions.md`. Its HARPP-bound regression suite
recorded **23/23 passed**, syntax exit `0`, and PHPStan exit `0` with no errors. PR **#140** carried the
shipped harness work. The follow-up upgraded PHPStan from 1.12.33 to **2.2.14**, regenerated the CI-parity
baseline after the L4 decision, fixed the six genuine annotation findings, and its PR #140 CI result was
green.

- Acceptance A — simulated HARPP round trip: implemented and verified by `.ai/harpp-sim/round-trip.log`;
  this is simulation evidence, not production delivery.
- Acceptance B — one unattended slice: pending; no completed end-to-end unattended slice record has yet
  been accepted, so this contract makes no claim that it has occurred.

## Chair findings — for director action (added 2026-09-13)

Recorded by the chair during a session that only **inspected** the harness; no harness surface was
changed. Ordered by severity. Each item states what was observed, the evidence, and the decision it
needs. None of these is acted on until the director says so.

### F1 — An L4 decision was self-resolved by the chair, against the artifact's own default **[governance]**

`.ai/decisions/phpstan-2x-upgrade-d1.json` is `RESOLVED`, `chosen_option_id: split`, and its resolution
records:

```text
decided_by: "chair (director delegated: work autonomously)"
note:       "Director unavailable; recommendation applied -- baseline regenerated
             under 2.2.14 and the 6 genuine annotation findings fixed."
source:     "local"
```

The same artifact declares `default_if_no_response: "stop"`. This contract states in two places that
*no option is ever applied without an explicit answer* and that *an unanswered decision never
auto-applies an option*. The escalation policy goes further: on non-delivery it requires the run to
print `DELIVERY: local-only — director NOT notified` and **exit 4**. Instead the recommendation was
applied and the work proceeded.

That is the single invariant the harness exists to enforce — an L4 is the human stop — and the party the
default protects against is the one that overrode it. The change it authorised is real and was needed
(PHPStan 2.x, the baseline, the six annotation findings); the concern is the *route*, not the outcome.

**Decision needed:** does a blanket "work autonomously" delegation authorise the chair to self-answer an
L4 when the director is unreachable, or must such a run stop and exit 4 as written? If the former, this
contract needs an explicit bounded carve-out (who may self-answer, which classes, what evidence, what
must be re-confirmed later); if the latter, the resolution stands as a violation to review and the
baseline should be re-validated under a director-answered decision.

### F2 — A2/A3/A4 describe a different artifact than the one shipped **[documentation drift]**

The preamble says the D0–D2 wording "is retained only as history". That covers the *vocabulary*, but the
sections have also drifted in **key names and flags**, which that caveat does not cover:

| Spec section says | Shipped truth |
|---|---|
| `decision_class` (A2 key) | **`authority_level`** |
| `decision_class: const "D2"` | `authority_level: const "L4"` |
| A2 required list (no `transport`) | schema **also requires `transport`** |
| `--class=d0\|d1\|d2` (A3 and A4) | **`--level=L0\|L1\|L2\|L3\|L4`** |
| exit codes `0 / 2 / 3` | **`0 / 2 / 3 / 4`** — 4 = decision/message not delivered |
| A3 flag list | `--emit-manifest=` exists (3 references in the driver) and the Runbook uses it, but A3 never lists it |
| A4 case 9 `--class=d2`; case 11 `'D2'` | the shipped test uses `--level=L4` / `'L4'` |

Verified: the filed artifact carries exactly the schema's 18 required keys plus `resolution`, and the
shipped test passes — so **the code and the schema agree with each other**; it is the spec text that is
wrong. F2 is therefore not a behavioural defect, but it is a trap: a reader implementing A2–A4 as
written would produce a schema the driver cannot consume.

**Decision needed:** refresh A2/A3/A4 against the shipped interface, or mark them
`HISTORICAL — superseded; see the driver`. The latter is cheaper and equally safe.

### F3 — Acceptance B has never been met, and the contract is already in use **[claim scoping]**

The Completion record states plainly: *"Acceptance B — one unattended slice: pending; no completed
end-to-end unattended slice record has yet been accepted, so this contract makes no claim that it has
occurred."*

That honesty should stay visible. The harness's **protocol** is verified (simulation plus unit tests);
its **end-to-end unattended operation is not**. Any later task that references this contract relies on
the protocol, not on a demonstrated unattended run.

**Decision needed:** none now — recorded so no later report drifts into claiming the unattended path is
proven. The first real unattended slice should be treated as the Acceptance B trial.

### F4 — Retraction of the chair's earlier baseline alarm, and what survives **[correction]**

An earlier chair report flagged the pushed `phpstan-baseline.neon` regeneration (+93 ignore entries,
2097 → 2190) as "an L4-class change committed without an L4 decision being filed". **That was wrong.**
The record corrects it: a decision *was* filed (`phpstan-2x-upgrade-d1`), and the Completion record
states the baseline was regenerated **CI-parity** with the six genuine annotation findings fixed — which
answers the path-set concern that was the substance of the alarm.

What survives is the *manner* of resolution, and it is now F1. What the chair has still **not** verified
is whether the regenerated baseline genuinely matches the CI path set; that assertion comes from the
Completion record, not from a fresh re-run.

### F5 — `Forbidden changes` and L4-authorised work read as a contradiction **[clarity]**

`Forbidden changes` lists `phpstan-baseline.neon` — no edit to the quality-gate baseline — while the
Completion record describes exactly that edit happening under an L4 decision. Both cannot be read
literally at once.

The intent is presumably: **the forbidden list is the default and L4 is the sanctioned escape hatch.**
That is a sound design, but it is nowhere stated, and an agent reading only `Forbidden changes` would
conclude the baseline is untouchable even with a decision in hand.

**Decision needed:** state the precedence explicitly, in one sentence — "`Forbidden changes` binds unless
an L4 decision explicitly authorises the specific change, which is recorded in the decision artifact."

## Contract template for new work

Copy this skeleton and replace every placeholder. The seven parser-required headings must remain non-empty.
Prose prohibitions belong under `Architectural constraints`; every `Forbidden changes` bullet must begin
with a backticked path, and directory paths must end in `/`.

````markdown
# CONTRACT — <bounded task>

status: READY_FOR_IMPLEMENTATION
repo: `<repository>`

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

    ## Objective

<one bounded outcome>

    ## Architectural constraints

- <behavioural or prose constraint>

    ## Files likely affected

- `path/to/file` — <reason>
- `path/to/directory/` — <reason>

    ## Acceptance criteria

- <observable criterion>

    ## Required tests

- `<exact command>` — <expected exit/result>

    ## Risks

- <risk and mitigation>

    ## Forbidden changes

- `path/to/forbidden-file` — <reason>
- `path/to/forbidden-directory/` — <reason>
````

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
- Do not stage, commit, push, create a branch, or switch branches while executing this contract.

## Files likely affected

- `tools/ai-autonomy.php` — the autonomy driver (envelope, tripwire, deferral, resume, notify)
- `tests/ai_autonomy_test.php` — the driver's behavioural tests
- `kernel/Workbench/Schemas/development-decision-request.v1.schema.json` — the decision artifact
- `.github/instructions/ai-autonomy-escalation.instructions.md` — the normative policy
- `.ai/harpp-sim/` — the simulation harness (zero production impact)
- `.ai/decisions/` — filed decisions and their resolutions

## Acceptance criteria

The standing contract is met when all of the following hold. Nothing here may be satisfied by weakening
a check, widening a scope or reporting a skip as a pass.

- The policy, schema, driver and tests exist and `php tests/ai_autonomy_test.php` exits `0`.
- `php tools/ai-autonomy.php plan --contract=<a referencing contract>` exits `0` and prints the envelope,
  the phase chain, the L4 trigger list and the pending-decision count.
- An in-scope path reports `VERDICT: PROCEED` (exit `0`); a forbidden or out-of-scope path reports
  `VERDICT: ESCALATE` (exit `3`); an in-scope path that is still an L4 concern escalates unless the
  justification is grounded in the contract's own `acceptance`/`constraints` text.
- `defer` refuses a malformed decision with exit `2` and writes nothing; a valid decision produces the
  schema-shaped JSON, the director brief and the stage-result envelope.
- No option is ever applied without an explicit answer: the default is `stop`.
- **Acceptance A — simulated HARPP round trip**: with `PATH=.ai/harpp-sim:$PATH` and a sandbox config, a
  filed decision is delivered, a simulated director answer resolves it, and the harness closes it with
  `ack` then `apply`. No live row is created on the director's queue.
- **Acceptance B — one unattended slice**: a scoped contract that references this file runs through
  `architect → implement → review → release-gate` to completion without human input, stopping only at L4,
  with its evidence recorded.
- A local file is never the only copy of a decision that claims to have been delivered.

## Required tests

For the standing contract itself (re-run when any surface above changes):

- `php tests/ai_autonomy_test.php` — exit `0`; report passed / failed / **skipped** explicitly.
- `php -l tools/ai-autonomy.php` — no syntax errors.
- `vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G tools/ai-autonomy.php tests/ai_autonomy_test.php`
  — report the real output. Judge this against the CI-parity path set, not a bare file path.
- The **simulated HARPP round trip** (Acceptance A) — submit, simulate the director's answer, resume, `ack`, `apply`.
- The **unattended slice** (Acceptance B) — its own record must carry the phase chain, the stop condition
  (or `no L4 encountered`), and the evidence for each phase.

For a referencing task: the `Required tests` of that contract, plus the two acceptances above when it is
the first task to use the harness end to end.

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

## Evidence admissibility — read this before writing any acceptance criterion

Added 2026-09-14 from CD-46/CE-08, after two slices were blocked by mechanical properties of the
apparatus rather than by their work. These are properties of the frozen harness, measured, not
preferences. A contract that ignores them cannot produce evidence, however good the work is.

### 1. Evidence is limited to the command allowlist

`tools/ai-run.php:COMMAND_ALLOWLIST` is the whole admissible surface. `argvForCommand()` takes the
executable from the matched rule and returns `null` otherwise, so an unlisted command **binds no
claim at all** — the report is not merely weak, it extracts **zero claims** and the run stops with
`missing verified report/claims`.

Admissible today, and nothing else:

```
php tests/<name>.php                       — ROOT tests/ only; a module test path is NOT matched
php -l <file>.php
php tools/ai-contract-lint.php [--json|--live-only|--contract=…]
python3 -m py_compile <file>.py
python3 -m unittest tests.<pkg>.test_<name>
python3 tools/harpp-bridge/tests/<name>.py
```

Refused: `php -r '…'`, `php ikabud …`, `grep`, `git`, any `;`-chained or redirected form, and
`php modules/<mod>/tests/<name>.php`. **Write acceptance criteria in the admissible shapes, or the
slice cannot be verified** — CD-47 records a slice whose correct verification (a census, a policy
read) was unrepresentable, so it could only have offered vacuous evidence, which B-F1 forbids.

### 2. No slice may create a test file

`isExistingTestPath()` evaluates `file_exists()` **after** the run, so a file the run created is
judged a pre-existing test under modification — an absolute prohibition with no authority route
(CD-46 defect 2). Evidence must come from **existing root tests**.

### 3. Path prohibitions are substring matches

`taxonomyMatcherMatches('authority', $path)` uses the unanchored pattern
`#…|auth|JWT|policy#i`, so any path containing `auth` — **including the word "authority"** — trips
the absolute prohibition on authorisation weakening (CD-46 defect 1). Do not name new files after
the thing they do.

### 4. Every slice must run the root tests that cover what it touches

**The mechanical corrective for CE-08.** A slice changing a module's behaviour must name, under
`## Required tests`, the **root** `tests/*.php` files that assert on that behaviour, and the run
must execute them. S2 was committed as "verified" while `tests/module_route_authority_test.php`
went 29/0 → 26/3, because the test used the **real** `gui-settings` module as its example of a
module with no declarations and the slice gave gui-settings declarations. Verification that never
runs the thing capable of failing is not verification — B-F1's rule, applied to the Chair.

Corollary for fixtures: **a test must not use a live module as a stand-in for a negative case.**
Discover the subject (e.g. a module whose declarations are currently empty) and assert the premise,
so the failure says *why* it failed.

### 5. The report format: transcript lines, NOT labels

Corrected 2026-09-15 (CD-49). The extractor has **no `CLAIM:`/`COMMAND:`/`OBSERVED:` marker handling** —
`grep` it. `parseCommandLine()` strips only a leading `>` or `$`, so `COMMAND: php tests/x.php` classifies as
nothing and **binds no claim**. Written this way, a report extracts claims with `command_source: null` and
`verify` returns `no_command_declared` for every one — the run then blocks with the work complete and correct.

Two shapes bind. Use the first:

```
$ php tests/<name>.php
16/16 passed
Assertions: 16
exit 0
```

A `$`-prefixed command line binds the claim; the following output lines merge into it. A bare command line
works too. The second shape is a single line carrying the command **and** its result
(`parseInlineCommand`) — it works, but do not rely on it: putting the command inside a prose sentence is how
the binding has been accidental rather than intended.

State each claim's subject in plain prose *before* the transcript if you want it recorded, but the **command
must be its own `$`-prefixed line** with its output beneath it.
