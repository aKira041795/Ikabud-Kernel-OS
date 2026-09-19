<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

echo "CLI tenant current: " . var_export(app()->tenant()->current(), true) . "\n\n";

$tenantId = 54;
$db = app()->dbForTenant($tenantId);
echo "tenant {$tenantId} connected db: " . $db->query('SELECT DATABASE()')->fetchColumn() . "\n\n";

$stmt = $db->prepare('SELECT setting_key, setting_value FROM tenant_module_settings WHERE module_id = ? ORDER BY setting_key');
$stmt->execute(['cms-akira-theme']);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "cms-akira-theme settings for tenant {$tenantId}: " . count($rows) . " row(s)\n";
foreach ($rows as $r) {
    echo "  " . $r['setting_key'] . " = " . $r['setting_value'] . "\n";
}

echo "\ntheme-ish settings across ALL modules (tenant {$tenantId}):\n";
$stmt = $db->query("SELECT module_id, setting_key, setting_value FROM tenant_module_settings WHERE setting_key LIKE '%theme%' ORDER BY module_id, setting_key");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  " . $r['module_id'] . ' . ' . $r['setting_key'] . ' = ' . $r['setting_value'] . "\n";
}

echo "\n_is module cms-akira-theme enabled?_\n";
$stmt = $db->prepare('SELECT setting_value FROM tenant_module_settings WHERE module_id = ? AND setting_key = ?');
$stmt->execute(['cms-akira-theme', '_module_enabled']);
echo "  _module_enabled = " . var_export($stmt->fetchColumn(), true) . "\n";
