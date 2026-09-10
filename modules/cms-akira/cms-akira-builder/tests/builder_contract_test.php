<?php

/** CMS Akira Phase 10A native composition authority gate. */

declare(strict_types=1);

use Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry;

$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
require_once $root . '/modules/cms-akira/cms-akira-core/helpers.php';
require_once $root . '/modules/cms-akira/cms-akira-editor/helpers.php';
require_once $root . '/modules/cms-akira/cms-akira-theme/helpers.php';
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/handlers.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

$mutations = array_slice(array_keys(cms_akira_builder_capability_handlers()), 4);
$register = static function (string $module, array $handlers, array $governed = []): void {
    foreach ($handlers as $id => $handler) {
        if (app()->capabilities()->has($id)) {
            continue;
        }
        app()->capabilities()->register($id, $module, static function (mixed $payload, string $capability = '', string $provider = '') use ($handler, $module): mixed {
            return moduleWithContext($module, static fn (): mixed => $handler($payload, $capability, $provider));
        }, 50, ['first'], in_array($id, $governed, true) ? ['requires_protocol' => 'v2', 'effects' => ['invalidates' => [CAB_BUILDER_INVALIDATION]]] : []);
    }
};
$register('cms-akira-core', cms_akira_core_capability_handlers());
$register('cms-akira-editor', cms_akira_editor_capability_handlers());
$register('cms-akira-theme', cms_akira_theme_capability_handlers());
$register(CAB_BUILDER_MODULE_ID, cms_akira_builder_capability_handlers(), $mutations);

$db = app()->db();
$tenantA = 994701;
$tenantB = 994702;
$originalTenant = app()->tenant()->current();
$admin = ['id' => 999701, 'role' => 'admin'];
$prefix = 'builder-' . bin2hex(random_bytes(5));
$slug = $prefix . '-post';
$keys = [];
$setIdentity = static function (int $tenant, array $actor): void {
    app()->tenant()->setTenantId($tenant);
    kernel_request_context_set('tenant_id', $tenant);
    app()->setUser($actor);
};
$call = static function (string $id, array $payload = []): array {
    return app()->cap()->call($id, $payload, ['caller' => ['module' => CAB_BUILDER_MODULE_ID, 'user' => app()->user()], 'mode' => 'first']);
};
$statusOf = static function (Throwable $error): ?int {
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        if ($cursor instanceof CabBuilderException) {
            return $cursor->httpStatus;
        }
    }
    return null;
};
$treeA = ['version' => 1, 'blocks' => [
    ['block' => 'hero', 'props' => ['title' => 'Section'], 'children' => [
        ['block' => 'richtext', 'props' => ['title' => 'Preview one', 'body' => 'Body'], 'children' => []],
        ['block' => 'richtext', 'props' => ['body' => 'Safe content'], 'children' => []],
        ['block' => 'cta', 'props' => ['cta_label' => 'Read', 'cta_href' => '/read'], 'children' => []],
    ]],
]];
$treeB = ['version' => 1, 'blocks' => [
    ['block' => 'richtext', 'props' => ['title' => 'Preview two'], 'children' => []],
]];

@file_put_contents($root . '/storage/logs/app.log', '');
@file_put_contents($root . '/storage/logs/error.log', '');
try {
    echo "=== CMS Akira Phase 10A builder ===\n";
    $setIdentity($tenantA, $admin);
    $db->prepare('DELETE FROM cms_akira_composition_revisions WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
    $db->prepare('DELETE FROM cms_akira_compositions WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
    $db->prepare('DELETE FROM cms_akira_posts WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
    $db->prepare("INSERT INTO cms_akira_posts (tenant_id, slug, title, subtitle, content, image, status, published_at) VALUES (?, ?, 'Builder fixture', '', 'body', NULL, 'published', NOW())")->execute([$tenantA, $slug]);

    $manifest = kernelReadJsonFile(dirname(__DIR__) . '/module.json');
    $ids = array_column($manifest['capabilities']['exposes'] ?? [], 'id');
    $check($ids === array_keys(cms_akira_builder_capability_handlers()), 'manifest and runtime expose exactly ten native builder capabilities');
    $check(($manifest['owns_tables'] ?? []) === ['cms_akira_compositions', 'cms_akira_composition_revisions'] && ($manifest['reads_tables'] ?? []) === $manifest['owns_tables'], 'builder owns and reads only its two tenant-scoped tables');
    $check(($manifest['depends'] ?? []) === ['cms-akira-core'] && ($manifest['_enabled'] ?? null) === false, 'native core extension remains explicitly tenant-activated');
    foreach ($mutations as $id) {
        $entry = $manifest['capabilities']['exposes'][array_search($id, $ids, true)] ?? [];
        $check(($entry['requires_protocol'] ?? '') === 'v2' && ($entry['effects']['invalidates'] ?? []) === [CAB_BUILDER_INVALIDATION], "{$id} is governed with one canonical invalidation");
    }
    $policies = new CapabilityAuthorizationRegistry($db);
    $check($policies->requiresProtocol('akira.builder.update@1', '1', CAB_BUILDER_MODULE_ID) === 'v2', 'policy seed durably requires protocol v2');

    $valid = $call('akira.builder.validate@1', ['idempotency_key' => $keys[] = $prefix . '-validate', 'tree' => $treeA]);
    $check(($valid['data']['valid'] ?? false) === true && ($valid['data']['tree'] ?? null) === $treeA, 'governed validator accepts and canonicalizes the fixed allowlist tree');
    $hostile = [
        ['version' => 1, 'blocks' => [['block' => 'script', 'props' => [], 'children' => []]]],
        ['version' => 1, 'blocks' => [['block' => 'cta', 'props' => ['label' => 'x', 'url' => 'javascript:alert(1)'], 'children' => []]]],
        ['version' => 1, 'blocks' => [['block' => 'hero', 'props' => ['src' => 'data:text/html,x', 'alt' => 'x'], 'children' => []]]],
        ['version' => 1, 'blocks' => [['block' => 'richtext', 'props' => ['text' => '{include ../../secret}'], 'children' => []]]],
        ['version' => 1, 'blocks' => [['block' => 'richtext', 'props' => ['content' => '<script>alert(1)</script>'], 'children' => []]]],
        ['version' => 1, 'blocks' => [['block' => 'richtext', 'props' => ['text' => 'ok', 'mystery' => true], 'children' => []]]],
    ];
    $denied = 0;
    foreach ($hostile as $tree) {
        try {
            cabBuilderValidateTree($tree);
        } catch (CabBuilderException) {
            ++$denied;
        }
    }
    $check($denied === count($hostile), 'unknown block/prop, script, unsafe schemes, and include/path payloads fail closed');
    $deep = ['block' => 'richtext', 'props' => ['text' => 'deep', 'level' => 2], 'children' => []];
    for ($i = 0; $i < 9; ++$i) {
        $deep = ['block' => 'hero', 'props' => ['title' => 'Section'], 'children' => [$deep]];
    }
    $depthDenied = false;
    try {
        cabBuilderValidateTree(['version' => 1, 'blocks' => [$deep]]);
    } catch (CabBuilderException) {
        $depthDenied = true;
    }
    $sizeDenied = false;
    try {
        cabBuilderValidateTree(['version' => 1, 'blocks' => [['block' => 'richtext', 'props' => ['text' => str_repeat('x', CAB_BUILDER_MAX_TREE_BYTES)], 'children' => []]]]);
    } catch (CabBuilderException) {
        $sizeDenied = true;
    }
    $check($depthDenied && $sizeDenied, 'depth and encoded-size caps fail closed');

    $createPayload = ['idempotency_key' => $keys[] = $prefix . '-create', 'entity_type' => 'post', 'entity_key' => $slug, 'title' => 'Landing composition', 'tree' => $treeA, 'change_note' => 'initial'];
    $created = $call('akira.builder.create@1', $createPayload);
    $replayed = $call('akira.builder.create@1', $createPayload);
    $revisionA = (int) ($created['data']['current_revision_id'] ?? 0);
    $check($created === $replayed && $revisionA > 0, 'create is idempotent and returns the initial revision projection');
    $get = $call('akira.builder.get@1', ['entity_type' => 'post', 'entity_key' => $slug]);
    $check(($get['data']['tree'] ?? null) === $treeA && !array_key_exists('id', $get['data'] ?? []) && !array_key_exists('tenant_id', $get['data'] ?? []), 'get returns current preview with no storage or tenant fields');
    $list = $call('akira.builder.compositions@1');
    $check(($list['total'] ?? 0) === 1 && !array_key_exists('tree', $list['rows'][0] ?? []), 'list is tenant-scoped and excludes composition trees');

    $stale = false;
    try {
        $call('akira.builder.update@1', ['idempotency_key' => $keys[] = $prefix . '-stale', 'entity_type' => 'post', 'entity_key' => $slug, 'tree' => $treeB, 'base_revision_id' => $revisionA + 100]);
    } catch (Throwable $error) {
        $stale = $statusOf($error) === 409;
    }
    $updated = $call('akira.builder.update@1', ['idempotency_key' => $keys[] = $prefix . '-update', 'entity_type' => 'post', 'entity_key' => $slug, 'title' => 'Updated composition', 'tree' => $treeB, 'base_revision_id' => $revisionA, 'change_note' => 'preview edit']);
    $revisionB = (int) ($updated['data']['current_revision_id'] ?? 0);
    $check($stale && $revisionB > $revisionA, 'stale base is a typed 409 and current base appends a revision');
    $history = $call('akira.builder.revisions@1', ['entity_type' => 'post', 'entity_key' => $slug]);
    $check(count($history['rows'] ?? []) === 2 && ($history['rows'][0]['base_revision_id'] ?? null) === $revisionA && !array_key_exists('tree', $history['rows'][0] ?? []), 'revision history is ordered and explicitly projected');

    $published = $call('akira.builder.publish@1', ['idempotency_key' => $keys[] = $prefix . '-publish', 'entity_type' => 'post', 'entity_key' => $slug]);
    $check(($published['data']['published_revision_id'] ?? null) === $revisionB && ($published['data']['status'] ?? '') === 'published', 'publish promotes exactly the current preview revision');
    $publishedRender = $call('akira.builder.render@1', ['entity_type' => 'post', 'entity_key' => $slug, 'source' => 'published']);
    $check(($publishedRender['ok'] ?? false) === true && str_contains((string) ($publishedRender['data']['html'] ?? ''), 'Preview two') && ($publishedRender['data']['theme_slug'] ?? '') === 'cms-akira-posts', 'published render traverses explicit Akira theme → ARK → DiSyL');
    $unregistered = $call('akira.builder.render@1', ['entity_type' => 'post', 'entity_key' => $slug, 'source' => 'published', 'view_id' => 'entity.detail.unregistered']);
    $check(($unregistered['ok'] ?? true) === false, 'unregistered exact ARK view fails closed');

    $treeC = ['version' => 1, 'blocks' => [['block' => 'richtext', 'props' => ['title' => 'Preview three'], 'children' => []]]];
    $edited = $call('akira.builder.update@1', ['idempotency_key' => $keys[] = $prefix . '-preview', 'entity_type' => 'post', 'entity_key' => $slug, 'tree' => $treeC, 'base_revision_id' => $revisionB]);
    $publishedAgain = $call('akira.builder.render@1', ['entity_type' => 'post', 'entity_key' => $slug, 'source' => 'published']);
    $preview = $call('akira.builder.render@1', ['entity_type' => 'post', 'entity_key' => $slug, 'source' => 'preview']);
    $check(str_contains((string) ($publishedAgain['data']['html'] ?? ''), 'Preview two') && !str_contains((string) ($publishedAgain['data']['html'] ?? ''), 'Preview three') && str_contains((string) ($preview['data']['html'] ?? ''), 'Preview three'), 'preview edits never mutate published output until the next publish');

    $fragment = app()->templates()->fragmentStore();
    $fragment->put($prefix . '-fragment', 'old', [CAB_BUILDER_INVALIDATION], 300, (string) $tenantA);
    $unpublished = $call('akira.builder.unpublish@1', ['idempotency_key' => $keys[] = $prefix . '-unpublish', 'entity_type' => 'post', 'entity_key' => $slug]);
    $check(array_key_exists('published_revision_id', $unpublished['data']) && $unpublished['data']['published_revision_id'] === null && $fragment->tryGet($prefix . '-fragment', [CAB_BUILDER_INVALIDATION], (string) $tenantA) === null, 'unpublish clears published state and invalidates the canonical tag');
    $audit = $db->prepare("SELECT new_data FROM audit_logs WHERE module = 'cms-akira-builder' AND action = 'akira.builder.unpublish' ORDER BY id DESC LIMIT 1");
    $audit->execute();
    $auditData = json_decode((string) $audit->fetchColumn(), true);
    $check(is_array($auditData) && ($auditData['correlation_id'] ?? null) === ($unpublished['correlation_id'] ?? null), 'durable same-PDO audit records correlation evidence');

    $setIdentity($tenantB, $admin);
    $check(($call('akira.builder.compositions@1')['rows'] ?? null) === [] && ($call('akira.builder.get@1', ['entity_type' => 'post', 'entity_key' => $slug])['ok'] ?? true) === false, 'shared-schema tenant B cannot read tenant A composition');
    $crossWrite = false;
    try {
        $call('akira.builder.delete@1', ['idempotency_key' => $keys[] = $prefix . '-cross', 'entity_type' => 'post', 'entity_key' => $slug]);
    } catch (Throwable $error) {
        $crossWrite = $statusOf($error) === 404;
    }
    $check($crossWrite, 'shared-schema tenant B cannot mutate tenant A composition');
    $setIdentity($tenantA, $admin);
    $spoof = false;
    try {
        $call('akira.builder.delete@1', ['idempotency_key' => $keys[] = $prefix . '-spoof', 'tenant_id' => $tenantB, 'entity_type' => 'post', 'entity_key' => $slug]);
    } catch (Throwable $error) {
        $spoof = $statusOf($error) === 422;
    }
    $check($spoof, 'payload tenant identity is rejected');

    $deleted = $call('akira.builder.delete@1', ['idempotency_key' => $keys[] = $prefix . '-delete', 'entity_type' => 'post', 'entity_key' => $slug]);
    $revisionCount = $db->prepare('SELECT COUNT(*) FROM cms_akira_composition_revisions WHERE tenant_id = ?');
    $revisionCount->execute([$tenantA]);
    $check(($deleted['data']['deleted'] ?? false) === true && (int) $revisionCount->fetchColumn() === 0, 'delete cascades same-member revisions');

    $tables = $db->query("SELECT table_name, engine, table_collation FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('cms_akira_compositions','cms_akira_composition_revisions')")->fetchAll(PDO::FETCH_ASSOC);
    $check(count($tables) === 2 && count(array_filter($tables, static fn (array $row): bool => strtoupper((string) $row['ENGINE']) === 'INNODB' && $row['TABLE_COLLATION'] === 'utf8mb4_unicode_ci')) === 2, 'both tables are MySQL-5.7 InnoDB utf8mb4_unicode_ci');
    $migration = (string) file_get_contents(dirname(__DIR__) . '/database/migrations/002_create_compositions.sql');
    $check(str_contains($migration, 'VARCHAR(190)') && !preg_match('/\b(JSON_TABLE|WITH RECURSIVE|ROW_NUMBER|GENERATED ALWAYS)\b/i', $migration), 'migration observes byte budgets and the MySQL-5.7 syntax set');
    $runner = new \Ikabud\Kernel\Database\MigrationRunner($db, $root . '/modules');
    $check($runner->status(CAB_BUILDER_MODULE_ID)['pending'] === [], 'builder migration ledger rerun is converged');
    $check(cabBuilderDb()->getOwnsTables() === ['cms_akira_compositions', 'cms_akira_composition_revisions'], 'ModuleDB boundary grants only builder-owned tables');

    $source = implode("\n", array_map(static fn (string $file): string => (string) file_get_contents($file), [dirname(__DIR__) . '/module.json', dirname(__DIR__) . '/helpers.php', dirname(__DIR__) . '/handlers.php', dirname(__DIR__) . '/routes.php']));
    $forbidden = ['cms' . '.builder.', 'akira' . '.content.get@1', 'cms' . 'ActiveTheme', 'JSON_' . 'TABLE'];
    $check(count(array_filter($forbidden, static fn (string $needle): bool => str_contains($source, $needle))) === 0, 'builder runtime has no forbidden legacy authority, theme fallback, or MySQL-8 residue');
    $check(!str_contains((string) @file_get_contents($root . '/storage/logs/app.log'), '[error]') && trim((string) @file_get_contents($root . '/storage/logs/error.log')) === '', 'builder run leaves logs clean');
} catch (Throwable $error) {
    $details = [];
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        $details[] = $cursor::class . ': ' . $cursor->getMessage();
    }
    $check(false, 'Phase 10A builder scenario completes', implode(' <- ', $details));
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    try {
        $db->prepare('DELETE FROM cms_akira_composition_revisions WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare('DELETE FROM cms_akira_compositions WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare('DELETE FROM cms_akira_posts WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare('DELETE FROM kernel_idempotency_keys WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare("DELETE FROM audit_logs WHERE module = 'cms-akira-builder'")->execute();
        app()->templates()->fragmentStore()->flushAll((string) $tenantA);
        app()->templates()->fragmentStore()->flushAll((string) $tenantB);
    } catch (Throwable $error) {
        $check(false, 'builder fixture cleanup succeeds', $error->getMessage());
    }
    app()->tenant()->setTenantId($originalTenant);
    kernel_request_context_delete('tenant_id');
    @file_put_contents($root . '/storage/logs/app.log', '');
    @file_put_contents($root . '/storage/logs/error.log', '');
}

echo "\nCMS Akira Builder: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
