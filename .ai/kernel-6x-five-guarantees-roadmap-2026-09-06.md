# KERNEL OS 6.x — FIVE-GUARANTEE ROADMAP (adjudicated 2026-09-06, after external review of PR #19)

task: Restructure the remaining Kernel OS 6.x roadmap around five guarantees (Identity, Authority, Execution,
Consistency, Evidence) per the go-with-changes review of PR #19 ("Evaluate Fork Roadmap" share). This file is the
AUTHORITATIVE roadmap order. Each numbered objective maps to a guarantee; the guarantees are the acceptance lens,
not a replacement for concrete deliverables.

## Adjudicated verdict (debate 2026-09-06)
- Direction ADOPTED (A): maturity-first; durable idempotency is the next major objective.
- Amendments ADOPTED (B): (1) 6.3 is a SHARED kernel idempotency primitive, not a workflow-only key;
  (2) 6.5 Authority is promoted to immediately after 6.3 (cheap, architecture:check-precedented);
  (3) guarantee table must not become ceremony — concrete objectives retained; (4) ARK/CMS adoption surface moves
  in the main app repo in parallel (kernel freeze does not gate it).

## Standing architecture constraints (governance rules)
1. NO-BROADEN rule: no new kernel subsystems (more AI features, workflow DSL, render directives, service
   orchestration, Workbench analyzers, module machinery) until 6.3 + 6.5 land. Existing abstractions must become
   "boringly dependable."
2. WORKBENCH-NARROW rule: Workbench static guards detect Ikabud-specific architectural invariants ONLY
   (cross-module table access, capability bypass, kernel render bypass, tenant boundary violation, dispatch inside
   transaction, forbidden module dependency). NEVER replicate general static analysis (PHPStan/Psalm own language
   semantics). Workbench verifies kernel architectural promises; it is not "another kernel inside the kernel."
3. CAPABILITY-BUS SEAM rule: preserve `app()->cap()->call()` (CapabilityBus) as the authorized-execution seam
   (registry = what exists; bus = authorized execution). Cross-domain execution flows through CapabilityBus
   (authorization, tenant context, observability, idempotency, policy).

## Guarantee → objective map (adjudicated order)
```
Guarantee      Responsibility                                  Roadmap objective
Identity       tenant, module, subject, principal              (cross-cutting; enforced by all)
Authority      capability ownership + permission               6.5 (immediately after 6.3)
Execution      workflow, concurrency, claiming                 PR #19 (done) — 6.2 trust slice ✅
Consistency    idempotency, mutation/invalidation              6.3 FIRST, then 6.4
Evidence       logs, audit, traces, Workbench gates            6.6
```

```
6.3  Consistency — DURABLE IDEMPOTENCY  (NEXT)   → .ai/phase63-durable-idempotency-contract-2026-09-06.md
     shared kernel idempotency primitive (claim/commit/release over kernel_idempotency_keys)
     → WorkflowEngine external keys + persisted result reuse + payload-conflict detection
     → then HTTP (kernel/Http/Idempotency.php) + EventBus (fireDurable) unify onto the same primitive
6.5  Authority — declared module→capability relationships + cross-module policy verification
     (architecture:check-style gates; closes the axis PR #19 did not touch)
6.4  Consistency — capability effect declarations (effects.invalidates) → kernel-driven entity-cache
     invalidation (module discipline → kernel contract); after stable capability ownership (6.5)
6.6  Evidence — unified execution/capability trace; causation/correlation IDs; Workbench architecture gates
```
ARK / CMS evolution (ARK authority layer + renderer breadth, Phase-3/5 of the original roadmap) continues OUTSIDE
this kernel progression in the main app repo (`.ai/phase3-ark-main-repo-record-2026-09-06.md`).

status: READY_FOR_IMPLEMENTATION — objective 6.3 is the implementable unit (its own contract).

---

## Execution log
```
6.3 Durable idempotency   ✅ COMPLETE (2026-09-06) — gate PASS after 1 repair round
     shared claim/commit/release primitive over kernel_idempotency_keys (ownership-enforced, no DDL)
     → WorkflowEngine external keys + persisted result reuse + payload-conflict detection + idempotency_in_progress
     tests/durable_idempotency_test.php 30/30; engine 32/32, lifecycle 12/12, concurrency 38/38 green
     evidence: test_results/phase63-evidence-r2.log; contract .ai/phase63-durable-idempotency-contract-2026-09-06.md
     follow-ons (documented): HTTP payload-aware adoption + EventBus fireDurable unify onto the primitive
6.5 Authority              ✅ IMPLEMENTED (2026-09-06) — awaiting review
     deterministic `php ikabud capability:audit` gate verifies exposed providers, compatible majors,
     declared consumer relationships, allow_callers policy, and static handler-map implementation
     → real-repository baseline: zero criticals and one documented warning (`kernel.audit.list@1`),
       surfaced by the audit; kernel.* and three optional kernel integrations explicitly classified
     tests: tests/capability_authority_audit_test.php; evidence: test_results/phase65-evidence-r3.log
        (R1: phase65-evidence.log; R2: phase65-evidence-r2.log — retained as historical evidence)
6.5 Authority            ✅ COMPLETE (2026-09-06) — gate PASS after 3 bounded repair rounds (5 → 2 → 1 → 0)
     kernel/Workbench/Audit/CapabilityAuthorityAuditor.php + `php ikabud capability:audit`
     checks A–E: exposed providers, compatible majors, declared consumer relationships (calls + depends),
     allow_callers policy (runtime-faithful), static handler-map implementation (token-wise)
     baseline: ZERO findings; `kernel.audit.list@1` follow-up resolved by scoped kernel registration
     tests 19/19; evidence test_results/phase65-evidence-r3.log (R4 gate review-phase65-r4.log = PASS)
     CHAIR FOLLOW-UP: resolved → baseline ZERO (`kernel.audit.list@1` registered over tenant-aware audit_logs).

## Merge status (2026-09-06)
```
6.3  MERGED to main — PR #20 (all 6 checks green). Guard regression the Workbench auditor CAUGHT was fixed
     (external-key path now composes with Phase-1 lock/claim; guard baseline-zero restored 35/35); mysql-5.7/
     mariadb PROCESSLIST portability fix applied to durable_idempotency_test (30/30).
6.5  MERGED to main — PR #21 (all 6 checks green). Rebased clean onto merged 6.3.
6.4  Consistency            ✅ COMPLETE + MERGED (PR #22, all 6 checks green) — gate PASS
      provider-owned `effects.invalidates` declarations drive mode-aware, tenant-scoped, fail-open
      entity-view cache invalidation after successful CapabilityBus dispatch; tests 19/19
      (tests/capability_effect_invalidation_test.php; evidence test_results/phase64-evidence.log)
6.6  Evidence               ✅ COMPLETE + MERGED (PR #22, all 6 checks green) — gate PASS
      WorkflowEngine step dispatch and CapabilityBus evidence logs share
      `wf:run:<run-id>:step:<step-id>` without changing caller identity or persistence; test 5/5
      (tests/unified_execution_trace_test.php; evidence test_results/phase66-evidence.log)
ALL FIVE GUARANTEES COMPLETE (2026-09-06): Execution (PR #19) · Consistency (6.3 + 6.4) · Authority (6.5) ·
Evidence (6.6) · Identity (cross-cutting). main HEAD 7be0857.
Open follow-ups (recorded): (a) kernel.audit.list@1 register-vs-retire — resolved by Gate 1;
(b) EventBus `fireDurable` adoption of the 6.3 primitive — resolved by Stabilization Gate 2 with one sanctioned
Kernel outbox, one canonicalizer, tenant validation, and safe legacy envelope-less observation; HTTP payload-aware
primitive capability — resolved additively by Stabilization Gate 3 with bounded wait, replay outcomes without a
nested version, and the 409/425 + `Retry-After: 2` adopter mapping. No kernel seam exists here: daily-ledger and
mobile POST/PUT seam wiring is a MAIN-CMS-REPO milestone; (c) ARK phases 3/5 in the main CMS app repo;
(d) config/app.php env-boolean (bool) casts → filter_var.
