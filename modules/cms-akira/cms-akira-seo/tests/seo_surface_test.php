<?php

/** CMS Akira SEO operator-surface contract. */

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = 'akiracms.test';
$_SERVER['REQUEST_URI'] = '/cms-akira-seo';
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
    ($routes['GET']['/cms-akira-seo'] ?? '') === 'cms-akira-seo:akiraSeoAdminPage',
    'admin GET route resolves to its module-owned handler'
);
foreach ([
    '/cms-akira-seo/upsert' => ['akiraSeoUpsertForm', 'akira.seo.upsert@1'],
    '/cms-akira-seo/delete' => ['akiraSeoDeleteForm', 'akira.seo.delete@1'],
] as $route => [$handler, $capability]) {
    $check(
        ($routes['POST'][$route] ?? '') === 'cms-akira-seo:' . $handler,
        $route . ' resolves to its module-owned handler'
    );
    $check(
        ($manifest['capabilities']['routes']['POST ' . $route] ?? '') === $capability,
        $route . ' declares its capability authority'
    );
    $quotedCapability = preg_quote($capability, '/');
    $check(
        preg_match('/function ' . preg_quote($handler, '/') . ".*?app\\(\\)->cap\\(\\)->call\\('" . $quotedCapability . "'/s", $handlers) === 1,
        $handler . ' invokes the declared capability through the bus'
    );
}

$roles = ['admin', 'administrator', 'superadmin'];
$contributions = [];
foreach (($manifest['admin_contributions'] ?? []) as $contribution) {
    if (is_array($contribution)) {
        $contributions[(string) ($contribution['id'] ?? '')] = $contribution;
    }
}
$sidebar = $contributions['cms-akira-seo.metadata'] ?? [];
$health = $contributions['cms-akira-seo.content-health'] ?? [];
$check(
    ($sidebar['host'] ?? '') === 'cms-akira-shell'
    && ($sidebar['location'] ?? '') === 'sidebar'
    && ($sidebar['route'] ?? '') === '/cms-akira-seo'
    && ($sidebar['roles'] ?? []) === $roles,
    'shell sidebar contribution uses the active SEO-policy role set'
);
$check(($health['roles'] ?? []) === $roles, 'dashboard contribution uses its active policy role set');
$check(
    str_contains($template, 'action="/cms-akira-seo/upsert"')
    && str_contains($template, 'action="/cms-akira-seo/delete"')
    && substr_count($template, '{csrf_field | raw}') === 2
    && str_contains($template, 'name="idempotency_key"'),
    'module-owned mutation forms carry CSRF and idempotency inputs'
);

app()->setUser(['id' => 91234, 'role' => 'administrator']);
$check((casSeoActor()['role'] ?? '') === 'administrator', 'local mutation gate admits administrator');
app()->setUser(['id' => 91235, 'role' => 'superadmin', 'source' => 'kernel']);
$check((casSeoActor()['role'] ?? '') === 'superadmin', 'local mutation gate admits kernel superadmin');

echo "\nCMS Akira SEO surface: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
