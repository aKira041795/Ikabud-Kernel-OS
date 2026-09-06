# KERNEL OS 6.x ROADMAP — ADJUDICATED CONTRACT (debate 2026-09-06)

task: Convert the adjudicated "Kernel OS 6.x + DiSyL roadmap" debate verdict into a scoped, implementable contract. This file is the master roadmap (all 5 phases, in adjudicated order). Phase 1 is the single implementable unit for THIS assignment (READY_FOR_IMPLEMENTATION). Phases 2–5 are PLANNED — they get their own contracts; do NOT implement them here.

objective: Land the first unit of the core-first verdict: make `WorkflowEngine` safe under concurrency and idempotent under retry/duplicate delivery (Kernel OS 6.2 "Trust Boundaries" item #7 — workflow hardening), so the concurrency axis stops being a silent double-execution risk before any ARK/Workbench breadth is added.

---

## Master roadmap (adjudicated order — from the 2026-09-06 debate)

```
1. 6.2 deterministic trust slice          → PHASE 1 (THIS CONTRACT, READY)
   [workflow step idempotency + locks — first unit]
2. Entity-view render-path performance    → PHASE 2 (PLANNED; own contract)
   [fragment/cache, MySQL-5.7-safe]
3. ARK authority layer + renderer breadth → PHASE 3 (PLANNED; lives in CMS app
   on hardened Entity view                  repo `storage/cms-themes/ark` — NOT
   (= Entity-view adoption vehicle)         in this barebones kernel repo)
4. Workbench expansion                    → PHASE 4 (PLANNED; dogfooded as
   auditor of phases 1–3)
5. Parallel small ARK/Workbench sliver    → PHASE 5 (PLANNED; keeps the product
   (non-blocking, demo-able each cycle)     visible)
```

Scope note: this repo is Ikabud-Kernel-OS (barebones kernel + DiSyL + Workbench + workflow kernel). ARK theme files and full CMS surface are NOT present here — phases 3/5 cannot be implemented in this workspace and are recorded for the main app repo.

---

## PHASE 1 — IMPLEMENTABLE UNIT: WorkflowEngine concurrency + idempotency hardening

### Grounded gap (verified 2026-09-06, `kernel/WorkflowEngine.php`, 933L)

- `advance(int $runId)` (L618–717) reads the run with a plain `SELECT * ... LIMIT 1` (L624) — NO `SELECT ... FOR UPDATE`, NO run-level lock. Two overlapping `advance()` calls can both select the same pending step and both execute the capability → double side effects.
- Step claim (L637–664) is read-then-write, not atomic: picks `pending|failed` then issues a separate `UPDATE ... SET status='running', attempt=attempt+1` (L663). No atomic claim guard (`UPDATE ... WHERE status='pending'` + `rowCount()`), so two concurrent advances can claim the same step; `attempt` increments are read-modify-write (L656–657), not atomic.
- `start()` (L525–608) creates a NEW run unconditionally per call (L546–559) — no active-run dedupe for the same `(workflow_key, module, entity_type, entity_id)`.
- `handleEvent()` (L451–493) calls `start()` for every matching subscription on every event delivery (L480–492) — duplicate/redelivered events create duplicate runs. No duplicate-event suppression.
- `replay()` (L766–806) + concurrent in-flight `advance()` → no run-level serialization → potential double execution after reset.
- Step `idempotency_key` column + index already exist in schema (`migrations/009_kernel_workflow_runs.sql`, `workflow_run_steps.idempotency_key` + `idx_workflow_step_idempotency`) but the value is a synthetic `step_<key>_<runId>_<n>` (L567, L581) — never caller-supplied, never enforced, provides zero dedupe.

### scope:
  allowed:
    - `kernel/WorkflowEngine.php` ONLY (plus tests and the workflow doc). Anchor lines: `start` L525, `handleEvent` L451, `advance` L618, `cancel` L722, `replay` L766.
    - Add a run-level concurrency guard: `advance()`/`replay()` (and any path that claims a step) must claim the run atomically so two overlapping advances on the same run cannot both execute the same step. On contention, return `ok:false` + a distinct `run_busy` reason — never a silent skip, never double execution. Prefer claim-then-release over holding a write lock across a long capability call (do NOT hold a DB lock during the capability execution).
    - Make the step claim atomic: transition the chosen step `pending→running` with an atomic guarded update (e.g. `UPDATE ... WHERE status IN ('pending','failed') ... ` claiming with `rowCount()===1` inside the run guard), and make the `attempt` increment part of that atomic claim — no read-modify-write outside a guard.
    - `start()`: prevent duplicate active runs for the same `(workflow_key, module, entity_type, entity_id)` when a run is `pending`/`running`. Behaviour must be explicit and non-breaking: return the existing active run id (ok:true + existing `run_id`) OR `ok:false` + `run_id` of the active run. Read ALL callers of `start()` first and preserve their expectations.
    - `handleEvent()`: auto-start must be idempotent under redelivery — no second active run for the same workflow/entity when one is already `pending`/`running`.
    - `replay()`: serialize with in-flight `advance()` using the same run guard; a replay racing a live advance must not double-execute (deterministic: serialized or refused).
    - Keep the synthetic `idempotency_key` as a trace field. Definition/caller-supplied idempotency-key **result reuse** is OUT OF SCOPE for this unit UNLESS a clean seam already exists; if you find one, implement it and test it; otherwise document it as a follow-on in the doc. Do not fabricate a reuse mechanism.
    - Tests: extend `tests/workflow_engine_test.php` + `tests/workflow_lifecycle_test.php` OR add `tests/workflow_concurrency_test.php`. Cover: (a) double-advance executes the capability exactly once (side-effect counted via a test capability), (b) `start()` dedupe for an entity with an active run, (c) duplicate `handleEvent()` delivery → one active run, (d) replay-while-running is serialized/refused without double-execution, (e) attempt increments exactly once per execution. Keep the existing 32-test workflow suite green.
    - Doc: update `docs/kernel/workflow-system.md` with the new concurrency/idempotency semantics (lock model, dedupe behaviour, return shape).

  prohibited:
    - No changes to `WorkflowRuntime.php` state-machine semantics (CMS-content workflow path) unless it demonstrably shares the same guard; verify first whether `WorkflowRuntime` uses `workflow_runs` or its own instance rows before coupling.
    - NO new migration / DDL. The schema already carries `idempotency_key`, `attempt`, `max_attempts`, `status`. If the implementer proves app-level locking cannot express a required guard, STOP and return `BLOCKED / ARCHITECTURE_DECISION_REQUIRED` BEFORE adding any migration (do not add DDL autonomously).
    - No MySQL 8-only SQL (window functions, CTEs, `JSON_TABLE`). `SELECT ... FOR UPDATE` and atomic `UPDATE` are allowed (MySQL 5.7-safe).
    - No cross-module DB access; no weakening of the capability call path; no changes to how capabilities are dispatched.
    - Do NOT touch other roadmap phases (entity render path, ARK, Workbench) or unrelated kernel services.
    - No full-suite runs during implementation — targeted tests only.
    - No `.env`, config, or template changes.

### constraints:
  - MySQL 5.7 Compatibility profile: InnoDB row locks are transactional — wrap the guard in the correct transaction boundary and never hold a write lock across a long-running capability call (claim → execute → release).
  - Capability side effects must run AT MOST ONCE per logical step execution. Idempotency lives at the run/step level; do not attempt to make capabilities internally idempotent.
  - Preserve the public API return shapes (`ok`, `run_id`, `step_id`, `error`, `status`, `run_busy`) — no breaking signature changes for module callers.
  - Fail closed: any lock/claim failure returns an explicit error; never silently mark a step completed or skip it.
  - Kernel style: PSR-12 (php-cs-fixer kernel scope) + PHPStan level 6 (existing baseline tolerated; NO new errors).
  - Follow the repo debug-first rule: read the code, then the two existing workflow test files, THEN implement; verify by reading actual code, not assumptions.

### acceptance:
  - Two overlapping `advance($runId)` calls (simulated with two DB connections/transactions in the test harness) execute the target capability exactly ONCE for that step; the loser returns a distinct busy/error state without corrupting step state.
  - `start()` for an entity that already has an active (`pending`/`running`) run creates NO second run and returns the existing run id (or an explicit non-ok result) — no silent duplicate.
  - `handleEvent()` delivered twice for the same event yields a single active run for the same workflow/entity.
  - `replay()` racing an in-flight `advance()` does not double-execute the step (serialized or refused deterministically).
  - Step `attempt` increments exactly once per execution (no lost increments from concurrent claims).
  - `tests/workflow_engine_test.php` (32 tests) and `tests/workflow_lifecycle_test.php` stay green; new concurrency tests pass.
  - `php -l` clean on all changed files; no new PHPStan errors; `storage/logs/app.log` + `storage/logs/error.log` checked after test runs (both logs, per repo rule).

### verification:
  - `php -l kernel/WorkflowEngine.php` and every touched test file.
  - Targeted runs: `php tests/workflow_engine_test.php`, `php tests/workflow_lifecycle_test.php`, plus the new concurrency test.
  - Check BOTH logs after runs (`storage/logs/app.log`, `storage/logs/error.log`).
  - Grep changed code for MySQL-8-only constructs (none expected — no migration).
  - Do NOT run the full suite during implementation.

### risk:
  - Callers may rely on `start()` always creating a run → dedupe must be non-breaking (return existing run_id) or opt-in; READ the callers first (this is the sharpest compatibility risk).
  - `WorkflowRuntime::transition()` has its own transaction/retry path (`runPrimaryDbOperation`, retry on disconnect). Verify whether it shares `workflow_runs` before coupling locking; if it uses separate instance rows, leave it untouched.
  - Concurrency tests in single-process PHP need two live DB connections; confirm the shared TestHarness supports two handles before writing the test; do not use artificial sleeps to fake a race — use a real lock-hold window.
  - Transaction boundary mistakes (lock held across capability call, or released before claim) are the primary correctness hazard — keep claim and execute in the correct scope.
  - `replay()` semantics change risk for operators who call it while a run is genuinely stuck; keep refusal reasons explicit and logged.

### status: READY_FOR_IMPLEMENTATION

---

## Review round 1 (GPT sol) → CHANGES_REQUIRED + chair adjudication (2026-09-06)

Review log: `test_results/review-sol.log` (7 findings). Chair rulings:

- **#1 (start empty-range race)** — FIX: add MySQL-5.7-safe no-DDL mutex (advisory lock GET_LOCK) or deterministic re-read; no DDL.
- **#2 (missing empty-range race test)** — FIX: real two-connection race test.
- **#3 (cancel clears in-flight running step)** — FIX: cancel must take the run guard and never reclaim an in-flight step; refuse or fail-closed.
- **#4 (dispatch + persist share catch → double exec)** — FIX: split phases; post-dispatch persistence failure must fail closed (status is VARCHAR(50) — a non-retryable state needs NO DDL).
- **#5 (dispatch path)** — ARCHITECT-APPROVED correction, NOT a violation. `App::capabilities()` returns CapabilityRegistry (no `call()` — registry contract is register/has/providers/capabilityIds/resolve/inspect/inspectAll); HEAD `app()->capabilities()->call()` would fatal on any real dispatch. `$this->app->cap()->call()` is the canonical CapabilityBus dispatch (used by EntityViewResolver/EventTriggers/ComponentRenderer/etc.). KEEP; require code+doc comment.
- **#6 (retention-helper)** — DO NOT revert (HEAD fatals on load — `src/helpers/workflow-retention.php` absent in this repo, verified). Keep load guard; comment it. Escalated as SEPARATE baseline blocker (upstream sync gap), outside Phase 1.
- **#7 (evidence)** — Chair independently reran: engine 32/32, lifecycle 12/12, concurrency 18/18, php -l clean, error.log 0 bytes. Substantiated. Repair round must capture raw outputs to test_results/.

status: CHANGES_REQUIRED → back to /implement (round 2, bounded repair) → then /review (round 2).

---

## Execution assignment (2026-09-06, chair)

```
/implement  → GPT sol  (openai-codex/gpt-5.6-sol) — best capabilities, autonomous per contract scope
/review     → GPT sol  (openai-codex/gpt-5.6-sol) — fresh review pass (contract + diff + tests)
fallback    → openai-codex/gpt-5.5 available for cheaper passes
verified    → gpt-5.6-sol OK, gpt-5.5 OK; gpt-5.4 REJECTED by ChatGPT account ("not supported")
rule        → if review returns CHANGES_REQUIRED, loop back to /implement (bounded repair)
status      → COMPLETE (Phase 1 passed full /implement → /review lifecycle, 2026-09-06)
```

---

## Round history + Phase 1 completion (2026-09-06)

```
R1 /implement (sol)  → PASS   (evidence: engine 32/32, lifecycle 12/12, concurrency 18/18)
R1 /review    (sol)  → CHANGES_REQUIRED  (7 findings; chair adjudication above)
R2 /implement (sol)  → PASS   (all 7 addressed: advisory-lock mutex, cancel guard,
                               fail-closed interrupted persistence, documented dispatch/retention)
R2 /review    (sol)  → CHANGES_REQUIRED  (2 findings: non-vacuous mutex proof + raw evidence transcript)
R3 /implement (sol)  → PASS   (deterministic GET_LOCK-held proof; concurrency 38/38; no production change)
R3 /review    (sol)  → PASS   ✅ GATE CLEARED
```

- Review logs: `test_results/review-sol.log` (R1), `review-sol-r2.log`, `review-sol-r3.log`.
- Implement logs: `test_results/implement-sol.log`, `implement-sol-r2.log`, `implement-sol-r3.log`.
- Raw evidence (final): `test_results/implement-sol-r3-evidence.log` — engine 32/32, lifecycle 12/12,
  concurrency 38/38, PHPStan level 6 clean, php -l clean, MySQL-8 audit none, git diff --check clean,
  error.log 0 bytes.
- Scope: `kernel/WorkflowEngine.php`, `tests/workflow_concurrency_test.php`, `docs/kernel/workflow-system.md`.
  No migration/DDL, no WorkflowRuntime changes, no other roadmap phases.
- Final status: `COMPLETE`. Follow-ons (OUT OF SCOPE, recorded): (a) restore `src/helpers/workflow-retention.php`
  upstream sync gap → payload-hash recording; (b) caller-supplied idempotency-key result reuse.
```

---

## Phases 2–5 status (2026-09-06) — full roadmap execution

```
PHASE 1  WorkflowEngine concurrency + idempotency hardening   ✅ COMPLETE (gate PASS)
PHASE 2  Entity-view render-path cache (opt-in fragment)      ✅ COMPLETE (gate PASS after 1 repair round)
PHASE 3  ARK authority layer + renderer breadth               📋 MAIN-CMS-REPO (not in this workspace —
                                                              storage/cms-themes/ark absent; see
                                                              .ai/phase3-ark-main-repo-record-2026-09-06.md)
PHASE 4  Workbench guard (two-layer)                          ✅ COMPLETE (gate PASS after chair two-layer
                                                              re-scope; static tripwire + runtime dispatch guard)
PHASE 5  Workbench sliver                                     ✅ folded into Phase 4 (workbench:audit +
                                                              IssueLedger UI); ARK sliver → main CMS repo (Phase 3)
```

- Phase 2 contract: `.ai/phase2-entity-view-render-cache-contract-2026-09-06.md` (round history + COMPLETE).
- Phase 4 contract: `.ai/phase4-workbench-auditor-contract-2026-09-06.md` (chair decisions R1/R3/R4 + COMPLETE).
- All implementable roadmap phases in this workspace are DONE and gate-cleared under GPT sol
  (openai-codex/gpt-5.6-sol), each with raw evidence in `test_results/`. ARK phases require the main CMS app repo.
```
