<?php
/** T1 diagnostic — why does akira.theme.resolve@1 throw in the synthetic-tenant contract test? */
declare(strict_types=1);
$root = dirname(__DIR__);
$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
require_once $root . '/modules/cms-akira/cms-akira-theme/helpers.php';
require_once $root . '/modules/cms-akira/cms-akira-theme/handlers.php';

$registry = app()->capabilities();
$handlers = cms_akira_theme_capability_handlers();
echo "capability_handlers count: " . count($handlers) . "\n";
foreach (['akira.theme.resolve@1', 'akira.theme.activate@1', 'akira.theme.registry@1'] as $id) {
    $has = $registry->has($id);
    $provs = $registry->providers($id);
    echo "$id: registry->has=" . var_export($has, true) . " providers=" . count($provs);
    foreach ($provs as $p) { echo " [" . ($p['provider'] ?? '?') . " metaKeys=" . implode(',', array_keys($p['meta'] ?? [])) . "]"; }
    echo "\n";
}

app()->tenant()->setTenantId(994701);
app()->setUser(['id' => 999701, 'role' => 'admin']);
kernel_request_context_set('tenant_id', 994701);
kernel_request_context_delete('active_theme_slug');

try {
    $r = app()->cap()->call('akira.theme.resolve@1', [], ['caller' => ['module' => 'cms-akira-theme', 'user' => ['id' => 999701, 'role' => 'admin']], 'mode' => 'first']);
    echo "RESOLVE OK: " . json_encode($r) . "\n";
} catch (Throwable $e) {
    echo "RESOLVE FAIL: " . $e::class . ": " . $e->getMessage() . "\n";
    // probe deeper: does applyAuthorizationRegistry see a policy row?
    try {
        $reg = new \Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry(app()->db());
        foreach (['akira.theme.resolve@1', 'akira.theme.activate@1'] as $id) {
            $rows = $reg->activePolicyRows($id);
            echo "policy rows for $id: " . count($rows) . "\n";
        }
    } catch (Throwable $e2) {
        echo "policy probe fail: " . $e2->getMessage() . "\n";
    }
}
