# /review — 6.3 ROUND 2 — durable idempotency (architectural review gate) — Codex Sol

You are the /review agent on Codex Sol. Working directory: /var/www/html/ikabudsix.
Read-only review — do NOT edit files or run tests. Verdict + precise remediation only.

## Context
6.3 review R1 = CHANGES_REQUIRED (4 findings). R2 /implement (GPT sol) reports PASS addressing all 4.
Independently verify R2 truly closes them without regressions. Do not rubber-stamp.

## Inputs
1. Contract: /var/www/html/ikabudsix/.ai/phase63-durable-idempotency-contract-2026-09-06.md
2. R1 review findings: /var/www/html/ikabudsix/test_results/review-phase63.log
3. R2 implement report: /var/www/html/ikabudsix/test_results/implement-phase63-r2.log
4. RAW evidence: /var/www/html/ikabudsix/test_results/phase63-evidence-r2.log
5. Actual code + diff: `git -C /var/www/html/ikabudsix diff -- kernel/Http/Idempotency.php kernel/WorkflowEngine.php docs/kernel/workflow-system.md`
   and read tests/durable_idempotency_test.php (the two-connection + ownership sections).

## Verify (R1 findings 1-4 closed?)
1. Deterministic loser outcome: no fixed short workflow-duration cutoff that throws. Loser waits/retries and
   observes the winner's committed outcome; genuinely abandoned/uncertain processing rows fail closed with a
   DISTINCT idempotency_in_progress (never auto-reclaimed, never double-executed). Five-minute cap documented.
   Does the loser return the winner's identical outcome/result when the winner completes after >10s? (Proof in test
   or code inspection.)
2. Test proof: two-connection test PROVES the loser entered the exact production claim wait (GET_LOCK) while the
   winner held it, before releasing — real synchronization, no sleeps; then asserts identical outcome, one run, one
   side effect.
3. Ownership: commit()/release() verify the calling connection owns the claim (advisory-lock ownership / IS_USED_LOCK
   / RELEASE_LOCK semantics) before mutating the processing row; a non-owner concurrent release is a no-op and cannot
   remove a live claim (double-execution risk closed). No DDL.
4. Evidence: phase63-evidence-r2.log is a complete raw transcript (migration + live unique-index SQL + outputs +
   DB identity, 30/30 idempotency + 32/32 engine + 12/12 lifecycle + 38/38 concurrency full output, PHPStan, lint,
   diff name/stat/check, final app.log + error.log contents; error.log empty).
Regression/scope: only the 4 permitted files changed; no DDL/MySQL-8 SQL/WorkflowRuntime change/6.4-6.6/ARK-CMS;
keyless path unchanged; Phase-1/Phase-2 suites green in transcript.

## Return format
verdict: PASS | CHANGES_REQUIRED
findings: (numbered; issue + why + file:line if known)
remediation: (ONLY if CHANGES_REQUIRED)
scope_note:
Do NOT return empty. Do NOT take over implementation.
