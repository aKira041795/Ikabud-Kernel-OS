<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';
require_once $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . "\n";
};

$modules = [
    'theme' => ['_enabled' => true, 'admin_contributions' => [[
        'id' => 'theme.studio', 'host' => 'cms-akira-shell', 'location' => 'sidebar',
        'label' => 'Theme Studio', 'route' => '/theme', 'roles' => ['admin'], 'order' => 5,
    ]]],
];
$admin = kernelContributionsForHostLocation('cms-akira-shell', 'sidebar', $modules, ['user' => ['role' => 'admin']]);
$author = kernelContributionsForHostLocation('cms-akira-shell', 'sidebar', $modules, ['user' => ['role' => 'author']]);
$check(count($admin) === 1 && ($admin[0]['route'] ?? '') === '/theme', 'admin receives shell sidebar contribution');
$check($author === [], 'role-restricted sidebar contribution fails closed');

$seo = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/cms-akira-seo/module.json'), true);
$widget = $seo['admin_contributions'][0] ?? [];
$check(($widget['location'] ?? '') === 'dashboard.widgets'
    && ($widget['render_capability'] ?? '') === 'akira.seo.content_health@1', 'SEO declares a least-privilege dashboard widget renderer');

printf("extension widget registry: %d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
