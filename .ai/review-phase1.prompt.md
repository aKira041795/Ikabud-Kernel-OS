# /review — Phase 1: WorkflowEngine concurrency + idempotency hardening (architectural review gate)

You are the /review agent in the Ikabud governed AI workflow, running on Codex Sol.
Working directory: /var/www/html/ikabudsix. Read-only architectural review — do NOT edit files, do NOT run tests.
Return a verdict with precise remediation requirements only.

## Inputs to review
1. Architecture contract (authoritative): /var/www/html/ikabudsix/.ai/kernel-6x-roadmap-contract-2026-09-06.md
   — especially scope.allowed, scope.prohibited, constraints, acceptance, verification, risk.
2. /implement evidence: /var/www/html/ikabudsix/test_results/implement-sol.log — the GPT-sol implementer's run
   transcript. Read its tail / extracted final Implementation Result (status/changed/verification sections).
3. Git diff of the actual changes: `git -C /var/www/html/ikabudsix status --short` and
   `git -C /var/www/html/ikabudsix diff -- kernel/WorkflowEngine.php tests/ docs/kernel/workflow-system.md`
   (plus any new untracked test file under tests/). Read the changed code directly with ctx_read as needed.
4. Governing rules: .github/instructions/ai-development-execution-handoff.instructions.md (section 15 /review),
   .github/copilot-instructions.md.

## What to verify (architectural review, not re-implementation)
- Concurrency correctness: is the run claim atomic? Is the step claim (pending|failed -> running) atomic with the
  attempt increment inside it (rowCount guard)? Is claim-then-release used — NO DB lock held across a long
  capability call? Does contention return ok:false + distinct run_busy (never silent skip / double execution)?
- Idempotency: start() dedupe for active runs is non-breaking (callers preserved); handleEvent() redelivery -> one
  active run; replay() serialized/refused against in-flight advance(); no double side effects.
- MySQL 5.7 Compatibility compliance: no window functions/CTEs/JSON_TABLE; no new migration/DDL.
- Scope compliance: only kernel/WorkflowEngine.php + tests + docs/kernel/workflow-system.md changed; no other
  roadmap phases; no WorkflowRuntime state-machine semantics changes unless demonstrably shared guard.
- API stability: public return shapes (ok, run_id, step_id, error, status, run_busy) preserved.
- Test adequacy vs contract acceptance (a)-(e); evidence the implementer actually ran the targeted tests and
  checked BOTH logs (app.log + error.log); php -l clean; no new PHPStan errors.
- Implementation Result honesty: does the reported status match the diff/test evidence?

## Return format (concise; verdict ONLY + precise remediation; do not rewrite code)
verdict: PASS | CHANGES_REQUIRED
findings: (numbered; each = issue + why it violates the contract/architecture + file:line if known)
remediation: (ONLY if CHANGES_REQUIRED — precise, bounded requirements to hand back to /implement)
scope_note:
Do NOT return empty. Do NOT take over implementation. If evidence is insufficient to judge, say so explicitly with what is missing.
