# /implement — 6.5 ROUND 2 (bounded repair) — Authority audit (Codex Sol)

You are the /implement agent on Codex Sol. Working directory: /var/www/html/ikabudsix.
6.5 review returned CHANGES_REQUIRED (5 findings). Repair per the remediations below. BOUNDED — no scope expansion.

## Authority & context (read first)
1. Contract: /var/www/html/ikabudsix/.ai/phase65-authority-audit-contract-2026-09-06.md
2. Review findings: /var/www/html/ikabudsix/test_results/review-phase65.log (5 findings — your directive)
3. Governing: /var/www/html/ikabudsix/.github/instructions/ai-development-execution-handoff.instructions.md
   (bounded repair) + .github/copilot-instructions.md (check BOTH logs after runs).
4. Read the ACTUAL code: kernel/Workbench/Audit/CapabilityAuthorityAuditor.php (A: L306 kernel.* exemption,
   C/D bypass L328, E handlerMap L271-292, policyFor L416-423), tests/capability_authority_audit_test.php,
   kernel/Capabilities/CapabilityBus.php applyPolicy (default + per-capability allow_callers combine semantics),
   and the real kernel.audit.* call sites (kernel/DiSyL/Component/ComponentRenderer.php:1075; App.php:338).

## Findings to FIX (with chair guidance)
1. Replace the blanket `kernel.*` exemption with an EXPLICIT, documented inventory of statically registered kernel
   capabilities + explicit exceptions. Investigate `kernel.audit.list@1` (called at ComponentRenderer.php:1075): is
   it registered anywhere in kernel/src/modules? If genuinely unregistered, either (a) it is a real latent gap →
   surface it as a finding (baseline then NOT zero → chair decision on whether to register it or baseline it), or
   (b) it IS registered somewhere → add it to the kernel allowlist with the registration file:line. Do not hide it
   behind the blanket exemption. Add a fixture proving an UNKNOWN kernel.* literal is detected.
2. Every non-kernel capability used from src/kernel must be EXPLICITLY classified (the three-entry optional list is
   not exhaustive), AND provider allow_callers must be applied to caller 'kernel'. Add kernel-consumer C/D fixtures.
3. Check E: parse the ACTUAL returned `{prefix}_capability_handlers()` array expression token-wise and validate the
   supported callable forms (string function names / [Class, method] pairs) WITHOUT accepting commented, unrelated,
   or unreachable entries, and WITHOUT treating class methods as bare global functions. Add adversarial E fixtures
   (commented entry, unrelated array, class-method-only entry).
4. Make policy evaluation mirror CapabilityBus::applyPolicy runtime semantics: combine default + per-capability
   allow_callers (do not array_merge-replace); an explicitly EMPTY allow_callers must behave as runtime does
   (no whitelist → allow by policy, subject to other gates), not deny everyone. Fixture-test both cases.
5. Evidence: regenerate test_results/phase65-evidence-r2.log so git diff --name-only/--stat/--check INCLUDE the new
   (untracked) auditor + test files (git add -N or explicit staged inventory), preserving the full raw verification
   transcript.

## Prohibitions (unchanged)
- Only: kernel/Workbench/Audit/CapabilityAuthorityAuditor.php + ikabud (only if wiring fix needed) +
  tests/capability_authority_audit_test.php + docs/kernel/cli-tools-reference.md. NO module.json/schema/DB change;
  NO DDL; NO MySQL-8-only SQL; NO runtime CapabilityBus/policy enforcement change (the AUDITOR must mirror runtime,
  not change it); NO ARK/CMS; NO other guarantees; NO full-suite runs.
- No silent skips: every exemption/classification must be explicit + documented (auditor docblock + cli-tools doc).
  If `kernel.audit.list@1` is genuinely unregistered and cannot be resolved or cleanly baselined, STOP and return
  BLOCKED / ARCHITECTURE_DECISION_REQUIRED with the exact evidence rather than guessing.

## Return format (final message; concise; file:line refs)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
findings_addressed: (#1..#5: how + file:line + fixture that proves it)
changed:
implementation_summary: (kernel inventory + audit.list finding, kernel-consumer classification, token E parse,
                         runtime-faithful policy, evidence staging)
verification: (point to test_results/phase65-evidence-r2.log; summarize counts + the audit.list disposition)
risks:
unresolved:
recommended_next_state: (REVIEW_REQUIRED if all findings addressed)
Do NOT return empty. Do NOT report success without the raw evidence file complete.
