<?php

declare(strict_types=1);

/**
 * Wiring proof: `catThemeValidate()` surfaces entity-view contract drift.
 *
 * The pure comparison is proven in tests/akira_theme_view_contract_drift_test.php.
 * This module test exercises the actual hook: shipped declarations pass, a
 * forced field drift becomes an error, and a resolver with no contracts at all
 * skips the check rather than inventing a pass.
 */

$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/bootstrap.php';
require_once $root . '/tests/_support/env_guard.php';
require_once dirname(__DIR__) . '/helpers.php';

requireNotLiveTenantDatabase();

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

if (!function_exists('cacRegisterPostEntityViews')) {
    require_once $root . '/modules/cms-akira/cms-akira-core/helpers/entity-views.php';
}
$views = app()->entityViews();
cacRegisterPostEntityViews($views);

$errorText = static fn (array $result): string => implode("\n", $result['errors'] ?? []);
$warningText = static fn (array $result): string => implode("\n", $result['warnings'] ?? []);

echo "=== Akira theme entity-view contract wiring ===\n";

// ── 1. The shipped declarations now agree with the registered contract ──
$editorial = catThemeValidate('akira-editorial');
$check(
    ($editorial['checks']['entity_view_contract'] ?? null) === true,
    'shipped editorial map agrees with the registered contract'
);
$check(
    str_contains($warningText($editorial), "'composition.detail'")
        && str_contains($warningText($editorial), 'contract is registered'),
    'composition.detail surfaces as a contract_not_registered warning'
);
$check(
    !str_contains($errorText($editorial), 'not present in the registered contract'),
    'no field_not_in_contract error for the corrected declarations'
);
$check(($editorial['valid'] ?? false) === true, 'the corrected editorial theme still validates');

$ark = catThemeValidate('akira-ark');
$check(
    ($ark['checks']['entity_view_contract'] ?? null) === true
        && !str_contains($errorText($ark), 'not present in the registered contract'),
    'shipped ark map agrees with the registered contract'
);

// ── 2. A contract that lacks a declared field is an error ──
$views->registerView('post', 'list', ['fields' => ['title']], 'drift-fixture');
$drifted = catThemeValidate('akira-editorial');
$check(
    ($drifted['checks']['entity_view_contract'] ?? null) === false,
    'the check records failure when a contract lacks a declared field'
);
$check(
    str_contains($errorText($drifted), "'post.list'")
        && str_contains($errorText($drifted), "'actions'"),
    'field_not_in_contract names the entity, view and field and lands in errors'
);
$check(($drifted['valid'] ?? true) === false, 'a field drift error invalidates the theme');

// Restore the canonical registration for the remaining assertions.
$views->registerView('post', 'list', [
    'fields' => ['title', 'subtitle', 'image', 'metadata', 'categories', 'actions', 'url'],
], 'cms-akira-core');

// ── 3. No obtainable contract => skipped, never an error and never a pass ──
$views->reset();
$skipped = catThemeValidate('akira-editorial');
$check(
    ($skipped['checks']['entity_view_contract'] ?? null) === 'skipped',
    'no contract obtainable records the check as skipped'
);
$check(
    !str_contains($errorText($skipped), 'registered contract')
        && !str_contains($warningText($skipped), 'no entity view contract is registered'),
    'the skipped path emits neither a drift error nor a manufactured pass'
);

echo "\nAkira theme entity-view contract wiring: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
