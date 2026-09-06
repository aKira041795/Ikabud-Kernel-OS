<?php

declare(strict_types=1);

/**
 * Entity-view ComponentRenderer fragment-cache contract tests.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../kernel/DiSyL/Cache/FragmentStore.php';

use Ikabud\Kernel\DiSyL\Cache\FragmentStore;
use Ikabud\Kernel\EntityContext\EntityQueryState;

$pass = 0;
$fail = 0;
$assert = static function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  PASS  {$name}\n";
        return;
    }
    $fail++;
    echo "  FAIL  {$name}" . ($detail !== '' ? "  → {$detail}" : '') . "\n";
};

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . '/' . $entry;
        if (is_dir($child)) {
            $removeTree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
};

$type = 'phase2_cache_probe_' . bin2hex(random_bytes(4));
$tmpRoot = sys_get_temp_dir() . '/' . $type;
@mkdir($tmpRoot, 0777, true);

$app = app();
$engine = $app->templates();
$engine->setFragmentStore(new FragmentStore($tmpRoot));
$resolver = $app->entityViews();
$resolver->reset();
$resolver->registerView($type, 'compact', [
    'fields' => ['id', 'name'],
    'actions' => [],
    'limit' => 10,
    'sort' => ['field' => 'id', 'direction' => 'asc'],
]);
$resolver->registerView($type, 'detailed', [
    'fields' => ['id', 'name'],
    'actions' => [],
]);
$resolver->registerView($type, 'contextual', [
    'fields' => ['id', 'name'],
    'actions' => ['open'],
    'action_urls' => ['open' => '{base_url}/items/{id}'],
    'action_methods' => ['open' => 'get'],
]);
$resolver->registerView($type, 'post_actions', [
    'fields' => ['id', 'name'],
    'actions' => ['remove'],
    'action_urls' => ['remove' => '/items/{id}'],
    'action_methods' => ['remove' => 'post'],
]);

$listCalls = 0;
$detailCalls = 0;
$app->capabilities()->register('entity.list.' . $type, '_phase2_test', static function () use (&$listCalls): array {
    $listCalls++;
    return [
        'rows' => [['id' => 1, 'name' => 'list-render-' . $listCalls]],
        'total' => 1,
    ];
}, 100);
$app->capabilities()->register('entity.get.' . $type, '_phase2_test', static function () use (&$detailCalls): array {
    $detailCalls++;
    return ['id' => 7, 'name' => 'detail-render-' . $detailCalls];
}, 100);

$listTag = '{ikb_entity_list source="' . $type . '.all" view="compact" limit="10" filter="status=active" cache="60" /}';
$detailTag = '{ikb_entity_detail source="' . $type . '.one" id="7" view="detailed" cache="60" /}';

echo "Entity-view render-path cache\n";
echo str_repeat('=', 64) . "\n";

try {
    echo "\n[List miss, hit, invalidation]\n";
    $app->tenant()->setTenantId(101);
    try {
        $first = $engine->renderString($listTag, ['current_user_role' => 'guest']);
    } catch (Throwable $e) {
        $first = '';
        echo '  DEBUG  first render threw ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
    $second = $engine->renderString($listTag, ['current_user_role' => 'guest']);
    $tenantFiles = glob($tmpRoot . '/101/*.json') ?: [];
    if ($listCalls === 0) {
        echo '  DEBUG  capability not invoked; rendered=' . substr((string)$first, 0, 300) . "\n";
    }
    $assert('cache miss renders and stores a fragment', $listCalls === 1 && count($tenantFiles) >= 1, 'calls=' . $listCalls);
    $assert('cache hit is byte-identical and skips capability', $second === $first && $listCalls === 1);

    $resolver->invalidateEntityCache($type, 101);
    $refreshed = $engine->renderString($listTag, ['current_user_role' => 'guest']);
    $assert('invalidation forces list refresh', $listCalls === 2 && $refreshed !== $first, 'calls=' . $listCalls);

    echo "\n[Tenant isolation]\n";
    $app->tenant()->setTenantId(202);
    $tenantTwo = $engine->renderString($listTag, ['current_user_role' => 'guest']);
    $assert('second tenant has an independent miss', $listCalls === 3 && $tenantTwo !== $refreshed, 'calls=' . $listCalls);
    $app->tenant()->setTenantId(101);
    $tenantOneAgain = $engine->renderString($listTag, ['current_user_role' => 'guest']);
    $assert('first tenant retains its own cached row', $listCalls === 3 && $tenantOneAgain === $refreshed);

    echo "\n[Canonical key dimensions]\n";
    $app->tenant()->setTenantId(250);
    $stateTag = '{ikb_entity_list source="' . $type . '.all" view="compact" id="cache-list" paginated="true" cache="60" /}';
    $before = $listCalls;
    $_GET = ['cache-list_page' => '2', 'unrelated' => 'ignored-a'];
    $pageTwo = $engine->renderString($stateTag, ['current_user_role' => 'guest']);
    $_GET = ['cache-list_page' => '3', 'unrelated' => 'ignored-b'];
    $pageThree = $engine->renderString($stateTag, ['current_user_role' => 'guest']);
    $assert('namespaced page values cannot collide', $listCalls === $before + 2 && $pageTwo !== $pageThree);

    $before = $listCalls;
    $_GET = ['cache-list_limit' => '5', 'cache-list_sort' => 'id', 'cache-list_dir' => 'asc'];
    $limitFive = $engine->renderString($stateTag, ['current_user_role' => 'guest']);
    $_GET = ['cache-list_limit' => '8', 'cache-list_sort' => 'name', 'cache-list_dir' => 'desc'];
    $limitEight = $engine->renderString($stateTag, ['current_user_role' => 'guest']);
    $assert('namespaced limit/sort/direction values cannot collide', $listCalls === $before + 2 && $limitFive !== $limitEight);

    $before = $listCalls;
    $_GET = ['cache-list_cursor' => 'cursor-a', 'cache-list_prev' => 'prev-a'];
    $cursorA = $engine->renderString($stateTag, ['current_user_role' => 'guest']);
    $_GET = ['cache-list_cursor' => 'cursor-b', 'cache-list_prev' => 'prev-b'];
    $cursorB = $engine->renderString($stateTag, ['current_user_role' => 'guest']);
    $assert('namespaced cursor/previous values cannot collide', $listCalls === $before + 2 && $cursorA !== $cursorB);
    $_GET = [];

    $before = $listCalls;
    $attrA = $engine->renderString(str_replace('cache="60"', 'class="variant-a" cache="60"', $listTag), ['current_user_role' => 'guest']);
    $attrB = $engine->renderString(str_replace('cache="60"', 'class="variant-b" cache="60"', $listTag), ['current_user_role' => 'guest']);
    $assert('render attributes cannot collide', $listCalls === $before + 2 && $attrA !== $attrB);

    $before = $listCalls;
    $childA = $engine->renderString('{ikb_entity_list source="' . $type . '.all" view="compact" cache="60"}<b>A-child</b>{/ikb_entity_list}', ['current_user_role' => 'guest']);
    $childB = $engine->renderString('{ikb_entity_list source="' . $type . '.all" view="compact" cache="60"}<b>B-child</b>{/ikb_entity_list}', ['current_user_role' => 'guest']);
    $assert('compiled child content cannot collide', $listCalls === $before + 2 && $childA !== $childB);

    $before = $listCalls;
    $contextTag = '{ikb_entity_list source="' . $type . '.all" view="contextual" cache="60" /}';
    $baseA = $engine->renderString($contextTag, ['current_user_role' => 'guest', 'base_url' => '/alpha']);
    $baseB = $engine->renderString($contextTag, ['current_user_role' => 'guest', 'base_url' => '/beta']);
    $assert('base_url render context cannot collide', $listCalls === $before + 2 && $baseA !== $baseB);

    $before = $listCalls;
    $queryA = new EntityQueryState(page: 2, limit: 5, sort: 'id', direction: 'asc', listId: 'explicit');
    $queryB = new EntityQueryState(page: 4, limit: 5, sort: 'id', direction: 'asc', listId: 'explicit');
    $explicitA = $engine->renderString($stateTag, ['current_user_role' => 'guest', '_queryState' => $queryA]);
    $explicitB = $engine->renderString($stateTag, ['current_user_role' => 'guest', '_queryState' => $queryB]);
    $assert('explicit _queryState values cannot collide', $listCalls === $before + 2 && $explicitA !== $explicitB);

    $before = $listCalls;
    $sourceA = $engine->renderString(str_replace('.all', '.recent', $listTag), ['current_user_role' => 'guest']);
    $sourceB = $engine->renderString(str_replace('.all', '.featured', $listTag), ['current_user_role' => 'guest']);
    $assert('source qualifiers cannot collide', $listCalls === $before + 2 && $sourceA !== $sourceB);

    $before = $listCalls;
    $viewA = $engine->renderString(str_replace('view="compact"', 'view="detailed"', $listTag), ['current_user_role' => 'guest']);
    $viewB = $engine->renderString(str_replace('view="compact"', 'view="contextual"', $listTag), ['current_user_role' => 'guest', 'base_url' => '/view']);
    $assert('view names cannot collide', $listCalls === $before + 2 && $viewA !== $viewB);

    $before = $listCalls;
    $limitA = $engine->renderString(str_replace('limit="10"', 'limit="6"', $listTag), ['current_user_role' => 'guest']);
    $limitB = $engine->renderString(str_replace('limit="10"', 'limit="7"', $listTag), ['current_user_role' => 'guest']);
    $assert('render limits cannot collide', $listCalls === $before + 2 && $limitA !== $limitB);

    $before = $listCalls;
    $sortA = $engine->renderString(str_replace('cache="60"', 'sort_field="id" sort_direction="asc" cache="60"', $listTag), ['current_user_role' => 'guest']);
    $sortB = $engine->renderString(str_replace('cache="60"', 'sort_field="name" sort_direction="desc" cache="60"', $listTag), ['current_user_role' => 'guest']);
    $assert('render sort field/direction cannot collide', $listCalls === $before + 2 && $sortA !== $sortB);

    $before = $listCalls;
    $filterA = $engine->renderString(str_replace('status=active', 'status=draft', $listTag), ['current_user_role' => 'guest']);
    $filterB = $engine->renderString(str_replace('status=active', 'status=archived', $listTag), ['current_user_role' => 'guest']);
    $assert('resolved filters cannot collide', $listCalls === $before + 2 && $filterA !== $filterB);

    echo "\n[Unsafe render paths]\n";
    $before = $listCalls;
    $postTag = '{ikb_entity_list source="' . $type . '.all" view="post_actions" cache="60" /}';
    $postOne = $engine->renderString($postTag, ['current_user_role' => 'guest']);
    $postTwo = $engine->renderString($postTag, ['current_user_role' => 'guest']);
    $assert('CSRF-bearing POST actions remain uncached', $listCalls === $before + 2 && $postOne !== $postTwo);

    $before = $listCalls;
    $bulkTag = '{ikb_entity_list source="' . $type . '.all" view="compact" bulk-actions="delete" bulk-action-url="/bulk" cache="60" /}';
    $bulkOne = $engine->renderString($bulkTag, ['current_user_role' => 'guest']);
    $bulkTwo = $engine->renderString($bulkTag, ['current_user_role' => 'guest']);
    $assert('bulk actions with random IDs remain uncached', $listCalls === $before + 2 && $bulkOne !== $bulkTwo);

    echo "\n[Per-user safety]\n";
    $app->tenant()->setTenantId(303);
    $userContext = ['current_user_role' => 'editor', 'user' => ['id' => 11, 'role' => 'editor']];
    $before = $listCalls;
    $uncachedUserOne = $engine->renderString($listTag, $userContext);
    $uncachedUserTwo = $engine->renderString($listTag, $userContext);
    $assert('identified user is not cached by default', $listCalls === $before + 2 && $uncachedUserOne !== $uncachedUserTwo, 'calls=' . $listCalls);

    $before = $listCalls;
    $perUserTag = str_replace('cache="60"', 'cache="60" cache-user="true"', $listTag);
    $perUserFirst = $engine->renderString($perUserTag, $userContext);
    $perUserSecond = $engine->renderString($perUserTag, $userContext);
    $assert('explicit per-user opt-in caches for one identity', $listCalls === $before + 1 && $perUserFirst === $perUserSecond);
    $otherUser = $engine->renderString($perUserTag, ['current_user_role' => 'editor', 'user' => ['id' => 12, 'role' => 'editor']]);
    $assert('explicit per-user keys distinguish identities', $listCalls === $before + 2 && $otherUser !== $perUserFirst);
    $anonymous = $engine->renderString($perUserTag, ['current_user_role' => 'editor']);
    $assert('user and anonymous cache scopes cannot collide', $listCalls === $before + 3 && $anonymous !== $perUserFirst);

    echo "\n[No opt-in regression]\n";
    $before = $listCalls;
    $noCacheTag = str_replace(' cache="60"', '', $listTag);
    $noCacheFirst = $engine->renderString($noCacheTag, ['current_user_role' => 'guest']);
    $noCacheSecond = $engine->renderString($noCacheTag, ['current_user_role' => 'guest']);
    $assert('cache attribute absent preserves normal rendering', $listCalls === $before + 2 && $noCacheFirst !== $noCacheSecond, 'calls=' . $listCalls);

    echo "\n[Detail miss, hit, invalidation]\n";
    $app->tenant()->setTenantId(404);
    $detailFirst = $engine->renderString($detailTag, ['current_user_role' => 'guest']);
    $detailSecond = $engine->renderString($detailTag, ['current_user_role' => 'guest']);
    $assert('detail miss stores and hit skips capability', $detailCalls === 1 && $detailFirst === $detailSecond);
    $differentRole = $engine->renderString($detailTag, ['current_user_role' => 'manager']);
    $assert('auth role has an independent cache key', $detailCalls === 2 && $differentRole !== $detailFirst);
    $differentId = $engine->renderString(str_replace('id="7"', 'id="8"', $detailTag), ['current_user_role' => 'guest']);
    $assert('detail entity IDs cannot collide', $detailCalls === 3 && $differentId !== $detailFirst);
    $resolver->invalidateEntityCache($type, 404);
    $detailRefreshed = $engine->renderString($detailTag, ['current_user_role' => 'guest']);
    $assert('invalidation forces detail refresh', $detailCalls === 4 && $detailRefreshed !== $detailFirst);

    echo "\n[Authoritative principal safety]\n";
    $app->tenant()->setTenantId(505);
    $app->setUser(['id' => 41, 'role' => 'editor', 'source' => 'kernel']);
    $before = $listCalls;
    $principalOne = $engine->renderString($listTag, ['current_user_role' => 'guest']);
    $principalTwo = $engine->renderString($listTag, ['current_user_role' => 'guest']);
    $assert('application principal disables caching without opt-in', $listCalls === $before + 2 && $principalOne !== $principalTwo);

    $before = $listCalls;
    $principalTag = str_replace('cache="60"', 'cache="60" cache-user="true"', $listTag);
    $principalCached = $engine->renderString($principalTag, ['current_user_role' => 'guest']);
    $principalHit = $engine->renderString($principalTag, ['current_user_role' => 'guest']);
    $app->setUser(['id' => 42, 'role' => 'editor', 'source' => 'kernel']);
    $principalOther = $engine->renderString($principalTag, ['current_user_role' => 'guest']);
    $assert('per-user opt-in partitions authoritative identities', $listCalls === $before + 2 && $principalCached === $principalHit && $principalOther !== $principalCached);

    $userProperty = new ReflectionProperty($app, 'currentUser');
    $userProperty->setValue($app, null);

    echo "\n[Observable fail-open]\n";
    $app->tenant()->setTenantId(606);
    $storagePath = defined('STORAGE_PATH') ? (string)constant('STORAGE_PATH') : __DIR__ . '/../storage';
    $logPath = $storagePath . '/logs/app.log';
    $logOffset = is_file($logPath) ? (int)filesize($logPath) : 0;
    $lookupStore = new FragmentStore($tmpRoot . '/lookup-failure', static function (string $operation): void {
        if ($operation === 'lookup') {
            throw new RuntimeException('forced lookup failure');
        }
    });
    $engine->setFragmentStore($lookupStore);
    $before = $listCalls;
    $lookupHtml = $engine->renderString($listTag, ['current_user_role' => 'guest']);
    clearstatcache(true, $logPath);
    $lookupLog = is_file($logPath) ? (string)file_get_contents($logPath, false, null, $logOffset) : '';
    $assert('lookup failure renders normally and logs warning', $listCalls === $before + 1 && $lookupHtml !== '' && str_contains($lookupLog, 'forced lookup failure'));

    $logOffset = is_file($logPath) ? (int)filesize($logPath) : 0;
    $writeStore = new FragmentStore($tmpRoot . '/write-failure', static function (string $operation): void {
        if ($operation === 'write') {
            throw new RuntimeException('forced write failure');
        }
    });
    $engine->setFragmentStore($writeStore);
    $before = $listCalls;
    $writeHtml = $engine->renderString($listTag, ['current_user_role' => 'guest']);
    clearstatcache(true, $logPath);
    $writeLog = is_file($logPath) ? (string)file_get_contents($logPath, false, null, $logOffset) : '';
    $assert('write failure returns rendered HTML and logs warning', $listCalls === $before + 1 && $writeHtml !== '' && str_contains($writeLog, 'forced write failure'));
} finally {
    $app->tenant()->reset();
    $resolver->reset();
    $removeTree($tmpRoot);
}

echo "\n" . str_repeat('=', 64) . "\n";
echo 'Total: ' . ($pass + $fail) . "  PASS: {$pass}  FAIL: {$fail}\n";
exit($fail === 0 ? 0 : 1);
