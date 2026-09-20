<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/src/helpers/module-manager.php';
require_once dirname(__DIR__) . '/src/helpers/module-migrations.php';

$tenantId = 54;

echo "=== tenant {$tenantId} ===\n";
$db = app()->dbForTenant($tenantId);
echo "tenant db: " . $db->query('SELECT DATABASE()')->fetchColumn() . "\n";

$stmt = $db->prepare("SELECT module_id, setting_key, setting_value FROM tenant_module_settings WHERE tenant_id = ? AND setting_key IN ('_module_enabled','_module_activation_state') ORDER BY module_id");
$stmt->execute([$tenantId]);
echo "\n-- activation rows --\n";
$activated = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if ($r['setting_key'] === '_module_enabled') {
        $v = json_decode((string)$r['setting_value'], true);
        $activated[$r['module_id']] = (bool)$v;
        echo sprintf("  %-28s _module_enabled=%s\n", $r['module_id'], var_export($v, true));
    }
}

$entry = tenantEntryModuleIdForTenant($tenantId);
echo "\nentry_module_id = {$entry}\n";

$always = moduleRegistryAlwaysActiveForTenant($tenantId);
echo "\nalways-active closure (" . count($always) . "): " . implode(', ', array_keys($always)) . "\n";

$defaults = moduleRegistryRuntimeDefaultModulesForTenant($tenantId);
echo "\nruntime-default modules (" . count($defaults) . "): " . implode(', ', array_keys($defaults)) . "\n";

$all = moduleRegistryRawModuleManifests();
echo "\n-- moduleIsActive(module, 54) --\n";
foreach (array_keys($all) as $id) {
    $st = moduleIsActive($id, $tenantId);
    echo sprintf("  %-30s %s\n", $id, $st ? 'ACTIVE' : 'inactive');
}

echo "\n-- moduleIsActive(module) with ambient/CLI context --\n";
foreach (['cms-akira-workflow', 'cms-akira-search', 'cms-akira-navigation', 'cms-akira-core', 'cms-akira-shell'] as $id) {
    echo sprintf("  %-30s %s\n", $id, moduleIsActive($id) ? 'ACTIVE' : 'inactive');
}
