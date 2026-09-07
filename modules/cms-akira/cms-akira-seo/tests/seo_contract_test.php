<?php

/** CMS Akira Phase 5B native SEO metadata contract and integration gate. */

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
    'akira.seo.upsert@1',
    'akira.seo.delete@1',
];
$registry = app()->capabilities();
foreach (cms_akira_seo_capability_handlers() as $id => $handler) {
    if ($registry->has($id)) {
        continue;
    }
    $meta = in_array($id, $mutations, true)
        ? ['requires_protocol' => 'v2', 'effects' => ['invalidates' => [CAS_SEO_INVALIDATION]]]
        : [];
    $registry->register(
        $id,
        'cms-akira-seo',
        static function (mixed $payload, string $capabilityId = '', string $provider = '') use ($handler): mixed {
            return moduleWithContext('cms-akira-seo', static fn (): mixed => $handler($payload, $capabilityId, $provider));
        },
        50,
        ['first'],
        $meta,
    );
}

$tenantA = 994501;
$tenantB = 994502;
$originalTenant = app()->tenant()->current();
$db = app()->db();
$prefix = 'seo-' . bin2hex(random_bytes(5));
$keys = [];
$admin = ['id' => 999601, 'role' => 'admin'];
$setIdentity = static function (int $tenant, array $user): void {
    app()->tenant()->setTenantId($tenant);
    kernel_request_context_set('tenant_id', $tenant);
    app()->setUser($user);
};
$call = static function (string $id, array $payload = []) use ($admin): array {
    return app()->cap()->call($id, $payload, [
        'caller' => ['module' => 'cms-akira-seo', 'user' => app()->user() ?? $admin],
        'mode' => 'first',
        'breaker_threshold' => 1000,
    ]);
};
$statusOf = static function (Throwable $error): ?int {
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        if ($cursor instanceof CasSeoMutationException) {
            return $cursor->httpStatus;
        }
    }
    return null;
};
$version = static function (string $entityType, string $entityKey) use ($db): string {
    $tenant = (int) app()->tenant()->current();
    $stmt = $db->prepare('SELECT updated_at FROM cms_akira_seo_metadata WHERE tenant_id = ? AND entity_type = ? AND entity_key = ?');
    $stmt->execute([$tenant, $entityType, $entityKey]);
    return (string) $stmt->fetchColumn();
};
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

@file_put_contents($root . '/storage/logs/app.log', '');
@file_put_contents($root . '/storage/logs/error.log', '');

try {
    echo "=== CMS Akira Phase 5B native SEO ===\n";
    $setIdentity($tenantA, $admin);
    $db->prepare('DELETE FROM cms_akira_seo_metadata WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);

    $module = dirname(__DIR__);
    $manifest = kernelReadJsonFile($module . '/module.json');
    $ids = array_column($manifest['capabilities']['exposes'] ?? [], 'id');
    $expectedIds = array_keys(cms_akira_seo_capability_handlers());
    $check($ids === $expectedIds, 'manifest and runtime expose exactly the four native SEO capabilities');
    $check(($manifest['depends'] ?? []) === ['cms-akira-core'], 'only the native Akira core module dependency remains');
    $check(
        ($manifest['owns_tables'] ?? []) === ['cms_akira_seo_metadata']
        && ($manifest['reads_tables'] ?? []) === ['cms_akira_seo_metadata'],
        'owned and readable SEO tables are explicit'
    );
    $check(($manifest['_enabled'] ?? null) === false && !isset($manifest['entities']), 'tenant activation is explicit and SEO claims no Kernel Entity Authority');
    foreach ($mutations as $id) {
        $entry = $manifest['capabilities']['exposes'][array_search($id, $ids, true)] ?? [];
        $check(
            ($entry['requires_protocol'] ?? '') === 'v2'
            && ($entry['effects']['invalidates'] ?? null) === [CAS_SEO_INVALIDATION],
            "{$id} is governed and has one canonical invalidation"
        );
    }
    $check(
        !isset($manifest['nav']) && !isset($manifest['admin_contributions'])
        && !isset($manifest['compatibility']) && !isset($manifest['uninstall']),
        'legacy nav/admin/authority scaffolding and cms compatibility fields are removed'
    );
    $policies = new CapabilityAuthorizationRegistry($db);
    $check(
        $policies->requiresProtocol('akira.seo.delete@1', '1', 'cms-akira-seo') === 'v2',
        'activation policy seed is durable and protocol-v2'
    );

    $createPayload = [
        'idempotency_key' => $keys[] = $prefix . '-upsert',
        'entity_type' => 'post',
        'entity_key' => 'hello-world',
        'title' => 'Hello World',
        'meta_description' => 'Akira SEO metadata for the hello-world post.',
        'canonical_url' => '/posts/hello-world',
        'robots' => 'index, follow',
        'og_title' => 'Hello World',
        'og_description' => 'Akira SEO metadata for the hello-world post.',
        'og_image' => 'https://cdn.example.test/hello-world.png',
    ];
    $created = $call('akira.seo.upsert@1', $createPayload);
    $replayed = $call('akira.seo.upsert@1', $createPayload);
    $count = $db->prepare('SELECT COUNT(*) FROM cms_akira_seo_metadata WHERE tenant_id = ? AND entity_type = ? AND entity_key = ?');
    $count->execute([$tenantA, 'post', 'hello-world']);
    $check($created === $replayed && (int) $count->fetchColumn() === 1, 'upsert is idempotent with one durable row');
    $check(
        array_keys($created['seo'] ?? []) === ['entity_type', 'entity_key', 'title', 'meta_description', 'canonical_url', 'robots', 'og_title', 'og_description', 'og_image'],
        'upsert returns an explicit projection'
    );

    $conflict = false;
    try {
        $changed = $createPayload;
        $changed['title'] = 'Conflicting payload';
        $call('akira.seo.upsert@1', $changed);
    } catch (Throwable $error) {
        $conflict = $statusOf($error) === 409;
    }
    $check($conflict, 'same idempotency key with changed payload is rejected');

    $get = $call('akira.seo.get@1', ['entity_type' => 'post', 'entity_key' => 'hello-world']);
    $check(
        ($get['ok'] ?? false) === true
        && array_keys($get['data'] ?? []) === ['entity_type', 'entity_key', 'title', 'meta_description', 'canonical_url', 'robots', 'og_title', 'og_description', 'og_image', 'created_at', 'updated_at'],
        'get returns the explicit detail projection'
    );
    $missing = $call('akira.seo.get@1', ['entity_type' => 'post', 'entity_key' => 'does-not-exist']);
    $check(($missing['ok'] ?? true) === false, 'missing SEO metadata get fails closed');

    $built = $call('akira.seo.meta.build@1', ['entity_type' => 'post', 'entity_key' => 'hello-world']);
    $check(
        ($built['ok'] ?? false) === true
        && ($built['resolved_from'] ?? '') === 'stored'
        && ($built['data']['canonical_url'] ?? '') === '/posts/hello-world',
        'meta.build returns stored escaped metadata with resolved_from stored'
    );
    $builtMissing = $call('akira.seo.meta.build@1', [
        'entity_type' => 'post',
        'entity_key' => 'does-not-exist',
        'defaults' => ['title' => 'Default title', 'meta_description' => 'Default description', 'robots' => 'noindex, nofollow'],
    ]);
    $check(
        ($builtMissing['ok'] ?? false) === true
        && ($builtMissing['resolved_from'] ?? '') === 'default'
        && ($builtMissing['stored'] ?? true) === false
        && ($builtMissing['data']['title'] ?? '') === 'Default title'
        && ($builtMissing['data']['robots'] ?? '') === 'noindex, nofollow',
        'missing record meta.build returns deterministic escaped defaults'
    );

    $fragmentStore = app()->templates()->fragmentStore();
    $fragmentStore->put($prefix . '-fragment', 'old seo', [CAS_SEO_INVALIDATION], 300, (string) $tenantA);
    $updated = $call('akira.seo.upsert@1', [
        'idempotency_key' => $keys[] = $prefix . '-update',
        'entity_type' => 'post',
        'entity_key' => 'hello-world',
        'title' => 'Hello World (updated)',
        'expected_updated_at' => $version('post', 'hello-world'),
    ]);
    $check(
        ($updated['seo']['title'] ?? '') === 'Hello World (updated)'
        && $fragmentStore->tryGet($prefix . '-fragment', [CAS_SEO_INVALIDATION], (string) $tenantA) === null,
        'successful mutation invalidates the single SEO entity-view key'
    );
    $audit = $db->prepare(
        "SELECT new_data FROM audit_logs WHERE module = 'cms-akira-seo' AND action = 'akira.seo.upsert' AND entity_id = ? ORDER BY id DESC LIMIT 1"
    );
    $audit->execute(['post:hello-world']);
    $auditData = json_decode((string) $audit->fetchColumn(), true);
    $check(
        is_array($auditData) && ($auditData['correlation_id'] ?? '') === ($updated['correlation_id'] ?? null),
        'durable same-PDO audit carries the mutation correlation id'
    );

    $canonicalDenied = false;
    try {
        $call('akira.seo.upsert@1', [
            'idempotency_key' => $keys[] = $prefix . '-bad-canonical',
            'entity_type' => 'post',
            'entity_key' => 'hostile-canonical',
            'canonical_url' => 'javascript:alert(1)',
        ]);
    } catch (Throwable $error) {
        $canonicalDenied = $statusOf($error) === 422;
    }
    $canonicalDataDenied = false;
    try {
        $call('akira.seo.upsert@1', [
            'idempotency_key' => $keys[] = $prefix . '-bad-canonical-data',
            'entity_type' => 'post',
            'entity_key' => 'hostile-canonical-data',
            'canonical_url' => 'data:text/html,<script>alert(1)</script>',
        ]);
    } catch (Throwable $error) {
        $canonicalDataDenied = $statusOf($error) === 422;
    }
    $check($canonicalDenied && $canonicalDataDenied, 'unsafe canonical URL schemes fail closed on write');

    $robotsDenied = false;
    try {
        $call('akira.seo.upsert@1', [
            'idempotency_key' => $keys[] = $prefix . '-bad-robots',
            'entity_type' => 'post',
            'entity_key' => 'hostile-robots',
            'robots' => 'index, follow"><script>alert(1)</script>',
        ]);
    } catch (Throwable $error) {
        $robotsDenied = $statusOf($error) === 422;
    }
    $check($robotsDenied, 'robots directive injection fails closed on write');

    $ogImageDenied = false;
    try {
        $call('akira.seo.upsert@1', [
            'idempotency_key' => $keys[] = $prefix . '-bad-og-image',
            'entity_type' => 'post',
            'entity_key' => 'hostile-og-image',
            'og_image' => '"/><script>alert(1)</script>',
        ]);
    } catch (Throwable $error) {
        $ogImageDenied = $statusOf($error) === 422;
    }
    $check($ogImageDenied, 'og_image attribute breakout fails closed on write');

    $hostileTitle = '<script>alert("x")</script>';
    $call('akira.seo.upsert@1', [
        'idempotency_key' => $keys[] = $prefix . '-hostile-text',
        'entity_type' => 'page',
        'entity_key' => 'hostile-text',
        'title' => $hostileTitle,
        'meta_description' => '"><img src=x onerror=alert(1)>',
        'canonical_url' => '/pages/hostile-text',
        'robots' => 'noindex',
    ]);
    $hostileGet = $call('akira.seo.get@1', ['entity_type' => 'page', 'entity_key' => 'hostile-text']);
    $hostileBuilt = $call('akira.seo.meta.build@1', ['entity_type' => 'page', 'entity_key' => 'hostile-text']);
    $check(
        ($hostileGet['data']['title'] ?? '') === $hostileTitle
        && ($hostileBuilt['data']['title'] ?? '') === $escape($hostileTitle)
        && !str_contains((string) ($hostileBuilt['data']['title'] ?? ''), '<script'),
        'plain-text fields are stored raw and HTML-attribute-escaped on meta.build output'
    );
    $hostileDescriptionOut = (string) ($hostileBuilt['data']['meta_description'] ?? '');
    $check(
        $hostileDescriptionOut === $escape('"><img src=x onerror=alert(1)>')
        && !str_contains($hostileDescriptionOut, '"')
        && !str_contains($hostileDescriptionOut, '<img'),
        'hostile meta_description cannot inject attributes through meta.build'
    );

    $db->prepare('INSERT INTO cms_akira_seo_metadata (tenant_id, entity_type, entity_key, canonical_url) VALUES (?, ?, ?, ?)')
        ->execute([$tenantA, 'post', 'corrupt-canonical', 'javascript:alert(1)']);
    $db->prepare('INSERT INTO cms_akira_seo_metadata (tenant_id, entity_type, entity_key, title) VALUES (?, ?, ?, ?)')
        ->execute([$tenantA, 'post', 'corrupt-title', '<b>x</b>&"q"']);
    $corruptCanonical = $call('akira.seo.meta.build@1', ['entity_type' => 'post', 'entity_key' => 'corrupt-canonical']);
    $corruptTitle = $call('akira.seo.meta.build@1', ['entity_type' => 'post', 'entity_key' => 'corrupt-title']);
    $check(
        ($corruptCanonical['data']['canonical_url'] ?? null) === null
        && ($corruptTitle['data']['title'] ?? '') === '&lt;b&gt;x&lt;/b&gt;&amp;&quot;q&quot;',
        'meta.build re-validates and escapes hostile stored values fail-closed'
    );

    $setIdentity($tenantB, $admin);
    $tenantBGet = $call('akira.seo.get@1', ['entity_type' => 'post', 'entity_key' => 'hello-world']);
    $check(($tenantBGet['ok'] ?? true) === false, 'shared-schema tenant B cannot read tenant A SEO metadata');
    $tenantBDeleteDenied = false;
    try {
        $call('akira.seo.delete@1', [
            'idempotency_key' => $keys[] = $prefix . '-tenant-b',
            'entity_type' => 'post',
            'entity_key' => 'hello-world',
            'expected_updated_at' => '2000-01-01 00:00:00',
        ]);
    } catch (Throwable $error) {
        $tenantBDeleteDenied = $statusOf($error) === 404;
    }
    $check($tenantBDeleteDenied, 'shared-schema tenant B cannot mutate tenant A SEO metadata');
    $setIdentity($tenantA, $admin);

    $spoofDenied = false;
    try {
        $call('akira.seo.upsert@1', [
            'idempotency_key' => $keys[] = $prefix . '-spoof',
            'tenant_id' => $tenantB,
            'entity_type' => 'post',
            'entity_key' => 'spoofed',
            'title' => 'Spoofed',
        ]);
    } catch (Throwable $error) {
        $spoofDenied = $statusOf($error) === 422;
    }
    $check($spoofDenied && ($call('akira.seo.get@1', ['tenant_id' => $tenantB, 'entity_type' => 'post', 'entity_key' => 'hello-world'])['ok'] ?? true) === false, 'payload tenant identity is rejected on mutation and read paths');

    app()->setUser(['id' => 999602, 'role' => 'editor']);
    $roleDenied = false;
    try {
        $call('akira.seo.delete@1', [
            'idempotency_key' => $keys[] = $prefix . '-role',
            'entity_type' => 'post',
            'entity_key' => 'hello-world',
            'expected_updated_at' => $version('post', 'hello-world'),
        ]);
    } catch (Throwable $error) {
        $roleDenied = str_contains($error->getMessage(), 'authorization denied');
    }
    $check($roleDenied, 'governed mutation policy denies a non-admin actor');
    $setIdentity($tenantA, $admin);

    $staleUpdateDenied = false;
    try {
        $call('akira.seo.upsert@1', [
            'idempotency_key' => $keys[] = $prefix . '-stale-update',
            'entity_type' => 'post',
            'entity_key' => 'hello-world',
            'title' => 'Stale update',
            'expected_updated_at' => '2000-01-01 00:00:00',
        ]);
    } catch (Throwable $error) {
        $staleUpdateDenied = $statusOf($error) === 409;
    }
    $check($staleUpdateDenied, 'upsert with a stale expected_updated_at fails closed');

    $secondUpsert = $call('akira.seo.upsert@1', [
        'idempotency_key' => $keys[] = $prefix . '-re-upsert',
        'entity_type' => 'post',
        'entity_key' => 'hello-world',
        'title' => 'Hello World',
        'expected_updated_at' => $version('post', 'hello-world'),
    ]);
    $countAgain = $db->prepare('SELECT COUNT(*) FROM cms_akira_seo_metadata WHERE tenant_id = ? AND entity_type = ? AND entity_key = ?');
    $countAgain->execute([$tenantA, 'post', 'hello-world']);
    $check(($secondUpsert['operation'] ?? '') === 'seo.upsert' && (int) $countAgain->fetchColumn() === 1, 'upsert updates in place without creating a duplicate row');

    $indexColumns = $db->query(
        "SELECT column_name FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = 'cms_akira_seo_metadata' AND index_name = 'uq_seo_tenant_entity'
         ORDER BY seq_in_index"
    )->fetchAll(PDO::FETCH_COLUMN);
    $check($indexColumns === ['tenant_id', 'entity_type', 'entity_key'], 'composite unique index (tenant_id, entity_type, entity_key) is evidenced');

    $duplicateDenied = false;
    try {
        $db->prepare('INSERT INTO cms_akira_seo_metadata (tenant_id, entity_type, entity_key) VALUES (?, ?, ?)')
            ->execute([$tenantA, 'post', 'hello-world']);
    } catch (PDOException $error) {
        $duplicateDenied = (($error->errorInfo[1] ?? null) == 1062);
    }
    $check($duplicateDenied, 'duplicate (tenant, type, key) insert is rejected by the unique index');

    $deleted = $call('akira.seo.delete@1', [
        'idempotency_key' => $keys[] = $prefix . '-delete',
        'entity_type' => 'post',
        'entity_key' => 'hello-world',
        'expected_updated_at' => $version('post', 'hello-world'),
    ]);
    $remaining = $db->prepare('SELECT COUNT(*) FROM cms_akira_seo_metadata WHERE tenant_id = ? AND entity_type = ? AND entity_key = ?');
    $remaining->execute([$tenantA, 'post', 'hello-world']);
    $check(($deleted['operation'] ?? '') === 'seo.delete' && (int) $remaining->fetchColumn() === 0, 'delete hard-removes the row');
    $check(($call('akira.seo.get@1', ['entity_type' => 'post', 'entity_key' => 'hello-world'])['ok'] ?? true) === false, 'deleted SEO metadata get fails closed');

    $topology = $db->query(
        "SELECT table_name, engine, table_collation FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'cms_akira_seo_metadata'"
    )->fetchAll(PDO::FETCH_ASSOC);
    $check(
        count($topology) === 1
        && strtoupper((string) $topology[0]['ENGINE']) === 'INNODB'
        && (string) $topology[0]['TABLE_COLLATION'] === 'utf8mb4_unicode_ci',
        'native SEO table is InnoDB utf8mb4_unicode_ci'
    );
    $migration = (string) file_get_contents($module . '/database/migrations/002_create_native_seo_metadata.sql');
    $check(
        str_contains($migration, 'UNIQUE KEY uq_seo_tenant_entity (tenant_id, entity_type, entity_key)')
        && str_contains($migration, 'VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin')
        && str_contains($migration, 'VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin')
        && !preg_match('/\b(CHECK|JSON_TABLE|WITH RECURSIVE|GENERATED ALWAYS)\b/i', $migration),
        'migration observes the MySQL-5.7 composite-index byte budget and syntax set'
    );
    $databaseManager = (string) file_get_contents($root . '/kernel/Services/DatabaseManager.php');
    $check(
        str_contains($databaseManager, 'dbForTenant')
        && str_contains($databaseManager, 'tenantRejectBaseDbConnection')
        && casDb()->getOwnsTables() === ['cms_akira_seo_metadata'],
        'dedicated topology uses Kernel connection selection/base-DB rejection and the same ModuleDB closure'
    );
    $runner = new \Ikabud\Kernel\Database\MigrationRunner($db, $root . '/modules');
    $check($runner->status('cms-akira-seo')['pending'] === [], 'member migration ledger rerun is converged');

    $source = implode("\n", array_map(static fn (string $file): string => (string) file_get_contents($file), [
        $module . '/module.json', $module . '/helpers.php', $module . '/handlers.php', $module . '/routes.php', $module . '/README.md',
    ]));
    $forbiddenA = 'cms' . '.seo.';
    $forbiddenB = 'akira' . '.content.get@1';
    $legacyTheme = 'cms' . 'ActiveTheme';
    $legacyRender = 'cms' . 'Render';
    $legacyAdmin = 'cms' . 'AdminContext';
    $check(
        !str_contains($source, $forbiddenA)
        && !str_contains($source, $forbiddenB)
        && !str_contains($source, $legacyTheme)
        && !str_contains($source, $legacyRender)
        && !str_contains($source, $legacyAdmin),
        'tracked SEO has no forbidden legacy authority, theme, or render residue'
    );

    $appLog = (string) @file_get_contents($root . '/storage/logs/app.log');
    $errorLog = (string) @file_get_contents($root . '/storage/logs/error.log');
    $check(!str_contains($appLog, '[error]') && trim($errorLog) === '', 'SEO run leaves application/error logs clean', trim($errorLog));
} catch (Throwable $error) {
    $check(false, 'Phase 5B SEO scenario completes', $error::class . ': ' . $error->getMessage());
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    try {
        $db->prepare('DELETE FROM cms_akira_seo_metadata WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare('DELETE FROM kernel_idempotency_keys WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare("DELETE FROM audit_logs WHERE module = 'cms-akira-seo'")->execute();
        app()->templates()->fragmentStore()->flushAll((string) $tenantA);
        app()->templates()->fragmentStore()->flushAll((string) $tenantB);
    } catch (Throwable $error) {
        $check(false, 'SEO fixture cleanup succeeds', $error->getMessage());
    }
    app()->tenant()->setTenantId($originalTenant);
    kernel_request_context_delete('tenant_id');
    @file_put_contents($root . '/storage/logs/app.log', '');
    @file_put_contents($root . '/storage/logs/error.log', '');
}

echo "\nCMS Akira SEO: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
