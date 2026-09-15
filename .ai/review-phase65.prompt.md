# /review — 6.5 AUTHORITY: declared module→capability audit — architectural review gate — Codex Sol

You are the /review agent on Codex Sol. Working directory: /var/www/html/ikabudsix.
Read-only review — do NOT edit files or run tests. Verdict + precise remediation only.

## Context
6.5 /implement (GPT sol) reports PASS. Independent review required — do not rubber-stamp.

## Inputs
1. Contract (authoritative): /var/www/html/ikabudsix/.ai/phase65-authority-audit-contract-2026-09-06.md
2. Implement report: /var/www/html/ikabudsix/test_results/implement-phase65.log
3. RAW evidence: /var/www/html/ikabudsix/test_results/phase65-evidence.log
4. Actual code + diff: `git -C /var/www/html/ikabudsix diff -- ikabud docs/kernel/cli-tools-reference.md`,
   read kernel/Workbench/Audit/CapabilityAuthorityAuditor.php (ctx_read full) and tests/capability_authority_audit_test.php.

## Verify (hard scrutiny)
- Checks A–E actually implemented and meaningful (not vacuous):
  A: every literal cap call resolves into exposes-union + kernel.* allowlist (modules/src/kernel scanned);
  B: versioned X@N matches an exposed major; C: consumer declares in depends or self-exposes;
  D: caller within provider allow_callers (when declared); E: declared exposes have a handler-map callable.
- Detection power: a temp-dir fixture for EACH regression (A–E) yields the expected finding with exact
  code/severity/line. Are the fixtures real (not vacuous)? Is there any check that could never fire?
- Baseline honesty: real repo = 0 findings/0 criticals. The kernel.* + three optional kernel-integration
  classifications (CapabilityAuthorityAuditor.php:31) are EXPLICIT and documented (not silent skips). Reasonable?
  Would a genuinely undeclared cap in the real repo be caught (test it mentally against e.g. a hypothetical
  src/ caller of a non-existent module cap)?
- Determinism + static: no DB/network; token-based literal scanning excludes comments/dynamic IDs without false
  negatives on real spellings.
- CLI: `php ikabud capability:audit` wired (help + dispatch + IssueLedger + --json), non-zero exit on criticals;
  no breakage of other commands.
- SCOPE: only new auditor + ikabud wiring + new test + cli-tools-reference doc + roadmap note. NO module.json/schema/
  DB change, NO DDL, NO MySQL-8 SQL, NO runtime CapabilityBus/policy enforcement change, NO general static analysis,
  NO ARK/CMS, NO other guarantees. WORKBENCH-NARROW respected.
- EVIDENCE: phase65-evidence.log complete raw transcript (7/7 test full output, CLI baseline run, lint, PHPStan,
  diff name/stat/check, logs before/after, error.log empty).

## Return format
verdict: PASS | CHANGES_REQUIRED
findings: (numbered; issue + why + file:line if known)
remediation: (ONLY if CHANGES_REQUIRED)
scope_note:
Do NOT return empty. Do NOT take over implementation. If evidence is insufficient, say what is missing explicitly.
