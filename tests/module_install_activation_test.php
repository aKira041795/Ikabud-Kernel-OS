<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    $ok ? ++$pass : ++$fail;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . "\n";
};

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE tenant_module_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, module_id TEXT NOT NULL, setting_key TEXT NOT NULL, setting_value TEXT, created_at TEXT, updated_at TEXT, UNIQUE(tenant_id,module_id,setting_key))');

tenantSetModuleActivationState($db, 71001, ['cms-akira-core', 'cms-akira-shell'], false, 'generation-a', true);
$enabledAtStage = $db->query("SELECT COUNT(*) FROM tenant_module_settings WHERE setting_key = '_module_enabled'")->fetchColumn();
$check((int)$enabledAtStage === 0, 'staging is non-routable');
$check((int)$db->query("SELECT COUNT(*) FROM tenant_module_settings WHERE setting_key = '_module_activation_state' AND setting_value = '\"staged\"'")->fetchColumn() === 2, 'closure activation is staged per member');

tenantSetModuleActivationState($db, 71001, ['cms-akira-core', 'cms-akira-shell'], true, 'generation-a');
$check((int)$db->query("SELECT COUNT(*) FROM tenant_module_settings WHERE tenant_id = 71001 AND setting_key = '_module_enabled' AND setting_value = 'true'")->fetchColumn() === 2, 'committed generation promotes closure');
$check((int)$db->query("SELECT COUNT(*) FROM tenant_module_settings WHERE setting_key = '_module_committed_generation' AND setting_value = '\"generation-a\"'")->fetchColumn() === 2, 'committed generation recorded');

tenantSetModuleActivationState($db, 71002, ['cms-akira-shell'], true, 'generation-b');
$check((int)$db->query("SELECT COUNT(*) FROM tenant_module_settings WHERE tenant_id = 71001 AND module_id = 'cms-akira-shell' AND setting_key = '_module_committed_generation' AND setting_value = '\"generation-a\"'")->fetchColumn() === 1, 'tenant B cannot overwrite tenant A state');
tenantSetModuleActivationState($db, 71001, ['cms-akira-shell'], true, 'generation-a');
$check((int)$db->query("SELECT COUNT(*) FROM tenant_module_settings WHERE tenant_id = 71001 AND module_id = 'cms-akira-shell' AND setting_key = '_module_enabled'")->fetchColumn() === 1, 'activation rerun is idempotent');
tenantSetModuleActivationState($db, 71001, ['cms-akira-shell'], false);
$check((int)$db->query("SELECT COUNT(*) FROM tenant_module_settings WHERE tenant_id = 71001 AND module_id = 'cms-akira-shell' AND setting_key = '_module_enabled' AND setting_value = 'false'")->fetchColumn() === 1, 'uninstall deactivates without deleting settings');

$source = (string)file_get_contents($root . '/kernel/Services/ModuleInstallService.php');
$migration = (string)file_get_contents($root . '/control-migrations/007_module_install_generations.sql');
foreach (['migration', 'policy_seed', 'activation_write', 'entry_write'] as $window) {
    $check(str_contains($source, "failIfRequested(\$options, '{$window}')"), "{$window} failure window is injectable");
}
foreach (['install_requested', 'dependencies_resolving', 'migrations_running', 'policy_seeding', 'activation_writing', 'pending_rollback'] as $state) {
    $check(str_contains($source, $state), "{$state} state is persisted");
}
$check(str_contains($migration, 'uq_tenant_install_generation'), 'tenant/generation uniqueness migration');
$check(str_contains($migration, 'uq_install_member_step'), 'per-member step uniqueness migration');
$check(str_contains($migration, 'ENGINE=InnoDB') && str_contains($migration, 'utf8mb4_unicode_ci'), 'MySQL 5.7 storage contract');
$check(str_contains($source, 'GET_LOCK') && str_contains($source, 'ikabud_module_install_'), 'per-tenant control-plane lock');
$check(str_contains($source, 'data_preserved'), 'uninstall explicitly preserves owned data');
$check(!str_contains($source, 'new TenantProvisioner') && !str_contains($source, '->provision('), 'installer does not invoke whole-tenant provisioner');

echo "module install activation: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
