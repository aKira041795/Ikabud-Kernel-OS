# CONTRACT — Acceptance A: a simulated HARPP, and the standing contract's remaining sections

status: READY_FOR_IMPLEMENTATION
repo: `/var/www/html/ikabudsix` — work in the current tree (branch
`feat/akira-editorial-and-authority-coverage`); do not create or switch branches, do not commit
references: `.ai/ai-autonomy-harness.contract.md` (the standing harness contract — read it first)
authority: owner directive 2026-09-13 *"simulate a harpp process and one unattended slice"* and
*"always delegate to capable models whenever possible"*

## Objective

Make the harness's **away-path provable without touching the director's live queue**, and finish the
standing contract so later work can reference it. Two deliverables: a simulation harness under
`.ai/harpp-sim/` that speaks the subset of the `harpp` CLI that `tools/ai-autonomy.php` uses, plus the
four remaining sections of `.ai/ai-autonomy-harness.contract.md`. Then prove the round trip end to end.

The point of simulating rather than using the live bridge: the delivery protocol has never been proven
against a real queue, and proving it by filing live rows on the director's production queue is
unacceptable for a test. A simulator proves the *harness side* of the protocol. It does **not** prove the
network path, and nothing may claim that it does.

## Verified facts — do not re-derive

- The driver is `tools/ai-autonomy.php`. Read it before writing the stub; do not guess its arguments.
- It locates the bridge with `command -v harpp`, so a stub directory placed first on `PATH` intercepts it.
- It calls, at minimum: `decision submit --title --body --context --requested --priority --source
  --workbench-state --decision-key`, `decision list --remote --state=...`, `decision ack <id> --rationale`,
  `decision apply <id> --rationale`, and `msg send --body --title --idempotency-key`.
- `HARPP_NOTIFY=0` makes the real bridge return `{"ok": true, "suppressed": true}`; the driver treats that
  as **not delivered** (exit `4`). The simulator must honour the same semantics so tests never look greener
  than production.
- `resume --from-harpp` matches a `DECIDED` row by `decision_key`, then closes it with `ack` then `apply`.
- The real lifecycle is `NOTIFIED → VIEWED → DECIDED → ACKNOWLEDGED → APPLIED`.
- `tests/ai_autonomy_test.php` (23 cases) already stubs `harpp` for the driver's own tests; read how it
  does that and keep the two consistent rather than inventing a third convention.

## Deliverables

### A1 — `.ai/harpp-sim/harpp` (executable)

A zero-dependency stub (python3 via `#!/usr/bin/env python3`, or bash) implementing the subset above with
the real lifecycle states. Requirements:

- State lives in `$HARPP_SIM_STORE` (default `.ai/harpp-sim/store.json`), created on demand. It must be
  safe to run repeatedly and must never write outside `$HARPP_SIM_STORE`'s directory.
- `decision submit` appends a `NOTIFIED` row and prints `{"ok": true, "data": {"id": N, "decision_key": …}}`,
  where `N` is the next integer id and `decision_key` is echoed back when supplied.
- `decision list --remote [--state=S]` filters by state and prints `{"data": {"decisions": [ … ]}}` with the
  fields the driver reads (`id`, `decision_key`, `title`, `decision`, `lifecycle_state`).
- `decision ack <id>` / `decision apply <id>` transition state and print `{"ok": true}`; an illegal
  transition must exit non-zero rather than silently succeeding.
- `msg send` appends a message row and prints `{"ok": true}`; record the `--idempotency-key` and refuse a
  duplicate key (proving the driver's idempotency, not undermining it).
- **Honour `HARPP_NOTIFY=0`**: print `{"ok": true, "suppressed": true}` and record nothing, mirroring the
  real bridge.
- It must not perform network I/O, must not read `~/.config/harpp`, and must not import anything outside
  the Python standard library.

### A2 — `.ai/harpp-sim/config.json`

A sandbox config (`base_url` pointing at a non-routable local value, a placeholder `bridge_key`, tenant 0)
so an accidental real call cannot succeed. No real credentials, ever.

### A3 — `.ai/harpp-sim/director-answer` (executable)

Simulates the director's away-channel: `director-answer <id> --decision <option-id> [--rationale TEXT]`
sets the row to `DECIDED` with that decision and prints the row. This is the stand-in for `harpp watch`.

### A4 — `.ai/harpp-sim/README.md`

State plainly: what it simulates (the CLI subset and the lifecycle), and what it does **not** prove — the
real network path, the bridge API, push/desktop notification, and any guarantee about the live queue.
Include the exact round-trip commands and the warning that a green simulation must never be reported as
production delivery.

### A5 — Finish `.ai/ai-autonomy-harness.contract.md`

Insert these four sections **before** `## Architectural constraints`, leaving the seven required headings
intact and non-empty (the kernel parser needs them), and keeping every bullet in `Files likely affected`
and `Forbidden changes` path-first with a trailing `/` for directories:

1. `## Runbook (unattended slice)` — the ordered commands for taking one scoped contract from
   `architect` to `release-gate` with no human input, the L4 stop condition, the evidence to collect at
   each phase, and the definition of done. Reference `tools/ai-task`, `tools/pi-arch-review.sh`,
   `tools/pi-arch-debate.py`, `harpp workflow start --manifest`, and `php tools/ai-autonomy.php plan
   --emit-manifest`.
2. `## Simulation harness` — what `.ai/harpp-sim/` proves, what it does not, and the rule that the
   simulated path is never used to claim production delivery.
3. `## Completion record` — the shipped slice: the seven files, the test evidence (23/23), the PHPStan
   2.2.14 upgrade and its CI result, PR #140, and the two acceptances with their current status.
4. `## Contract template for new work` — a copy-paste skeleton containing the reference block
   (`harness: references …`), the seven required headings, and a reminder that prose prohibitions belong
   in `Architectural constraints` while `Forbidden changes` bullets must be path-first.

### A6 — Prove it, and keep the transcript

Run the round trip with the stub first on `PATH` and record the full transcript with real exit codes in
`.ai/harpp-sim/round-trip.log`: `plan` → `defer` (expect exit `0`, `DELIVERY: harpp`) → `director-answer`
→ `status --remote` → `resume --from-harpp` (expect `ack` then `apply` in that order in the store) →
`status` showing `RESOLVED`. Also record a suppressed run (`HARPP_NOTIFY=0`) proving the driver reports
non-delivery with exit `4` and still writes the local artifact.

## Architectural constraints

- The simulator proves the harness side only. Never describe it as delivery to the director.
- No network, no `~/.config/harpp` access, no real credentials, no new dependency.
- `tools/ai-autonomy.php` is **not** to be modified. If it cannot work against the stub without a change,
  STOP and report `BLOCKED` with the exact incompatibility rather than editing it.
- The standing contract's seven required headings must survive; do not restructure the file.
- Tests must not be able to reach the live queue: stub first on `PATH`, `HARPP_SIM_STORE` (or
  `HARPP_CONFIG`) sandboxed, `HARPP_NOTIFY=0` wherever absence of delivery is being asserted.

## Files likely affected

- `.ai/harpp-sim/harpp` — new stub bridge
- `.ai/harpp-sim/director-answer` — new stand-in for the director's channel
- `.ai/harpp-sim/config.json` — new sandbox config
- `.ai/harpp-sim/README.md` — new, states the limits
- `.ai/harpp-sim/round-trip.log` — new transcript with real exit codes
- `.ai/ai-autonomy-harness.contract.md` — four sections added before Architectural constraints

## Acceptance criteria

- `PATH=.ai/harpp-sim:$PATH HARPP_SIM_STORE=.ai/harpp-sim/store.json php tools/ai-autonomy.php defer …`
  exits `0` and prints `DELIVERY: harpp`, and the store contains one `NOTIFIED` row with the right
  `decision_key`.
- `director-answer` moves that row to `DECIDED`; `resume --from-harpp` resolves it and the store then shows
  `ACKNOWLEDGED` before `APPLIED`.
- With `HARPP_NOTIFY=0` the same flow exits `4`, prints `director NOT notified`, and still writes the local
  artifact — i.e. suppression is not delivery.
- A duplicate `msg send` idempotency key is refused by the stub.
- `~/.config/harpp` is untouched: capture mtime+hash of `config.json` and `decisions.json` before and
  after, and show they are identical.
- The four new sections exist in the standing contract, before `## Architectural constraints`, with the
  seven required headings still present and non-empty.

## Required tests

- `php tools/ai-autonomy.php plan --contract=.ai/ai-autonomy-harness.contract.md` → exit `0` (proves the
  additions did not break the parser).
- `python3 .ai/harpp-sim/harpp decision list --remote` → exit `0`, valid JSON.
- The full round trip from A6, with the transcript written to `.ai/harpp-sim/round-trip.log`.
- `php tests/ai_autonomy_test.php` → still exit `0` (no regression from the new files).
- The before/after `~/.config/harpp` mtime+hash comparison.

## Risks

- A stub that is more permissive than the real bridge gives false confidence: mirror the real responses,
  including the `suppressed` shape and non-zero exits for illegal transitions.
- Two conventions for stubbing `harpp` (this one and the test file's) would drift; align them.
- The four contract sections could drift from the shipped reality; cite the real evidence, not intent.

## Forbidden changes

- `tools/ai-autonomy.php` — no edit; report BLOCKED instead if incompatible.
- `~/.config/harpp/` — never read, never write.
- `composer.json` — no new PHP dependency.
- `package.json` — no new Node dependency.
- `.github/workflows/` — no edit to CI workflow definitions.
- `kernel/Workbench/Development/` — no edit to those classes.
- `git add` — no staging, commit, push, branch creation or switching (a rule, not a path).
