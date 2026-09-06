# 6.5 — AUTHORITY: declared module→capability audit — CONTRACT (adjudicated 2026-09-06)

task: Implement the Authority-guarantee objective: a deterministic static audit verifying that every capability a
module/kernel consumer CALLS or DEPENDS on is actually DECLARED as exposed by an enabled module (or a known
kernel.* capability), and that the caller is within the provider's declared policy. Closes the axis PR #19 did not
touch (declared-relationship verification). Stays within WORKBENCH-NARROW (Ikabud architecture rules only — never
general static analysis).

objective: Turn "undeclared capability references silently pass architecture:check then throw
CapabilityNotFoundException at runtime" into a CI gate. Grounded gap (Explore 2026-09-06): architecture:check
Phase 2 builds $allCapIds (ikabud ~L5007) but never consults it — a cap that is called/depended-on but exposed by
NO enabled module passes; no provider-owner/allow_callers cross-check; only modules/ scanned (kernel/src/templates
unscanned); declared-but-unimplemented exposes are a load-time log, not a gate.

## Grounded seams (verified 2026-09-06)
- Declaration: module.json `capabilities.exposes[]` ({id "base.cap@N", priority, modes}) + `depends[]` +
  `policy.capabilities["<id>"].allow_callers` (see modules/gui-settings/module.json:269-285). Handler map:
  `{prefix}_capability_handlers()` in module helpers.php (gui-settings/helpers.php:243) → array id=>callable.
- Existing audit: `architecture:check` (ikabud L4850-5234; Phase 2 undeclared-calls L4989-5048; $allCapIds L5007
  unused; exit(1) L5225). CLI wiring precedent: `workbench:audit` (ikabud L6125-6220, IssueLedger, exit on criticals).
- Runtime authority: kernel/Capabilities/CapabilityBus.php (registry->resolve/providers, applyPolicy allow/deny
  L242, applyAuthorizationRegistry L411). Ideal inventory: kernel/Capabilities/CapabilityCatalog.php
  (declared_providers from exposes + dependent_modules from depends + runtime_registered).
- Test pattern: tests/workbench_workflow_guard_audit_test.php (auditor + temp-dir fixtures + baseline-clean gate),
  plain-PHP bootstrap.

## scope:
  allowed:
    - NEW `kernel/Workbench/Audit/CapabilityAuthorityAuditor.php`: constructor takes an explicit modules root
      (fixture-testable, mirroring WorkflowGuardAuditor). Static, no DB. Checks:
      (A) every static `app()->cap()->call('X')` / `$app->cap()->call('X')` / `app()->capabilities()->call('X')`
      literal resolves into the union of all enabled modules' `capabilities.exposes` ids + known `kernel.*`
      (fixes the unused-$allCapIds gap) — scan modules/, src/, kernel/;
      (B) versioned `X@N` must match an exposed major per registry->resolve semantics;
      (C) the consumer declares X in its own `depends` or self-exposes it;
      (D) the consumer module is allowed by the provider's `policy.capabilities[X].allow_callers` (when declared);
      (E) each exposed id has a callable in its `{prefix}_capability_handlers()` map (declared-but-unimplemented).
      Findings: {code, severity, file:line, message}; baseline on the REAL repo = ZERO findings OR an explicit,
      documented allow/baseline (kernel-internal runtime consumers like Workbench 'ai.*' optional-module calls must
      be classified: kernel.* allow; OR a documented explicit baseline list with --fail-on-new semantics — no silent
      skips).
    - CLI: new `php ikabud capability:audit` (help + dispatch mirroring workbench:audit), prints findings, exit
      non-zero on criticals, optional IssueLedger ingest + --json.
    - Tests: new `tests/capability_authority_audit_test.php` — (a) real-repo baseline clean (zero, or documented
      explicit baseline with no silent skip); (b) temp-dir fixture manifests/helpers reintroducing each regression
      (A–E) → expected finding with exact code/severity/line.
    - Doc: docs/kernel/cli-tools-reference.md (capability:audit) + a note in the five-guarantee roadmap on the
      Authority gate.
  prohibited:
    - NO module.json/schema/DB changes; NO DDL; NO MySQL-8-only SQL (audit is pure static PHP — moot but keep clean).
    - NO change to runtime CapabilityBus/applyPolicy/applyAuthorizationRegistry enforcement semantics.
    - NO general static-analysis replication (PHPStan territory). NO ARK/CMS. NO other guarantees (6.4/6.6). NO
      full-suite runs.
    - Do NOT silently skip findings — any baseline must be explicit and documented.

### constraints:
  - Deterministic: same repo → same findings. Static only, no DB/network.
  - Baseline clean on the real repo (zero findings, OR an explicit documented baseline list; prefer zero by
    classifying kernel-internal consumers correctly).
  - Scanning must handle the real call-site spellings present in modules/src/kernel (quote styles, $app vs app(),
    cap vs capabilities) without false positives; anchor on literal ids.
  - PSR-12 + PHPStan level 6 (no new errors). Read actual code first; check BOTH logs after runs.

### acceptance:
  - `php ikabud capability:audit` against the real repo: ZERO criticals (baseline clean or explicit documented
    baseline).
  - A fixture reintroducing each of A–E (unexposed called cap; version mismatch; undeclared consumer depends;
    caller outside allow_callers; exposed-but-unimplemented) produces the corresponding finding with correct
    code/severity/line.
  - Exit non-zero on criticals; findings actionable.
  - New test file passes; php -l clean; PHPStan no new errors; both logs clean.

### verification:
  - php -l on new/changed files; full tests/capability_authority_audit_test.php output; `php ikabud capability:audit`
    real-repo output; PHPStan level 6 on new files; git diff --check + name/stat; logs before/after (error.log
    empty). Capture RAW output to test_results/phase65-evidence.log.

### risk:
  - Real repo has only gui-settings module; kernel/src may reference optional-module caps (e.g. Workbench 'ai.*',
    CMS-adjacent) → baseline could be non-zero. Mitigate: kernel.* allowlist + classify kernel-internal runtime
    consumers explicitly; any residual must be a DOCUMENTED explicit baseline (never silent). Escalate to chair if
    classification is ambiguous.
  - False positives from varied call-site spellings → anchor on literal capability ids; fixture-verify each check.

### status: READY_FOR_IMPLEMENTATION
