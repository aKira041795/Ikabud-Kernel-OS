# Kernel Gate: kernel-owned idempotency capability bridge (for guarded module mutation paths)

task: Expose the existing `Idempotency` primitive (kernel/Http/Idempotency.php: claim/commit/release/
canonicalPayloadHash over kernel_idempotency_keys) as KERNEL-OWNED CAPABILITIES that any module can legally call via
CapabilityBus — mirroring the `kernel.audit.record@1` precedent (kernel provider bridging a kernel-owned table for
modules without module ownership). This unblocks CMS Akira fork P2: a guarded module mutation handler currently
cannot call `Idempotency::claim()` directly because KernelPDO denies module access to kernel-owned
`kernel_idempotency_keys`, even on the single app DB where all tables coexist.

objective: A module can participate in durable idempotency on its OWN caller-managed transaction through the kernel
capability bus — no direct kernel-table access, no isolation violation, no new table. Kernel 6.x consistency
guarantee (6.3 idempotency) becomes usable by modules, matching how evidence (audit) already is.

## Verified context (main 34f1606; P2 R4 preflight evidence)
- Single app DB `ikabudsix` has all three tables (cms_akira_posts [on provisioning], kernel_idempotency_keys,
  audit_logs), InnoDB, one connection — topology proven.
- BLOCKER: inside `moduleWithContext('cms-akira-core')`, `Idempotency::claim(..., app()->db())` is DENIED by
  `KernelPDO` (module context cannot touch kernel-owned kernel_idempotency_keys). cms-akira-core cannot claim the
  kernel table in its manifest; direct module escalation forbidden. No kernel idempotency capability exists.
- Precedent: `kernel.audit.record@1` (kernel/App.php) is a kernel provider letting modules write audit_logs without
  owning it; it runs under kernel escalation on the module/current connection.

## scope
  allowed:
    - kernel/App.php — register kernel-owned idempotency capabilities (kernel provider), modeled on the
      kernel.audit.record@1 registration.
    - kernel/Http/Idempotency.php — only if a tiny seam is needed to expose claim/commit/release with an explicit
      caller PDO; otherwise call the existing static methods as-is.
    - config/app.php — schema_modes entry if consistent with the other kernel caps (choose after reading).
    - tests/ — focused test: a simulated guarded-module caller can claim/commit/release through the kernel
      capability on its own PDO/transaction; duplicate/conflict/in_progress outcomes; tenant agreement; wrong-PDO
      rejected.
    - docs — cli-tools/api reference note if applicable.
  prohibited:
    - NO change to Idempotency semantics (claim/commit/release/lock discipline/canonicalizer stay byte-identical).
    - NO module ownership of kernel_idempotency_keys; NO weakening KernelPDO isolation.
    - NO new table; NO schema change; NO workflow/eventbus behavior change.
    - NO-BROADEN.

## constraints
- Capability id naming: `kernel.idempotency.claim@1`, `kernel.idempotency.commit@1`, `kernel.idempotency.release@1`,
  `kernel.idempotency.hash@1` (canonicalPayloadHash) — or a single `kernel.idempotency@1` with an operation field;
  pick the cleaner contract but keep the kernel.audit.record precedent (one capability per operation is simplest and
  matches audit.record's shape). Kernel provider; caller module authorized via the capability bus caller policy.
- The capabilities MUST operate on the CALLER's PDO/transaction: payload carries the caller's PDO (or the kernel
  resolves app()->db() under kernel escalation for the caller's tenant — the implementer verifies which is the legal
  same-connection path on the single app DB and enforces it). Enforce tenant agreement with the current tenant
  (positive int + TenantResolver::current()) — reject mismatch BEFORE any lock/write.
- claim returns new/duplicate/conflict/in_progress exactly as the primitive; commit persists the caller-supplied
  outcome; release only on certain pre-publication failure per the primitive's discipline. The capability handler
  runs with kernel escalation so kernel_idempotency_keys access is legal; it must NOT start/commit/rollback the
  caller's transaction (transaction ownership stays with the caller).
- Same-envelope canonical hash = `kernel.idempotency.hash@1` over the caller's canonical array (R4/R2 two-layer:
  modules build the envelope, kernel hashes it — one canonicalizer).
- PHP 7.4/8.0, repo style; php -l, phpstan (no baseline additions), cs-fixer CI style.

## acceptance
- A guarded module handler can, on its own transaction + PDO, call `kernel.idempotency.claim@1` (new →
  proceed), perform a write, `kernel.idempotency.commit@1` (outcome persisted), and the transaction COMMITs — all
  without direct kernel-table access. Duplicate → stored outcome returned; conflict → rejected; in_progress →
  rejected. Wrong tenant → rejected. Wrong PDO → rejected.
- `Idempotency::` static API + WorkflowEngine/EventBus callers byte-compatible (full suite green).
- Fork P2 becomes unblocked (P2 re-run is a SEPARATE gate after this merges).
- Full `composer test` green (CI 6/6; local dormant-fork condition handled by the audit fix — 18/18). capability:
  audit + Workbench zero; both logs clean.

## verification
- New focused kernel-capability test (guarded-module caller through the bus on its own PDO).
- durable_idempotency_test (30) + workflow suite + outbox (22) + ark runtime (17) + authority (18) all green.
- Full `composer test`; CI 6/6.

## risk
- LOW-MEDIUM. New kernel capabilities are additive + mirror the audit.record precedent exactly. Main care: the
  caller-PDO/tenant enforcement must not let a module claim on another tenant or another connection (tests enforce).
  Transaction ownership stays with the caller (the capability never commits/rolls back) — verified by test.

status: READY_FOR_IMPLEMENTATION (chair-approved kernel micro-gate 4; surfaces the missing kernel idempotency
capability bridge, precedent = kernel.audit.record@1)

## Implementation result — 2026-09-07

status: PASS

- Registered kernel-owned `claim`, `commit`, `release`, and canonical `hash` capabilities in `kernel/App.php` with enforced schemas.
- Claim/commit/release require a positive tenant matching `TenantResolver::current()` and the exact caller application PDO before escalation. They call the unchanged `Idempotency` primitive under kernel escalation and do not manage transactions.
- Added the capabilities to the closed-world authority inventory, API reference, and schema-mode enforcement configuration.
- Added `tests/kernel_idempotency_capability_test.php`: guarded module claim/write/commit and replay, conflict, in-progress, release, wrong tenant/PDO pre-write rejection, and caller transaction ownership (14/14).
- Verification: focused PHPStan and PHP-CS-Fixer clean; required idempotency/workflow/outbox/ARK/authority suites green; `composer test` 104/104; application and PHP error logs clean after the scoped run.
- `Idempotency.php` was not changed.
