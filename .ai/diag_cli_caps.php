<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/src/helpers/module-manager.php';

use Ikabud\Kernel\Capabilities\AuthorityScopeResolver;

$probe = function (string $label): void {
    $ids = [];
    foreach (getEnabledModules() as $module) {
        $ids[] = (string) ($module['id'] ?? '?');
    }
    echo "--- {$label} ---\n";
    echo 'enabled modules: ' . count($ids) . "\n";
    echo '  ' . implode(', ', array_slice($ids, 0, 30)) . "\n";
    echo 'cms-akira-theme enabled: ' . (in_array('cms-akira-theme', $ids, true) ? 'YES' : 'NO') . "\n";

    loadModuleRoutes(['GET' => [], 'POST' => [], 'PUT' => [], 'DELETE' => []]);

    echo 'handlers fn loaded: ' . (function_exists('cms_akira_theme_capability_handlers') ? 'YES' : 'NO') . "\n";
    echo 'catThemeValidate loaded: ' . (function_exists('catThemeValidate') ? 'YES' : 'NO') . "\n";

    try {
        $result = app()->cap()->call('akira.theme.activate@1', [
            'theme_slug' => 'akira-editorial',
            'idempotency_key' => 'diag-' . bin2hex(random_bytes(4)),
        ], ['caller' => ['module' => 'cms-akira-theme', 'user' => null], 'mode' => 'first']);
        echo 'CALL OK: ' . json_encode($result) . "\n";
    } catch (Throwable $e) {
        echo 'CALL FAILED: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
    echo "\n";
};

$probe('WITHOUT tenant scope');

try {
    AuthorityScopeResolver::withScope(54, AuthorityScopeResolver::CLI, static function () use ($probe): void {
        $probe('WITH scope(54, CLI)');
    });
} catch (Throwable $e) {
    echo 'withScope failed: ' . $e->getMessage() . "\n";
}
