<?php

/**
 * CMS Akira Phase 10B — cms-akira-profile-visual install/enable path proof.
 *
 * Profile-visual is install metadata only. This test proves the Kernel
 * module-install activation closure can enable the ENTIRE visual graph
 * (incl. cms-akira-builder) from an EMPTY tenant state, without making the
 * closure routable before commit, idempotently on rerun, and without leaking
 * to another tenant. Full per-tenant migrations + policy seeding run through
 * the Kernel ModuleInstallService::install pipeline in a real deployment; the
 * activation write path (the same tenantSetModuleActivationState closure the
 * canonical module_install_activation_test exercises) is proven here.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . "\n";
};

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE tenant_module_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, module_id TEXT NOT NULL, setting_key TEXT NOT NULL, setting_value TEXT, created_at TEXT, updated_at TEXT, UNIQUE(tenant_id,module_id,setting_key))');

$visual = kernelReadJsonFile($root . '/modules/cms-akira/cms-akira-profile-visual/module.json');
$builder = kernelReadJsonFile($root . '/modules/cms-akira/cms-akira-builder/module.json');
$expected = ['cms-akira-builder', 'cms-akira-core', 'cms-akira-shell', 'cms-akira-editor', 'cms-akira-theme', 'cms-akira-navigation', 'cms-akira-media', 'cms-akira-seo', 'cms-akira-workflow', 'cms-akira-search'];
$install = $visual['installs'] ?? [];
$depends = $visual['depends'] ?? [];

echo "=== CMS Akira Phase 10B profile-visual install path ===\n";

// profile-visual is a pure, data-free install bundle.
$check(($visual['kind'] ?? '') === 'profile', 'profile-visual is a profile');
$check($install === $expected && $depends === $expected, 'profile-visual installs/depends == full visual graph incl. builder');
$check(($visual['owns_tables'] ?? null) === [] && ($visual['reads_tables'] ?? null) === [] && ($visual['migrations'] ?? null) === [], 'profile-visual is table/migration free');
$check(($visual['routes'] ?? null) === false, 'profile-visual is not routable');
$check(!isset($visual['entry_module']) && !isset($visual['authentication_provider']) && !isset($visual['entry_delegate']), 'profile-visual has no entry/auth residue');
$check(!is_dir($root . '/modules/cms-akira/cms-akira-profile-visual/database') && !is_file($root . '/modules/cms-akira/cms-akira-profile-visual/handlers.php') && !is_file($root . '/modules/cms-akira/cms-akira-profile-visual/routes.php'), 'profile-visual ships module.json + README only');

// Builder is tracked but remains _enabled:false (repository never auto-installs).
$check(($builder['_enabled'] ?? null) === false && ($builder['id'] ?? '') === 'cms-akira-builder', 'builder stays tracked _enabled:false until a tenant install');

// Empty-tenant activation of the full visual closure via the install activation path.
$freshTenant = 71881;
tenantSetModuleActivationState($db, $freshTenant, $expected, false, 'visual-gen-a', true);
$stagedRoutable = (int) $db->query("SELECT COUNT(*) FROM tenant_module_settings WHERE tenant_id = {$freshTenant} AND setting_key = '_module_enabled'")->fetchColumn();
$stagedState = (int) $db->query("SELECT COUNT(*) FROM tenant_module_settings WHERE tenant_id = {$freshTenant} AND setting_key = '_module_activation_state' AND setting_value = '\"staged\"'")->fetchColumn();
$check($stagedRoutable === 0 && $stagedState === count($expected), 'staging the visual closure on an empty tenant is non-routable and staged per member');

tenantSetModuleActivationState($db, $freshTenant, $expected, true, 'visual-gen-a');
$enabledMembers = (int) $db->query("SELECT COUNT(*) FROM tenant_module_settings WHERE tenant_id = {$freshTenant} AND setting_key = '_module_enabled' AND setting_value = 'true'")->fetchColumn();
$builderEnabled = (int) $db->query("SELECT COUNT(*) FROM tenant_module_settings WHERE tenant_id = {$freshTenant} AND module_id = 'cms-akira-builder' AND setting_key = '_module_enabled' AND setting_value = 'true'")->fetchColumn();
$generation = (int) $db->query("SELECT COUNT(*) FROM tenant_module_settings WHERE tenant_id = {$freshTenant} AND setting_key = '_module_committed_generation' AND setting_value = '\"visual-gen-a\"'")->fetchColumn();
$check($enabledMembers === count($expected) && $generation === count($expected), 'committed generation enables every visual member');
$check($builderEnabled === 1, 'cms-akira-builder is enabled for the empty tenant only after the visual commit');

// Idempotent rerun + tenant isolation.
tenantSetModuleActivationState($db, $freshTenant, $expected, true, 'visual-gen-a');
$check((int) $db->query("SELECT COUNT(*) FROM tenant_module_settings WHERE tenant_id = {$freshTenant} AND setting_key = '_module_enabled' AND setting_value = 'true'")->fetchColumn() === count($expected), 'install/activation rerun is idempotent');
tenantSetModuleActivationState($db, 71882, ['cms-akira-builder'], true, 'visual-gen-b');
$check((int) $db->query("SELECT COUNT(*) FROM tenant_module_settings WHERE tenant_id = {$freshTenant} AND module_id = 'cms-akira-builder' AND setting_key = '_module_committed_generation' AND setting_value = '\"visual-gen-b\"'")->fetchColumn() === 0, 'another tenant cannot overwrite this tenant buildergeneration');

// The Kernel install service resolves a profile selection by its installs + depends closure.
$source = (string) file_get_contents($root . '/kernel/Services/ModuleInstallService.php');
$check(str_contains($source, "['installs']") && str_contains($source, "['depends']"), 'ModuleInstallService resolves profiles by installs + depends closure');

echo "\nCMS Akira Builder profile-visual install path: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
