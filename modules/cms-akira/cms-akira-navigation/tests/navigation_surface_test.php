<?php

/** CMS Akira navigation operator-surface contract. */

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = 'akiracms.test';
$_SERVER['REQUEST_URI'] = '/cms-akira-navigation';
require $root . '/bootstrap.php';
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/handlers.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . "\n";
};

$module = dirname(__DIR__);
$manifest = json_decode((string) file_get_contents($module . '/module.json'), true, 512, JSON_THROW_ON_ERROR);
$routes = require $module . '/routes.php';
$handlers = (string) file_get_contents($module . '/handlers.php');
$template = (string) file_get_contents($module . '/templates/admin.disyl');

$check(
    ($routes['GET']['/cms-akira-navigation'] ?? '') === 'cms-akira-navigation:akiraNavigationAdminPage',
    'admin GET route resolves to its module-owned handler'
);
$check(
    ($routes['POST']['/cms-akira-navigation/menu/create'] ?? '') === 'cms-akira-navigation:akiraNavigationMenuCreateForm',
    'menu-create POST route resolves to its module-owned handler'
);
$check(
    ($manifest['capabilities']['routes']['POST /cms-akira-navigation/menu/create'] ?? '') === 'akira.navigation.menu.create@1',
    'menu-create POST declares its capability authority'
);
$check(
    preg_match("/function akiraNavigationMenuCreateForm.*?app\\(\\)->cap\\(\\)->call\\('akira\\.navigation\\.menu\\.create@1'/s", $handlers) === 1,
    'menu-create handler invokes the declared capability through the bus'
);
$contribution = $manifest['admin_contributions'][0] ?? [];
$check(
    ($contribution['host'] ?? '') === 'cms-akira-shell'
    && ($contribution['location'] ?? '') === 'sidebar'
    && ($contribution['route'] ?? '') === '/cms-akira-navigation'
    && ($contribution['roles'] ?? []) === ['admin', 'administrator', 'superadmin'],
    'shell contribution uses the active navigation-policy role set'
);
$check(
    str_contains($template, 'action="/cms-akira-navigation/menu/create"')
    && str_contains($template, '{csrf_field | raw}')
    && str_contains($template, 'name="idempotency_key"'),
    'module-owned form carries CSRF and idempotency inputs'
);

app()->setUser(['id' => 91234, 'role' => 'administrator']);
$check((canNavigationActor()['role'] ?? '') === 'administrator', 'local mutation gate admits administrator');
app()->setUser(['id' => 91235, 'role' => 'superadmin', 'source' => 'kernel']);
$check((canNavigationActor()['role'] ?? '') === 'superadmin', 'local mutation gate admits kernel superadmin');

echo "\nCMS Akira navigation surface: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
