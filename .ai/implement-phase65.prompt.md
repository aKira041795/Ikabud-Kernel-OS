# /implement — 6.5 AUTHORITY: declared module→capability audit — Codex Sol

You are the /implement agent on Codex Sol. Working directory: /var/www/html/ikabudsix (Ikabud-Kernel-OS).
Execute objective 6.5 of the adjudicated five-guarantee roadmap: a deterministic static audit that verifies every
capability a consumer calls/depends on is declared as exposed by an enabled module (or known kernel.*), caller
within the provider's declared policy, and declared exposes are implemented. Real edits + real tests.

## Authority & rules (read first)
1. Contract (AUTHORITATIVE): /var/www/html/ikabudsix/.ai/phase65-authority-audit-contract-2026-09-06.md
   (scope/constraints/acceptance/verification/risk). Roadmap context: /var/www/html/ikabudsix/.ai/kernel-6x-five-guarantees-roadmap-2026-09-06.md.
2. Governing: /var/www/html/ikabudsix/.github/instructions/ai-development-execution-handoff.instructions.md
   (bounded repair, result format) + .github/copilot-instructions.md (check BOTH logs after runs).
3. Scoping research (file:line anchors — read actual code, do not assume): architecture:check Phase 2
   (ikabud ~L4989-5048; $allCapIds L5007 UNUSED — the gap), declaration shape modules/gui-settings/module.json
   (~L269-285 capabilities.exposes/depends/policy.allow_callers), handler map
   modules/gui-settings/helpers.php (~L243 gui_settings_capability_handlers), CapabilityRegistry::resolve/providers,
   CapabilityCatalog (ideal inventory), and the workbench:audit CLI wiring precedent (ikabud ~L6125-6220).

## Task (single objective)
Build kernel/Workbench/Audit/CapabilityAuthorityAuditor.php (static, no DB, explicit modules root for fixtures)
+ wire `php ikabud capability:audit`. Checks A–E per contract: (A) every literal cap()->call resolves into
exposes-union + kernel.* allowlist across modules/src/kernel; (B) versioned X@N matches an exposed major;
(C) consumer declares X in depends or self-exposes; (D) consumer allowed by provider policy allow_callers;
(E) every exposed id has a callable in the module's {prefix}_capability_handlers() map.
Baseline on the REAL repo must be ZERO findings or an EXPLICIT documented baseline (never silent). Handle kernel.*
and kernel-internal runtime consumers (e.g. Workbench 'ai.*' optional-module calls) explicitly — classify, don't skip.
Findings: {code, severity, file:line, message}; exit non-zero on criticals; optional IssueLedger + --json.

## Requirements (from contract — implement all)
- Deterministic, static, no DB/network. Anchor on literal capability ids across real call-site spellings
  (app()->cap()->call / $app->cap()->call / app()->capabilities()->call / quote styles) with NO false positives.
- Real-repo baseline clean (zero or explicit documented baseline; prefer zero via correct kernel-internal
  classification). If classification is ambiguous (a called cap with no declaring module that is neither kernel.*
  nor an optional consumer), do NOT guess — return BLOCKED/ARCHITECTURE_DECISION_REQUIRED with the exact list.
- CLI `php ikabud capability:audit` mirroring workbench:audit (help + dispatch), prints findings, non-zero on
  criticals.
- Tests tests/capability_authority_audit_test.php: (a) real-repo baseline (zero or documented explicit baseline,
  asserted); (b) temp-dir fixtures reintroducing each regression A–E → expected finding with exact code/severity/line.
- Doc: docs/kernel/cli-tools-reference.md (capability:audit) + roadmap note.

## Prohibitions (unchanged)
- Only: new kernel/Workbench/Audit/CapabilityAuthorityAuditor.php + ikabud CLI wiring + tests/capability_authority_audit_test.php
  + docs/kernel/cli-tools-reference.md (and roadmap doc note). NO module.json/schema/DB change; NO DDL; NO
  MySQL-8-only SQL; NO runtime CapabilityBus/policy enforcement change; NO general static analysis; NO ARK/CMS; NO
  other guarantees; NO full-suite runs.

## Verification (capture RAW to test_results/phase65-evidence.log)
- php -l on new/changed files; full tests/capability_authority_audit_test.php output; `php ikabud capability:audit`
  real-repo output (baseline); vendor/bin/phpstan analyse --level=6 --no-progress on new files; git diff --check
  + --name-only/--stat; logs before/after (error.log empty; app.log informational only).

## Return format (final message; concise; file:line refs)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:              (files + file:line refs)
implementation_summary: (auditor checks A–E, baseline result + classification of kernel-internal consumers,
                         CLI wiring, determinism)
verification:         (point to test_results/phase65-evidence.log; summarize counts)
risks:
unresolved:
recommended_next_state: (REVIEW_REQUIRED if done per contract)
Do NOT return empty. Do NOT report success without the raw evidence file complete.
