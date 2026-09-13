---
description: "Normative policy for bounded implementation autonomy and structured decision escalation."
applyTo: "**/*"
---
# AI Autonomy and Escalation Policy

## Invariant

An approved task contract is the permission envelope. Autonomy is never unbounded: it is bounded by the contract's scope, constraints, forbidden changes, and acceptance criteria. Deferral is a recorded state, not a failure.

Unattended operation is the default. The harness runs scoped phases and returns to `implement` after `CHANGES_REQUIRED` without supervision. A human is involved only at L4; the purpose is for the director to create business, not monitor the run.

## Autonomy envelope

- `allowed_scope`: paths the task authorises the harness to change.
- `forbidden_scope`: paths the harness must not change.
- `constraints`: architectural limits on every implementation choice.
- `acceptance`: outcomes the unattended run must achieve.
- `required_tests`: verification the run is authorised and required to perform.
- `forbidden_rules`: textual prohibitions that remain binding even when no path can represent them.
- `baseline_scope`: pre-existing changes the harness may preserve but must not treat as task scope.

Contract authoring rule: every `Forbidden changes` bullet starts with a backticked path and directories end in `/`. Put prose prohibitions in `Architectural constraints`, which is retained verbatim as text.

## Authority ladder

HARPP's `DEFAULT_AUTHORITY_POLICY` is the source of truth for the repository-wide L0–L4 vocabulary:

| Level | Classification | Required action |
|---|---|---|
| `L0` | Trivial, reversible, in-scope | Proceed silently. |
| `L1` | In-scope, contained, reversible | Proceed. |
| `L2` **default** | In-scope work with real consequence | Proceed and record. |
| `L3` | In-scope but cross-cutting or hard to reverse | Proceed, record, and notify. |
| `L4` **human approval** | A director decision | Stop and file. |

An L4 is triggered by:

- Any path outside `allowed_scope`.
- Anything in `forbidden_scope`.
- Schema, DDL, or migration change.
- Auth, authorisation, policy, or security weakening.
- New runtime dependency.
- Public API or capability contract change.
- Cross-module coupling or ownership change.
- Data deletion or irreversible migration.
- Disabling, skipping, deleting, or weakening an existing test or gate to get a pass.
- Editing a quality-gate baseline.
- A second failed repair attempt on the same failure.
- Any acceptance criterion that cannot be met without widening scope.
- Attempt or budget exhaustion.

## Phase progression

The harness runs `architect → implement → review → release-gate` unattended. `CHANGES_REQUIRED` returns to `implement` automatically. L4 is the only human stop. Asking permission for an in-scope L0–L3 step is a defect, not diligence.

## Director-away operation

L0–L3 proceed unattended. The phase chain is a HARPP workflow manifest with bounded repair budgets. Report progress with `harpp msg send` and the owner message types `PROGRESS`, `DECISION_REQUIRED`, `BLOCKED`, `RELEASE_READY`, and `FAILED`; L4 is the only stop.

## Director channel (HARPP)

HARPP is the external director service, found through `PATH`: never vendor it and never use a hardcoded CLI path. An L4 is filed with `harpp decision submit`, or with `harpp_submit_decision` when an agent is inside VS Code with the MCP server attached. The `decision_key` is exactly the local `decision_id`. The director answers remotely through `harpp watch`, `harpp decision list`, and `harpp decision decide`; the harness closes the lifecycle with `harpp decision ack` then `harpp decision apply`.

There is one server-side queue and two clients, with no duplicate decision store. Inside VS Code use MCP tools `harpp_submit_decision`, `harpp_list_decisions`, `harpp_get_decision`, `harpp_acknowledge_decision`, `harpp_apply_decision`, `harpp_send_message`, and `harpp_post_status`. Outside an editor use `harpp decision submit|list|view|decide|ack|apply`, `harpp msg send`, and `harpp watch`. Both surfaces identify the same row by `decision_key`.

The MCP surface intentionally has no `decide` tool. The answer arrives through the owner channel (`harpp watch` processes owner messages and decided decisions) or through `harpp decision decide <id> --decision D`; the harness then acknowledges and applies it. Never invent a MCP decide tool or fall back to prose because it is absent.

The chair must add this entry under `servers` in `~/.config/Code/User/mcp.json`, preserving all existing entries (including `lean-ctx`) and validating the resulting JSON:

```json
"harpp": {"type": "stdio", "command": "python3", "args": ["/var/www/html/applicationostest/tools/harpp-bridge/harpp_mcp.py"]}
```

This registration is additive and VS Code must be reloaded afterward. Repository agents must not edit the chair-owned user configuration.

With the server attached, an L4 submitted by the harness is visible through `harpp_list_decisions`; a CLI answer from `harpp decision decide <id> --decision D` is consumed by `tools/ai-autonomy.php resume --from-harpp`, which performs ack then apply on the same `decision_key`.

### No silent non-delivery

The local artifact is always written before delivery is attempted. If HARPP is unavailable or suppresses delivery, print exactly `DELIVERY: local-only — director NOT notified`, print the retry command, and exit 4. A file's existence does not mean the decision was filed with the director. Only an acknowledged HARPP submission is delivered.

## Deferred-decision contract

An L4 stop creates an artifact conforming to `urn:ikabud:workbench:development-decision-request:v1`. It contains 2–4 mutually exclusive options, each with `id`, `label`, `effect`, `cost`, `blast_radius`, and `reversibility`; a mandatory recommendation naming one option and explaining why; `default_if_no_response: stop`; HARPP transport status; the checkpoint and exact resume command; and an `already_done` list. Work remains at a resumable checkpoint.

## Answering and resuming

The director answers with one option id. The harness resumes at the recorded checkpoint and records whether the source was `local` or `harpp`. An unanswered or pre-`DECIDED` HARPP decision never applies an option.

## Prohibited behaviours

- Do not touch a path outside `allowed_scope` or anything in `forbidden_scope`; defer at L4.
- Do not make schema, DDL, or migration changes without L4 approval.
- Do not weaken auth, authorisation, policy, or security.
- Do not add a runtime dependency without L4 approval.
- Do not change a public API or capability contract without L4 approval.
- Do not introduce cross-module coupling or change ownership without L4 approval.
- Do not delete data or perform an irreversible migration without L4 approval.
- Do not disable, skip, delete, or weaken an existing test or gate to get a pass.
- Do not edit a quality-gate baseline.
- Do not continue after a second failed repair attempt on the same failure.
- Do not widen scope to meet an acceptance criterion.
- Do not continue after attempt or budget exhaustion.
- No silent scope expansion or silent HARPP non-delivery.
- No "asking a question" without options and a recommendation.
- No reporting a `SKIP` as a pass.
- No test may create a live decision on the host: every test invocation sets `HARPP_NOTIFY=0` and uses a stubbed `harpp` first on `PATH` with a sandbox `HARPP_CONFIG`.

## Risks and open items

`DevelopmentTaskContract` currently classifies the first whitespace token of prose in `Forbidden changes` as a path and requires a trailing `/` to classify directories. The driver warns on suspicious bare words. Correcting the kernel parser is a separate root fix requiring its own contract.

## Relationship to the directive

This file is normative for §12 and §13 of `ai-development-execution-handoff.instructions.md`; it makes their autonomy and repair boundaries enforceable and does not change role separation.
