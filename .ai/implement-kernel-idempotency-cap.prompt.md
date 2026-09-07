You are the /implement + /review agent for Ikabud Kernel OS (repo root /var/www/html/ikabudsix, main 34f1606).
Execute the **kernel-owned idempotency capability bridge** micro-gate per the authoritative contract:

    .ai/contract-kernel-idempotency-cap-gate-2026-09-07.md

READ THE CONTRACT FIRST. Kernel change, additive, mirrors the kernel.audit.record@1 precedent. Do not broaden.

## Context anchors (verify, don't guess)
- kernel/Http/Idempotency.php — the primitive: static claim(string $key, int $tenantId, string $payloadHash,
  ?PDO $db = null, ?int $waitCapSeconds = null), commit(...), release(...), canonicalPayloadHash(mixed). Lock
  discipline + envelope + fail-closed semantics. Call sites: WorkflowEngine.php (~619/762), EventBus.php (~456/473).
- kernel/App.php — kernel capability registration pattern: kernel.audit.record@1 (~L187, kernel provider, registers
  via $caps->register with $kernelCapabilityMeta), kernel.auth.* etc. Model the new caps on kernel.audit.record@1.
  kernel.audit.record@1 lets modules write audit_logs (kernel-owned) without owning it — THE precedent.
- KernelPDO guarding: a module handler (moduleWithContext) calling Idempotency::claim directly is denied on the
  kernel-owned table. A KERNEL capability handler runs with kernel escalation → legal access on the caller's PDO.
- The single app DB (ikabudsix) has all three tables; topology proven by the fork P2 R4 preflight.

## Deliverables (contract scope ONLY)
1. Register kernel-owned idempotency capabilities (kernel provider), modeled on kernel.audit.record@1:
   e.g. `kernel.idempotency.claim@1`, `kernel.idempotency.commit@1`, `kernel.idempotency.release@1`,
   `kernel.idempotency.hash@1` (pick the cleanest set; one capability per operation is simplest). Each runs with
   kernel escalation on the CALLER's PDO/transaction (caller supplies the PDO or the handler resolves app()->db()
   under kernel escalation for the caller's tenant — verify which is the legal same-connection path and enforce it).
   Enforce tenant agreement (positive int + TenantResolver::current()); reject wrong tenant/PDO before any
   lock/write. The capability NEVER starts/commits/rolls back the caller's transaction.
2. Only a tiny seam in Idempotency.php IF needed to expose claim/commit/release for the handler; otherwise call the
   existing static methods unchanged. Do NOT change Idempotency semantics.
3. config/app.php schema_modes entry only if consistent (choose after reading; match audit.record style or omit).
4. Focused test (repo plain-PHP style): a simulated guarded-module caller claims → writes → commits through the
   kernel capability on its own PDO + transaction; duplicate returns stored outcome; conflict rejected; in_progress
   rejected; wrong tenant rejected; wrong PDO rejected; capability never commits/rolls back the caller txn.
5. docs note if applicable. Append result to the contract.

## Verification
- php -l; targeted phpstan (no baseline additions); cs-fixer CI style.
- New focused test passes. durable_idempotency_test (30), workflow engine/concurrency/lifecycle/guard suite,
  eventbus_durable_outbox (22), ark_renderer_runtime (17), capability_authority_audit (18) all green.
- Full `composer test`; CI 6/6. Local dormant-fork condition is handled (audit fix #31) — authority 18/18.
- Both logs clean.

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state:
