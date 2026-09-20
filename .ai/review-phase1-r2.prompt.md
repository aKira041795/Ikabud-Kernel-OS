# /review — ROUND 2 — Phase 1: WorkflowEngine concurrency + idempotency (architectural review gate)

You are the /review agent on Codex Sol. Working directory: /var/www/html/ikabudsix.
Read-only architectural review — do NOT edit files, do NOT run tests. Return a verdict with precise remediation only.

## Context
Round 1 review returned CHANGES_REQUIRED (7 findings). Round-2 /implement (GPT sol) reports PASS addressing all 7.
Your job: independently verify the round-2 repairs are correct, complete, and within scope. Do not rubber-stamp.

## Inputs
1. Contract + chair adjudication (authoritative): /var/www/html/ikabudsix/.ai/kernel-6x-roadmap-contract-2026-09-06.md
   — read the "Review round 1 ... + chair adjudication" section; the rulings on #5 (approve dispatch correction)
   and #6 (keep retention guard, escalated separately) are chair decisions you may sanity-check but not reopen as blockers.
2. Round-1 review findings: /var/www/html/ikabudsix/test_results/review-sol.log
3. Round-2 implement evidence: /var/www/html/ikabudsix/test_results/implement-sol-r2.log (raw outputs captured)
4. Actual code + diff: `git -C /var/www/html/ikabudsix status --short`,
   `git -C /var/www/html/ikabudsix diff -- kernel/WorkflowEngine.php tests/workflow_concurrency_test.php docs/kernel/workflow-system.md`
   Read the changed code directly (ctx_read) — especially the advisory-lock mutex in start(), the cancel() guard,
   and the fail-closed interrupted/persistence split in advance().

## Verify (hard scrutiny on round-2 changes)
- #1 Advisory-lock mutex: GET_LOCK acquired on the SAME connection as the run-creation transaction? Lock name
  tuple-scoped and released reliably (RELEASE_LOCK or connection end) on ALL paths (success, contention, exception)?
  No DDL added. Deterministic re-read returns the winning active run_id. Deadlock/contention handling is explicit.
- #2 Empty-range race test is a REAL two-connection/forked race (no artificial sleeps) and asserts one active row,
  one shared run id, one side effect. Does it actually prove the mutex, or could it pass vacuously?
- #3 cancel() guard: cancel takes the run-row FOR UPDATE guard and refuses (run_busy) when a step is mid-flight,
  so replay() cannot reclaim an executing capability. No path lets cancel flip an in-flight step to cancelled.
- #4 Fail-closed: dispatch vs completion-persistence are separated. A post-dispatch persistence failure transitions
  the step to non-retryable 'interrupted' (or stays fail-closed 'running') — never retryable → no double execution.
  advance()/replay() refuse to reclaim interrupted steps. Capability exceptions (no side effects) still retry safely.
- #5/#6 (chair-approved): confirm the canonical cap()->call() dispatch and the guarded retention include are kept
  and now DOCUMENTED in code + docs (not just present).
- Scope compliance: only kernel/WorkflowEngine.php + tests/workflow_concurrency_test.php + docs/kernel/workflow-system.md.
  No migration/DDL, no MySQL-8 SQL, no WorkflowRuntime changes, no other roadmap phases.
- API stability: public return shapes (ok, run_id, step_id, error, status, run_busy, deduplicated) preserved.
- Evidence honesty: does test_results/implement-sol-r2.log actually show the raw command runs (engine 32/32,
  lifecycle 12/12, concurrency 29/29, php -l, PHPStan, log checks)? Any discrepancy between reported and actual?

## Return format
verdict: PASS | CHANGES_REQUIRED
findings: (numbered; each = issue + why it violates contract/architecture + file:line if known)
remediation: (ONLY if CHANGES_REQUIRED — precise bounded requirements for /implement round 3)
scope_note:
Do NOT return empty. Do NOT take over implementation. If evidence is insufficient, say what is missing explicitly.
