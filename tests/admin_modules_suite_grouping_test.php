<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/http/page-handlers.php';

$modules = [
    'acme-core' => [
        'id' => 'acme-core',
        'name' => 'Acme Core',
        'suite' => 'acme',
        'kind' => 'product-core',
        'product' => ['id' => 'acme', 'name' => 'Acme Product'],
    ],
    'acme-search' => ['id' => 'acme-search', 'name' => 'Search', 'suite' => 'acme'],
    'solo-suite' => ['id' => 'solo-suite', 'name' => 'Solo Suite', 'suite' => 'solo'],
    'standalone' => ['id' => 'standalone', 'name' => 'Standalone'],
];
$moduleList = [
    ['id' => 'acme-core', 'name' => 'Acme Core', 'suite' => 'acme', 'enabled' => true],
    ['id' => 'acme-search', 'name' => 'Search', 'suite' => 'acme', 'enabled' => false],
    ['id' => 'solo-suite', 'name' => 'Solo Suite', 'suite' => 'solo', 'enabled' => true],
    ['id' => 'standalone', 'name' => 'Standalone', 'suite' => null, 'enabled' => false],
];

$grouped = kernelGroupAdminModules($moduleList, $modules);
$suites = $grouped['module_suites'];
$flatIds = array_column($grouped['standalone_modules'], 'id');

$failures = [];
if (count($suites) !== 1 || ($suites[0]['suite'] ?? null) !== 'acme') {
    $failures[] = 'multi-member suite is grouped';
}
if (($suites[0]['name'] ?? null) !== 'Acme Product') {
    $failures[] = 'suite display name comes from core product';
}
if (($suites[0]['enabled_count'] ?? null) !== 1 || !($suites[0]['any_enabled'] ?? false) || ($suites[0]['all_enabled'] ?? true)) {
    $failures[] = 'suite enablement summary reflects member states';
}
if ($flatIds !== ['solo-suite', 'standalone']) {
    $failures[] = 'single-member suites and standalone modules remain flat';
}

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . implode('; ', $failures) . "\n");
    exit(1);
}

echo "PASS: admin module suite grouping\n";
