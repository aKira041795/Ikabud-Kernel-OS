<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manifest = json_decode((string)file_get_contents($root . '/module.json'), true);
$routes = require $root . '/routes.php';
$handlers = (string)file_get_contents($root . '/handlers.php');
$helpers = (string)file_get_contents($root . '/helpers.php');
$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    $ok ? ++$pass : ++$fail;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . "\n";
};

$check(($manifest['id'] ?? '') === 'cms-akira-shell', 'unique shell id');
$check(($manifest['entry_module'] ?? false) === true, 'entry module declared');
$check(!isset($manifest['auth_owned']) && !isset($manifest['authentication_provider']), 'Kernel owns authentication');
$check(($manifest['owns_tables'] ?? null) === [] && ($manifest['reads_tables'] ?? null) === [], 'shell is table-free');
$check(($routes['GET']['/cms-akira-shell'] ?? '') === 'cms-akira-shell:akiraShellDashboard', 'dashboard route');
$check(isset($routes['GET']['/cms-akira-shell/login']), 'presentation login route');
$check(isset($routes['GET']['/cms-akira-shell/health']), 'health route');
$check(isset($routes['GET']['/cms-akira-shell/forbidden']), 'authorization failure route');
$check(str_contains($handlers, "akiraShellRedirect('/login')"), 'anonymous login redirects to Kernel');
$check(str_contains($handlers, "akiraShellRedirect('/cms-akira-shell')"), 'authenticated login redirects to dashboard');
$check(str_contains($handlers, "entityViews()->resolve('post', 'list'"), 'list and dashboard consume Entity Views');
foreach (['create', 'update', 'publish', 'unpublish', 'delete'] as $operation) {
    $check(str_contains($handlers, "akira.post.{$operation}@1"), "{$operation} uses canonical capability");
}
$check(str_contains($helpers, "app()->requireAnyRole('admin')"), 'Kernel role authorization');
$check(str_contains($helpers, 'app()->csrfEnforce()'), 'Kernel CSRF enforcement');
$check(!preg_match('/(?:cmsRender|cmsRequireCap|cmsActiveTheme|cms_akira_posts|require.+modules\\/cms\\/)/', $handlers . $helpers), 'no forbidden content/auth/database shortcut');
$check(isset($routes['GET']['/cms-akira-shell/compositions']) && isset($routes['GET']['/cms-akira-shell/compositions/{key}/edit']), 'builder admin list and editor routes mounted under the shell guard');
$check(str_contains($handlers, 'akiraShellBuilderAdmin'), 'shell handlers mount the builder admin bundle');
$check(str_contains($helpers, 'cms-akira-builder-root') && str_contains($helpers, '/admin/assets/cms-akira-builder'), 'shell serves the CSP-safe builder bundle container');
$check(count($manifest['nav'] ?? []) === 4, 'dashboard, posts, compositions and health navigation');
$check(($manifest['nav'][2]['url'] ?? '') === '/cms-akira-shell/compositions', 'compositions nav entry present in shell module.json');
$check(isset($routes['POST']['/cms-akira-shell/posts/{slug}/delete']), 'delete route');

echo "shell contract: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
