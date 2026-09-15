# /review — 6.3 DURABLE IDEMPOTENCY (shared kernel primitive) — architectural review gate — Codex Sol

You are the /review agent on Codex Sol. Working directory: /var/www/html/ikabudsix.
Read-only review — do NOT edit files or run tests. Verdict + precise remediation only.

## Context
6.3 /implement (GPT sol) reports PASS. Independent review required — do not rubber-stamp.

## Inputs
1. Contract (authoritative): /var/www/html/ikabudsix/.ai/phase63-durable-idempotency-contract-2026-09-06.md
   — scope.allowed/prohibited, constraints, acceptance, verification, risk.
2. Roadmap context: /var/www/html/ikabudsix/.ai/kernel-6x-five-guarantees-roadmap-2026-09-06.md
3. Implement report: /var/www/html/ikabudsix/test_results/implement-phase63.log
4. RAW evidence: /var/www/html/ikabudsix/test_results/phase63-evidence.log
5. Actual code + diff:
   `git -C /var/www/html/ikabudsix diff -- kernel/Http/Idempotency.php kernel/WorkflowEngine.php docs/kernel/workflow-system.md`
   and read tests/durable_idempotency_test.php (ctx_read).

## Verify (hard scrutiny)
- SHARED PRIMITIVE, not a bespoke seam: claim/commit/release over kernel_idempotency_keys with atomic unique
  (hash,tenant) claim returning {new|duplicate|conflict}. Reuses existing columns — NO DDL. MySQL-5.7-safe
  (no window fn/CTE/JSON_TABLE). Claim is concurrency-safe: two concurrent first claims → exactly one winner;
  the loser deterministically receives the winner's outcome (no sleeps, real two-connection).
- WORKFLOW ADOPTION: start() optional external key — first executes + commits outcome; duplicate (same key + same
  NORMALIZED payload hash) returns stored run_id/result, no new run, no re-execution; conflicting payload →
  explicit idempotency_payload_conflict (no execution, no overwrite). Keyless path + return shapes UNCHANGED.
  Synthetic step key stays a trace field.
- NORMALIZATION: deterministic (recursive assoc-key sort, list order/types preserved, identity incl. tenant) and
  documented in workflow-system.md. Same logical payload in different serializations cannot false-conflict; real
  payload differences cannot be missed.
- TENANT SCOPING: same key text in different tenants does not collide (verified by test).
- FAIL-CLOSED: processing uncertainty + abandoned claims fail closed (never silent double-execution, never silent
  swallow of a conflict). No DDL fallback needed (unique constraint verified — confirm against migration 011 + live).
- SCOPE: only kernel/Http/Idempotency.php + kernel/WorkflowEngine.php + new test + docs/kernel/workflow-system.md.
  No WorkflowRuntime change, no other guarantees (6.4/6.5/6.6), no ARK/CMS, no DDL/MySQL-8 SQL.
- EVIDENCE: phase63-evidence.log is a complete raw transcript (26/26 new + engine 32/32 + lifecycle 12/12 +
  concurrency 38/38 + lint + PHPStan + diff checks + logs, error.log empty). HTTP/EventBus unification documented
  as follow-on (not silently dropped).
- Phase-1/Phase-2 suites still green in the transcript (no regression from touching WorkflowEngine/Idempotency).

## Return format
verdict: PASS | CHANGES_REQUIRED
findings: (numbered; issue + why + file:line if known)
remediation: (ONLY if CHANGES_REQUIRED — precise bounded requirements)
scope_note:
Do NOT return empty. Do NOT take over implementation. If evidence is insufficient, say what is missing explicitly.
