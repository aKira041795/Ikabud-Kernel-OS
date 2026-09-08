<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manifest = json_decode((string)file_get_contents($root . '/module.json'), true);
$routes = require $root . '/routes.php';
$handlers = (string)file_get_contents($root . '/handlers.php');
$helpers = (string)file_get_contents($root . '/helpers.php');
$login = (string)file_get_contents(dirname($root, 3) . '/templates/modules/cms-akira-shell/pages/login.disyl');
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
$check(str_contains($login, 'https://cdn.tailwindcss.com') && str_contains($login, 'alpinejs@3.14.3'), 'login ingests reference Tailwind and Alpine assets');
$check(str_contains($login, 'tailwind.config') && str_contains($login, 'CMS Akira') && !str_contains($login, '<style>'), 'login uses branded palette without bespoke CSS');
$check(str_contains($helpers, 'app()->entityRenderers()->renderList') && str_contains($handlers, 'data-akira-entity-view="post-list"'), 'posts render through styled Kernel entity-view container');
$check(str_contains($helpers, 'https://cdn.tailwindcss.com') && str_contains($helpers, 'aria-label="Akira administration"'), 'admin shell ingests design system and accessible navigation');
$check(str_contains($handlers, "'filters' => ['include_unpublished' => true") && str_contains($handlers, 'akiraShellPagination'), 'admin list uses governed filtering and pagination');
$check(str_contains($helpers, "['actions'] = ['edit', 'delete']") && str_contains($helpers, "'delete' => 'POST'") && str_contains($helpers, 'Delete this post?'), 'entity-view rows expose edit and confirmed delete actions');
$check(str_contains($handlers, 'name="status"') && str_contains($handlers, 'x-text="body"'), 'editor exposes draft/published status and safe text preview');
$check(str_contains($helpers, 'akiraShellCall($capability, $input)') && str_contains($helpers, "'expected_updated_at'"), 'create and edit save through capabilities with optimistic concurrency');
$check(str_contains($handlers, "['Published', \$published") && str_contains($handlers, 'akiraShellRecentPosts'), 'dashboard presents governed counts and recent posts');

echo "shell contract: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
