# /review — PHASE 2 — Entity-view render-path cache (architectural review gate) — Codex Sol

You are the /review agent on Codex Sol. Working directory: /var/www/html/ikabudsix.
Read-only architectural review — do NOT edit files, do NOT run tests. Verdict + precise remediation only.

## Context
Phase 2 /implement (GPT sol) reports PASS. Independent review required — do not rubber-stamp.

## Inputs
1. Contract (authoritative): /var/www/html/ikabudsix/.ai/phase2-entity-view-render-cache-contract-2026-09-06.md
   — scope.allowed / scope.prohibited / constraints / acceptance / verification.
2. Implement report: /var/www/html/ikabudsix/test_results/implement-phase2.log
3. RAW evidence: /var/www/html/ikabudsix/test_results/phase2-evidence.log
4. Actual code + diff:
   `git -C /var/www/html/ikabudsix diff -- kernel/DiSyL/Component/ComponentRenderer.php kernel/EntityContext/EntityViewResolver.php docs/kernel/entity-context-system.md`
   and read tests/entity_view_render_cache_test.php (ctx_read).

## Verify (hard scrutiny)
- SEMANTIC TRANSPARENCY: cache hits must serve byte-identical HTML to a fresh render for the same key. Is the
  cache key truly canonical — includes tenant, source, view, limit, sort, filters, page/cursor, auth role, render
  attributes/children, detail ID — and is the stored HTML identical (not wrapped/altered)?
- NO LEAK: per-user content is NOT cached by default; only explicit cache-user="true" opts in, and that key
  includes the user identity. Confirmed in code?
- TENANT ISOLATION: tenant is part of the key / store partition. Two tenants cannot share a row.
- FAIL-OPEN: FragmentStore errors fall back to a normal render with a warning log (never silently serve wrong HTML
  as authoritative, never crash the render).
- INVALIDATION: invalidateEntityCache(type, tenant) actually invalidates the tag used by tryGet/put so a mutation
  can refresh. Does invalidation reach the same tag namespace as the cache put?
- SCOPE: only ComponentRenderer.php + EntityViewResolver.php (invalidator) + new test + doc changed? No DDL, no
  MySQL-8 SQL, no capability-dispatch/data-fetch change, no DefaultEntityRenderer behaviour change, no ARK/CMS.
- OPT-IN ONLY: no cache attr => behaviour identical to today (regression-free). Prove via the test coverage.
- EVIDENCE: is test_results/phase2-evidence.log a COMPLETE UNEDITED transcript (lint, new test 12/12, existing
  targeted 92/92, PHPStan, diff check, logs before/after, error.log empty)? Any section missing/truncated?
- No full-suite run; both logs checked.

## Return format
verdict: PASS | CHANGES_REQUIRED
findings: (numbered; each = issue + why + file:line if known)
remediation: (ONLY if CHANGES_REQUIRED — precise bounded requirements for a repair round)
scope_note:
Do NOT return empty. Do NOT take over implementation. If evidence is insufficient, say what is missing explicitly.
