# C5 CONTRACT — Governance census: make the ungoverned remainder visible

status: READY_FOR_IMPLEMENTATION
owner: implementation agent (Sol)
repo: /var/www/html/ikabudsix — branch `main` (PR #108 is separate; do not touch that branch)

## Context you must reuse (do not re-derive)

`.ai/thesis-measurement-sol-run.log` is the reference method. It established:

- `daily-ledger`: **0 of 52** business POST operations governed. Route dispatch is a direct call —
  `executeModuleHandler()` → `$routeCallable($params)` at
  `src/helpers/module-manager.php:2992-2998` — with no capability authorization, no kernel audit,
  no kernel idempotency.
- `daily-ledger` **reimplements governance locally** (`dl_auditLog()` → `$ctx->audit()` at
  `handlers.php:25-36`, cache-backed idempotency at `handlers.php:76-103`).
- **Akira's ratio has never been measured.** 15 modules, 84 capabilities, 77 routes, 43 dependency
  ids, and 0/15 modules carry a Workbench contract.
- `workbench:validate` is a contract **file-existence** checker; `workbench:explain` needs a run ID;
  `workbench:audit` audits `kernel/WorkflowEngine.php`. None of them can report governance coverage.

Background: `docs/architecture/akira-beyond-the-cms.md` (P2) and
`docs/architecture/kernel-substrate-thesis.md`.

## Objective

One command that answers, for any module, **which routed operations are governed, which are
declared exempt with a reason, and which are undeclared** — with evidence per operation — and that
reports the **honest governance ratio**, first for Akira, then for every module.

This is the instrument that must exist *before* enforcement (C6), because you cannot enforce what
you cannot measure, and we currently cannot state Akira's own number.

## Deliverables

**D1 — `workbench:governance` command.** Human-readable by default, `--json` for machines.
Accepts a module id, or `--all` across every discovered module.

For each routed operation it must report a classification and its evidence:

| Class | Meaning |
|---|---|
| `governed` | the handler's body reaches the capability bus |
| `exempt` | explicitly declared exempt, **with a reason** |
| `undeclared` | neither — the failure state |

Evidence per operation must include the route, method, handler reference, the resolved source
`file:line`, and **how** the classification was reached (what was found, or not found).

**D2 — A declarative exempt list with reasons.** A module must be able to declare a route exempt
and say why (session/login routes and health endpoints are legitimate exemptions). Put it where it
fits the existing conventions — `module.json` or the module's `workbench-contract.json`; choose and
justify. An exemption without a reason must be rejected, not silently accepted.

**D3 — Per-module summary + honest ratios.** A table: module, governed, exempt, undeclared, total,
ratio. Then **report Akira's real figure** — all 15 `cms-akira-*` modules plus `gui-settings` — and
`daily-ledger`'s, using the same denominator as the reference measurement (business operations,
auth/session infrastructure excluded) and stating that rule in the output.

**D4 — The instrument must itself be falsifiable.** Add a test that proves the classifier works by
asserting on known cases: at least one route it must classify `governed`, at least one it must
classify `undeclared`, and that an exemption without a reason fails. An instrument nobody can
disprove is an oracle, not a measurement.

## Design requirements

- **Domain-neutral.** No module id, domain noun or business concept may appear in the classifier
  logic. `grep -riE "daily-ledger|akira|content_type" kernel/Workbench/` must stay empty. Anything
  domain-specific belongs in the *declaration*, not the instrument.
- **Not gameable by comments.** Detection must not be satisfied by a comment, a string literal, or
  a mention in a docblock. Say in the output how it distinguishes a real bus call.
- **Static and safe.** It must not execute module code, run migrations, enable modules, or touch
  tenant data. `daily-ledger` must be analysed without being enabled, migrated, or written to.
- **Cheap.** It should run over every module in seconds, so it can eventually run in CI.

## Explicit non-goals

- **No enforcement change.** Do not modify dispatch, the capability bus, or the authorization
  registry. Making enforcement mandatory is C6, and it depends on this census existing.
- **No changes to `modules/daily-ledger/**`.** Untracked by design — ownership is the user's call.
  You may *analyse* it; you may not edit or commit it.
- **No Akira route rewrites.** If Akira turns out to be largely ungoverned, **report it**. That is
  a finding, not a defect to paper over in this cycle.
- Do not re-label an ungoverned operation as exempt to improve the ratio. Exemption means *"this
  operation does not need authority"*, never *"I could not make it governed"*.

## Constraints

- Do not commit, push, or branch. Leave everything uncommitted.
- Back up `storage/modules.json` before running any test suite and restore it afterwards
  (gui-settings ON, all `cms-akira-*` ON, `daily-ledger` OFF).
- Do not touch `.ai/**`.
- If this requires a kernel change beyond adding a Workbench subsystem and CLI wiring, **STOP** and
  return `ARCHITECTURE_DECISION_REQUIRED` with the options.

## Acceptance

1. `workbench:governance --all` runs and produces the table, including Akira's real ratio.
2. The classifier test passes and would fail if the classifier stopped detecting bus calls.
3. `daily-ledger` is analysed without being enabled, migrated, or modified.
4. `grep -riE "daily-ledger|akira|content_type" kernel/Workbench/` returns nothing.
5. The full suite is unchanged: **0 failed**.
6. Raw command output pasted as evidence for each item above.

## Deliverable

Result block per the standard format, with raw output. **If Akira's ratio is low, lead with that.**
An uncomfortable true number is worth more than a comfortable asserted one.
