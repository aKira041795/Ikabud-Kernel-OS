# /implement — PHASE 4 — Workbench auditor of Phase-1 hardening (Codex Sol)

You are the /implement agent on Codex Sol. Working directory: /var/www/html/ikabudsix (Ikabud-Kernel-OS).
Execute PHASE 4 of the adjudicated roadmap: expand Workbench into a static AUDITOR that regresses the Phase-1
WorkflowEngine concurrency/idempotency invariants + flags MySQL-8-only SQL, emitting via the existing IssueLedger,
exposed as `php ikabud workbench:audit`. Fully static (no live DB). Real edits + real tests.

## Authority & rules (read first)
1. Contract (AUTHORITATIVE): /var/www/html/ikabudsix/.ai/phase4-workbench-auditor-contract-2026-09-06.md
   Read scope.allowed / scope.prohibited / constraints / acceptance / verification / risk and follow exactly.
2. Governing: /var/www/html/ikabudsix/.github/instructions/ai-development-execution-handoff.instructions.md
   (/implement sequence, bounded repair, result format). /var/www/html/ikabudsix/.github/copilot-instructions.md
   (debug-first, check BOTH storage/logs/app.log + storage/logs/error.log after runs).
3. Scoping research (file:line anchors — read actual code, do not assume):
   - Reuse: kernel/Workbench/Issues/IssueLedger.php (~L8; issue.v1 format; JSON storage storage/private/workbench/issues).
   - Static-scan precedent: `architecture:check` in the `ikabud` CLI (~L4850). CLI workbench:* dispatch precedent:
     ikabud ~L6124-6210 + help lines ~L1166. Web: /superadmin/workbench (src/http/core-routes.php:33;
     superadmin-handlers.php ~L1715, IssueLedger wired ~L2950).
   - Phase-1 hardened invariants to AUDIT are in kernel/WorkflowEngine.php (read the ACTUAL file to anchor exact
     signatures/comments): GET_LOCK/RELEASE_LOCK symmetry in start() absent-tuple mutex; findActiveRun dedupe
     before insert; step claim UPDATE ... status IN (pending,failed) with rowCount() !== 1 guard + attempt++
     inside the claim; run-row FOR UPDATE committed BEFORE $this->app->cap()->call(); cancel()/replay() run-row
     guard never reclaiming in-flight 'running'; fail-closed post-dispatch persistence (interrupted, not
     retryable); MySQL-8-only SQL patterns (OVER(, CTE WITH ... AS (, JSON_TABLE, SKIP LOCKED).

## Task (single objective)
Build kernel/Workbench/Audit/WorkflowGuardAuditor.php — a deterministic static scanner. It MUST audit the current
hardened kernel/WorkflowEngine.php with ZERO findings (baseline clean, no false positives) and MUST detect each
reintroduced regression from fixtures. Wire `php ikabud workbench:audit`. Emit via IssueLedger (existing format —
NO schema change).

## Requirements (from contract — implement all)
- Auditor: static file read + line-indexed token/regex checks anchored on stable invariant signatures/comments.
  Each finding: invariant code, severity, file:line, message. Baseline clean on the real file is a HARD gate.
- Cover at minimum: GET_LOCK/RELEASE_LOCK symmetry; claim rowCount guard + in-claim attempt increment;
  FOR UPDATE before cap->call() (no capability dispatch under the DB lock); cancel/replay run-row guard +
  no in-flight 'running' reclaim; fail-closed post-dispatch persistence; MySQL-8-only SQL pattern flags.
- CLI: new `php ikabud workbench:audit` (mirror existing workbench:* dispatch + help precisely), prints findings,
  exits non-zero on criticals. Findings route into the existing IssueLedger so they appear in the
  /superadmin/workbench issues UI.
- Tests: tests/workbench_workflow_guard_audit_test.php (plain-PHP pattern per tests/mysql57_skip_locked_compat_test.php):
  (a) audit the REAL hardened file => ZERO findings; (b) temp-dir fixture sources each reintroduced regression =>
  expected finding (correct code/severity/line). Static only — no DB, no bootstrap beyond constants.

## Hard prohibitions
- Only: kernel/Workbench/Audit/WorkflowGuardAuditor.php (new) + `ikabud` CLI wiring + tests/ + (only if a doc note
  is warranted) docs. NO migration/DDL; NO MySQL-8-only SQL in the auditor; NO change to WorkflowEngine.php
  behaviour; NO change to IssueLedger/schemas; NO ARK/CMS; NO other roadmap phases; NO full-suite runs.
- Auditor must NOT depend on live DB / heavy runtime machinery. Deterministic.

## Verification (capture RAW to test_results/phase4-evidence.log)
- php -l on new/changed files.
- php tests/workbench_workflow_guard_audit_test.php (full output).
- Run `php ikabud workbench:audit` and capture its output (expect zero criticals on the real repo).
- vendor/bin/phpstan analyse --level=6 --no-progress on changed files; git diff --check; git diff --name-only + --stat.
- wc -c storage/logs/app.log storage/logs/error.log BEFORE and AFTER; report any error-level lines.

## Return format (final message; concise; file:line refs)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:              (files + file:line refs)
implementation_summary: (checks implemented, baseline-clean proof, CLI wiring, IssueLedger routing)
verification:         (point to test_results/phase4-evidence.log; summarize counts)
risks:
unresolved:
recommended_next_state: (REVIEW_REQUIRED if done per contract)
Do NOT return empty. Do NOT report success without the raw evidence file complete.
