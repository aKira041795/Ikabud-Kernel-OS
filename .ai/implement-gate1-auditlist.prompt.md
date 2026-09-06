You are the /implement + /review agent for Ikabud Kernel OS (repo root /var/www/html/ikabudsix, HEAD 90957d7).
Execute **Stabilization Gate 1** per the authoritative contract:

    .ai/contract-stabilization-gate1-auditlist-2026-09-06.md

READ THE CONTRACT FIRST — it is small and authoritative. Do not broaden scope.

## Context you may need (verify, don't guess)
- kernel/App.php:184-260 — existing `kernel.audit.record@1` registration (kernel provider). Mirror its shape for the
  new `kernel.audit.list@1`: `$caps->register('kernel.audit.list@1', 'kernel', function ($payload) {...}, 1000,
  ['first'], $kernelCapabilityMeta('kernel.audit.list@1'))`.
- kernel/DiSyL/Component/ComponentRenderer.php:1061-1140 — `renderAuditLog()`: calls
  `app()->cap()->call('kernel.audit.list@1', ['entity_type'=>..,'entity_id'=>..,'limit'=>..])`, reads
  `$result['rows'] ?? $result`, falls back to "No audit entries found." Inspect the exact row keys it displays and
  return rows that satisfy it. NOTE revision #2: guard at :1074 checks `method_exists($app,'capabilities')` but the
  call uses `$app->cap()->call(...)` — verify reachability after registration; minimal guard alignment allowed only if
  required, with a comment.
- kernel/Workbench/Audit/CapabilityAuthorityAuditor.php:31-57 — remove `kernel.audit.list@1` from
  BASELINED_UNREGISTERED_KERNEL_CAPABILITIES, add it to KERNEL_CAPABILITIES with source `kernel/App.php:<line>`.
- tests/capability_authority_audit_test.php:96-109 — real-repo assertions currently expect exactly one warning
  (kernel.audit.list@1) + zero criticals. Update to assert ZERO findings. Do NOT weaken the other audit assertions.
- audit_logs table schema (search migrations/) — confirm columns for entity_type/entity_id/actor/timestamps and which
  DB kernel.audit.record@1 writes to (App.php:184-260 shows the write path). The list handler must read the SAME DB,
  honor tenant current scope, ORDER BY id DESC, LIMIT clamped 1..100.
- config/app.php:44-48 `capabilities.schema_modes` — decide whether to add `kernel.audit.list@1` (read how
  schema_validation_mode behaves so entity_id=null from the renderer does NOT hard-fail the call).
- Role gating for kernel capabilities: how audit/read kernel caps restrict to kernel-admin roles. REVISION #1:
  allowed roles are the verbatim users.role values `['admin','superadmin']` — never 'administrator'.

## Deliverables (all within allowed scope — NO schema/DDL, NO module changes, NO audit.record changes)
1. Register `kernel.audit.list@1` in kernel/App.php as a kernel provider returning `['rows'=>[...]]` (scoped read of
   audit_logs by entity_type/entity_id/limit, admin/superadmin gated, tenant/actor-scoped consistent with
   kernel.audit.record@1).
2. Update CapabilityAuthorityAuditor (move baseline → KERNEL_CAPABILITIES) so real-repo `audit()` is EMPTY.
3. Update tests/capability_authority_audit_test.php real-repo assertions to expect zero findings.
4. Add a functional test (e.g. tests/capability_audit_test.php) that: writes a row via
   `app()->cap()->call('kernel.audit.record@1', ...)`, reads it back via `kernel.audit.list@1` with matching
   entity_type/entity_id, asserts the row round-trips; and exercises the ComponentRenderer renderAuditLog path (source
   set) so it does NOT fall back to "No audit entries found" when rows exist. Plain-PHP test requiring bootstrap.php,
   following the repo's existing test style; assert concrete behavior, not mocks.
5. Update docs/kernel/cli-tools-reference.md L61-67 (remove the unregistered note) and the .ai roadmap follow-up note
   to "resolved → baseline ZERO".
6. Update .ai/contract-stabilization-gate1-auditlist-2026-09-06.md status to IMPLEMENTED (append result).

## Verification (do all)
- php -l on every touched PHP file.
- php tests/capability_authority_audit_test.php  → must pass (baseline ZERO).
- Your new functional test  → must pass.
- Then feature layer: php tests/capability_effect_invalidation_test.php and php tests/unified_execution_trace_test.php.
- composer test if targeted pass and time permits (report full-suite status).
- Check BOTH storage/logs/app.log AND storage/logs/error.log after every run — no new errors from the audit path.
- git diff --stat; confirm scope contains ONLY the allowed files.
- Do not add PHPStan baseline entries.

## Constraints
- Bounded repair: max ~2 repair rounds. If you hit an architectural blocker (e.g. audit_logs is not in the DB the
  renderer path assumes, or role gating requires an architectural decision), STOP and return BLOCKED with evidence —
  do NOT invent architecture.
- Do NOT disable or weaken the auditor's fail-on-new logic to make tests pass.

## Report (compact result block)
Return:
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed: (file list)
implementation_summary: (2-5 bullets)
verification: (test names + pass counts, log status)
scope: unexpected_files
risks:
unresolved:
recommended_next_state:
