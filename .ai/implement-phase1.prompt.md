# /implement — Phase 1: WorkflowEngine concurrency + idempotency hardening

You are the /implement agent in the Ikabud governed AI workflow, running on Codex Sol.
Execute PHASE 1 of the adjudicated roadmap contract. Do real file edits and run real tests.
Working directory: /var/www/html/ikabudsix (Ikabud-Kernel-OS repo). Do all work there.

## Authority & rules (read first)
1. Read /var/www/html/ikabudsix/.ai/kernel-6x-roadmap-contract-2026-09-06.md — this is the AUTHORITATIVE contract.
   Its scope.allowed, scope.prohibited, constraints, acceptance, verification, risk sections are binding.
2. Read /var/www/html/ikabudsix/.github/instructions/ai-development-execution-handoff.instructions.md and follow
   the /implement implementation sequence, bounded repair loop, and result format. NEVER enter an unbounded
   edit->test loop; if the same failure persists without new evidence, or scope exceeds the contract, STOP and
   return BLOCKED / ARCHITECTURE_DECISION_REQUIRED. Do NOT add any migration/DDL autonomously.
3. Read /var/www/html/ikabudsix/.github/copilot-instructions.md. Mandatory debug-first rule + check BOTH logs
   (storage/logs/app.log AND storage/logs/error.log) after every test run and on every issue.

## Task (single objective)
Make kernel/WorkflowEngine.php safe under concurrency and idempotent under retry/duplicate delivery, per the
contract acceptance criteria, with matching tests and a doc update. Nothing more.

## Required discovery (verify by reading actual code — never assume)
- kernel/WorkflowEngine.php (~933L): start ~L525, handleEvent ~L451, advance ~L618, cancel ~L722, replay ~L766.
- kernel/WorkflowRuntime.php: determine whether it shares workflow_runs or uses its own instance rows BEFORE
  coupling any locking. If it uses separate rows, leave it untouched.
- migrations/009_kernel_workflow_runs.sql: confirm workflow_run_steps.idempotency_key column + index.
- tests/workflow_engine_test.php and tests/workflow_lifecycle_test.php: existing patterns; the 32-test suite must stay green.
- The shared TestHarness / test bootstrap: confirm it can open TWO live DB connections for real concurrency tests.
  Do NOT fake races with artificial sleeps — use a real lock-hold window or two handles.
- Grep ALL callers of WorkflowEngine::start() before changing dedupe behaviour; preserve their expectations
  (this is the sharpest compatibility risk). Dedupe must be non-breaking.
- docs/kernel/workflow-system.md (update with new semantics).

## Implementation requirements (from contract — implement all)
- Run-level guard: advance()/replay() (and any step-claiming path) claim the run atomically so two overlapping
  advances on one run cannot both execute the same step. On contention return ok:false + a distinct `run_busy`
  reason. Claim-then-release; NEVER hold a DB lock across a long capability call.
- Atomic step claim: transition chosen step pending|failed -> running via a guarded UPDATE (rowCount()===1) inside
  the run guard; make the attempt increment part of that atomic claim (no read-modify-write outside a guard).
- start(): no duplicate active runs for same (workflow_key, module, entity_type, entity_id) when one is
  pending/running — return the existing run id (ok:true) or ok:false + existing run_id. Explicit, non-breaking.
- handleEvent(): auto-start idempotent under redelivery — no second active run.
- replay(): serialize with in-flight advance() via the same run guard; no double execution (serialized or refused).
- Keep the synthetic idempotency_key as a trace field. Caller-supplied idempotency-key RESULT REUSE is out of
  scope UNLESS a clean seam already exists — if one exists, implement+test it; else document as follow-on. Do not
  fabricate a reuse mechanism.
- Tests: extend tests/workflow_engine_test.php + tests/workflow_lifecycle_test.php OR add
  tests/workflow_concurrency_test.php. Cover: (a) double-advance executes capability exactly once (side-effect
  counted via a test capability), (b) start() dedupe with an active run, (c) duplicate handleEvent() delivery ->
  one active run, (d) replay-while-running serialized/refused without double-execution, (e) attempt increments
  exactly once per execution.
- Doc: update docs/kernel/workflow-system.md (lock model, dedupe behaviour, return shapes).

## Hard prohibitions
- Only kernel/WorkflowEngine.php + tests + docs/kernel/workflow-system.md. NO migration/DDL. NO MySQL 8-only SQL
  (window functions, CTEs, JSON_TABLE) — FOR UPDATE and atomic UPDATE are allowed (MySQL 5.7-safe).
- No changes to WorkflowRuntime state-machine semantics unless it demonstrably shares the same guard.
- No cross-module DB access; no weakening of capability dispatch; no other roadmap phases (entity render path,
  ARK, Workbench); no .env/config/template changes.
- Do NOT run the full suite — targeted tests only.

## Verification (run these; report exact results)
- php -l on every changed file.
- Targeted: php tests/workflow_engine_test.php, php tests/workflow_lifecycle_test.php, and the new concurrency test.
- Check BOTH logs after runs. PHPStan level 6 on changed files if available (no NEW errors beyond baseline).
- Review your own git diff for scope compliance before finishing.

## Return format (final message, concise; summaries with file:line refs, NOT full file dumps)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:            (files + file:line refs of guard / dedupe / replay / claim changes)
implementation_summary: (lock/claim model, transaction boundaries, dedupe behaviour, return shapes, WorkflowRuntime coupling finding)
verification:       (exact targeted-test commands + PASS/FAIL + counts, php -l results, log check result)
scope: unexpected_files:
risks:
unresolved:
recommended_next_state:

Do NOT return empty. Do NOT report success without verification evidence. Honest status only.
