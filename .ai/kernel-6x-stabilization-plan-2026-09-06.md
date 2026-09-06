# KERNEL 6.x STABILIZATION — pending recommendations (adjudicated 2026-09-06, after 2nd external review "go")

task: Resolve the pending recommendations from the 2nd external assessment of main @90957d7 (verdict improved to
"go"). This is a STABILIZATION period — no new kernel subsystems, no Kernel 7. Success = zero-exception baselines
and the gates proving modules live inside the five guarantees.

## Adjudicated resolution (debate 2026-09-06)
- A's agenda adopted; B's sequencing amendments folded in. Each item is its OWN contract + OWN gate (no risk
  laundering). HTTP adoption is additive-only and LAST. NO-BROADEN retained as a standing rule. Kernel 7 deferred.
- Real-module adoption (CMS/Ecommerce/WMS/Guidance/Ledger/HARPP inside the five guarantees) is a MAIN-CMS-REPO
  milestone; the kernel gates (capability:audit, Workbench) are its test harness — do NOT claim it here.

## Order
```
(a) Resolve kernel.audit.list@1  (baseline → ZERO exceptions)   → .ai/contract-stabilization-auditlist.md
(b) EventBus fireDurable → shared idempotency primitive        → own contract
(c) HTTP idempotency adoption (additive-only, LAST)            → own contract (+ full regression of retry path)
NEXT after (a)-(c): record real-module adoption (main repo) + production observation → THEN Kernel 7 (deferred)
```

## Standing rules (reaffirmed)
1. NO-BROADEN: a new kernel subsystem is only justified if NO existing abstraction owns the concern AND it is
   universally kernel-level; otherwise it is a module/service or an extension of an existing contract.
2. Baselines are temporary debt, not architecture — no growing KNOWN_EXCEPTION_n collection. Each surfaced finding
   is resolved (register/retire/fix) to a ZERO baseline.
3. WORKBENCH-NARROW + capability:audit + Workbench guard baselines stay at ZERO.
4. Idempotency converges to ONE shared primitive (claim/commit/release over kernel_idempotency_keys) as an OS
   service — HTTP, EventBus, Workflow all consume it; payload-conflict semantics everywhere.

status: READY — item (a) is the first implementable unit (own contract).

---

## Multi-model debate (2026-09-06, DeepSeek Pro vs Codex Sol, 5 rounds) — outcome + reconciliation
- Run: `python3 tools/pi-arch-debate.py …` (DEBATE_MAX_ROUNDS=5). Participants: DeepSeek Pro (drafter) ↔
  Codex Sol (critic). Verdict after 5 rounds: **REVISIONS** (not APPROVED). Converged draft:
  `.ai/current-task.md`; artifacts `.ai/debate/round-{1..5}-*.{txt,jsonl}`.
- Codex Sol verified the draft against HEAD 90957d7 and issued 8 concrete corrections (round-5-critique):
  1. Role vocabulary must be `'admin','superadmin'` (kernel users.role ENUM), NOT `'administrator','superadmin'`
     (would deny every admin — requireAnyRole compares verbatim).
  2. Gate 1 must verify the ComponentRenderer `cap()` alias (guard/call method-name mismatch) so registering the
     capability actually stops the renderer's error path.
  3. Legacy envelope-less rows (written by old check()/store()) currently observe as `conflict` — classify them as
     in_progress/duplicate (raw outcome), never conflict — explicit code change, not just a canonicalizer.
  4. "One canonicalizer" = extract `Idempotency::canonicalPayloadHash()` from the EXISTING
     `WorkflowEngine::externalPayloadHash()` algorithm (byte-identical); WorkflowEngine calls it — exactly one.
  5. Tenant contract: validate opts['tenant_id'] as positive int + agreement with TenantResolver::current().
  6. Gate 3: commit() already writes the envelope — drop redundant `version` inside the HTTP outcome payload.
  7. claim() wait param bounds the total deadline (mobile 2s → single attempt → in_progress; 300 default preserves
     WorkflowEngine).
  8. Gate 2 baseline = existing tests/durable_idempotency_test.php + the 6.3 contract (run at 90957d7) — not greenfield.
- CHAIR RECONCILIATION: all 8 accepted and folded into the gates below. Status: **APPROVED (chair, after genuine
  5-round two-model debate + verified revisions)**. Gates executed strictly in order, each own contract + own gate.

```
GATE 1  Resolve kernel.audit.list@1 → capability:audit baseline ZERO   ✅ DONE (PR #24, merged main 57dd86f)
        - kernel.audit.list@1 registered in App.php as kernel provider over audit_logs (admin/superadmin,
          tenant-aware, entity filters, limit 1-100, nullable entity_id schema).
        - Auditor baseline ZERO (closed-world KERNEL_CAPABILITIES; dead baselined-unregistered mechanism removed).
        - Functional test 4/4 incl. ComponentRenderer audit_log render path (reachability proven). 18/18 + 19/19 + 5/5.
        - CI 6/6 green. Logs clean. Contract: .ai/contract-stabilization-gate1-auditlist-2026-09-06.md
GATE 2  EventBus fireDurable → shared Idempotency primitive ✅ IMPLEMENTED (Kernel-owned tenant outbox;
        keyed row-id replay/conflict safety; one canonicalizer; envelope-less rows never conflict)
GATE 3  HTTP idempotency adoption → shared primitive, ADDITIVE-ONLY, regress mobile retry path (LAST)
NEXT: real-module adoption (main CMS repo) → production observation → THEN Kernel 7 (deferred)
```
