# /review — 6.4 CONSISTENCY: capability effect declarations → auto entity-cache invalidation — Codex Sol

You are the /review agent on Codex Sol. Working directory: /var/www/html/ikabudsix.
Read-only review — do NOT edit files or run tests. Verdict + precise remediation only.

## Context
6.4 /implement (GPT sol) reports PASS. Independent review required — do not rubber-stamp.

## Inputs
1. Contract (authoritative): /var/www/html/ikabudsix/.ai/phase64-effect-invalidation-contract-2026-09-06.md
2. Implement report: /var/www/html/ikabudsix/test_results/implement-phase64.log
3. RAW evidence: /var/www/html/ikabudsix/test_results/phase64-evidence.log
4. Actual code + diff: `git -C /var/www/html/ikabudsix diff -- src/helpers/module-manager.php src/helpers/module-routes.php kernel/Capabilities/CapabilityBus.php docs/kernel/entity-context-system.md`,
   read tests/capability_effect_invalidation_test.php (ctx_read).

## Verify (hard scrutiny)
- EFFECTS DECLARATION is additive: module.json capabilities.exposes[] effects.invalidates (array of non-empty
  entity.list.<type>/entity.detail.<type> tags); validators ADD light validation WITHOUT rejecting unknown fields or
  breaking schema-v1 manifests (gui-settings has no effects → still valid).
- REGISTRATION CARRIER: effects lifted into provider meta at register (local + service-proxy branches) so the
  dispatcher can read them; provider-owned (origin module), never derived from caller.
- AUTO-INVALIDATION correctness in CapabilityBus::call(): only EXECUTED providers invalidate (mode-aware — verify
  first/pipeline/fanout semantics against callPipeline/callFanout; an unexecuted/denied provider must NOT
  invalidate); invalidation happens AFTER success ONLY (failed capability → no invalidation); tenant-correct
  (app()->tenant()->current(), same as resolver); fail-open (resolver/store error never breaks the write path);
  capabilities without effects behave exactly as before.
- TESTS prove the contract (not vacuous): write capability with effects → next render REFRESHES (re-invoked) with
  NO manual invalidator call; no-effects capability → stale served (no spurious invalidation); FAILED write → no
  invalidation; multi-tag (list+detail); tenant-correct; manifest validation fixture.
- NO REGRESSION: entity cache 32/32, capability authority audit 19/19, workflow guard 35/35 green in transcript.
- SCOPE: only the 5 permitted files (+roadmap note). No DDL/MySQL-8-only SQL; no FragmentStore/render/cache/
  invalidator semantics change; no 6.5 auditor behavior change; no ARK/CMS; no other guarantees.
- EVIDENCE: phase64-evidence.log complete raw transcript (19/19 + 32/32 + 19/19 + 35/35 full output, lint, PHPStan,
  diff name/stat incl. untracked, logs; error.log empty).

## Return format
verdict: PASS | CHANGES_REQUIRED
findings: (numbered; issue + why + file:line if known)
remediation: (ONLY if CHANGES_REQUIRED)
scope_note:
Do NOT return empty. Do NOT take over implementation. If evidence is insufficient, say what is missing explicitly.
