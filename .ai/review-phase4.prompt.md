# /review — PHASE 4 — Workbench auditor of Phase-1 hardening (architectural review gate) — Codex Sol

You are the /review agent on Codex Sol. Working directory: /var/www/html/ikabudsix.
Read-only review — do NOT edit files or run tests. Verdict + precise remediation only.

## Context
Phase 4 /implement (GPT sol) reports PASS. Independent review required — do not rubber-stamp.

## Inputs
1. Contract (authoritative): /var/www/html/ikabudsix/.ai/phase4-workbench-auditor-contract-2026-09-06.md
2. Implement report: /var/www/html/ikabudsix/test_results/implement-phase4.log
3. RAW evidence: /var/www/html/ikabudsix/test_results/phase4-evidence.log
4. Actual code + diff:
   - New: kernel/Workbench/Audit/WorkflowGuardAuditor.php (ctx_read full)
   - CLI wiring: git diff on the `ikabud` file
   - Test: tests/workbench_workflow_guard_audit_test.php
   Read the REAL kernel/WorkflowEngine.php too (the auditor's target) to judge baseline-clean honesty.

## Verify (hard scrutiny)
- BASELINE CLEAN: auditing the CURRENT hardened kernel/WorkflowEngine.php yields ZERO findings. Is that claim
  true from the evidence/code, or does the auditor have blind spots that make baseline-clean vacuous (checks too
  narrow / anchored so loosely they'd never fire)?
- DETECTION POWER: for each audited invariant (GET_LOCK/RELEASE_LOCK symmetry; atomic claim with rowCount guard +
  in-claim attempt increment; FOR UPDATE committed before cap->call(); cancel/replay run-row guard + no in-flight
  reclaim; fail-closed post-dispatch persistence; MySQL-8-only SQL patterns) the fixture tests must prove a
  REINTRODUCED regression is caught with correct code/severity/line. Do the fixtures actually exercise each
  invariant, or are some untested?
- ISSUE FORMAT: findings route through IssueLedger in the existing issue.v1 format — NO schema/ledger change.
- CLI: `php ikabud workbench:audit` wired exactly like existing workbench:* commands; exits non-zero on criticals;
  does not break other workbench:* commands.
- SCOPE: only new kernel/Workbench/Audit/WorkflowGuardAuditor.php + ikabud wiring + new test changed? No
  WorkflowEngine.php behaviour change, no IssueLedger/schema change, no DDL, no MySQL-8 SQL in the auditor, no
  ARK/CMS, no other roadmap phases. Auditor is static (no live DB / no heavy runtime).
- EVIDENCE: test_results/phase4-evidence.log is a complete raw transcript (lint, 20/20 test full output, CLI audit
  output, PHPStan, diff checks incl. name/stat, logs before/after, error.log empty). PHPStan claim: CLI retains
  the same pre-existing errors as HEAD and none in added lines — verify added lines are clean.
- Determinism: same input => same findings.

## Return format
verdict: PASS | CHANGES_REQUIRED
findings: (numbered; issue + why + file:line if known)
remediation: (ONLY if CHANGES_REQUIRED — precise bounded requirements)
scope_note:
Do NOT return empty. Do NOT take over implementation. If evidence is insufficient, say what is missing explicitly.
