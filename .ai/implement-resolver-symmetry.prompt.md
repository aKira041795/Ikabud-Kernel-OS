You are the /implement + /review agent for Ikabud Kernel OS (repo root /var/www/html/ikabudsix, main c014cdf).
Execute the **EntityViewResolver @1-fallback symmetry** kernel micro-gate per the authoritative contract:

    .ai/contract-resolver-symmetry-gate-2026-09-07.md

READ THE CONTRACT FIRST. It is tiny + additive. Kernel change; do not broaden.

## Exact change (mirror existing code — verify against the file, don't guess)
kernel/EntityContext/EntityViewResolver.php:
- resolveAsResult(): after `$capabilityId = "entity.list.{$sanitizedType}";` (L506) add the SAME fallback block that
  resolve() has at L380-384: `if (function_exists('app') && ($app=app())!==null && method_exists($app,'capabilities')
  && !$app->capabilities()->has($capabilityId) && $app->capabilities()->has($capabilityId.'@1')) {
  $capabilityId .= '@1'; }`
- resolveDetail(): after `$capabilityId = "entity.get.{$sanitizedType}";` (L576) add the identical fallback
  (`entity.get.{type}` → `entity.get.{type}@1`).
- Do NOT touch resolve() (already correct) or anything else.

## Deliverables
1. The two fallback additions (identical semantics to resolve()).
2. A focused test (repo plain-PHP style): a module/fixture exposing ONLY versioned `entity.list.post@1` +
   `entity.get.post@1` resolves via resolveAsResult('entity.list.post') and resolveDetail('entity.get.post', …);
   and an unversioned `entity.list.post`/`entity.get.post` registration still takes precedence when present.
   Follow the existing entity view test patterns (tests/entity_view_render_cache_test.php + ark_renderer_runtime_test
   show how a view contract + capability are registered in tests).
3. One-line doc note if the fallback is documented.
4. Append result to the contract file.

## Verification
- php -l, targeted phpstan (no baseline additions), cs-fixer CI style.
- New focused test passes.
- `php tests/entity_view_render_cache_test.php` (32) + `php tests/ark_renderer_runtime_test.php` (17) stay green.
- `composer test` full. KNOWN: local capability_authority_audit_test is 17/18 due to the dormant unaligned
  modules/cms-akira submodules (out-of-scope, gitignored/absent in CI, resolved by fork P1 next) — report it as the
  known condition; do NOT try to fix it here. CI must pass 6/6.
- Both logs clean.

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state:
