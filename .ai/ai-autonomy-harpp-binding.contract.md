# CONTRACT — Bind the autonomy harness to HARPP (director channel) and to the L0–L4 authority ladder

status: READY_FOR_IMPLEMENTATION
repo: `/var/www/html/ikabudsix` — branch: `main` (work in the tree)
owner: implementation agent (Sol, via Pi)
chair: this session · authority: product owner directive 2026-09-13 — *"this is not specifically for the
kernel only, but generally how my vscode approach intent/directives from the human director. there's harpp,
with python, which is a tool when the human director is away but need to check and connect with his desktop
vscode app."*

Read first: the seven files produced by `.ai/ai-autonomy-harness.contract.md` (this slice modifies them —
read what you actually wrote, do not re-derive it), then `docs/architecture/akira-beyond-the-cms.md` §"The
kernel understands delegated authority, never AI authority" (line ~253).

## Objective

The previous slice made deferral a local file. That is invisible to an absent director and duplicates a
control plane that already exists. **HARPP is the director channel and the authority ladder; this repository
only produces the request.** Bind the driver to HARPP so an L4 decision reaches the director's desktop/VS
Code, and so a *failure to deliver* is loud instead of a prose sentence in a run log.

## Verified facts — do not re-derive

HARPP is an external Python control plane. It is **not** in this repository, it must not be vendored, and it
is found on `PATH` (`harpp` → `/home/kajagogoo/.local/bin/harpp` → symlink into
`/var/www/html/applicationostest/tools/harpp-bridge/harpp`). Config: `~/.config/harpp/config.json`
(`base_url`, `bridge_key`, `tenant_id`, `workspace`, …). Never read or print that file's secret values.

1. **The authority ladder already exists** — `harpp_client.py:32-39`:
   `DEFAULT_HARPP_AUTHORITY = "L2"` and
   `DEFAULT_AUTHORITY_POLICY = {"L0": "autonomous", "L1": "autonomous", "L2": "autonomous",
   "L3": "autonomous", "L4": "human_approval"}`. **This is the canonical vocabulary.** The repo invents no
   second one (the previous slice's `D0/D1/D2` classes are replaced by L-levels).
2. **Decision lifecycle** — `NOTIFIED → VIEWED → DECIDED → ACKNOWLEDGED → APPLIED`; terminal
   `CLOSED | EXPIRED | SUPERSEDED | CANCELLED`. Client calls: `submit_decision`, `list_decisions`,
   `view_decision`, `record_decision` (DECIDED, the owner's answer), `acknowledge_decision` (ACKNOWLEDGED),
   `apply_decision` (APPLIED), `cancel_decision`.
3. **`submit_decision` payload** — `harpp_client.py:329-343` posts exactly
   `{title, body, context, requested_decision, priority, source, workbench_state, decision_key?}` to
   `/api/v1/harpp/bridge/decisions`; `workbench_state` defaults to `"ARCHITECTURE_DECISION_REQUIRED"`;
   `decision_key` is an optional idempotency key.
4. **CLI equivalents** (`harpp --help`): `decision submit --title --body [--context --requested --priority
   --source --workbench-state --decision-key]`, `decision list [--limit --remote --state --priority
   --workbench-state]`, `decision view <id>`, `decision decide <id> --decision --rationale`,
   `decision ack <id> [--rationale]`, `decision apply <id> [--rationale]`, `decision cancel <id>`;
   `msg send --body [--title --conversation-id --harness-session-id --idempotency-key]`;
   `msg poll`, `status --message ...`, `watch [--once --interval --inbox]`.
5. **Owner message types** — `OWNER_MESSAGE_TYPES = {INFO, PROGRESS, WARNING, DECISION_REQUIRED, BLOCKED,
   RELEASE_READY, FAILED}`.
6. **The phase loop already exists** — `harpp workflow start --manifest <json> --conversation <id>
   [--workspace --model --max-repairs --max-total-cycles --max-browser-repairs --max-tool-retries
   --max-network-retries --contract-revision --authority-level --dry-run]`, with
   `workflow validate | list | show | resume`. Manifest shape (`workflows/governed-loop.json`): top-level
   `title`, and `stages[]` of `{name, model, prompt_file, marker, verify, timeout}` — `marker` is a stdout
   sentinel, `verify` is a shell command. Budgets default from `DEFAULT_WORKFLOW_BUDGETS`
   (`max_total_cycles 8, max_repairs 3, max_browser_repairs 2, max_tool_retries 2, max_network_retries 2`).
7. **The desktop/VS Code path is MCP** — `tools/harpp-bridge/harpp_mcp.py` (stdlib-only, stdio JSON-RPC)
   exposes `harpp_submit_decision`, `harpp_list_decisions`, `harpp_acknowledge_decision`,
   `harpp_apply_decision`, `harpp_get_decision`, `harpp_send_message`, `harpp_poll_messages`,
   `harpp_post_status`, … `.ai/read-authority-extension.flash-run.log:1` records an agent that needed
   `harpp_submit_decision` and could not reach it, so the escalation degraded to prose. That is the defect.
8. **Live-pollution guard** — `harpp_client._notify_enabled()` suppresses owner-facing sends when
   `HARPP_NOTIFY=0` or `HARPP_TESTING_MODE=1`, returning `{"ok": true, "suppressed": true}`. Its docstring
   records that the workflow escalation tests previously created **live decisions on the host**.
   **Every test in this slice must run with `HARPP_NOTIFY=0` and never touch the network.**

## Deliverables

### A1 — Policy rewrite: `.github/instructions/ai-autonomy-escalation.instructions.md`

Keep the file's frontmatter and its structure, and make these changes:

- Replace the `D0/D1/D2` class vocabulary with the **authority ladder** `L0–L4`, stating the mapping and
  citing HARPP as the source of truth (`DEFAULT_AUTHORITY_POLICY`). Table:
  `L0` trivial/reversible in-scope → proceed silently; `L1` in-scope, contained, reversible → proceed;
  `L2` **(default)** in-scope work with real consequence → proceed + record; `L3` in-scope but
  cross-cutting or hard to reverse → proceed + record + notify; `L4` **human approval** → stop and file.
  The `L4` trigger list is the old D2 list, unchanged in content.
- Add a **"Director channel (HARPP)"** section: the harness files an L4 decision through HARPP
  (`harpp decision submit`, or the `harpp_submit_decision` MCP tool when the agent runs inside VS Code
  with the MCP server attached), the director answers remotely (`harpp watch` / `decision list` /
  `decision decide`), and the harness closes the loop with `decision ack` then `decision apply`.
  State the decision key convention and that HARPP is an external service **found on `PATH`** — never
  vendored into the repository, never a hardcoded path.
- Add the **no-silent-non-delivery rule**: if HARPP is unavailable the harness must still write the local
  artifact, must say `DELIVERY: local-only — director NOT notified`, and must exit non-zero. A run may not
  treat a decision as filed because a file exists.
- Add a **"Director-away operation"** paragraph: `L0–L3` proceed unattended; the phase chain is expressed
  as a HARPP workflow manifest with bounded repair budgets; progress is reported with
  `harpp msg send` using the owner message types (`PROGRESS`, `DECISION_REQUIRED`, `BLOCKED`,
  `RELEASE_READY`, `FAILED`); `L4` is the only stop.
- Keep the prohibited-behaviours section; replace its D-references with L-references and add
  "no test may create a live decision on the host (`HARPP_NOTIFY=0` in every test invocation)".

### A2 — Schema update: `kernel/Workbench/Schemas/development-decision-request.v1.schema.json`

- `decision_class` (const `"D2"`) → `authority_level` (const `"L4"`).
- Add a required `transport` object, `additionalProperties: false`, describing delivery:
  `{channel: enum[harpp-cli, harpp-mcp, local-only], attempted: boolean, delivered: boolean,
   suppressed: boolean, harpp_decision_id: string|null, error: string|null}`.
- Add optional `notification` object `{message_type: enum[PROGRESS, DECISION_REQUIRED, BLOCKED,
  RELEASE_READY, FAILED], conversation_id: string|null, sent: boolean}`.
- Keep `options` (2–4), `recommendation`, `default_if_no_response` (const `"stop"`), `checkpoint`,
  `resolution` and everything else exactly as they are. Update the root `description` to name HARPP.

### A3 — Driver update: `tools/ai-autonomy.php`

Keep the existing structure, exit-code discipline and the `check` tripwire. Change/extend:

- **Exit codes** become `0` ok · `2` malformed input or unparseable contract (fail closed) · `3` escalate
  (L4 required) · **`4` decision filed locally but not delivered to the director**. Document them.
- **`check`** takes `--level=L0|L1|L2|L3|L4` (default `L2`). `L0|L1` → `VERDICT: PROCEED`;
  `L2|L3` → `VERDICT: RECORD`; `L4` → `VERDICT: ESCALATE`. The scope and sensitive-class rules are
  unchanged, except a sensitive trigger now forces **at least `L4`** (escalate) unless the justification is
  grounded in the contract's own `acceptance`/`constraints` text, in which case it degrades to `L3`
  (`RECORD`). Print the resolved level in the output.
- **`defer`** keeps every existing validation rule and additionally:
  - locates `harpp` with `command -v` (no hardcoded path, no shell string interpolation of user input);
  - calls `harpp decision submit --title=<question> --body=<options+recommendation+default rendered as text>
    --context=<why_now> --requested=<recommended option id> --priority=<--priority, default normal>
    --source=ikabudsix --workbench-state=ARCHITECTURE_DECISION_REQUIRED --decision-key=<decision_id>`;
  - passes the environment through so `HARPP_NOTIFY=0` / `HARPP_CONFIG` are honoured, and treats a
    `{"suppressed": true}` response as **suppressed, not delivered**;
  - records the outcome in the artifact's `transport` block and prints the delivery line
    (`DELIVERY: harpp` / `DELIVERY: local-only — director NOT notified`) plus the exact retry command
    `php tools/ai-autonomy.php defer --retry=<decision_id> --contract=...`;
  - exits `4` when not delivered, `0` when delivered;
  - writes the local artifact **always**, before attempting delivery.
- **`status`** gains `--remote`: with it, `harpp decision list --remote [--state=]` and print the HARPP
  lifecycle state per decision, merged with the local artifacts by `decision_id`; without it, behave as now.
  When HARPP is unavailable, say so and fall back to local, exit `0` (status is a read, not a gate).
- **`resume`** gains `--from-harpp`: pull `harpp decision list --remote --state=DECIDED`, match the
  `decision_key`, and use that decision text as the answer (no `--choose` needed). With `--choose` the
  behaviour is unchanged. On either path, when the answer did not originate locally, call
  `harpp decision ack <id>` then `harpp decision apply <id>` and record `resolution.source`
  (`local` | `harpp`). Never resume a decision whose HARPP state is pre-`DECIDED`.
- **`plan`** gains `--emit-manifest=PATH` (or `--manifest` to stdout): emit a HARPP workflow manifest in
  the `workflows/governed-loop.json` shape — stages `architect`, `implement`, `review`, `release-gate`,
  each with `model`, `prompt_file`, `marker`, `verify`, `timeout` — derived from the contract's required
  tests where a real command exists (use `php tests/<...>` / `vendor/bin/phpstan …` where the contract names
  them; otherwise a `verify` that is honest, e.g. `git diff --check`). It must also print the exact run
  command:
  `harpp workflow start --manifest=<path> --conversation=<id> --workspace=$(pwd) --authority-level=L2 --max-repairs=3`.
- **`plan` scope hygiene (found by dogfooding the previous slice)** — the kernel parser turns the first
  whitespace token of every `Forbidden changes` bullet into a path, so prose lines there produce phantom
  entries (observed: `No`, `other`, `bullets`, `Each`), and a directory only matches its children when the
  entry ends in `/` (observed: `.github/workflows` was typed as a file and would not have matched
  `.github/workflows/ci.yml`). Therefore: (a) `plan` must **warn** — not silently drop, not fail — for each
  scope entry that is a single bare word with no `/` or `.` and no such path in the repository, printing
  `WARN suspicious scope entry (prose?)`; (b) the policy must state the authoring rule: in
  `Forbidden changes` every bullet starts with a backticked path, directories end in `/`, and prose
  prohibitions belong in `Architectural constraints` (which is captured verbatim as text); (c) `check` must
  report an entry that matched by directory prefix as such. Do not change the kernel parser — its prose
  classification is a separate root fix that needs its own contract; record that as an open item in the
  policy's risks.
- **`notify`** (new): `notify --type=PROGRESS|DECISION_REQUIRED|BLOCKED|RELEASE_READY|FAILED --body=TEXT
  [--conversation=N --title=T]` → `harpp msg send` with `--idempotency-key` derived from
  `task + phase + type + body hash` so a retried phase boundary does not double-post. Not delivered ⇒ exit
  `4` with the same loud line. This is how the director follows the run while away.
- Style unchanged: no new dependency, no bootstrap, no network from PHP itself (the network lives in
  HARPP), docblocks everywhere, PHPStan clean.

### A4 — Test update: `tests/ai_autonomy_test.php`

Keep every existing case (adjusted to the new level vocabulary) and add, **with HARPP stubbed**:

- the test creates a temporary `bin/` directory containing an executable stub named `harpp` (a shell or
  PHP script) that appends its `"$@"` to a JSONL file and exits with a configurable code, puts that
  directory first on `PATH`, sets `HARPP_CONFIG=<tmp>/config.json`, and sets `HARPP_NOTIFY=0`;
- assert the stub was invoked with `decision submit` and that the recorded arguments carry `--title`,
  `--requested`, `--workbench-state=ARCHITECTURE_DECISION_REQUIRED` and `--decision-key=<decision_id>`;
- assert a successful stub → exit `0`, `transport.delivered === true`, output contains `DELIVERY: harpp`;
- assert a failing stub (exit `1`) → exit `4`, `transport.delivered === false` and the output contains
  `director NOT notified`; assert the local artifact still exists;
- assert a stub returning `{"ok": true, "suppressed": true}` → `transport.suppressed === true`, exit `4`
  (suppressed is not delivered);
- assert `check --level=L4` escalates (exit `3`) and `--level=L1` proceeds (exit `0`);
- assert `resume --from-harpp` (stub returning a `DECIDED` decision whose `decision_key` matches) resolves
  the decision and that the stub saw `decision ack` followed by `decision apply`;
- assert `--emit-manifest` writes a manifest whose stages are exactly
  `[architect, implement, review, release-gate]` and that it parses as JSON;
- **assert the test never reaches the network**: every `harpp` invocation in the test goes through the stub
  (the real binary is not on the stubbed `PATH`), and no test command omits `HARPP_NOTIFY=0`.
  State this in a comment at the top of the file.

If `proc_open`/`exec` is unavailable, `SKIP:` with the reason — never pass silently.

### A5 — Wiring updates (same three files as before, additive)

- `tools/ai-task` — replace the previously added `defer` example line with the HARPP-bound ones:
  `php tools/ai-autonomy.php plan --emit-manifest=/tmp/wf.json` and
  `harpp workflow start --manifest=/tmp/wf.json --conversation=<id> --authority-level=L2`, keeping the
  `defer` example. ≤ 4 lines added in total.
- `.github/AGENTS.md` — extend the "Autonomy and decision deferral" subsection: the L0–L4 ladder, that
  HARPP is the director channel (external, on `PATH`, or its MCP tools in VS Code), and the four commands
  (`plan --emit-manifest`, `check`, `defer`, `notify`). ≤ 20 lines added.
- `.github/instructions/ai-development-execution-handoff.instructions.md` — add to §12/§13 (additive
  bullets only): the authority ladder is the repo-wide vocabulary, L4 is the only human stop, an L4 is
  filed through `tools/ai-autonomy.php defer` which delivers it to the director via HARPP, and a decision
  that cannot be delivered is a failed run, not a filed one. ≤ 8 lines added.

### A6 — The two-surface seam (this is the point of the slice)

**One queue, two clients, zero divergence.** The decision queue is HARPP server-side; `harpp` CLI and the
HARPP MCP server are two clients of it. Nothing is stored in a second place, so an answer given while away
and an answer given at VS Code are the same row.

- **Verified gap**: `~/.config/Code/User/mcp.json` is 159 bytes and registers **only** `lean-ctx`
  (`servers: lean-ctx -> {args,command,env,type}`; no env keys). HARPP is **not** attached to VS Code, which
  is why `.ai/read-authority-extension.flash-run.log:1` records an agent unable to call
  `harpp_submit_decision`. The repo has no `.vscode/` directory.
- **Register the HARPP MCP server for VS Code** — **CHAIR-OWNED. Do not write
  `~/.config/Code/User/mcp.json`; it is the director's live editor config and currently provides
  `lean-ctx`.** Your part is documentation only: in the policy file, include the exact snippet to add under
  `servers`, in this shape:
  `"harpp": {"type": "stdio", "command": "python3", "args": ["/var/www/html/applicationostest/tools/harpp-bridge/harpp_mcp.py"]}`,
  and say that it is additive, that the existing entries must be preserved, and that VS Code needs a reload
  to pick it up. The chair applies it and validates the JSON.
- **Document the surface split** in the policy file, under "Director channel": the agent inside VS Code
  uses the MCP tools (`harpp_submit_decision`, `harpp_list_decisions`, `harpp_get_decision`,
  `harpp_acknowledge_decision`, `harpp_apply_decision`, `harpp_send_message`, `harpp_post_status`);
  automation outside an editor session uses the CLI (`harpp decision submit|list|view|decide|ack|apply`,
  `harpp msg send`, `harpp watch`). Both write the same server-side queue. The MCP surface has **no**
  `decide` tool by design — the director's answer arrives through the owner channel (`harpp watch`
  auto-processes owner messages and decided decisions) or `harpp decision decide <id> --decision D`; the
  harness then acknowledges and applies. State this explicitly so an agent never invents a "decide" tool
  and never treats a missing MCP tool as a reason to fall back to prose.
- **Seamless acceptance**: with the MCP server attached, an L4 decision raised by the harness is visible to
  `harpp_list_decisions` in VS Code; answering it via `harpp decision decide <id> --decision D` makes
  `tools/ai-autonomy.php resume --from-harpp` resolve it and close it with `ack` then `apply`. The same
  `decision_key` identifies it from both surfaces.
- Also state in the policy that unattended operation is the **default**: the harness runs the scoped phases
  and returns to `implement` on `CHANGES_REQUIRED` by itself; a human is involved only at L4, and the whole
  purpose is that the director creates business instead of monitoring the run.

## Architectural constraints

- HARPP is external: **no vendoring, no hardcoded path, no import of its Python modules, no changes to
  `/var/www/html/applicationostest`.** Locate it with `command -v harpp`. The single exception is the
  VS Code MCP registration in A6, which necessarily names the MCP server's path in *user-level* config.
- The repository remains usable with HARPP absent: local artifacts always written, degradation explicit,
  exit `4`. With HARPP present, `defer` must not be able to report success unless HARPP acknowledged.
- No new PHP dependency; the driver still does no network I/O itself.
- Never read, print, log or write `~/.config/harpp/config.json` values (it holds a live `bridge_key`, a CMS
  token and a Groq API key).
- Tests must never create a live HARPP decision: stub on `PATH` + `HARPP_NOTIFY=0` + sandbox `HARPP_CONFIG`.
- PHP 8.2 compatible; `vendor/bin/phpstan analyse -c phpstan.neon` stays clean.

## Files likely affected

- `.github/instructions/ai-autonomy-escalation.instructions.md` — L-ladder + HARPP channel + delivery rule
- `tools/ai-autonomy.php` — levels, HARPP delivery, `--from-harpp`, `--emit-manifest`, `notify`
- `tests/ai_autonomy_test.php` — stubbed-HARPP delivery cases
- `kernel/Workbench/Schemas/development-decision-request.v1.schema.json` — `authority_level` + `transport`
- `tools/ai-task` — HARPP-bound next steps
- `.github/AGENTS.md` — autonomy/HARPP subsection
- `.github/instructions/ai-development-execution-handoff.instructions.md` — two additive bullets
- `~/.config/Code/User/mcp.json` — **chair-owned, not yours to edit**; documented in the policy only (A6)

## Acceptance criteria

- An L4 decision is delivered to HARPP with the exact payload fields of fact 3, and its `decision_key`
  equals the local `decision_id`.
- A run whose decision was not delivered exits `4` and prints `director NOT notified`; the local artifact
  still exists and `--retry` re-attempts delivery without creating a duplicate (`decision_key`).
- `check` maps L0/L1 → PROCEED, L2/L3 → RECORD, L4 → ESCALATE, and a sensitive trigger forces L4 unless the
  justification is grounded in the contract.
- `status --remote` reports the HARPP lifecycle state; `resume --from-harpp` resolves a `DECIDED` decision
  and closes it with `ack` then `apply`.
- `plan --emit-manifest` produces a four-stage HARPP workflow manifest plus the exact `harpp workflow start`
  command, so the scoped phases can run unattended with bounded repair budgets.
- `php tests/ai_autonomy_test.php` exits `0`, and **no test invocation can reach the live HARPP**.
- The HARPP MCP server is registered for VS Code without altering the existing `lean-ctx` entry, so an L4
  decision raised by the harness is visible and answerable from the desktop VS Code app — the same
  `decision_key` as the HARPP-away channel.

## Required tests

- `php tests/ai_autonomy_test.php` — exit `0`; report passed/failed/skipped explicitly.
- `php -l tools/ai-autonomy.php`.
- `vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G tools/ai-autonomy.php tests/ai_autonomy_test.php`.
- `HARPP_NOTIFY=0 php tools/ai-autonomy.php check "edit the driver" --path=tools/ai-autonomy.php --level=L2 --contract=.ai/ai-autonomy-harness.contract.md; echo $?` → `0`.
- `HARPP_NOTIFY=0 php tools/ai-autonomy.php check "touch the kernel" --path=kernel/App.php --level=L2 --contract=.ai/ai-autonomy-harness.contract.md; echo $?` → `3`.
- `HARPP_NOTIFY=0 php tools/ai-autonomy.php plan --emit-manifest=/tmp/ai-autonomy-wf.json --contract=.ai/ai-autonomy-harness.contract.md && python3 -m json.tool /tmp/ai-autonomy-wf.json | head -20` → valid JSON, four stages.
- (chair, after your run) `php -r` JSON check on `~/.config/Code/User/mcp.json` → `servers` contains both `lean-ctx` and `harpp`.
- `HARPP_NOTIFY=0 php tools/ai-autonomy.php defer --task=probe --question=Q --why=W --option=a|A|e|c|r|reversible --contract=.ai/ai-autonomy-harness.contract.md --decisions-dir=/tmp/ai-autonomy-probe; echo $?` → `2` (one option is not a decision).

## Risks

- Duplicating HARPP logic in PHP instead of calling it — the binding must stay thin: build the payload,
  call the CLI, record the result.
- A test that stubs `harpp` badly could still let a real call through; the stub directory must be first on
  `PATH` and the test must assert the stub captured every invocation.
- `--from-harpp` matching the wrong decision: match on `decision_key`, never on the question text.
- Escalation fatigue: keep L0–L1 wide and L4 narrow, per the policy.

## Forbidden changes

- No changes to `/var/www/html/applicationostest/**` or anything outside this repository.
- No vendoring of HARPP, no Python imports, no hardcoded `/home/...` or `/var/www/html/...` path to it.
- No edits to `kernel/Workbench/Development/**`, `phpstan-baseline.neon`, `composer.json`, `package.json`,
  `.github/workflows/**`, or any file already modified/untracked in git before this slice — other than the
  seven files listed above.
- No live HARPP call from any test; no `HARPP_NOTIFY` other than `0` in test invocations; no network.
- No printing or persisting of `~/.config/harpp/config.json` secret values.
- No edit to any other server entry, key or value in `~/.config/Code/User/mcp.json`; the change is the
  addition of the `harpp` server only, and its existing `env` values are never printed.
- No `git add`/`commit`/`push`; no branch creation or switching.
- No weakening, skipping or deleting an existing test to reach a pass.
