<?php

/** Akira core post mutations invalidate the optional shell page cache on commit only. */

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = 'akiracms.test';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
require_once $root . '/src/helpers/page-cache.php';
require_once $root . '/tests/_support/env_guard.php';
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/cms-akira-shell/helpers.php';

requireNotLiveTenantDatabase();
if (!function_exists('pageCacheSet') || !function_exists('pageCacheGet')
    || !function_exists('akiraShellInvalidatePublicCache')) {
    testEnvironmentSkip('page-cache helpers are unavailable in CLI');
}

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

$registry = app()->capabilities();
foreach (cms_akira_core_capability_handlers() as $id => $handler) {
    if ($registry->has($id)) {
        continue;
    }
    $meta = in_array($id, [
        'akira.post.create@1', 'akira.post.update@1', 'akira.post.publish@1',
        'akira.post.unpublish@1', 'akira.post.delete@1',
    ], true) ? ['requires_protocol' => 'v2', 'effects' => ['invalidates' => ['entity.list.post']]] : [];
    $registry->register(
        $id,
        'cms-akira-core',
        static function (mixed $payload, string $capabilityId = '', string $provider = '') use ($handler): mixed {
            return moduleWithContext('cms-akira-core', static fn (): mixed => $handler($payload, $capabilityId, $provider));
        },
        50,
        ['first'],
        $meta,
    );
}
app()->entityAuthority()->registerAuthority('post', 'cms-akira-core', ['authority' => true]);

$tenantId = (int) app()->tenant()->current();
$db = app()->db();
$slug = 'cache-contract-' . bin2hex(random_bytes(5));
$keyPrefix = 'cache-contract-' . bin2hex(random_bytes(6));
app()->setUser(['id' => 999031, 'role' => 'admin']);
$call = static function (string $capability, array $payload): array {
    return app()->cap()->call($capability, $payload, [
        'caller' => ['module' => 'cms-akira-core', 'user' => app()->user() ?? []],
        'mode' => 'first',
    ]);
};
$html = '<!doctype html><html><body>' . str_repeat('cache sentinel ', 20) . '</body></html>';

try {
    echo "=== Akira post page-cache invalidation ===\n";
    $created = $call('akira.post.create@1', [
        'idempotency_key' => $keyPrefix . '-create',
        'slug' => $slug,
        'title' => 'Cache contract',
        'content' => '<p>Cache contract body</p>',
    ]);
    $version = $db->prepare('SELECT updated_at FROM cms_akira_posts WHERE tenant_id = ? AND slug = ?');
    $version->execute([$tenantId, $slug]);

    $cacheReader = new ReflectionFunction('pageCacheGet');
    $cacheHas = static fn (string $uri): bool => is_array($cacheReader->invoke($uri));
    pageCacheSet('/', $html, 'cms-akira-shell');
    pageCacheSet('/posts/' . $slug, $html, 'cms-akira-shell');
    if (!$cacheHas('/') || !$cacheHas('/posts/' . $slug)) {
        testEnvironmentSkip('CLI page-cache store cannot persist test entries');
    }

    $published = $call('akira.post.publish@1', [
        'idempotency_key' => $keyPrefix . '-publish',
        'slug' => $slug,
        'expected_updated_at' => (string) $version->fetchColumn(),
    ]);
    $check(($created['ok'] ?? false) === true && ($published['post']['status'] ?? '') === 'published', 'core capability publishes the post');
    $check(!$cacheHas('/') && !$cacheHas('/posts/' . $slug), 'committed publish clears shell scope and post URL');

    pageCacheSet('/', $html, 'cms-akira-shell');
    pageCacheSet('/posts/' . $slug, $html, 'cms-akira-shell');
    try {
        $call('akira.post.update@1', [
            'idempotency_key' => $keyPrefix . '-failed-update',
            'slug' => $slug,
            'expected_updated_at' => '2000-01-01 00:00:00',
            'title' => 'Must roll back',
        ]);
        $check(false, 'failed mutation rolls back');
    } catch (Throwable) {
        $check(true, 'failed mutation rolls back');
    }
    $check($cacheHas('/') && $cacheHas('/posts/' . $slug), 'rolled-back mutation leaves healthy cache untouched');
} finally {
    pageCacheFlushAll();
    try {
        $db->prepare('DELETE FROM cms_akira_post_revisions WHERE tenant_id = ? AND post_slug = ?')->execute([$tenantId, $slug]);
        $db->prepare('DELETE FROM cms_akira_posts WHERE tenant_id = ? AND slug = ?')->execute([$tenantId, $slug]);
        $db->prepare('DELETE FROM kernel_idempotency_keys WHERE tenant_id = ?')->execute([$tenantId]);
        $db->prepare("DELETE FROM audit_logs WHERE module = 'cms-akira-core' AND entity_type = 'post' AND new_data LIKE ?")->execute(['%' . $slug . '%']);
    } catch (Throwable $error) {
        $check(false, 'cache test fixture cleanup succeeds', $error->getMessage());
    }
}

echo "\nAkira post page cache: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
