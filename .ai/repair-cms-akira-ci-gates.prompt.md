You are the /repair agent for the CMS Akira fork product-core now tracked in Ikabud Kernel OS
(repo root /var/www/html/ikabudsix, branch feat/include-cms-akira-core, PR #36). The module passes functional +
kernel-contracts gates but fails the repo's coding-standards (PHP-CS-Fixer) and static-analysis (PHPStan) CI jobs
because it was developed as a gitignored runtime unit and never scanned by CI. Bring it to kernel CI standard.

## Scope (files ONLY — do not touch kernel/ or any other module)
- modules/cms-akira/cms-akira-core/helpers.php
- modules/cms-akira/cms-akira-core/helpers/capabilities.php
- modules/cms-akira/cms-akira-core/handlers.php
- modules/cms-akira/cms-akira-core/routes.php (only if lint/phpstan flags it)

## Failures to fix (from CI; verify locally)
PHPStan (~25, mainly missing array value-type annotations):
- Functions with `array $params` / `array $payload` / `array $fields` / `array $existing` / `array $post` params
  lacking value types → add PHPDoc `@param array<string, mixed> $x` (match the repo's convention — see how
  kernel handlers + modules/daily-ledger annotate).
- Return types on `cms_akira_core_capability_handlers()`, `cac_cap_cms_post_get_1()`, `cac_cap_cms_post_list_1()`,
  `cac_cap_cms_post_create_1()`, `cac_cap_cms_post_update_1()`, `cac_cap_entity_list_post_1()`,
  `cac_cap_entity_get_post_1()` lacking iterable value types → `@return array<string, mixed>` (or the exact shape).
- `cacDb()` "should return Ikabud\Kernel\Contracts\ModuleDB but returns DatabaseContract" → per repo convention
  (copilot-instructions: type module DB helpers to `Ikabud\Kernel\Contracts\ModuleDB`, `module()->db()` returns the
  module DB contract; do NOT cast to a raw PDO return). Fix the declared return type/implementation so it is honest
  and PHPStan-clean.
- "Else branch is unreachable because ternary operator condition is always true" (helpers.php ~L198 area) → fix the
  logic/typing so PHPStan is satisfied (inspect the actual ternary; correct the condition or annotate).
- "Offset 'error_count'/'warning_count' on ... always exists and is not nullable" → remove the unnecessary
  `?? ` on those array offsets (the array shape guarantees them).

PHP-CS-Fixer: run `composer lint` (dry-run, CI version 3.95.18 style — brace on same line `): void {` etc.) and fix
helpers.php (and any other flagged cms-akira-core file) so `composer lint` is clean for the module files.

## Verification (do all, report counts)
- `./vendor/bin/phpstan analyse --memory-limit=1G --no-progress modules/cms-akira/cms-akira-core` → 0 errors
  (do NOT add phpstan-baseline entries).
- `composer lint` (cs-fixer dry-run) → clean for the cms-akira-core files.
- `php -l` on every touched file.
- Do NOT regress functionality: P1/P2 module tests still pass
  (php modules/cms-akira/cms-akira-core/tests/post_read_render_test.php + post_mutation_test.php), authority audit
  18/18 (php tests/capability_authority_audit_test.php).
- Both logs clean.
- Do NOT modify module.json / migrations / routes behavior / any kernel file.

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED
changed:
summary:
verification: (phpstan errors 0, lint clean, tests counts, logs)
