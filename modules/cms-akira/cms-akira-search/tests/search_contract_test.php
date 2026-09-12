<?php

/** CMS Akira Phase 7 native search contract and integration gate. */

declare(strict_types=1);

use Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry;

$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
require_once $root . '/tests/_support/env_guard.php';
require_once $root . '/modules/cms-akira/cms-akira-core/helpers.php';
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/handlers.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

$registry = app()->capabilities();
$register = static function (string $moduleId, array $handlers, array $mutations = [], string $invalidation = '') use ($registry): void {
    foreach ($handlers as $id => $handler) {
        if ($registry->has($id)) {
            continue;
        }
        $meta = in_array($id, $mutations, true)
            ? ['requires_protocol' => 'v2', 'effects' => ['invalidates' => [$invalidation]]]
            : [];
        $registry->register($id, $moduleId, static function (mixed $payload, string $capabilityId = '', string $provider = '') use ($moduleId, $handler): mixed {
            return moduleWithContext($moduleId, static fn (): mixed => $handler($payload, $capabilityId, $provider));
        }, 50, ['first'], $meta);
    }
};
$register('cms-akira-core', cms_akira_core_capability_handlers(), [
    'akira.post.create@1', 'akira.post.update@1', 'akira.post.publish@1', 'akira.post.unpublish@1', 'akira.post.delete@1',
], 'entity.list.post');
$mutations = ['akira.search.upsert@1', 'akira.search.delete@1', 'akira.search.rebuild@1'];
$register(CAS_SEARCH_MODULE_ID, cms_akira_search_capability_handlers(), $mutations, CAS_SEARCH_INVALIDATION);
app()->entityAuthority()->registerAuthority('post', 'cms-akira-core', ['authority' => true]);

$tenantA = 994901;
$tenantB = 994902;
requireTenantModulesActive($tenantA, ['cms-akira-core', CAS_SEARCH_MODULE_ID]);
$originalTenant = app()->tenant()->current();
$db = app()->db();
$prefix = 'search-' . bin2hex(random_bytes(5));
$admin = ['id' => 999901, 'role' => 'admin'];
$setIdentity = static function (int $tenant, array $user) use ($admin): void {
    app()->tenant()->setTenantId($tenant);
    kernel_request_context_set('tenant_id', $tenant);
    app()->setUser($user ?: $admin);
};
$call = static function (string $id, array $payload = []): array {
    return app()->cap()->call($id, $payload, [
        'caller' => ['module' => CAS_SEARCH_MODULE_ID, 'user' => app()->user()],
        'mode' => 'first', 'breaker_threshold' => 1000,
    ]);
};
$statusOf = static function (Throwable $error): ?int {
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        if ($cursor instanceof CasSearchException) {
            return $cursor->httpStatus;
        }
    }
    return null;
};
$document = static fn (string $key, string $title, string $body = 'Native Akira searchable body'): array => [
    'entity_type' => 'post', 'document_key' => $key,
    'fields' => ['title' => $title, 'body' => $body, 'summary' => 'Allowlisted summary', 'status' => 'published', 'meta' => ['url' => '/posts/' . $key]],
];

@file_put_contents($root . '/storage/logs/app.log', '');
@file_put_contents($root . '/storage/logs/error.log', '');

try {
    echo "=== CMS Akira Phase 7 native search ===\n";
    $setIdentity($tenantA, $admin);
    $db->prepare('DELETE FROM cms_akira_search_documents WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
    $db->prepare('DELETE FROM cms_akira_posts WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);

    $module = dirname(__DIR__);
    $manifest = kernelReadJsonFile($module . '/module.json');
    $ids = array_column($manifest['capabilities']['exposes'] ?? [], 'id');
    $check(($manifest['id'] ?? '') === CAS_SEARCH_MODULE_ID && ($manifest['name'] ?? '') === 'CMS Akira Search', 'stable native module id and name replace the dormant adapter');
    $check(($manifest['kind'] ?? '') === 'extension' && ($manifest['extends'] ?? '') === 'cms-akira-core' && ($manifest['depends'] ?? []) === ['cms-akira-core'], 'search is an extension of core with one module dependency');
    $check(($manifest['owns_tables'] ?? []) === ['cms_akira_search_documents'] && ($manifest['reads_tables'] ?? []) === ['cms_akira_search_documents'], 'manifest freezes one owned and readable search table');
    $check(($manifest['migrations'] ?? []) === ['database/migrations/001_initial.sql', 'database/migrations/002_create_native_search_documents.sql'], 'manifest freezes marker plus native table migration');
    $check($ids === array_keys(cms_akira_search_capability_handlers()), 'manifest and runtime expose build, upsert, delete, query, and rebuild exactly');
    $check(($manifest['_enabled'] ?? null) === false && !isset($manifest['nav']) && !isset($manifest['admin_contributions']) && !isset($manifest['entities']), 'activation remains explicit with no legacy nav or Entity Authority');
    foreach ($mutations as $id) {
        $entry = $manifest['capabilities']['exposes'][array_search($id, $ids, true)] ?? [];
        $check(($entry['requires_protocol'] ?? '') === 'v2' && ($entry['effects']['invalidates'] ?? null) === [CAS_SEARCH_INVALIDATION], "{$id} is governed with one canonical invalidation");
    }
    $policy = new CapabilityAuthorizationRegistry($db);
    $check($policy->requiresProtocol('akira.search.upsert@1', '1', CAS_SEARCH_MODULE_ID) === 'v2' && $policy->requiresProtocol('akira.search.rebuild@1', '1', CAS_SEARCH_MODULE_ID) === 'v2', 'mutation policy seeding is durable protocol-v2');

    $built = $call('akira.search.document.build@1', $document('hello-world', 'Hello World', '<p>Native <b>Akira</b> body</p>'));
    $builtDocument = $built['data']['document'] ?? [];
    $check(($built['ok'] ?? false) === true && array_keys($builtDocument) === ['entity_type', 'document_key', 'title', 'body', 'summary', 'status', 'meta'], 'document.build returns only the frozen projection');
    $check(($builtDocument['body'] ?? '') === 'Native Akira body' && ($builtDocument['status'] ?? '') === 'published', 'document.build normalizes searchable text deterministically');
    $unknownType = $call('akira.search.document.build@1', ['entity_type' => 'page', 'document_key' => 'x', 'fields' => ['title' => 'x', 'body' => 'x', 'status' => 'published']]);
    $unknownField = $call('akira.search.document.build@1', $document('bad-fields', 'Bad') + ['secret' => 'leak']);
    $draft = $document('draft-post', 'Draft');
    $draft['fields']['status'] = 'draft';
    $draftResult = $call('akira.search.document.build@1', $draft);
    $check(($unknownType['ok'] ?? true) === false && ($unknownField['ok'] ?? true) === false && ($draftResult['ok'] ?? true) === false, 'unknown type/field and unpublished content fail closed');
    $spoofBuild = $document('spoof', 'Spoof') + ['tenant_id' => $tenantB];
    $check(($call('akira.search.document.build@1', $spoofBuild)['ok'] ?? true) === false, 'document.build rejects payload tenant identity');

    $payload = ['idempotency_key' => $prefix . '-upsert', 'document' => $document('hello-world', 'Hello World')];
    $created = $call('akira.search.upsert@1', $payload);
    $replayed = $call('akira.search.upsert@1', $payload);
    $count = $db->prepare('SELECT COUNT(*) FROM cms_akira_search_documents WHERE tenant_id = ? AND entity_type = ? AND document_key = ?');
    $count->execute([$tenantA, 'post', 'hello-world']);
    $check($created === $replayed && (int) $count->fetchColumn() === 1, 'upsert is durable-idempotent and unique');
    $check(array_keys($created['data'] ?? []) === ['entity_type', 'document_key', 'title', 'summary', 'status', 'indexed_at'], 'upsert response is allowlisted');
    $conflict = false;
    try {
        $changed = $payload;
        $changed['document'] = $document('hello-world', 'Changed');
        $call('akira.search.upsert@1', $changed);
    } catch (Throwable $error) {
        $conflict = $statusOf($error) === 409;
    }
    $check($conflict, 'changed payload under one idempotency key conflicts');

    $fragmentStore = app()->templates()->fragmentStore();
    $fragmentStore->put($prefix . '-fragment', 'stale search', [CAS_SEARCH_INVALIDATION], 300, (string) $tenantA);
    $call('akira.search.upsert@1', ['idempotency_key' => $prefix . '-invalidate', 'document' => $document('second-post', 'Second Native Post')]);
    $check($fragmentStore->tryGet($prefix . '-fragment', [CAS_SEARCH_INVALIDATION], (string) $tenantA) === null, 'successful mutation invalidates the sole search-document tag');
    $audit = $db->prepare("SELECT new_data FROM audit_logs WHERE module = ? AND action = 'akira.search.upsert' AND entity_id = ? ORDER BY id DESC LIMIT 1");
    $audit->execute([CAS_SEARCH_MODULE_ID, 'post:second-post']);
    $auditData = json_decode((string) $audit->fetchColumn(), true);
    $check(is_array($auditData) && ($auditData['correlation_id'] ?? '') !== '' && ($auditData['tenant_id'] ?? 0) === $tenantA, 'same-PDO durable audit records trusted tenant and correlation');

    $query = $call('akira.search.query@1', ['term' => 'Native', 'entity_type' => 'post', 'page' => 1, 'limit' => 1]);
    $check(($query['ok'] ?? false) === true && ($query['total'] ?? 0) === 2 && count($query['rows'] ?? []) === 1, 'query filters and pages current-tenant matching documents');
    $check(array_keys($query['rows'][0] ?? []) === ['entity_type', 'document_key', 'title', 'summary', 'status', 'indexed_at'], 'query projection excludes body, metadata, ids, and tenant identity');
    $wildcardLiteral = $call('akira.search.query@1', ['term' => '%']);
    $badQuery = $call('akira.search.query@1', ['term' => 'Native', 'sort' => 'title']);
    $check(($wildcardLiteral['total'] ?? -1) === 0 && ($badQuery['ok'] ?? true) === false, 'query escapes SQL wildcards and rejects unknown controls');

    $setIdentity($tenantB, $admin);
    $check(($call('akira.search.query@1', ['term' => 'Native'])['total'] ?? -1) === 0, 'shared-schema tenant B cannot query tenant A documents');
    $call('akira.search.upsert@1', ['idempotency_key' => $prefix . '-tenant-b', 'document' => $document('hello-world', 'Tenant B Native')]);
    $setIdentity($tenantA, $admin);
    $tenantAQuery = $call('akira.search.query@1', ['term' => 'Tenant B']);
    $check(($tenantAQuery['total'] ?? -1) === 0, 'same document identity remains isolated between shared-schema tenants');
    $spoofMutation = false;
    try {
        $call('akira.search.delete@1', ['idempotency_key' => $prefix . '-spoof-delete', 'tenant_id' => $tenantB, 'entity_type' => 'post', 'document_key' => 'hello-world']);
    } catch (Throwable $error) {
        $spoofMutation = $statusOf($error) === 422;
    }
    $check($spoofMutation && ($call('akira.search.query@1', ['tenant_id' => $tenantB, 'term' => 'Native'])['ok'] ?? true) === false, 'read and mutation paths reject payload tenant spoofing');

    app()->setUser(['id' => 999902, 'role' => 'editor']);
    $roleDenied = false;
    try {
        $call('akira.search.delete@1', ['idempotency_key' => $prefix . '-role', 'entity_type' => 'post', 'document_key' => 'hello-world']);
    } catch (Throwable $error) {
        $roleDenied = str_contains($error->getMessage(), 'authorization denied');
    }
    $check($roleDenied, 'governed policy denies a non-admin mutation');
    $setIdentity($tenantA, $admin);

    $call('akira.post.create@1', [
        'idempotency_key' => $prefix . '-post-create', 'slug' => 'lifecycle-post', 'title' => 'Lifecycle Native',
        'subtitle' => 'Lifecycle summary', 'content' => 'Lifecycle searchable content',
    ]);
    $postVersion = static function () use ($db, $tenantA): string {
        $stmt = $db->prepare("SELECT updated_at FROM cms_akira_posts WHERE tenant_id = ? AND slug = 'lifecycle-post'");
        $stmt->execute([$tenantA]);
        return (string) $stmt->fetchColumn();
    };
    $corePublished = $call('akira.post.publish@1', [
        'idempotency_key' => $prefix . '-post-publish', 'slug' => 'lifecycle-post', 'expected_updated_at' => $postVersion(),
    ]);
    $publishConsumers = app()->events()->fire('akira.post.lifecycle.committed', [
        'operation' => 'publish', 'entity_type' => 'post', 'document_key' => 'lifecycle-post', 'correlation_id' => $corePublished['correlation_id'], 'tenant_id' => $tenantA,
    ], 'cms-akira-core');
    $present = $call('akira.search.query@1', ['term' => 'Lifecycle searchable']);
    $check($publishConsumers >= 1 && ($present['total'] ?? 0) === 1, 'Post publish plus committed event seam converges to a present document');
    $coreUnpublished = $call('akira.post.unpublish@1', [
        'idempotency_key' => $prefix . '-post-unpublish', 'slug' => 'lifecycle-post', 'expected_updated_at' => $postVersion(),
    ]);
    app()->events()->fire('akira.post.lifecycle.committed', [
        'operation' => 'unpublish', 'entity_type' => 'post', 'document_key' => 'lifecycle-post', 'correlation_id' => $coreUnpublished['correlation_id'], 'tenant_id' => $tenantA,
    ], 'cms-akira-core');
    $check(($call('akira.search.query@1', ['term' => 'Lifecycle searchable'])['total'] ?? -1) === 0, 'Post unpublish plus committed event seam removes the document');
    $coreRepublished = $call('akira.post.publish@1', [
        'idempotency_key' => $prefix . '-post-republish', 'slug' => 'lifecycle-post', 'expected_updated_at' => $postVersion(),
    ]);
    app()->events()->fire('akira.post.lifecycle.committed', [
        'operation' => 'publish', 'entity_type' => 'post', 'document_key' => 'lifecycle-post', 'correlation_id' => $coreRepublished['correlation_id'],
    ], 'cms-akira-core');
    $coreDeleted = $call('akira.post.delete@1', [
        'idempotency_key' => $prefix . '-post-delete', 'slug' => 'lifecycle-post', 'expected_updated_at' => $postVersion(),
    ]);
    app()->events()->fire('akira.post.lifecycle.committed', [
        'operation' => 'delete', 'entity_type' => 'post', 'document_key' => 'lifecycle-post', 'correlation_id' => $coreDeleted['correlation_id'],
    ], 'cms-akira-core');
    $check(($call('akira.search.query@1', ['term' => 'Lifecycle searchable'])['total'] ?? -1) === 0, 'Post delete plus committed event seam removes the document');
    $eventTenantDenied = false;
    try {
        moduleWithContext(CAS_SEARCH_MODULE_ID, static fn (): array => casSearchConsumePostLifecycle([
            'operation' => 'publish', 'entity_type' => 'post', 'document_key' => 'lifecycle-post', 'correlation_id' => 'spoof', 'tenant_id' => $tenantB,
        ]));
    } catch (Throwable $error) {
        $eventTenantDenied = $statusOf($error) === 422;
    }
    $check($eventTenantDenied, 'lifecycle consumer rejects host/event tenant mismatch');

    $db->prepare("INSERT INTO cms_akira_posts (tenant_id, slug, title, subtitle, content, status, published_at) VALUES (?, 'rebuild-post', 'Rebuild Native', 'Repair summary', 'Repair searchable content', 'published', NOW())")
        ->execute([$tenantA]);
    $db->prepare("INSERT INTO cms_akira_search_documents (tenant_id, entity_type, document_key, title, body, summary, status, meta, indexed_at) VALUES (?, 'post', 'drift-stale', 'Stale', 'stale drift', '', 'published', '{}', NOW()), (?, 'post', 'rebuild-post', 'Drifted title', 'wrong', '', 'published', '{}', NOW())")
        ->execute([$tenantA, $tenantA]);
    $rebuilt = $call('akira.search.rebuild@1', ['idempotency_key' => $prefix . '-rebuild']);
    $rebuiltReplay = $call('akira.search.rebuild@1', ['idempotency_key' => $prefix . '-rebuild']);
    $staleCount = $db->prepare("SELECT COUNT(*) FROM cms_akira_search_documents WHERE tenant_id = ? AND document_key IN ('drift-stale', 'lifecycle-post')");
    $staleCount->execute([$tenantA]);
    $freshTitle = $db->prepare("SELECT title FROM cms_akira_search_documents WHERE tenant_id = ? AND document_key = 'rebuild-post'");
    $freshTitle->execute([$tenantA]);
    $check($rebuilt === $rebuiltReplay && (int) $staleCount->fetchColumn() === 0 && $freshTitle->fetchColumn() === 'Rebuild Native', 'missed delivery/drift then deterministic rebuild converges and replays idempotently');

    $indexColumns = $db->query("SELECT column_name FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'cms_akira_search_documents' AND index_name = 'uq_search_tenant_entity_document' ORDER BY seq_in_index")->fetchAll(PDO::FETCH_COLUMN);
    $check($indexColumns === ['tenant_id', 'entity_type', 'document_key'], 'composite tenant/type/document unique index is evidenced');
    $duplicateDenied = false;
    try {
        $db->prepare("INSERT INTO cms_akira_search_documents (tenant_id, entity_type, document_key, title, body, summary, status, meta, indexed_at) VALUES (?, 'post', 'rebuild-post', 'x', 'x', '', 'published', '{}', NOW())")->execute([$tenantA]);
    } catch (PDOException $error) {
        $duplicateDenied = (($error->errorInfo[1] ?? null) == 1062);
    }
    $check($duplicateDenied, 'database unique key rejects duplicate current-tenant document identity');
    $topology = $db->query("SELECT engine, table_collation FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'cms_akira_search_documents'")->fetch(PDO::FETCH_ASSOC);
    $check(is_array($topology) && strtoupper((string) $topology['ENGINE']) === 'INNODB' && ($topology['TABLE_COLLATION'] ?? '') === 'utf8mb4_unicode_ci', 'native table is InnoDB utf8mb4_unicode_ci');
    $migration = (string) file_get_contents($module . '/database/migrations/002_create_native_search_documents.sql');
    $check(str_contains($migration, 'UNIQUE KEY uq_search_tenant_entity_document (tenant_id, entity_type, document_key)') && str_contains($migration, 'CHARACTER SET ascii COLLATE ascii_bin') && !preg_match('/\b(CHECK|JSON_TABLE|WITH RECURSIVE|GENERATED ALWAYS)\b/i', $migration), '002 migration is byte-budgeted and MySQL-5.7-safe');
    $runner = new \Ikabud\Kernel\Database\MigrationRunner($db, $root . '/modules');
    $check($runner->status(CAS_SEARCH_MODULE_ID)['pending'] === [], 'member migration ledger rerun is converged');
    $databaseManager = (string) file_get_contents($root . '/kernel/Services/DatabaseManager.php');
    $check(str_contains($databaseManager, 'dbForTenant') && str_contains($databaseManager, 'tenantRejectBaseDbConnection') && casSearchDb()->getOwnsTables() === ['cms_akira_search_documents'], 'dedicated topology selects/rejects through Kernel and uses the same ModuleDB closure');

    $source = implode("\n", array_map(static fn (string $file): string => (string) file_get_contents($file), [$module . '/module.json', $module . '/helpers.php', $module . '/handlers.php', $module . '/routes.php', $module . '/README.md']));
    $forbidden = ['cms-akira-search-' . 'adapter', 'search.' . 'index.', 'akira.' . 'content.get@1', 'cms' . 'RequireCap', 'cms' . 'Render', 'cms' . 'ActiveTheme'];
    $clean = true;
    foreach ($forbidden as $residue) {
        $clean = $clean && !str_contains($source, $residue);
    }
    $check($clean, 'tracked search has no old identity, foreign index, deleted content, or legacy CMS residue');

    $appLog = (string) @file_get_contents($root . '/storage/logs/app.log');
    $errorLog = (string) @file_get_contents($root . '/storage/logs/error.log');
    $check(!str_contains($appLog, '[error]') && trim($errorLog) === '', 'search run leaves application/error logs clean', trim($errorLog));
} catch (Throwable $error) {
    $messages = [];
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        $messages[] = $cursor::class . ': ' . $cursor->getMessage();
    }
    $check(false, 'Phase 7 search scenario completes', implode(' <- ', $messages));
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    try {
        $db->prepare('DELETE FROM cms_akira_search_documents WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare('DELETE FROM cms_akira_posts WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare('DELETE FROM kernel_idempotency_keys WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare('DELETE FROM audit_logs WHERE module = ?')->execute([CAS_SEARCH_MODULE_ID]);
        app()->templates()->fragmentStore()->flushAll((string) $tenantA);
        app()->templates()->fragmentStore()->flushAll((string) $tenantB);
    } catch (Throwable $error) {
        $check(false, 'search fixture cleanup succeeds', $error->getMessage());
    }
    app()->tenant()->setTenantId($originalTenant);
    kernel_request_context_delete('tenant_id');
    kernel_request_context_delete('correlation_id');
    @file_put_contents($root . '/storage/logs/app.log', '');
    @file_put_contents($root . '/storage/logs/error.log', '');
}

echo "\nCMS Akira Search: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
