<?php

/** CMS Akira Phase 4 native navigation contract and integration gate. */

declare(strict_types=1);

use Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry;

$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/handlers.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

$mutations = [
    'akira.navigation.menu.create@1',
    'akira.navigation.menu.update@1',
    'akira.navigation.menu.delete@1',
    'akira.navigation.item.create@1',
    'akira.navigation.item.update@1',
    'akira.navigation.item.delete@1',
];
$registry = app()->capabilities();
foreach (cms_akira_navigation_capability_handlers() as $id => $handler) {
    if ($registry->has($id)) {
        continue;
    }
    $meta = in_array($id, $mutations, true)
        ? ['requires_protocol' => 'v2', 'effects' => ['invalidates' => [CAN_NAVIGATION_INVALIDATION]]]
        : [];
    $registry->register(
        $id,
        'cms-akira-navigation',
        static function (mixed $payload, string $capabilityId = '', string $provider = '') use ($handler): mixed {
            return moduleWithContext('cms-akira-navigation', static fn (): mixed => $handler($payload, $capabilityId, $provider));
        },
        50,
        ['first'],
        $meta,
    );
}

$tenantA = 994101;
$tenantB = 994102;
$originalTenant = app()->tenant()->current();
$db = app()->db();
$prefix = 'nav-' . bin2hex(random_bytes(5));
$keys = [];
$admin = ['id' => 999401, 'role' => 'admin'];
$setIdentity = static function (int $tenant, array $user): void {
    app()->tenant()->setTenantId($tenant);
    kernel_request_context_set('tenant_id', $tenant);
    app()->setUser($user);
};
$call = static function (string $id, array $payload = []) use ($admin): array {
    return app()->cap()->call($id, $payload, [
        'caller' => ['module' => 'cms-akira-navigation', 'user' => app()->user() ?? $admin],
        'mode' => 'first',
    ]);
};
$statusOf = static function (Throwable $error): ?int {
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        if ($cursor instanceof CanNavigationMutationException) {
            return $cursor->httpStatus;
        }
    }
    return null;
};
$version = static function (string $table, int $tenant, string $column, string $key) use ($db): string {
    if (!in_array($table, ['cms_akira_menus', 'cms_akira_menu_items'], true)
        || !in_array($column, ['menu_key', 'item_key'], true)) {
        throw new RuntimeException('Unsafe fixture lookup.');
    }
    $stmt = $db->prepare("SELECT updated_at FROM {$table} WHERE tenant_id = ? AND {$column} = ?");
    $stmt->execute([$tenant, $key]);
    return (string) $stmt->fetchColumn();
};

@file_put_contents($root . '/storage/logs/app.log', '');
@file_put_contents($root . '/storage/logs/error.log', '');

try {
    echo "=== CMS Akira Phase 4 native navigation ===\n";
    $setIdentity($tenantA, $admin);
    $db->prepare('DELETE FROM cms_akira_menu_items WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
    $db->prepare('DELETE FROM cms_akira_menus WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);

    $module = dirname(__DIR__);
    $manifest = kernelReadJsonFile($module . '/module.json');
    $ids = array_column($manifest['capabilities']['exposes'] ?? [], 'id');
    $expectedIds = array_keys(cms_akira_navigation_capability_handlers());
    $check($ids === $expectedIds, 'manifest and runtime expose exactly the nine native capabilities');
    $check(($manifest['depends'] ?? []) === ['cms-akira-core'], 'only the native Akira core module dependency remains');
    $check(
        ($manifest['owns_tables'] ?? []) === ['cms_akira_menus', 'cms_akira_menu_items']
        && ($manifest['reads_tables'] ?? []) === ['cms_akira_menus', 'cms_akira_menu_items'],
        'owned and readable navigation tables are explicit'
    );
    $check(($manifest['_enabled'] ?? null) === false && !isset($manifest['entities']), 'tenant activation is explicit and navigation claims no Kernel Entity Authority');
    foreach ($mutations as $id) {
        $entry = $manifest['capabilities']['exposes'][array_search($id, $ids, true)] ?? [];
        $check(
            ($entry['requires_protocol'] ?? '') === 'v2'
            && ($entry['effects']['invalidates'] ?? null) === [CAN_NAVIGATION_INVALIDATION],
            "{$id} is governed and has one canonical invalidation"
        );
    }
    $policies = new CapabilityAuthorizationRegistry($db);
    $check(
        $policies->requiresProtocol('akira.navigation.item.delete@1', '1', 'cms-akira-navigation') === 'v2',
        'activation policy seed is durable and protocol-v2'
    );

    $createPayload = [
        'idempotency_key' => $keys[] = $prefix . '-menu-create',
        'slug' => 'main-menu',
        'location' => 'primary',
        'title' => 'Main navigation',
    ];
    $created = $call('akira.navigation.menu.create@1', $createPayload);
    $menuKey = (string) ($created['menu']['key'] ?? '');
    $replayed = $call('akira.navigation.menu.create@1', $createPayload);
    $count = $db->prepare('SELECT COUNT(*) FROM cms_akira_menus WHERE tenant_id = ? AND slug = ?');
    $count->execute([$tenantA, 'main-menu']);
    $check($created === $replayed && $menuKey !== '' && (int) $count->fetchColumn() === 1, 'menu create is idempotent with one durable row');
    $check(array_keys($created['menu'] ?? []) === ['key', 'slug', 'location', 'title'], 'menu mutation returns an explicit projection');

    $conflict = false;
    try {
        $changed = $createPayload;
        $changed['title'] = 'Conflicting payload';
        $call('akira.navigation.menu.create@1', $changed);
    } catch (Throwable $error) {
        $conflict = $statusOf($error) === 409;
    }
    $check($conflict, 'same idempotency key with changed payload is rejected');

    $rootItem = $call('akira.navigation.item.create@1', [
        'idempotency_key' => $keys[] = $prefix . '-root',
        'menu_slug' => 'main-menu',
        'label' => 'Home',
        'url' => '/',
        'weight' => 20,
    ]);
    $rootKey = (string) ($rootItem['item']['key'] ?? '');
    $child = $call('akira.navigation.item.create@1', [
        'idempotency_key' => $keys[] = $prefix . '-child',
        'menu_slug' => 'main-menu',
        'parent_key' => $rootKey,
        'label' => 'News',
        'reference_type' => 'post',
        'reference_key' => 'latest-news',
        'weight' => 5,
    ]);
    $childKey = (string) ($child['item']['key'] ?? '');
    $first = $call('akira.navigation.item.create@1', [
        'idempotency_key' => $keys[] = $prefix . '-first',
        'menu_slug' => 'main-menu',
        'label' => 'About',
        'url' => '/about',
        'weight' => 10,
    ]);
    $firstKey = (string) ($first['item']['key'] ?? '');

    $menus = $call('akira.navigation.menus@1');
    $check(($menus['total'] ?? 0) === 1 && array_keys($menus['rows'][0] ?? []) === ['key', 'slug', 'location', 'title'], 'menus list is tenant-scoped and explicitly projected');
    $tree = $call('akira.navigation.tree@1', ['slug' => 'main-menu']);
    $items = $tree['data']['items'] ?? [];
    $check(
        ($tree['ok'] ?? false) === true
        && ($items[0]['label'] ?? '') === 'About'
        && ($items[1]['children'][0]['href'] ?? '') === '/posts/latest-news',
        'tree is ordered and resolves stable Akira Post references'
    );
    $itemKeys = array_keys($items[1] ?? []);
    $check(
        $itemKeys === ['key', 'parent_key', 'label', 'href', 'reference', 'weight', 'depth', 'children']
        && !array_intersect(['id', 'tenant_id', 'menu_key', 'created_at', 'updated_at'], $itemKeys),
        'tree item projection drops every storage and tenant field'
    );
    $resolved = $call('akira.navigation.resolve@1', ['location' => 'primary']);
    $check(
        ($resolved['data']['location'] ?? '') === 'primary'
        && ($resolved['data']['menu']['key'] ?? '') === $menuKey
        && count($resolved['data']['items'] ?? []) === 2,
        'location resolve returns the canonical projected tree'
    );

    $fragmentStore = app()->templates()->fragmentStore();
    $fragmentStore->put($prefix . '-fragment', 'old navigation', [CAN_NAVIGATION_INVALIDATION], 300, (string) $tenantA);
    $updatedItem = $call('akira.navigation.item.update@1', [
        'idempotency_key' => $keys[] = $prefix . '-item-update',
        'menu_slug' => 'main-menu',
        'item_key' => $firstKey,
        'label' => 'About Akira',
        'url' => '/about-akira',
        'weight' => 10,
        'expected_updated_at' => $version('cms_akira_menu_items', $tenantA, 'item_key', $firstKey),
    ]);
    $check(
        ($updatedItem['item']['label'] ?? '') === 'About Akira'
        && $fragmentStore->tryGet($prefix . '-fragment', [CAN_NAVIGATION_INVALIDATION], (string) $tenantA) === null,
        'successful mutation invalidates the single navigation entity-view key'
    );
    $audit = $db->prepare(
        "SELECT new_data FROM audit_logs WHERE module = 'cms-akira-navigation' AND action = 'akira.navigation.item.update' AND entity_id = ? ORDER BY id DESC LIMIT 1"
    );
    $audit->execute([$firstKey]);
    $auditData = json_decode((string) $audit->fetchColumn(), true);
    $check(
        is_array($auditData) && ($auditData['correlation_id'] ?? '') === ($updatedItem['correlation_id'] ?? null),
        'durable same-PDO audit carries the mutation correlation id'
    );

    $setIdentity($tenantB, $admin);
    $tenantBMenus = $call('akira.navigation.menus@1');
    $tenantBTree = $call('akira.navigation.tree@1', ['slug' => 'main-menu']);
    $check(($tenantBMenus['rows'] ?? null) === [] && ($tenantBTree['ok'] ?? true) === false, 'shared-schema tenant B cannot read tenant A navigation');
    $tenantBWriteDenied = false;
    try {
        $call('akira.navigation.item.update@1', [
            'idempotency_key' => $keys[] = $prefix . '-tenant-b',
            'menu_slug' => 'main-menu',
            'item_key' => $firstKey,
            'label' => 'Stolen',
            'expected_updated_at' => '2000-01-01 00:00:00',
        ]);
    } catch (Throwable $error) {
        $tenantBWriteDenied = $statusOf($error) === 404;
    }
    $check($tenantBWriteDenied, 'shared-schema tenant B cannot mutate tenant A navigation');
    $setIdentity($tenantA, $admin);

    $spoofDenied = false;
    try {
        $call('akira.navigation.menu.create@1', [
            'idempotency_key' => $keys[] = $prefix . '-spoof',
            'tenant_id' => $tenantB,
            'slug' => 'spoofed',
            'location' => 'spoofed',
            'title' => 'Spoofed',
        ]);
    } catch (Throwable $error) {
        $spoofDenied = $statusOf($error) === 422;
    }
    $check($spoofDenied && ($call('akira.navigation.menus@1', ['tenant_id' => $tenantB])['ok'] ?? true) === false, 'payload tenant identity is rejected on mutation and read paths');

    app()->setUser(['id' => 999402, 'role' => 'editor']);
    $roleDenied = false;
    try {
        $call('akira.navigation.menu.delete@1', [
            'idempotency_key' => $keys[] = $prefix . '-role',
            'slug' => 'main-menu',
            'expected_updated_at' => $version('cms_akira_menus', $tenantA, 'menu_key', $menuKey),
        ]);
    } catch (Throwable $error) {
        $roleDenied = str_contains($error->getMessage(), 'authorization denied');
    }
    $check($roleDenied, 'governed mutation policy denies a non-admin actor');
    $setIdentity($tenantA, $admin);

    $badUrl = false;
    try {
        $call('akira.navigation.item.create@1', [
            'idempotency_key' => $keys[] = $prefix . '-bad-url',
            'menu_slug' => 'main-menu',
            'label' => 'Unsafe',
            'url' => 'javascript:alert(1)',
        ]);
    } catch (Throwable $error) {
        $badUrl = $statusOf($error) === 422;
    }
    $check($badUrl, 'unsafe navigation URLs fail closed');

    $orphanRows = [[
        'item_key' => str_repeat('a', 32), 'parent_key' => str_repeat('b', 32), 'label' => 'Orphan',
        'url' => '/', 'reference_type' => null, 'reference_key' => null, 'weight' => 0, 'depth' => 1,
    ]];
    $orphanDenied = false;
    try {
        canNavigationBuildTree($orphanRows);
    } catch (Throwable) {
        $orphanDenied = true;
    }
    $cycleRows = [
        ['item_key' => str_repeat('a', 32), 'parent_key' => str_repeat('b', 32), 'label' => 'A', 'url' => '/', 'reference_type' => null, 'reference_key' => null, 'weight' => 0, 'depth' => 1],
        ['item_key' => str_repeat('b', 32), 'parent_key' => str_repeat('a', 32), 'label' => 'B', 'url' => '/', 'reference_type' => null, 'reference_key' => null, 'weight' => 0, 'depth' => 1],
    ];
    $cycleDenied = false;
    try {
        canNavigationBuildTree($cycleRows);
    } catch (Throwable) {
        $cycleDenied = true;
    }
    $check($orphanDenied && $cycleDenied, 'orphan and cycle corruption fail closed');

    $deletedChild = $call('akira.navigation.item.delete@1', [
        'idempotency_key' => $keys[] = $prefix . '-delete-child',
        'menu_slug' => 'main-menu',
        'item_key' => $childKey,
        'expected_updated_at' => $version('cms_akira_menu_items', $tenantA, 'item_key', $childKey),
    ]);
    $check(($deletedChild['operation'] ?? '') === 'item.delete', 'item delete completes as an audited idempotent mutation');
    $updatedMenu = $call('akira.navigation.menu.update@1', [
        'idempotency_key' => $keys[] = $prefix . '-menu-update',
        'slug' => 'main-menu',
        'location' => 'header',
        'title' => 'Header navigation',
        'expected_updated_at' => $version('cms_akira_menus', $tenantA, 'menu_key', $menuKey),
    ]);
    $check(($updatedMenu['menu']['location'] ?? '') === 'header', 'menu update changes projected location/title');
    $deletedMenu = $call('akira.navigation.menu.delete@1', [
        'idempotency_key' => $keys[] = $prefix . '-menu-delete',
        'slug' => 'main-menu',
        'expected_updated_at' => $version('cms_akira_menus', $tenantA, 'menu_key', $menuKey),
    ]);
    $itemCount = $db->prepare('SELECT COUNT(*) FROM cms_akira_menu_items WHERE tenant_id = ? AND menu_key = ?');
    $itemCount->execute([$tenantA, $menuKey]);
    $check(($deletedMenu['operation'] ?? '') === 'menu.delete' && (int) $itemCount->fetchColumn() === 0, 'menu delete cascades only its own member items');

    $topology = $db->query(
        "SELECT table_name, engine, table_collation FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name IN ('cms_akira_menus','cms_akira_menu_items')"
    )->fetchAll(PDO::FETCH_ASSOC);
    $check(
        count($topology) === 2
        && count(array_filter($topology, static fn (array $row): bool => strtoupper((string) $row['ENGINE']) === 'INNODB'
            && (string) $row['TABLE_COLLATION'] === 'utf8mb4_unicode_ci')) === 2,
        'both native tables are InnoDB utf8mb4_unicode_ci'
    );
    $migration = (string) file_get_contents($module . '/database/migrations/002_create_native_navigation.sql');
    $check(
        str_contains($migration, 'VARCHAR(190)') && !preg_match('/\b(CHECK|JSON_TABLE|WITH RECURSIVE|GENERATED ALWAYS)\b/i', $migration),
        'migration observes the MySQL-5.7 composite-index byte budget and syntax set'
    );
    $databaseManager = (string) file_get_contents($root . '/kernel/Services/DatabaseManager.php');
    $check(
        str_contains($databaseManager, 'dbForTenant')
        && str_contains($databaseManager, 'tenantRejectBaseDbConnection')
        && canDb()->getOwnsTables() === ['cms_akira_menus', 'cms_akira_menu_items'],
        'dedicated topology uses Kernel connection selection/base-DB rejection and the same ModuleDB closure'
    );
    $runner = new \Ikabud\Kernel\Database\MigrationRunner($db, $root . '/modules');
    $check($runner->status('cms-akira-navigation')['pending'] === [], 'member migration ledger rerun is converged');

    $source = implode("\n", array_map(static fn (string $file): string => (string) file_get_contents($file), [
        $module . '/module.json', $module . '/helpers.php', $module . '/handlers.php', $module . '/routes.php', $module . '/README.md',
    ]));
    $forbiddenA = 'cms' . '.menus.';
    $forbiddenB = 'akira' . '.content.get@1';
    $legacyTheme = 'cms' . 'ActiveTheme';
    $check(!str_contains($source, $forbiddenA) && !str_contains($source, $forbiddenB) && !str_contains($source, $legacyTheme), 'tracked navigation has no forbidden legacy authority or theme residue');

    $appLog = (string) @file_get_contents($root . '/storage/logs/app.log');
    $errorLog = (string) @file_get_contents($root . '/storage/logs/error.log');
    $check(!str_contains($appLog, '[error]') && trim($errorLog) === '', 'navigation run leaves application/error logs clean', trim($errorLog));
} catch (Throwable $error) {
    $check(false, 'Phase 4 navigation scenario completes', $error::class . ': ' . $error->getMessage());
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    try {
        $db->prepare('DELETE FROM cms_akira_menu_items WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare('DELETE FROM cms_akira_menus WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare('DELETE FROM kernel_idempotency_keys WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare("DELETE FROM audit_logs WHERE module = 'cms-akira-navigation'")->execute();
        app()->templates()->fragmentStore()->flushAll((string) $tenantA);
        app()->templates()->fragmentStore()->flushAll((string) $tenantB);
    } catch (Throwable $error) {
        $check(false, 'navigation fixture cleanup succeeds', $error->getMessage());
    }
    app()->tenant()->setTenantId($originalTenant);
    kernel_request_context_delete('tenant_id');
    @file_put_contents($root . '/storage/logs/app.log', '');
    @file_put_contents($root . '/storage/logs/error.log', '');
}

echo "\nCMS Akira navigation: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
