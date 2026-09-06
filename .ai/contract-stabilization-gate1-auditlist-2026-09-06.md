# Stabilization Gate 1 — Resolve `kernel.audit.list@1` → capability:audit baseline ZERO

task: Register `kernel.audit.list@1` as a real scoped kernel capability over `audit_logs` so the
ComponentRenderer audit-log path resolves through the capability bus, and the CapabilityAuthorityAuditor
real-repository baseline drops from "0 critical + 1 warning" to **0 findings**.

objective: ZERO-exception `capability:audit` baseline. No KNOWN_EXCEPTION debt. The baselined-unregistered
warning for `kernel.audit.list@1` (CapabilityAuthorityAuditor.php:51-57) is removed by real registration, not by
suppressing the audit.

scope:
  allowed:
    - kernel/App.php  — register `kernel.audit.list@1` (kernel provider) next to `kernel.audit.record@1` (~L187)
    - kernel/Workbench/Audit/CapabilityAuthorityAuditor.php — move `kernel.audit.list@1` OUT of
      BASELINED_UNREGISTERED_KERNEL_CAPABILITIES INTO KERNEL_CAPABILITIES (source = new App.php line)
    - tests/capability_authority_audit_test.php — real-repo baseline assertion (L96-109) now expects ZERO findings
    - tests/ — new functional capability:audit test (record → list → renderer audit_log path)
    - config/app.php — schema_modes: add `kernel.audit.list@1` mode consistent with audit.record (choose warn/enforce
      only after reading schema_validation behavior; entity_id null must not break the renderer path)
    - docs/kernel/cli-tools-reference.md (L61-67 note) + `.ai/kernel-6x-five-guarantees-roadmap-2026-09-06.md`
      follow-up note — record registration resolved the baseline
  prohibited:
    - NO schema/DDL changes — `audit_logs` already exists (kernel-owned)
    - NO new modules, NO changes to module capabilities
    - NO changing kernel.audit.record@1 semantics
    - NO weakening the auditor's closed-world inventory / fail-on-new logic
    - NO touching ComponentRenderer.php UNLESS revision #2 (below) proves the guard/call pair prevents the renderer
      from reaching the newly registered capability — then a minimal guard alignment is allowed and must be shown in
      the diff with a comment.

constraints:
  - REVISION #1 (role vocabulary): the capability's authorization must use verbatim kernel users.role values
    `['admin','superadmin']`. `'administrator'` is FORBIDDEN (would deny every admin). Mirror whatever gate
    `kernel.audit.record@1` uses for audit capability authorization.
  - REVISION #2 (reachability): ComponentRenderer.php:1074 guards `method_exists($app,'capabilities')` but invokes
    `$app->cap()->call('kernel.audit.list@1', ...)` at :1075. VERIFY the call actually reaches the registered cap
    after registration (guard/call pair). If `cap()` may not exist when `capabilities()` does, align the guard to the
    method actually invoked — minimal fix, commented.
  - Payload contract (from ComponentRenderer.php:1075-1081): `{entity_type: string, entity_id: string|null,
    limit: int}` → return shape consumed by renderAuditLog: `['rows' => [...]]` (L1081 `$result['rows'] ?? $result`).
    Read ComponentRenderer.php:1089-1140 to satisfy the exact row keys renderAuditLog displays.
  - Scoping: mirror `kernel.audit.record@1`'s actor/tenant/DB resolution (App.php:205-215). Rows must be read from the
    same DB audit.record writes to; respect tenant current scope; ORDER BY id DESC; LIMIT clamped (1..100).
  - audit_logs actor columns: actor_user_id (kernel users) / actor_module_user_id (module users) — return readable
    actor representation if renderAuditLog expects it.
  - Keep the auditor's own `CAPABILITY_KERNEL_BASELINED_UNREGISTERED` code path intact (other callers still trigger
    it) — only the real-repo instance must disappear.

acceptance:
  - `(new CapabilityAuthorityAuditor(__DIR__.'/../modules'))->audit()` on the real repo returns ZERO findings
    (no critical, no warning). capability_authority_audit_test real-repo assertions updated to assert empty.
  - `kernel.audit.list@1` is callable through `app()->cap()->call('kernel.audit.list@1', ...)` and returns audit rows
    written by `kernel.audit.record@1` for a matching entity_type (functional test, not a mock).
  - ComponentRenderer `{audit_log}` render path with a real source resolves rows (no "No audit entries found" fallback)
    when matching audit rows exist — verified via the functional test and/or a render assertion.
  - Both logs clean: storage/logs/app.log and storage/logs/error.log show no new error/warning from the audit path
    after tests.
  - docs/kernel/cli-tools-reference.md L61-67 no longer lists kernel.audit.list@1 as unregistered; roadmap follow-up
    note updated to "resolved → baseline ZERO".
  - php -l clean on all touched PHP; no new PHPStan baseline additions (keep existing baseline intact).
  - Full targeted suite green: capability_authority_audit_test + capability_effect_invalidation_test +
    unified_execution_trace_test + new capability_audit functional test; then affected feature tests.

e2e_acceptance:
  - None new beyond the renderer audit_log functional path above (no user-facing surface change).

verification:
  - `php tests/capability_authority_audit_test.php` (baseline ZERO)
  - new functional audit test (record → list → renderer)
  - `composer test` subset / full as appropriate after targeted pass
  - grep both logs after runs

risk:
  - LOW-MEDIUM. Registration is additive; auditor baseline removal is the only "decrease" in coverage, offset by the
    new functional test proving the registered capability serves real data. Role gating uses admin/superadmin
    (revision #1) so no auth regression. audit_logs is read-only here.

unresolved:
  - exact renderAuditLog row-key expectations + exact DB handle audit.record uses → implementer reads
    ComponentRenderer.php:1061-1140 and App.php:184-260 before coding (do NOT guess).

status: IMPLEMENTED

result: `kernel.audit.list@1` is registered as an admin/superadmin-gated, tenant-aware scoped read over
`audit_logs`; record → list → ComponentRenderer behavior is covered by a functional test, and the
real-repository capability authority baseline is ZERO findings.
