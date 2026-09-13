<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/posts';
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/handlers.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? "  ✓ " : "  ✗ ") . $label . "\n";
};

$views = app()->entityViews();
$views->reset();
if (function_exists('kernel_request_context_delete')) {
    kernel_request_context_delete('active_theme_slug');
}
cacRegisterPostEntityViews($views);

$contracts = $views->registeredViewContracts();
$list = $contracts['post.list'] ?? null;
$detail = $contracts['post.detail'] ?? null;

$capabilities = app()->capabilities();
if (!$capabilities->has('entity.list.post')) {
    $capabilities->register(
        'entity.list.post',
        'kernel',
        static fn (mixed $payload): array => [
            'rows' => [[
                'title' => 'Theme-independent Post',
                'subtitle' => '',
                'image' => '',
                'metadata' => '',
                'categories' => [],
                'actions' => [],
                'url' => '/posts/theme-independent-post',
            ]],
            'total' => 1,
        ],
        50,
        ['first']
    );
}
http_response_code(200);
ob_start();
pageCmsAkiraPosts();
$listHtml = (string) ob_get_clean();
$listStatus = http_response_code();

$check(
    is_array($list) && is_array($detail)
        && ($list['provider'] ?? null) === 'cms-akira-core'
        && ($detail['provider'] ?? null) === 'cms-akira-core',
    'Post list/detail contracts remain module-owned without an active theme'
);
$check(
    cacPostViewRegistrationValid('list') && cacPostViewRegistrationValid('detail'),
    'fail-closed route guards accept the module registrations'
);
$check(
    !in_array('slug', $list['fields'] ?? [], true)
        && !in_array('tenant_id', $list['fields'] ?? [], true)
        && in_array('url', $list['fields'] ?? [], true),
    'public list identity is exposed only as its canonical URL'
);
$check(
    cacPostThemeSlug() === 'cms-akira-posts'
        && app()->arkRenderers()->resolve('entity.list.post', cacPostThemeSlug()) !== null
        && app()->arkRenderers()->resolve('entity.detail.post', cacPostThemeSlug()) !== null,
    'no active-theme context uses the installed canonical fallback renderers'
);
$check(
    $listStatus === 200 && str_contains($listHtml, 'Theme-independent Post'),
    '/posts remains 200 and renders through the fallback when no theme is active'
);

echo "\nCMS Akira module-owned Post entity views: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
