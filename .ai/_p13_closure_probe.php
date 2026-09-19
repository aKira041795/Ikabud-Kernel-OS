<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/src/helpers/module-manager.php';
require_once dirname(__DIR__) . '/src/helpers/module-migrations.php';

$tenantId = 54;
$all = moduleRegistryRawModuleManifests();

// capability -> [provider modules]
$exposesByCap = [];
foreach ($all as $mid => $m) {
    foreach ((array)($m['capabilities']['exposes'] ?? []) as $e) {
        $id = is_array($e) ? trim((string)($e['id'] ?? '')) : trim((string)$e);
        if ($id === '') continue;
        $exposesByCap[$id][] = $mid;
    }
}

$shell = $all['cms-akira-shell'] ?? [];
$depends = (array)($shell['capabilities']['depends'] ?? []);
$activated = [];
$db = app()->dbForTenant($tenantId);
$stmt = $db->prepare("SELECT module_id, setting_value FROM tenant_module_settings WHERE tenant_id=? AND setting_key='_module_enabled'");
$stmt->execute([$tenantId]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $activated[$r['module_id']] = json_decode((string)$r['setting_value'], true) === true;
}

$active = [];
$inactive = [];
echo "=== shell capability.depends provider coverage for tenant {$tenantId} ===\n";
foreach ($depends as $ref) {
    $cap = is_array($ref) ? trim((string)($ref['id'] ?? '')) : trim((string)$ref);
    if ($cap === '') continue;
    $providers = $exposesByCap[$cap] ?? [];
    if ($providers === []) { echo sprintf("  %-42s -> NO PROVIDER\n", $cap); continue; }
    foreach ($providers as $p) {
        $st = moduleIsActive($p, $tenantId);
        echo sprintf("  %-42s -> %-26s %s\n", $cap, $p, $st ? 'ACTIVE' : 'INACTIVE');
        if ($st) $active[$p] = true; else $inactive[$p] = true;
    }
}
echo "\nproviders ACTIVE (union): " . implode(', ', array_keys($active)) . "\n";
echo "providers INACTIVE (union): " . (array_keys($inactive) ? implode(', ', array_keys($inactive)) : '(none)') . "\n";

echo "\n=== modules explicitly activated but NOT in shell depends providers ===\n";
foreach (array_keys($activated) as $m) {
    if (!isset($active[$m]) && !isset($inactive[$m])) echo "  {$m}\n";
}
