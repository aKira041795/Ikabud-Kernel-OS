# 6.4 — CONSISTENCY: capability effect declarations → kernel-driven entity-cache invalidation — CONTRACT (2026-09-06)

task: Implement the Consistency-guarantee objective that moves entity-cache invalidation from MODULE DISCIPLINE to a
KERNEL CONTRACT: a capability that mutates an entity DECLARES its effect (`effects.invalidates: ["entity.list.<type>",
"entity.detail.<type>"]`) and the kernel auto-invalidates those entity-view cache tags AFTER the capability dispatch
SUCCEEDS. Closes the documented-but-unenforced manual pattern.

objective: Eliminate the "developer forgot invalidation → stale cache until TTL" failure mode. The kernel already
knows which capability mutated what (provider module, tenant); the effect declaration makes invalidation automatic,
deterministic, and opt-in (additive). Grounded gap (Explore 2026-09-06): invalidateEntityCache exists
(EntityViewResolver.php:62-95, tags entity.list.<type>/entity.detail.<type>, fail-open) but NO production module
calls it — docs describe the manual pattern (docs/kernel/entity-context-system.md:259-263) that is nowhere enforced.

## Grounded seams (verified 2026-09-06)
- Invalidator: kernel/EntityContext/EntityViewResolver.php:62 `invalidateEntityCache(type, ?tenant)` →
  fragmentStore invalidate of tags entity.list.<type>/entity.detail.<type> (:77-79), fail-open (:90-95).
- Cache tags at render: kernel/DiSyL/Component/ComponentRenderer.php:2302-2323 (`entity.<kind>.<entityType>` tags;
  FragmentStore dep_versions.json per-tenant, file+APCu — no SQL).
- Declaration: module.json `capabilities.exposes[]` (gui-settings/module.json:269-283: id/priority/modes/description).
  NO effects field exists. Validators are ADDITIVE (no unknown-key rejection):
  src/helpers/manifest-validation.php:166-179, module-manager.php validateModuleCapabilities :1825-1964.
- Registration carrier: src/helpers/module-routes.php:180-245 → register($capId,$moduleId,$handler,$priority,$modes,
  meta['policy'/'schema'/'origin']) :217-229. meta is the carrier for effects.
- Post-success dispatch seam: kernel/Capabilities/CapabilityBus.php:182 call() → match(mode) :221-225 → return :229;
  providers carry meta; callProvider already reads meta (e.g. schema :689). Tenant: app()->tenant()->current()
  (kernel/App.php:1001) — same default the resolver uses.
- Test pattern: tests/entity_view_render_cache_test.php (temp FragmentStore, synthetic registerView/register cap,
  setTenantId, render call-count asserts) + capability fixture via temp module dir
  (tests/capability_authority_audit_test.php pattern).

## scope:
  allowed:
    - Additive `effects.invalidates` on module.json `capabilities.exposes[]`: an optional array of non-empty cache
      tags (`entity.list.<type>` / `entity.detail.<type>`). Validators stay additive — add light validation in
      validateModuleCapabilities (effects.invalidates is an array of non-empty strings) WITHOUT rejecting unknown
      fields or breaking schema v1.
    - Carry effects into provider meta at registration: src/helpers/module-routes.php (the register meta array;
      include the service-proxy branch if it shares the register path).
    - Auto-invalidation AFTER successful dispatch in kernel/Capabilities/CapabilityBus.php call(): gather the
      EXECUTED provider(s) (respecting mode first/pipeline/fanout — only providers actually run), read
      meta['effects']['invalidates'], map to tenant, call app()->entityViews()->invalidateEntityCache(...) per tag
      (resolver is fail-open). Effects are provider-module-owned (key on provider meta origin, not caller).
      No invalidation on failure; no behavior change for capabilities without effects.
    - Tests: new tests/capability_effect_invalidation_test.php (synthetic): register entity.list/detail view + a
      WRITE capability whose provider meta carries effects.invalidates for that type; render (populate fragment);
      dispatch the write capability via app()->cap()->call(...); assert the NEXT render refreshes (capability
      re-invoked) WITHOUT the test calling the invalidator. Also: capability without effects → no invalidation
      (stale served); invalidation on failure → NOT applied; multi-tag invalidation. Optional: a manifest-validation
      fixture (temp module dir) for effects.invalidates shape.
    - Doc: docs/kernel/entity-context-system.md (effect-declaration contract) + a roadmap note.
  prohibited:
    - NO migration/DDL; NO MySQL-8-only SQL (feature is file/APCu + additive JSON).
    - NO change to render/cache semantics, FragmentStore, or the invalidator. NO change to dispatch/auth/policy
      semantics beyond the post-success effect hook. NO change to 6.5 auditor behavior (may optionally expose effects
      in inspect later — NOT this unit). NO ARK/CMS. NO other guarantees. NO full-suite runs.
    - Do NOT auto-invalidate on capability FAILURE or for callers without an executed provider.

### constraints:
  - Opt-in and additive: capabilities without effects behave exactly as today; schema v1 manifests stay valid.
  - Only EXECUTED providers trigger invalidation (mode-aware); effects are provider-owned (declared by the module
    exposing the capability), never derived from the caller.
  - Deterministic, tenant-correct (same tenant the resolver uses); fail-open (a resolver/store error never breaks
    the write path).
  - PSR-12 + PHPStan level 6 (no new errors); read actual code first; check BOTH logs after runs.

### acceptance:
  - A synthetic write capability declaring effects.invalidates for type X: after render-populating the entity
    fragment for X, dispatching the write capability causes the NEXT render of X to refresh (capability re-invoked,
    call-count proves it) WITHOUT any manual invalidator call.
  - A capability WITHOUT effects: fragment stays cached across dispatch (no spurious invalidation).
  - A FAILED write capability: no invalidation (fragment stays cached).
  - Multiple tags (list+detail) all invalidated; tenant-scoped correctly.
  - module.json additive validation accepts the effects block; schema-v1 manifests without it remain valid.
  - New test passes; existing cache + capability + workflow suites stay green; php -l clean; PHPStan no new errors;
    both logs clean.

### verification:
  - php -l on changed files; new tests/capability_effect_invalidation_test.php full output; targeted Phase-2 cache
    suite (entity_view_render_cache_test 32/32) + capability:audit test still green; PHPStan level 6 on changed
    files; git diff --check + name/stat; logs before/after (error.log empty). Capture RAW output to
    test_results/phase64-evidence.log.

### risk:
  - Tag naming drift between effect declarations and render tags — mitigate: document the exact tag contract
    (entity.list.<type> / entity.detail.<type>) and validate non-empty strings; a future 6.5-style audit can
    cross-check unresolved tags (documented follow-on, not this unit).
  - Mode semantics (fanout/pipeline) must invalidate only for providers that actually executed — verify against
    callPipeline/callFanout before coding.

### status: READY_FOR_IMPLEMENTATION
