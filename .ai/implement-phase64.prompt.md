# /implement — 6.4 CONSISTENCY: capability effect declarations → auto entity-cache invalidation — Codex Sol

You are the /implement agent on Codex Sol. Working directory: /var/www/html/ikabudsix (Ikabud-Kernel-OS, branch
feat/6x-consistency-effects-6.4 off main). Execute objective 6.4: capability-declared mutation effects
(effects.invalidates) → kernel auto-invalidates the entity-view cache after successful dispatch. Real edits + tests.

## Authority & rules (read first)
1. Contract (AUTHORITATIVE): /var/www/html/ikabudsix/.ai/phase64-effect-invalidation-contract-2026-09-06.md
   (scope/constraints/acceptance/verification/risk). Roadmap: .ai/kernel-6x-five-guarantees-roadmap-2026-09-06.md.
2. Governing: /var/www/html/ikabudsix/.github/instructions/ai-development-execution-handoff.instructions.md
   (bounded repair, result format) + .github/copilot-instructions.md (check BOTH logs after runs).
3. Read the ACTUAL code (never assume): kernel/EntityContext/EntityViewResolver.php (invalidateEntityCache :62-95),
   kernel/DiSyL/Component/ComponentRenderer.php (cache tags :2302-2323), kernel/Capabilities/CapabilityBus.php
   (call() :182; mode dispatch :221-229; callPipeline/callFanout), src/helpers/module-routes.php (register meta
   :180-245), src/helpers/module-manager.php (validateModuleCapabilities :1825-1964), modules/gui-settings/module.json,
   tests/entity_view_render_cache_test.php + tests/capability_authority_audit_test.php (patterns).

## Task (single objective)
1. Additive `effects.invalidates` on module.json capabilities.exposes[] (array of non-empty tags
   entity.list.<type> / entity.detail.<type>); light additive validation in validateModuleCapabilities (do NOT
   reject unknown fields; schema v1 stays valid).
2. Carry effects into provider meta at registration (src/helpers/module-routes.php register meta, incl. service-proxy
   branch if it shares the path).
3. Auto-invalidation in CapabilityBus::call() AFTER successful dispatch: gather ONLY the EXECUTED provider(s)
   (mode-aware first/pipeline/fanout), read meta['effects']['invalidates'], map to tenant
   (app()->tenant()->current() — same default the resolver uses), call
   app()->entityViews()->invalidateEntityCache(...) per tag (resolver is fail-open). Effects are provider-owned.
   NO invalidation on failure or for capabilities without effects; no dispatch/auth/policy semantics change.
4. Tests tests/capability_effect_invalidation_test.php (synthetic pattern): render-populate fragment for type X;
   dispatch a WRITE capability whose provider meta carries effects.invalidates for X → NEXT render refreshes
   (capability re-invoked) with NO manual invalidator call. Also: no-effects capability → stale served (no
   spurious invalidation); FAILED write → no invalidation; multi-tag (list+detail) invalidation; tenant-correct.
   Optional manifest fixture for effects.invalidates shape.
5. Doc: docs/kernel/entity-context-system.md (effect-declaration contract) + roadmap note.

## Prohibitions (unchanged)
- Only: src/helpers/module-routes.php + src/helpers/module-manager.php + kernel/Capabilities/CapabilityBus.php +
  tests/capability_effect_invalidation_test.php + docs/kernel/entity-context-system.md (+ roadmap note). NO
  migration/DDL. NO MySQL-8-only SQL. NO change to FragmentStore/render/cache/invalidator semantics. NO 6.5 auditor
  behavior change. NO ARK/CMS. NO other guarantees. NO full-suite runs.

## Verification (capture RAW to test_results/phase64-evidence.log)
- php -l on changed files; full tests/capability_effect_invalidation_test.php output; re-run targeted Phase-2 cache
  suite (tests/entity_view_render_cache_test.php 32/32) + tests/capability_authority_audit_test.php (19/19) +
  tests/workbench_workflow_guard_audit_test.php (35/35); PHPStan level 6 on changed files; git diff --check +
  --name-only/--stat (incl. untracked via intent-to-add); logs before/after (error.log empty).

## Return format (final message; concise; file:line refs)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:              (files + file:line refs)
implementation_summary: (effects field + validation, registration carrier, mode-aware post-success invalidation,
                         tenant mapping, fail-open, no-effects behavior)
verification:         (point to test_results/phase64-evidence.log; summarize counts)
risks:
unresolved:
recommended_next_state: (REVIEW_REQUIRED if done per contract)
Do NOT return empty. Do NOT report success without the raw evidence file complete.
