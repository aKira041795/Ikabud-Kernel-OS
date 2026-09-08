<?php

/** CMS Akira Phase 10B authenticated JSON capability-bridge HTTP handler gate. */

declare(strict_types=1);

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
$tenant = 994721;
$originalTenant = app()->tenant()->current();
$prefix = 'bridge-' . bin2hex(random_bytes(4));
$slug = $prefix . '-post';
$setIdentity = static function (int $tenantId, ?array $actor): void {
    app()->tenant()->setTenantId($tenantId);
    kernel_request_context_set('tenant_id', $tenantId);
    app()->setUser($actor);
};

/** Invoke a bridge HTTP handler in an isolated response buffer. @return array{status:int,body:array<string,mixed>} */
$bridge = static function (string $handler, array $params = [], string $rawBody = '') use (&$check): array {
    http_response_code(200);
    $GLOBALS['cab_builder_http_raw_body'] = $rawBody;
    try {
        ob_start();
        $handler($params);
        $out = (string) ob_get_clean();
        $status = http_response_code();
    } catch (Throwable $error) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        $check(false, "bridge {$handler} did not fail closed", $error->getMessage());
        return ['status' => 500, 'body' => []];
    } finally {
        unset($GLOBALS['cab_builder_http_raw_body']);
    }
    $decoded = json_decode($out, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
};

$validTree = ['version' => 1, 'blocks' => [
    ['type' => 'section', 'props' => ['layout' => 'stack'], 'children' => [
        ['type' => 'heading', 'props' => ['text' => 'Bridge preview', 'level' => 2], 'children' => []],
        ['type' => 'rich_text', 'props' => ['content' => '<p>Safe <strong>content</strong></p>'], 'children' => []],
    ]],
]];
$hostileTree = ['version' => 1, 'blocks' => [['type' => 'paragraph', 'props' => ['text' => '<script>alert(1)</script>'], 'children' => []]]];

@file_put_contents($root . '/storage/logs/app.log', '');
@file_put_contents($root . '/storage/logs/error.log', '');
// Clear any persisted capability circuit-breaker state left by earlier runs so a
// hostile-path failure earlier in this test cannot trip the governed mutation breaker.
foreach ([$root . '/storage/cache/capability_breakers.json', capability_cache_path('capability_breakers.json')] as $breakerFile) {
    if (is_string($breakerFile) && is_file($breakerFile)) {
        @unlink($breakerFile);
    }
}
ob_start(); // buffer all check output so header()/http_response_code() remain effective in CLI

try {
    echo "=== CMS Akira Phase 10B builder HTTP bridge ===\n";
    $db->prepare('DELETE FROM cms_akira_composition_revisions WHERE tenant_id = ?')->execute([$tenant]);
    $db->prepare('DELETE FROM cms_akira_compositions WHERE tenant_id = ?')->execute([$tenant]);
    $db->prepare('DELETE FROM cms_akira_posts WHERE tenant_id = ?')->execute([$tenant]);
    $db->prepare("INSERT INTO cms_akira_posts (tenant_id, slug, title, subtitle, content, image, status, published_at) VALUES (?, ?, 'Bridge fixture', '', 'body', NULL, 'published', NOW())")->execute([$tenant, $slug]);

    $routes = require dirname(__DIR__) . '/routes.php';
    $expect = [
        'GET' => [
            '/api/v1/cms-akira/builder/compositions' => 'akiraBuilderApiCompositions',
            '/api/v1/cms-akira/builder/compositions/{key}' => 'akiraBuilderApiGet',
            '/api/v1/cms-akira/builder/compositions/{key}/revisions' => 'akiraBuilderApiRevisions',
            '/api/v1/cms-akira/builder/compositions/{key}/render' => 'akiraBuilderApiRender',
        ],
        'POST' => [
            '/api/v1/cms-akira/builder/validate' => 'akiraBuilderApiValidate',
            '/api/v1/cms-akira/builder/compositions' => 'akiraBuilderApiCreate',
            '/api/v1/cms-akira/builder/compositions/{key}' => 'akiraBuilderApiUpdate',
            '/api/v1/cms-akira/builder/compositions/{key}/publish' => 'akiraBuilderApiPublish',
            '/api/v1/cms-akira/builder/compositions/{key}/unpublish' => 'akiraBuilderApiUnpublish',
            '/api/v1/cms-akira/builder/compositions/{key}/delete' => 'akiraBuilderApiDelete',
        ],
    ];
    $allRouted = true;
    foreach ($expect as $method => $paths) {
        foreach ($paths as $path => $handler) {
            if (($routes[$method][$path] ?? '') !== 'cms-akira-builder:' . $handler) {
                $allRouted = false;
            }
        }
    }
    $check($allRouted, 'routes.php wires every builder JSON endpoint to its thin HTTP handler');

    // ── Authorization denial (anonymous first — no actor is set yet) ──
    app()->tenant()->setTenantId($tenant);
    kernel_request_context_set('tenant_id', $tenant);
    $anon = $bridge('akiraBuilderApiCompositions', []);
    $check($anon['status'] === 401 && ($anon['body']['ok'] ?? true) === false, 'anonymous request is denied 401 with JSON fail-closed body');
    $setIdentity($tenant, ['id' => 999722, 'role' => 'editor']);
    $nonAdmin = $bridge('akiraBuilderApiCompositions', []);
    $check($nonAdmin['status'] === 403 && ($nonAdmin['body']['ok'] ?? true) === false, 'non-admin role is denied 403');
    $setIdentity($tenant, ['id' => 999721, 'role' => 'admin']);

    // ── Fail closed on malformed JSON / hostile tree ──
    $badJson = $bridge('akiraBuilderApiCreate', [], '{not-json');
    $check($badJson['status'] === 400 && ($badJson['body']['ok'] ?? true) === false, 'malformed JSON body fails closed with 400');
    $hostile = $bridge('akiraBuilderApiCreate', [], json_encode(['entity_type' => 'post', 'entity_key' => $slug, 'title' => 'x', 'tree' => $hostileTree, 'change_note' => 'x', 'idempotency_key' => $prefix . '-bad']));
    $check(in_array($hostile['status'], [422, 409], true) && ($hostile['body']['ok'] ?? true) === false, 'hostile script tree is rejected by the bridge fail-closed');

    // ── Create → get → list ──
    $createBody = json_encode(['entity_type' => 'post', 'entity_key' => $slug, 'title' => 'Bridge composition', 'tree' => $validTree, 'change_note' => 'initial', 'idempotency_key' => $prefix . '-create']);
    $created = $bridge('akiraBuilderApiCreate', [], $createBody);
    $revisionA = (int) ($created['body']['data']['current_revision_id'] ?? 0);
    $check($created['status'] === 200 && ($created['body']['ok'] ?? false) === true && $revisionA > 0, 'create succeeds through the HTTP bridge');
    $get = $bridge('akiraBuilderApiGet', ['key' => $slug]);
    $check($get['status'] === 200 && ($get['body']['data']['tree'] ?? null) === $validTree, 'get returns the current preview tree via HTTP bridge');
    $list = $bridge('akiraBuilderApiCompositions', []);
    $check(($list['body']['total'] ?? 0) === 1 && $list['status'] === 200, 'list is reachable and tenant-scoped via HTTP bridge');

    // ── Optimistic concurrency: stale base rejected, current applied ──
    $stale = $bridge('akiraBuilderApiUpdate', ['key' => $slug], json_encode(['title' => 'x', 'tree' => $validTree, 'base_revision_id' => $revisionA + 100, 'change_note' => 'stale', 'idempotency_key' => $prefix . '-stale']));
    $check($stale['status'] === 409 && ($stale['body']['ok'] ?? true) === false, 'stale base_revision_id is a typed 409 over HTTP');
    $treeB = ['version' => 1, 'blocks' => [['type' => 'heading', 'props' => ['text' => 'Draft two', 'level' => 3], 'children' => []]]];
    $updateBody = json_encode(['title' => 'Updated bridge composition', 'tree' => $treeB, 'base_revision_id' => $revisionA, 'change_note' => 'draft edit', 'idempotency_key' => $prefix . '-update']);
    $updated = $bridge('akiraBuilderApiUpdate', ['key' => $slug], $updateBody);
    $revisionB = (int) ($updated['body']['data']['current_revision_id'] ?? 0);
    $check($updated['status'] === 200 && $revisionB > $revisionA, 'save draft (base revision) succeeds through the bridge');
    $revisions = $bridge('akiraBuilderApiRevisions', ['key' => $slug]);
    $check(count($revisions['body']['rows'] ?? []) === 2 && ($revisions['body']['rows'][0]['base_revision_id'] ?? null) === $revisionA, 'revision history is bridged with ordered base chain');

    // ── Validate endpoint ──
    $validated = $bridge('akiraBuilderApiValidate', [], json_encode(['tree' => $treeB, 'idempotency_key' => $prefix . '-validate']));
    $check($validated['status'] === 200 && ($validated['body']['data']['valid'] ?? false) === true, 'governed validate is reachable through the bridge');

    // ── Preview vs published separation ──
    $_GET['source'] = 'published';
    $published = $bridge('akiraBuilderApiRender', ['key' => $slug]);
    $check($published['status'] === 404, 'published render before publish fails closed with 404');
    $_GET['source'] = 'preview';
    $preview = $bridge('akiraBuilderApiRender', ['key' => $slug]);
    $check($preview['status'] === 200 && str_contains((string) ($preview['body']['data']['html'] ?? ''), 'Draft two'), 'preview render returns the current draft through the bridge');
    unset($_GET['source']);

    $publishBody = json_encode(['idempotency_key' => $prefix . '-publish']);
    $published = $bridge('akiraBuilderApiPublish', ['key' => $slug], $publishBody);
    $check($published['status'] === 200 && ($published['body']['data']['status'] ?? '') === 'published' && ($published['body']['data']['published_revision_id'] ?? null) === $revisionB, 'publish promotes exactly the current preview revision through the bridge');
    $_GET['source'] = 'published';
    $publishedRender = $bridge('akiraBuilderApiRender', ['key' => $slug]);
    $_GET['source'] = 'preview';
    $previewAfter = $bridge('akiraBuilderApiRender', ['key' => $slug]);
    unset($_GET['source']);
    $check(str_contains((string) ($publishedRender['body']['data']['html'] ?? ''), 'Draft two') && ($publishedRender['body']['data']['source'] ?? '') === 'published', 'published render shows published revision');
    $treeC = ['version' => 1, 'blocks' => [['type' => 'heading', 'props' => ['text' => 'New draft three', 'level' => 4], 'children' => []]]];
    $edit = $bridge('akiraBuilderApiUpdate', ['key' => $slug], json_encode(['tree' => $treeC, 'base_revision_id' => $revisionB, 'change_note' => 'new draft', 'idempotency_key' => $prefix . '-preview']));
    $_GET['source'] = 'published';
    $publishedAfterEdit = $bridge('akiraBuilderApiRender', ['key' => $slug]);
    $_GET['source'] = 'preview';
    $previewAfterEdit = $bridge('akiraBuilderApiRender', ['key' => $slug]);
    unset($_GET['source']);
    $check($publishedAfterEdit['status'] === 200 && str_contains((string) ($publishedAfterEdit['body']['data']['html'] ?? ''), 'Draft two') && !str_contains((string) ($publishedAfterEdit['body']['data']['html'] ?? ''), 'New draft three') && str_contains((string) ($previewAfterEdit['body']['data']['html'] ?? ''), 'New draft three'), 'editing preview never mutates published output over HTTP until next publish');

    // ── Unpublish / delete ──
    $unpublish = $bridge('akiraBuilderApiUnpublish', ['key' => $slug], json_encode(['idempotency_key' => $prefix . '-unpublish']));
    $check($unpublish['status'] === 200 && ($unpublish['body']['data']['published_revision_id'] ?? null) === null, 'unpublish clears the published pointer through the bridge');
    $delete = $bridge('akiraBuilderApiDelete', ['key' => $slug], json_encode(['idempotency_key' => $prefix . '-delete']));
    $count = $db->prepare('SELECT COUNT(*) FROM cms_akira_composition_revisions WHERE tenant_id = ?');
    $count->execute([$tenant]);
    $check($delete['status'] === 200 && ($delete['body']['data']['deleted'] ?? false) === true && (int) $count->fetchColumn() === 0, 'delete cascades revisions through the bridge');

    // ── Logs clean ──
    $check(!str_contains((string) @file_get_contents($root . '/storage/logs/app.log'), '[error]') && trim((string) @file_get_contents($root . '/storage/logs/error.log')) === '', 'builder HTTP bridge run leaves logs clean');
} catch (Throwable $error) {
    $check(false, 'Phase 10B builder HTTP bridge scenario completes', $error->getMessage());
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    try {
        $db->prepare('DELETE FROM cms_akira_composition_revisions WHERE tenant_id = ?')->execute([$tenant]);
        $db->prepare('DELETE FROM cms_akira_compositions WHERE tenant_id = ?')->execute([$tenant]);
        $db->prepare('DELETE FROM cms_akira_posts WHERE tenant_id = ?')->execute([$tenant]);
        $db->prepare('DELETE FROM kernel_idempotency_keys WHERE tenant_id = ?')->execute([$tenant]);
        $db->prepare("DELETE FROM audit_logs WHERE module = 'cms-akira-builder'")->execute();
        app()->templates()->fragmentStore()->flushAll((string) $tenant);
    } catch (Throwable $error) {
        $check(false, 'builder HTTP bridge fixture cleanup succeeds', $error->getMessage());
    }
    app()->tenant()->setTenantId($originalTenant);
    kernel_request_context_delete('tenant_id');
    @file_put_contents($root . '/storage/logs/app.log', '');
    @file_put_contents($root . '/storage/logs/error.log', '');
}

echo "\nCMS Akira Builder HTTP bridge: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
