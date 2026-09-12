<?php

/** Executable dedicated-database tenant isolation parity gate for theme activation settings. */

declare(strict_types=1);

$root = dirname(__DIR__, 4);
define('IKABUD_CLI', true);
$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
require_once $root . '/tests/_support/env_guard.php';
require_once dirname(__DIR__) . '/helpers.php';

requireTwoDistinctDedicatedTenantDatabases();

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

$connections = app()->controlDb()->query(
    'SELECT tenant_id FROM kernel_tenant_db_connections ORDER BY tenant_id ASC LIMIT 2'
)->fetchAll(PDO::FETCH_COLUMN);
if (count($connections) < 2) {
    echo "CMS Akira theme dedicated: BLOCKED — two configured dedicated tenant databases are required\n";
    exit(2);
}

$tenantA = (int) $connections[0];
$tenantB = (int) $connections[1];
$pdoA = app()->dbForTenant($tenantA);
$pdoB = app()->dbForTenant($tenantB);
$baseName = (string) app()->db()->query('SELECT DATABASE()')->fetchColumn();
$nameA = $pdoA instanceof PDO ? (string) $pdoA->query('SELECT DATABASE()')->fetchColumn() : '';
$nameB = $pdoB instanceof PDO ? (string) $pdoB->query('SELECT DATABASE()')->fetchColumn() : '';
$check($pdoA instanceof PDO && $pdoB instanceof PDO, 'Kernel selects both configured tenant connections');
$check($nameA !== '' && $nameB !== '' && $nameA !== $baseName && $nameB !== $baseName, 'live connected identities reject the Kernel/base database');
$check($nameA !== $nameB, 'dedicated tenants resolve to distinct databases');

if (!$pdoA instanceof PDO || !$pdoB instanceof PDO) {
    echo "\nCMS Akira theme dedicated: {$passed} passed, " . (++$failed) . " failed\n";
    exit(1);
}

$originalTenant = app()->tenant()->current();
$readSetting = static function (PDO $pdo, int $tenant): ?string {
    $settings = _readTenantModuleSettingsSingle('cms-akira-theme', $tenant, $pdo);
    $value = $settings[CAT_THEME_SETTING_ACTIVE] ?? null;
    return is_string($value) ? $value : null;
};

try {
    moduleTenantSettingsEnsureTable($pdoA);
    moduleTenantSettingsEnsureTable($pdoB);
    $pdoA->prepare('DELETE FROM tenant_module_settings WHERE tenant_id = ? AND module_id = ?')->execute([$tenantA, 'cms-akira-theme']);
    $pdoB->prepare('DELETE FROM tenant_module_settings WHERE tenant_id = ? AND module_id = ?')->execute([$tenantB, 'cms-akira-theme']);

    // Use the same Kernel-owned setting upsert the activation capability calls,
    // but against each tenant's explicit dedicated PDO to prove connection
    // selection and cross-database isolation of the security-relevant state.
    $wroteA = tenantWriteModuleSetting($pdoA, $tenantA, 'cms-akira-theme', CAT_THEME_SETTING_ACTIVE, 'ark-renderer-fixture');
    $check(
        $wroteA === true && $readSetting($pdoA, $tenantA) === 'ark-renderer-fixture',
        'activation setting persists on tenant A dedicated database'
    );
    $check($readSetting($pdoB, $tenantB) === null, 'tenant B dedicated database cannot read tenant A activation setting');

    $wroteB = tenantWriteModuleSetting($pdoB, $tenantB, 'cms-akira-theme', CAT_THEME_SETTING_ACTIVE, 'cms-akira-posts');
    $check(
        $wroteB === true
        && $readSetting($pdoB, $tenantB) === 'cms-akira-posts'
        && $readSetting($pdoA, $tenantA) === 'ark-renderer-fixture',
        'dedicated activation settings remain isolated under the same setting key'
    );

    $tablesA = $pdoA->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'cms_akira_theme%'"
    );
    $tablesB = $pdoB->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'cms_akira_theme%'"
    );
    $check((int) $tablesA->fetchColumn() === 0 && (int) $tablesB->fetchColumn() === 0, 'dedicated activation never creates a theme table on either database');
} catch (Throwable $error) {
    $check(false, 'dedicated theme scenario completes', $error->getMessage());
} finally {
    try {
        $pdoA->prepare('DELETE FROM tenant_module_settings WHERE tenant_id = ? AND module_id = ?')->execute([$tenantA, 'cms-akira-theme']);
        $pdoB->prepare('DELETE FROM tenant_module_settings WHERE tenant_id = ? AND module_id = ?')->execute([$tenantB, 'cms-akira-theme']);
    } catch (Throwable) {
        // Cleanup is best-effort.
    }
    app()->tenant()->setTenantId($originalTenant);
    kernel_request_context_delete('tenant_id');
    kernel_request_context_delete('_tenant_module_settings_cache');
    kernel_request_context_delete('active_theme_slug');
}

echo "\nCMS Akira theme dedicated: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
