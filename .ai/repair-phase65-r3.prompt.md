# /implement — 6.5 ROUND 3 (narrow bounded repair) — Authority audit (Codex Sol)

You are the /implement agent on Codex Sol. Working directory: /var/www/html/ikabudsix.
6.5 review R2 returned CHANGES_REQUIRED with 2 narrow findings. Scope note confirms the kernel.audit.list@1
call-site baseline is honest/explicit and R1 findings 2–5 are closed. Fix ONLY these 2; do not reopen closed work.

## Authority & context (read first)
1. Contract: /var/www/html/ikabudsix/.ai/phase65-authority-audit-contract-2026-09-06.md
2. Review findings: /var/www/html/ikabudsix/test_results/review-phase65-r2.log (2 findings — your directive)
3. Governing: /var/www/html/ikabudsix/.github/instructions/ai-development-execution-handoff.instructions.md
   + .github/copilot-instructions.md (check BOTH logs after runs).
4. Read the ACTUAL code: kernel/Workbench/Audit/CapabilityAuthorityAuditor.php (dependency validation ~L98 where
   kernel.* depends is blanket-exempt; the closed-world kernel inventory ~L32-66), tests/capability_authority_audit_test.php,
   and .ai/kernel-6x-five-guarantees-roadmap-2026-09-06.md (~L63-68, the execution-log note).

## Findings to FIX
1. Validate `kernel.*` DEPENDS declarations against the explicit closed-world kernel inventory (with
   compatible-version semantics, same as call sites), instead of blanket-exempting them. Add a fixture where a
   module declares `depends: ["kernel.unknown@1"]` with NO call site and it produces a CRITICAL finding. The
   existing kernel.audit.list@1 call-site warning baseline must remain an explicit warning (not silently promoted
   or hidden); only genuinely unknown kernel.* dependencies/calls are critical.
2. Update the roadmap execution-log note (.ai/kernel-6x-five-guarantees-roadmap-2026-09-06.md ~L63-68) to record
   the actual R2 state: ONE documented warning baseline (kernel.audit.list@1, surfaced by the audit) and reference
   the R2 evidence path (test_results/phase65-evidence-r2.log) — do not claim zero findings.

## Prohibitions (unchanged)
- Only: kernel/Workbench/Audit/CapabilityAuthorityAuditor.php + tests/capability_authority_audit_test.php +
  docs/kernel/cli-tools-reference.md (if wording changes) + the roadmap note. NO module.json/schema/DB change; NO
  DDL; NO MySQL-8-only SQL; NO runtime CapabilityBus/policy change; NO ARK/CMS; NO other guarantees; NO full-suite.

## Return format (final message; concise; file:line refs)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
findings_addressed: (#1, #2: how + file:line + fixture)
changed:
implementation_summary:
verification: (full test output; CLI run; lint; PHPStan; diff incl. untracked; logs; point to evidence file)
risks:
unresolved:
recommended_next_state: (REVIEW_REQUIRED)
Do NOT return empty. Do NOT report success without verification evidence.
