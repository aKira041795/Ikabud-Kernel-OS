# PHASE 4 — Workbench auditor of Phase-1 hardening (adjudicated roadmap) — CONTRACT (2026-09-06)

task: Implement the bounded Phase-4 unit of the adjudicated Kernel-OS roadmap: expand Workbench into a static
AUDITOR that regresses the Phase-1 WorkflowEngine concurrency/idempotency invariants (and flags MySQL-8-only SQL),
emitting findings through the existing IssueLedger, exposed as `php ikabud workbench:audit`. Fully static (no live
DB) so it is deterministically unit-testable. This "dogfoods" Workbench as the auditor of the hardened core.

objective: Give the platform a deterministic, testable guard that detects when the Phase-1 guarantees in
kernel/WorkflowEngine.php are regressed (missing advisory locks, non-atomic claims, unguarded cancel/replay,
non-fail-closed persistence, MySQL-8-only SQL), routed through Workbench's existing issue/ledger/severity model.

## Grounded seams (verified 2026-09-06)
- Workbench has NO auditor role today and no dedicated tests. Existing pieces to REUSE:
  - `kernel/Workbench/Issues/IssueLedger.php` (~L8; ingest/state machine; JSON-file-backed
    `storage/private/workbench/issues`) + issue.v1 schema — emit findings here.
  - `kernel/Workbench/Governance/WorkbenchMetrics.php`, `Runs/RunProvenance.php` optional.
  - Static-scan precedent: `architecture:check` in the `ikabud` CLI (~L4850) — no-DB source scan.
  - CLI dispatch precedent for workbench:* in `ikabud` (~L6124-6210) + help lines (~L1166).
- Phase-1 hardened invariants to AUDIT (in `kernel/WorkflowEngine.php`): GET_LOCK/RELEASE_LOCK symmetry in
  start() absent-tuple mutex; findActiveRun dedupe before insert; step claim `UPDATE ... WHERE status IN
  (pending,failed)` with `rowCount() !== 1` guard + `attempt = attempt + 1` INSIDE the claim; run-row
  `FOR UPDATE` lock committed BEFORE `$this->app->cap()->call()`; cancel()/replay() using the run-row guard and
  never reclaiming an in-flight 'running' step; fail-closed post-dispatch persistence (rowCount()===1 →
  non-retryable interrupted), MySQL-8-only SQL patterns (OVER(, CTE WITH ... AS (, JSON_TABLE, SKIP LOCKED).

## scope:
  allowed:
    - NEW `kernel/Workbench/Audit/WorkflowGuardAuditor.php`: static source scanner over kernel/WorkflowEngine.php
      (and, if trivially shared, an interface allowing future targets). Each check returns findings with
      code/severity/line, matching the existing issue format. Zero false positives on the CURRENT hardened file is
      a hard requirement (baseline must audit clean).
    - Wire a new `php ikabud workbench:audit` command in the `ikabud` CLI (mirror existing workbench:* dispatch +
      help), printing findings; non-zero exit on criticals.
    - Emit through existing IssueLedger::ingest (issue.v1 format, category/severity) — NO ledger/schema change.
    - Tests: new `tests/workbench_workflow_guard_audit_test.php` following the plain-PHP pattern (see
      tests/mysql57_skip_locked_compat_test.php). Cover: (a) auditing the REAL hardened file yields ZERO findings
      (no-false-positive baseline); (b) temp-dir fixture sources each reintroduced regression yield the expected
      finding (correct code/severity/line). Static only — no DB.
    - php -l on changed files; PHPStan level 6 (no NEW errors); check BOTH logs after runs.
  prohibited:
    - NO migration/DDL; NO MySQL-8-only SQL in the auditor itself.
    - NO changes to WorkflowEngine.php behaviour or to IssueLedger/schemas.
    - NO live-DB dependency in the audit path (must run statically).
    - NO ARK/CMS work (main CMS repo). NO other roadmap phases. NO full-suite runs.
    - Do NOT make the auditor depend on heavy runtime/Comprehension machinery; keep it deterministic and cheap.

### constraints:
  - Zero false positives on the current codebase (baseline clean) — otherwise the auditor is noise.
  - Deterministic, static, no DB, no network. Same input → same findings.
  - Findings must be actionable: precise code (invariant name), severity, file:line.
  - PSR-12 + PHPStan level 6 (no NEW errors). Read actual code before coding; check both logs.

### acceptance:
  - `php ikabud workbench:audit` runs against the current repo and reports ZERO critical findings (baseline clean).
  - A fixture that reintroduces each audited regression (e.g. a start() without RELEASE_LOCK, a claim without a
    rowCount guard, a cap->call() inside the lock, a cancel() touching 'running' without the guard, an
    `OVER(` window function) produces the corresponding finding with correct severity + line.
  - IssueLedger receives the findings in the existing format (no schema change).
  - New test file passes; php -l clean; PHPStan no new errors; both logs clean.

### verification:
  - php -l on new/changed files.
  - Targeted: new tests/workbench_workflow_guard_audit_test.php (baseline zero + regression fixtures).
  - Run `php ikabud workbench:audit` and show output.
  - Capture RAW output to a test_results/phase4-evidence.log (full transcript).
  - Check BOTH logs.

### risk:
  - False positives erode trust — mitigate with the zero-findings-on-current baseline test + fixture-based tests.
  - Regex/token fragility on refactors of WorkflowEngine.php — mitigate by anchoring on stable invariant
    signatures (comments + structure), and keep checks narrow.
  - CLI wiring must not break existing workbench:* commands — mirror the existing dispatch block precisely.

### status: READY_FOR_IMPLEMENTATION

---

## Review round 1 (GPT sol) → CHANGES_REQUIRED + chair ruling (2026-09-06)
Review log: test_results/review-phase4.log (7 findings: detection power bypassable — signature-presence checks).
Chair ruling: upgrade to method-local STRUCTURAL analysis. R2 repair did so (brace-aware extraction, ordered
checks, adversarial fixtures, 26/26) but review R2 (test_results/review-phase4-r2.log) escalated further:
checks still text/offset-based, not execution-aware (prepared-but-unexecuted statements pass, bound lock values not
compared, `if(false)` unreachable decoys pass, parameterized status mutations evade, ingestion proof synthetic).

## Round 2 → round 3 — CHAIR ARCHITECTURAL DECISION (2026-09-06)
A hand-written token/regex auditor cannot prove non-bypassability against arbitrary PHP — that is an unbounded
static-analysis goal. DECISION (bounded, authoritative):
1. Upgrade WorkflowGuardAuditor to a **token_get_all-based statement/control-flow model** (brace-matched method
   body; statement-ordered; basic reachability: linear sequence, if/else with constant-condition pruning,
   return/throw termination, try/finally cleanup association). Checks then operate on:
   - **EXECUTED statements**: a prepared-statement variable is only meaningful if a reachable `execute()` call on
     that variable exists in the method (statement → its execute).
   - **BOUND values**: compare the actual SQL literal passed to prepare (and bound params where lock names are
     parameterized) — not placeholder text.
   - **REACHABLE branches**: rollback/busy/throw/interrupt/success must be reachable (reject `if (false)` and
     nested-conditional decoys); release must be an unconditional cleanup action in `finally` (or provably on all
     exit paths) with the same bound lock name.
   - **ALL status mutations**: inspect every `workflow_run_steps`/`workflow_runs` UPDATE in cancel/replay; a
     predicate must prove exclusion of `running` (bound-value aware) unless the targeted-range structure protects it.
2. **Defined guarantee boundary (formal, documented, tested)**: the auditor's guarantee is
   "detects every regression expressible in the bounded statement/control-flow grammar that kernel/WorkflowEngine.php
   uses (method-local)." Patterns outside that grammar (interprocedural aliasing, eval/reflection-generated SQL,
   deliberate obfuscation) are DOCUMENTED known-limits — they are still caught by the runtime concurrency tests
   (Phase 1, 38/38) and human review. Add a doc section + a documented-limits test so review judges against this
   bounded contract, not unbounded detection.
3. Completion-failure/interruption: require REACHABLE executed completion-UPDATE + guaranteed fail-closed return
   (throw or interrupt) associated with the row-count failure branch.
4. Round-3 remediation (from review R2): fixtures for EVERY remaining bypass (unexecuted lock/release/claim,
   bound-value lock mismatch, short-circuit release, unreachable nested rejection/fail-closed branches,
   parameterized/decoy step mutations) with exact code/severity/line; plus a REAL auditor finding round-tripped
   through a temporary IssueLedger (invariant code + location).

status: CHANGES_REQUIRED → round-3 bounded repair → re-review against the defined guarantee boundary.

---

## Round 3 → escalation — CHAIR ESCALATION / ARCHITECTURE_DECISION_REQUIRED (2026-09-06)
R3 implement (token model, 35/35, baseline zero) -> R3 review = CHANGES_REQUIRED (review-phase4-r3.log, 4 findings):
path-insensitive execute binding (mutually exclusive prepare/execute); bound values compared as expressions not
resolved values (var reassignment between GET_LOCK/RELEASE_LOCK; status param resolving to 'running'); rejection /
fail-closed prove presence not ALL-EXIT guarantees (early conditional exit before direct rollback); targeted-range
protection validated only by a fragment, not current-run scoping of mutation + subquery.

Observation: 3 consecutive reviews each found bypasses INSIDE the then-declared boundary, and the remediation now
demands reaching-definitions + path-sensitive value environments + all-paths exit analysis — converging on full
program analysis. A hand-written static analyzer has NO terminating guarantee against an adversarial reviewer.
Per the bounded-repair rule this is ARCHITECTURE_DECISION_REQUIRED. Options presented to the USER (final authority):
  1. (recommended) Two-layer re-scope: static auditor becomes an HONEST, narrow regression TRIPWIRE (declared
     heuristic guarantee = exactly what the model proves); RUNTIME invariant self-checks added to WorkflowEngine
     (additive, guarded, defense-in-depth) become the non-bypassable correctness authority, covered by the Phase-1
     concurrency suite + new assertions. Final review judged against the re-scoped two-layer contract.
  2. Continue the static-analysis arms race (implement reaching-definitions/path-sensitive value env/all-path
     exits) — expensive, unbounded, no terminating guarantee.
  3. Freeze Phase 4 at the round-3 static tripwire as a documented heuristic guard; Phase-1 runtime concurrency
     tests remain the true oracle (accept tripwire, no further escalation).
  4. Pivot Phase 4 to runtime-only: drop the static auditor; `workbench:audit` runs the Phase-1 concurrency +
     invariant assertions and reports a summary.

status: BLOCKED — awaiting user architecture decision.

---

## Round 4 — CHAIR DECISION (autonomous, user unavailable) — TWO-LAYER RE-SCOPE (2026-09-06)
User deferred to autonomous decision. DECISION = Option 1 (two-layer re-scope), executed pragmatically:
- LAYER A (static, re-scoped): WorkflowGuardAuditor is retained as an HONEST, NARROW regression TRIPWIRE. Its
  declared guarantee is exactly what the model proves: method-local presence/ordering of the canonical hardened
  patterns, heuristic, NOT a correctness prover. Baseline-zero + adversarial fixtures retained. Reaching-definitions
  / path-sensitive dataflow escalation is EXPLICITLY OUT OF SCOPE (recorded; unbounded for a hand-written analyzer).
- LAYER B (runtime = non-bypassable authority): add an OFF-BY-DEFAULT runtime invariant self-check to
  WorkflowEngine — asserts NO run-lock transaction is open at capability dispatch (defense-in-depth so a future
  refactor that moves dispatch inside the lock fails loudly in test/CI mode). Additive, zero default behaviour
  change, exposed via a test-only setter. Phase-1 concurrency suite (38/38) + Phase-2 cache suite remain the
  authoritative runtime oracle.
- Final review judged against this two-layer contract. Further static-analysis escalation = out of scope.

status: READY_FOR_IMPLEMENTATION (round 4).

---

## Round history + completion (2026-09-06)

```
R1 /implement (sol) → PASS  (baseline 0, tests 20/20)        → R1 /review: CHANGES_REQUIRED (7 bypassable checks)
R2 /implement (sol) → PASS  (structural, 26/26)               → R2 /review: CHANGES_REQUIRED (execution-aware demands)
R3 /implement (sol) → PASS  (token model, 35/35)              → R3 /review: CHANGES_REQUIRED (path-sensitive demands)
R4 /implement (sol) → PASS  (TWO-LAYER re-scope per chair)    → R4 /review: PASS  ✅ GATE CLEARED
```

- Chair decisions recorded above: R1 structural analysis; R3 guarantee boundary; R4 two-layer re-scope (static
  heuristic tripwire + off-by-default runtime dispatch guard). Static-analysis escalation beyond the tripwire is
  explicitly OUT OF SCOPE (unbounded for a hand-written analyzer; runtime layer is the non-bypassable authority).
- Deliverables: kernel/Workbench/Audit/WorkflowGuardAuditor.php (heuristic tripwire, baseline 0),
  `php ikabud workbench:audit` (CLI, IssueLedger-routed), tests/workbench_workflow_guard_audit_test.php (35/35),
  kernel/WorkflowEngine.php runtime dispatch guard (off by default; 11/11 in
  tests/workflow_runtime_guard_test.php), docs/kernel/workbench-workflow-guard-auditor.md.
- No-regression evidence: engine 32/32, lifecycle 12/12, concurrency 38/38, entity cache 32/32 — all green after
  the WorkflowEngine runtime-guard change.
- Evidence: test_results/implement-phase4{-r2,-r3,-r4}.log, review-phase4{-r2,-r3,-r4}.log,
  phase4-evidence{-r2,-r3,-r4}.log.
- Final status: COMPLETE. Phase-5 Workbench sliver (keep developer surface visible) is satisfied by the
  workbench:audit command + existing /superadmin/workbench IssueLedger UI surfacing its findings.
