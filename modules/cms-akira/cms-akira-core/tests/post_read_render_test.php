<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/handlers.php';

// Keep headers mutable while exercising route handlers from CLI.
ob_start();

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? "  ✓ " : "  ✗ ") . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

$registry = app()->capabilities();
foreach (cms_akira_core_capability_handlers() as $id => $handler) {
    if (!$registry->has($id)) {
        $registry->register($id, 'cms-akira-core', static function (mixed $payload, string $capabilityId = '', string $provider = '') use ($handler): mixed {
            return moduleWithContext('cms-akira-core', static fn (): mixed => $handler($payload, $capabilityId, $provider));
        }, 50, ['first']);
    }
}

$tenantA = 991001;
$tenantB = 991002;
$db = cacDb();
$db->prepare('DELETE FROM cms_akira_posts WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
$insert = $db->prepare(
    'INSERT INTO cms_akira_posts (tenant_id, slug, title, subtitle, content, image, status, published_at) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$internalMarker = 'SECRET_INTERNAL_7f34';
$xss = '<script>alert(1)</script><img src=x onerror=alert(2)>';
$insert->execute([$tenantA, 'safe-post', $xss, 'Sub " onclick="evil()', $xss, 'javascript:alert(3)', 'published', '2026-09-07 00:00:00']);
$insert->execute([$tenantA, 'draft-post', 'Draft secret', '', 'Never public', null, 'draft', null]);
$insert->execute([$tenantB, 'tenant-b-post', 'Tenant B only', '', 'B body', '/media/b.jpg', 'published', '2026-09-06 00:00:00']);

try {
    echo "=== CMS Akira P1 canonical Post path ===\n";
    $manifest = kernelReadJsonFile(dirname(__DIR__) . '/module.json');
    $ids = array_column($manifest['capabilities']['exposes'], 'id');
    $readIds = ['akira.post.get@1', 'akira.post.list@1', 'entity.list.post@1', 'entity.get.post@1'];
    $check(array_values(array_intersect($ids, $readIds)) === $readIds, 'P1 four versioned read/bridge capabilities remain exposed');
    $dependencies = $manifest['capabilities']['depends'] ?? [];
    $check(!isset($manifest['depends']) && array_filter($dependencies, static fn (string $id): bool => !str_starts_with($id, 'kernel.')) === [], 'legacy module/capability dependencies are absent');
    $check($manifest['owns_tables'] === ['cms_akira_posts'] && $manifest['reads_tables'] === ['cms_akira_posts'], 'Post table ownership and read contract are explicit');
    $dormantCount = 0;
    foreach (glob(dirname(__DIR__, 2) . '/*/module.json') ?: [] as $memberManifest) {
        $member = kernelReadJsonFile($memberManifest);
        if (($member['id'] ?? '') !== 'cms-akira-core' && ($member['_enabled'] ?? null) === false) {
            ++$dormantCount;
        }
    }
    $check($dormantCount === 14, 'all 14 non-core suite members are explicitly dormant');

    foreach ($readIds as $id) {
        $providers = $registry->providers($id);
        $check(count($providers) === 1 && ($providers[0]['provider'] ?? '') === 'cms-akira-core', "{$id} has one cms-akira-core handler");
    }
    $check(!$registry->has('entity.list.post') && !$registry->has('entity.get.post'), 'unversioned bridge handlers are not declared');
    $check($registry->has('entity.list.post@1') && $registry->has('entity.get.post@1'), 'resolver @1 fallback targets are active');

    app()->tenant()->setTenantId($tenantA);
    cacRegisterPostEntityViews(app()->entityViews());
    $contracts = app()->entityViews()->registeredViewContracts();
    $primitiveTypes = ['string', 'int', 'float', 'bool', 'json', 'date', 'datetime', 'reference'];
    foreach (['post.list', 'post.detail'] as $key) {
        $contract = $contracts[$key] ?? [];
        $types = array_values($contract['source_schema']['fields'] ?? []);
        $check(($contract['provider'] ?? '') === 'cms-akira-core' && is_array($contract['fields'] ?? null) && !in_array('*', $contract['fields'], true), "{$key} is exact-provider and explicit-field only");
        $check(array_diff($types, $primitiveTypes) === [] && !isset($contract['role_fields']), "{$key} separates primitive schema from field_contracts");
    }

    $spoofed = app()->cap()->call('akira.post.list@1', [
        'tenant_id' => $tenantB,
        'status' => 'draft',
        'filters' => ['status' => 'draft'],
        'sort' => ['field' => 'not_a_column', 'direction' => 'sideways'],
        'limit' => 500,
        'offset' => -20,
    ], ['caller' => ['module' => 'cms-akira-core'], 'mode' => 'first']);
    $check(count($spoofed['rows'] ?? []) === 1 && ($spoofed['rows'][0]['slug'] ?? '') === 'safe-post', 'domain list ignores tenant/status/filter spoofing and bounds invalid query controls');

    $list = app()->entityViews()->resolve('post', 'list', ['limit' => 50, 'filters' => ['status' => 'draft']]);
    $check($list['error'] === null && count($list['rows']) === 1, 'EntityViewResolver list is tenant-scoped and published-only', json_encode($list));
    $listDto = $list['rows'][0] ?? [];
    $check(array_keys($listDto) === ['title', 'subtitle', 'image', 'metadata', 'actions', 'url'], 'list bridge returns the exact fresh DTO');
    $check($listDto['url'] === '/posts/safe-post' && $listDto['image'] === '', 'slug URL is canonical and unsafe image scheme is rejected');
    $check(!array_intersect(['id', 'tenant_id', 'slug', 'status', 'content', 'updated_at'], array_keys($listDto)), 'list DTO drops transport/internal fields');

    $detail = app()->entityViews()->resolveDetail('post', 'safe-post', 'detail');
    $detailDto = $detail['entity'] ?? [];
    $check($detail['error'] === null && array_keys($detailDto) === ['title', 'subtitle', 'image', 'body', 'metadata', 'actions', 'url'], 'detail bridge returns the exact fresh DTO');
    $check(!array_intersect(['id', 'tenant_id', 'slug', 'status', 'created_at'], array_keys($detailDto)), 'detail DTO drops transport/internal fields');
    $draft = app()->entityViews()->resolveDetail('post', 'draft-post', 'detail');
    $check($draft['entity'] === null, 'unpublished detail is not returned');

    $listSelection = app()->arkRenderers()->resolve('entity.list.post', 'cms-akira-posts');
    $detailSelection = app()->arkRenderers()->resolve('entity.detail.post', 'cms-akira-posts');
    $check(($listSelection['renderer'] ?? '') === 'article-grid' && ($detailSelection['renderer'] ?? '') === 'article-page', 'ARK exact mappings select both DiSyL renderers');
    $listHtml = app()->arkRenderers()->render('entity.list.post', ['posts' => $list['rows']], 'cms-akira-posts');
    $detailHtml = app()->arkRenderers()->render('entity.detail.post', ['post' => $detailDto], 'cms-akira-posts');
    $allHtml = (string)$listHtml . (string)$detailHtml;
    $check(is_string($listHtml) && str_contains($listHtml, 'data-ark-renderer="article-grid"') && is_string($detailHtml) && str_contains($detailHtml, 'data-ark-renderer="article-page"'), 'list/detail render through ARK into DiSyL HTML');
    $check(!str_contains($allHtml, '<script>') && !str_contains($allHtml, '<img src=x') && !str_contains($allHtml, 'onclick="evil') && str_contains($allHtml, '&lt;script&gt;'), 'stored title/subtitle/body values are context-escaped');
    $injectedDto = cacPostProject([
        'slug' => 'stored-xss',
        'title' => $xss,
        'subtitle' => $xss,
        'content' => $xss,
        'image' => 'data:text/html,<script>alert(4)</script>',
        'published_at' => '2026-09-07&lt;img src=x onerror=alert(5)&gt;',
        'url' => 'javascript:alert(6)',
        'metadata' => $xss,
    ], true);
    $injectedHtml = app()->arkRenderers()->render('entity.detail.post', ['post' => $injectedDto], 'cms-akira-posts');
    $check(is_string($injectedHtml) && !str_contains($injectedHtml, '<script>') && !str_contains($injectedHtml, '<img src=x') && !str_contains($injectedHtml, 'javascript:'), 'stored metadata/image/url payloads cannot create executable output');
    $extraListDto = cacPostProject(['slug' => 'extra-list', 'title' => 'List', 'internal_secret' => $internalMarker], false);
    $extraDetailDto = cacPostProject(['slug' => 'extra-detail', 'title' => 'Detail', 'content' => 'Body', 'internal_secret' => $internalMarker], true);
    $extraHtml = (string)app()->arkRenderers()->render('entity.list.post', ['posts' => [$extraListDto]], 'cms-akira-posts')
        . (string)app()->arkRenderers()->render('entity.detail.post', ['post' => $extraDetailDto], 'cms-akira-posts');
    $check(!array_key_exists('internal_secret', $extraListDto) && !array_key_exists('internal_secret', $extraDetailDto) && !str_contains($extraHtml, $internalMarker), 'undeclared stored columns are absent from list/detail DTOs and HTML');
    $check(!str_contains($allHtml, 'javascript:') && !str_contains($allHtml, 'tenant_id') && !str_contains($allHtml, '991001'), 'unsafe URL/internal tenant data never reaches HTML');

    app()->tenant()->setTenantId($tenantB);
    $tenantBList = app()->entityViews()->resolve('post', 'list');
    $check(count($tenantBList['rows']) === 1 && ($tenantBList['rows'][0]['title'] ?? '') === 'Tenant B only', 'tenant B cannot read tenant A rows');

    $check(cacPostValidSlug('safe-post') === 'safe-post' && cacPostValidSlug('../unsafe') === null && cacPostValidSlug('Mixed-Case') === null && cacPostValidSlug('x%2fy') === null, 'slug validation enforces one canonical form');
    $check(app()->arkRenderers()->resolve('entity.list.unknown', 'cms-akira-posts') === null, 'ARK never wildcard-falls back');

    app()->entityViews()->reset();
    ob_start();
    pageCmsAkiraPosts();
    $missingRouteHtml = (string)ob_get_clean();
    $missingGuardOk = !cacPostViewRegistrationValid('list') && !cacPostViewRegistrationValid('detail');
    $check($missingGuardOk && http_response_code() === 404 && str_contains($missingRouteHtml, 'Not Found'), 'missing registrations return route 404 before fallback rendering', json_encode([$missingGuardOk, http_response_code(), $missingRouteHtml]));
    app()->entityViews()->registerView('post', 'list', ['fields' => '*'], 'wrong-provider');
    app()->entityViews()->registerView('post', 'detail', ['fields' => ['title']], 'wrong-provider');
    ob_start();
    pageCmsAkiraPostDetail(['slug' => 'safe-post']);
    $wrongProviderHtml = (string)ob_get_clean();
    $wrongGuardOk = !cacPostViewRegistrationValid('list') && !cacPostViewRegistrationValid('detail');
    $check($wrongGuardOk && http_response_code() === 404 && str_contains($wrongProviderHtml, 'Not Found'), 'wrong-provider/wildcard registrations return route 404 without fallback', json_encode([$wrongGuardOk, http_response_code(), $wrongProviderHtml]));
    cacRegisterPostEntityViews(app()->entityViews());
    $check(cacPostViewRegistrationValid('list') && cacPostViewRegistrationValid('detail'), 'canonical registrations restore route activation');
    app()->tenant()->setTenantId($tenantA);
    http_response_code(200);
    ob_start();
    pageCmsAkiraPosts();
    $listRouteHtml = (string)ob_get_clean();
    ob_start();
    pageCmsAkiraPostDetail(['slug' => 'safe-post']);
    $detailRouteHtml = (string)ob_get_clean();
    $check(http_response_code() === 200 && str_contains($listRouteHtml, 'article-grid') && str_contains($detailRouteHtml, 'article-page'), 'GET route handlers complete resolver to exact ARK DiSyL rendering', json_encode([http_response_code(), $listRouteHtml, $detailRouteHtml]));

    $migrationFile = new SplFileObject(dirname(__DIR__) . '/database/migrations/002_create_posts.sql');
    $migration = '';
    while (!$migrationFile->eof()) {
        $migration .= (string)$migrationFile->fgets();
    }
    $check(substr_count(strtoupper($migration), 'CREATE TABLE') === 1 && stripos($migration, 'IF NOT EXISTS') === false, 'one-table DDL relies on the migration ledger, not IF NOT EXISTS');
    $check(str_contains($migration, 'every applicable tenant database') && str_contains($migration, 'inactive/pending') && str_contains($migration, 'rerunning the ledger converges'), 'partial tenant provisioning failure and rerun behavior are explicit');
    $application = app();
    $runner = new \Ikabud\Kernel\Database\MigrationRunner($application->db(), $root . '/modules');
    $check($runner->status('cms-akira/cms-akira-core')['pending'] === [], 'migration ledger rerun is converged');
} finally {
    $db->prepare('DELETE FROM cms_akira_posts WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
}

echo "\nCMS Akira P1: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
