# HARPP v1 — FROZEN (2026-09-16)

**Why.** The director's directive of 2026-09-16: *"governance can become an attractor that consumes execution."*
v1 accumulated rules, each individually reasonable, until the aggregate default behaviour was: **when uncertain,
analyse the governance system instead of advancing the objective.** The measurement is in CD-60 and CD-61 — seven
consecutive Chair decisions about the harness's own instruments (CD-46 → CD-52), 23 of 60 decisions and 29 of the
last 45 dispatched runs shaped by the harness rather than by the product.

**v1 is the research record, not the operating system.** It is frozen, not deleted. It answered a real question —
*what happens if you aggressively formalise governed autonomy?* — and the answer is worth keeping.

## Frozen

No further development, no new runs, no new doctrine, no new measurement:

- `tools/ai-autonomy.php` — plan / check / defer / resume / stop-report / trust surface
- `tools/ai-run.php` — the run ledger, trust-surface hashing, commit gate, scope delta
- the per-slice contract regime (`.ai/*.contract.md`) and `tools/ai-authority-preflight.php` taxonomy
- the census, pillars and any measurement framework
- `.github/instructions/ai-autonomy-escalation.instructions.md` and
  `.github/instructions/ai-development-execution-handoff.instructions.md` — **read-only**. They stay in the tree as
  the record and for anything the v2 constitution does not address, but for work under
  `tools/harpp2/CONSTITUTION.md` **the constitution is authoritative** and overrides them.

## Still usable

- Every artifact in `.ai/` and `.ai/runs/` as **evidence** — including the blocked runs, which are the record of the
  apparatus, not of the work.
- `.ai/chair-decisions.md` as the Chair's provenance log. v2 keeps recording decisions there.
- The **pty-safe dispatch technique** in `.ai/dispatch-lane.sh` (setsid + `script -qec`, so a lane is not killed when
  the spawning terminal backgrounds it). v2 implements its own small dispatcher and borrows only this technique.
- `.ai/akira-completion-plan.md`, `.ai/akira-master-plan.md` — the objective and its status board, both live.

## Carried forward into v2

Provenance · evidence over executor opinion · boundary preservation · red-first falsification · one working tree one
writer (serial lanes) · pty-safe dispatch.

## Deliberately not carried

Trust surface and commit gate · per-path authority taxonomy · per-slice contract boilerplate · census and pillars ·
stop-report formalism · the deferred-decision schema. Each returns only if a real run stops because its absence
blocked execution — the blocker method in `tools/harpp2/CONSTITUTION.md`.

## Unfreezing

Requires a director decision. Nothing in this file may be reversed by a run, a contract, or a Chair decision.
