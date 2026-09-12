<?php

/** CMS Akira P1 post <-> taxonomy assignment (category links) contract. */

declare(strict_types=1);

use Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry;

$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = 'akiracms.test';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
require_once $root . '/tests/_support/env_guard.php';
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/handlers.php';

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
    $meta = [];
    if (in_array($id, [
        'akira.post.create@1', 'akira.post.update@1', 'akira.post.publish@1',
        'akira.post.unpublish@1', 'akira.post.delete@1',
    ], true)) {
        $meta = ['requires_protocol' => 'v2', 'effects' => ['invalidates' => ['entity.list.post']]];
    } elseif ($id === 'akira.post.set_taxonomies@1') {
        $meta = ['requires_protocol' => 'v2', 'effects' => ['invalidates' => ['entity.list.post', 'entity.detail.post']]];
    } elseif (in_array($id, [
        'akira.taxonomy.create@1', 'akira.taxonomy.update@1', 'akira.taxonomy.delete@1',
    ], true)) {
        $meta = ['requires_protocol' => 'v2', 'effects' => ['invalidates' => ['entity.list.taxonomy']]];
    } elseif (in_array($id, [
        'akira.content_type.create@1', 'akira.content_type.update@1', 'akira.content_type.delete@1',
    ], true)) {
        $meta = ['requires_protocol' => 'v2', 'effects' => ['invalidates' => ['entity.list.content_type']]];
    }
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
app()->entityAuthority()->registerAuthority('taxonomy', 'cms-akira-core', ['authority' => true]);
cacRegisterPostEntityViews(app()->entityViews());

$tenantA = (int) app()->tenant()->current();
$tenantB = 992103;
$originalTenant = app()->tenant()->current();
$db = app()->db();
requireCapabilityAuthorizationPolicies($db, [
    ['capability_id' => 'akira.post.create@1', 'provider' => 'cms-akira-core'],
    ['capability_id' => 'akira.taxonomy.create@1', 'provider' => 'cms-akira-core'],
    ['capability_id' => 'akira.post.set_taxonomies@1', 'provider' => 'cms-akira-core'],
]);
$prefix = 'ptx-' . bin2hex(random_bytes(6));
$slug = $prefix . '-post';
$keys = [];
$setIdentity = static function (int $tenant, array $user): void {
    app()->tenant()->setTenantId($tenant);
    kernel_request_context_set('tenant_id', $tenant);
    app()->setUser($user);
};
$admin = ['id' => 999020, 'role' => 'admin'];
$call = static function (string $capability, array $payload, ?array $user = null): array {
    $user ??= app()->user() ?? [];
    return app()->cap()->call($capability, $payload, [
        'caller' => ['module' => 'cms-akira-core', 'user' => $user],
        'mode' => 'first',
        'breaker_threshold' => 1000,
    ]);
};
$shellCall = static function (string $capability, array $payload): array {
    return app()->cap()->call($capability, $payload, [
        'caller' => ['module' => 'cms-akira-shell', 'user' => app()->user()],
        'mode' => 'first',
        'breaker_threshold' => 1000,
    ]);
};
$thrownStatus = static function (Throwable $error): ?int {
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        if ($cursor instanceof CacPostTaxonomyMutationException) {
            return $cursor->httpStatus;
        }
    }
    return null;
};
$rootMessageOf = static function (Throwable $error): string {
    $cursor = $error;
    while ($cursor->getPrevious() instanceof Throwable) {
        $cursor = $cursor->getPrevious();
    }
    return trim($cursor->getMessage());
};

@file_put_contents($root . '/storage/logs/app.log', '');
@file_put_contents($root . '/storage/logs/error.log', '');

try {
    echo "=== CMS Akira P1 post <-> taxonomy assignment path ===\n";
    $setIdentity($tenantA, $admin);

    $manifest = kernelReadJsonFile(dirname(__DIR__) . '/module.json');
    $exposes = [];
    foreach ($manifest['capabilities']['exposes'] as $expose) {
        $exposes[$expose['id']] = $expose;
    }
    $entry = $exposes['akira.post.set_taxonomies@1'] ?? [];
    $check(($entry['requires_protocol'] ?? '') === 'v2', 'akira.post.set_taxonomies@1 declares protocol v2');
    // The kernel manifest validator + effect runtime accept only entity.list.* /
    // entity.detail.* invalidation tags; entity.list.post invalidates the post's
    // whole cache namespace (list + single-item), so the second tag declares the
    // detail/get projection explicitly.
    $check(($entry['effects']['invalidates'] ?? null) === ['entity.list.post', 'entity.detail.post'], 'set_taxonomies invalidates entity.list.post + entity.detail.post');
    $check(($exposes['akira.post.get@1']['requires_protocol'] ?? '') === ''
        && !isset($exposes['akira.post.get@1']['effects'])
        && ($exposes['akira.post.list@1']['requires_protocol'] ?? '') === ''
        && !isset($exposes['akira.post.list@1']['effects']), 'post reads stay ungoverned (no protocol, no effects)');
    $check(in_array('cms_akira_post_taxonomies', $manifest['owns_tables'] ?? [], true)
        && in_array('cms_akira_post_taxonomies', $manifest['reads_tables'] ?? [], true), 'post taxonomy table declared as owned and read by core');
    $check(in_array('database/migrations/006_create_post_taxonomies.sql', $manifest['migrations'] ?? [], true), 'post taxonomy migration registered');
    $check((bool)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'cms_akira_post_taxonomies'")->fetchColumn(), 'assignment table exists in the tenant database');

    $policyDb = new CapabilityAuthorizationRegistry($db);
    $check(
        $policyDb->hasPolicyFor('akira.post.set_taxonomies@1', '1', 'cms-akira-core')
        && $policyDb->requiresProtocol('akira.post.set_taxonomies@1', '1', 'cms-akira-core') === 'v2',
        'activation seeds idempotent set_taxonomies policy'
    );
    $policyRow = $db->query("SELECT caller_module, allowed_roles FROM capability_authorization_policies WHERE provider = 'cms-akira-core' AND capability_id = 'akira.post.set_taxonomies@1' AND policy_version = 1")->fetch(PDO::FETCH_ASSOC);
    $check(
        is_array($policyRow)
        && ($policyRow['allowed_roles'] ?? '') === 'admin,editor,administrator,superadmin'
        && ($policyRow['caller_module'] ?? '') === 'cms-akira-core,cms-akira-shell',
        'set_taxonomies policy binds editor+ roles and the core+shell callers'
    );

    // Fixtures: one draft post + two categories in tenant A.
    $created = $call('akira.post.create@1', [
        'idempotency_key' => $keys[] = $prefix . '-post',
        'slug' => $slug,
        'title' => 'Assignment Contract',
        'content' => 'Body',
    ]);
    $check(($created['ok'] ?? false) === true && ($created['post']['slug'] ?? '') === $slug, 'admin creates a draft Post');

    $catA = $call('akira.taxonomy.create@1', [
        'idempotency_key' => $keys[] = $prefix . '-cat-a',
        'type' => 'category',
        'name' => 'Product News',
        'slug' => 'product-news',
    ]);
    $catB = $call('akira.taxonomy.create@1', [
        'idempotency_key' => $keys[] = $prefix . '-cat-b',
        'type' => 'category',
        'name' => 'Releases',
        'slug' => 'releases',
    ]);
    $check(($catA['ok'] ?? false) === true && ($catB['ok'] ?? false) === true, 'two governed categories exist');

    // Reads are additive: fresh rows carry empty (but present) assignment keys.
    $freshGet = $call('akira.post.admin.get@1', ['slug' => $slug, 'include_unpublished' => true]);
    $check(
        ($freshGet['ok'] ?? false) === true
        && ($freshGet['data']['taxonomy_ids'] ?? null) === []
        && ($freshGet['data']['categories'] ?? null) === [],
        'post.get includes empty taxonomy_ids + categories on a fresh post'
    );

    $versionStmt = $db->prepare('SELECT updated_at FROM cms_akira_posts WHERE tenant_id = ? AND slug = ?');
    $versionStmt->execute([$tenantA, $slug]);
    $versionBefore = (string)$versionStmt->fetchColumn();

    $assignKey = $keys[] = $prefix . '-assign';
    $assigned = $call('akira.post.set_taxonomies@1', [
        'idempotency_key' => $assignKey,
        'slug' => $slug,
        'taxonomy_ids' => [(int)$catA['taxonomy']['id'], (int)$catB['taxonomy']['id']],
        'expected_updated_at' => $versionBefore,
    ]);
    $check(
        ($assigned['ok'] ?? false) === true && ($assigned['operation'] ?? '') === 'set_taxonomies'
        && ($assigned['post']['taxonomy_ids'] ?? null) === [(int)$catA['taxonomy']['id'], (int)$catB['taxonomy']['id']],
        'admin assigns two categories to the post'
    );
    $versionStmt->execute([$tenantA, $slug]);
    $shellAssigned = $shellCall('akira.post.set_taxonomies@1', [
        'idempotency_key' => $keys[] = $prefix . '-shell-assign',
        'slug' => $slug,
        'taxonomy_ids' => [(int)$catA['taxonomy']['id'], (int)$catB['taxonomy']['id']],
        'expected_updated_at' => (string)$versionStmt->fetchColumn(),
    ]);
    $check(($shellAssigned['ok'] ?? false) === true, 'the seeded policy admits the cms-akira-shell caller');

    $versionStmt->execute([$tenantA, $slug]);
    $versionAfter = (string)$versionStmt->fetchColumn();
    $check($versionAfter === $versionBefore, 'assignment never writes the post row (updated_at unchanged)');

    $links = $db->prepare('SELECT taxonomy_id FROM cms_akira_post_taxonomies WHERE tenant_id = ? AND post_slug = ? ORDER BY taxonomy_id');
    $links->execute([$tenantA, $slug]);
    $linkIds = array_map('intval', array_map('strval', $links->fetchAll(PDO::FETCH_COLUMN)));
    $check($linkIds === [(int)$catA['taxonomy']['id'], (int)$catB['taxonomy']['id']], 'link rows persist one per assigned term');

    $replayed = $call('akira.post.set_taxonomies@1', [
        'idempotency_key' => $assignKey,
        'slug' => $slug,
        'taxonomy_ids' => [(int)$catA['taxonomy']['id'], (int)$catB['taxonomy']['id']],
        'expected_updated_at' => $versionBefore,
    ]);
    $links->execute([$tenantA, $slug]);
    $check($replayed === $assigned && count($links->fetchAll(PDO::FETCH_COLUMN)) === 2, 'same key replays the stored outcome with no second write');

    $conflict = false;
    try {
        $call('akira.post.set_taxonomies@1', [
            'idempotency_key' => $assignKey,
            'slug' => $slug,
            'taxonomy_ids' => [(int)$catA['taxonomy']['id']],
            'expected_updated_at' => $versionBefore,
        ]);
    } catch (Throwable $e) {
        $conflict = $thrownStatus($e) === 409;
    }
    $check($conflict, 'same key with a different assignment payload is rejected as 409');

    // Reads now project the assignment (labels + ids) on get, list and the bridge.
    $versionStmt->execute([$tenantA, $slug]);
    $get = $call('akira.post.admin.get@1', ['slug' => $slug, 'include_unpublished' => true]);
    $names = array_column(is_array($get['data']['categories'] ?? null) ? $get['data']['categories'] : [], 'name');
    $check(
        ($get['ok'] ?? false) === true
        && ($get['data']['taxonomy_ids'] ?? null) === [(int)$catA['taxonomy']['id'], (int)$catB['taxonomy']['id']]
        && in_array('Product News', $names, true) && in_array('Releases', $names, true),
        'post.get returns taxonomy_ids and category labels after assignment'
    );
    $list = $call('akira.post.admin.list@1', ['slug' => $slug, 'include_unpublished' => true, 'filters' => ['search' => $slug]]);
    $listRow = null;
    foreach (is_array($list['rows'] ?? null) ? $list['rows'] : [] as $row) {
        if (($row['slug'] ?? '') === $slug) {
            $listRow = $row;
        }
    }
    $check(
        ($list['ok'] ?? false) === true && is_array($listRow)
        && ($listRow['taxonomy_ids'] ?? null) === [(int)$catA['taxonomy']['id'], (int)$catB['taxonomy']['id']]
        && count($listRow['categories'] ?? []) === 2,
        'post.list decorates its rows with assignments'
    );
    $bridged = $call('entity.list.post@1', [
        'filters' => ['include_unpublished' => true, 'search' => $slug], 'limit' => 25, 'offset' => 0,
    ]);
    $bridgeRow = null;
    foreach (is_array($bridged['rows'] ?? null) ? $bridged['rows'] : [] as $row) {
        if (($row['slug'] ?? '') === $slug) {
            $bridgeRow = $row;
        }
    }
    $check(
        ($bridged['ok'] ?? false) === true && $bridgeRow === null,
        'public entity.list.post excludes the unpublished draft despite admin-shaped filters'
    );

    // Administration list filter by one category returns the assigned draft.
    $filtered = $call('akira.post.admin.list@1', ['include_unpublished' => true, 'filters' => ['taxonomy_id' => (int)$catA['taxonomy']['id']]]);
    $filteredSlugs = array_column(is_array($filtered['rows'] ?? null) ? $filtered['rows'] : [], 'slug');
    $check(($filtered['ok'] ?? false) === true && in_array($slug, $filteredSlugs, true), 'list filters by a category id');

    // Assignment is atomic replacement (delete + insert).
    $versionStmt->execute([$tenantA, $slug]);
    $reassigned = $call('akira.post.set_taxonomies@1', [
        'idempotency_key' => $keys[] = $prefix . '-reassign',
        'slug' => $slug,
        'taxonomy_ids' => [(int)$catB['taxonomy']['id']],
        'expected_updated_at' => (string)$versionStmt->fetchColumn(),
    ]);
    $links->execute([$tenantA, $slug]);
    $check(
        ($reassigned['ok'] ?? false) === true
        && array_map('intval', array_map('strval', $links->fetchAll(PDO::FETCH_COLUMN))) === [(int)$catB['taxonomy']['id']],
        'reassignment atomically replaces the previous set'
    );

    // Clearing assignments is an explicit empty set.
    $versionStmt->execute([$tenantA, $slug]);
    $cleared = $call('akira.post.set_taxonomies@1', [
        'idempotency_key' => $keys[] = $prefix . '-clear',
        'slug' => $slug,
        'taxonomy_ids' => [],
        'expected_updated_at' => (string)$versionStmt->fetchColumn(),
    ]);
    $links->execute([$tenantA, $slug]);
    $get = $call('akira.post.admin.get@1', ['slug' => $slug, 'include_unpublished' => true]);
    $check(
        ($cleared['ok'] ?? false) === true
        && count($links->fetchAll(PDO::FETCH_COLUMN)) === 0
        && ($get['data']['taxonomy_ids'] ?? null) === [],
        'an empty taxonomy_ids clears all links'
    );

    // OCC guards a concurrent content edit.
    $versionStmt->execute([$tenantA, $slug]);
    $stale = false;
    try {
        $call('akira.post.set_taxonomies@1', [
            'idempotency_key' => $keys[] = $prefix . '-stale',
            'slug' => $slug,
            'taxonomy_ids' => [(int)$catB['taxonomy']['id']],
            'expected_updated_at' => '2000-01-01 00:00:00',
        ]);
    } catch (Throwable $e) {
        $stale = $thrownStatus($e) === 409;
    }
    $check($stale, 'optimistic concurrency rejects a stale post version');

    $missing = false;
    try {
        $call('akira.post.set_taxonomies@1', [
            'idempotency_key' => $keys[] = $prefix . '-missing-term',
            'slug' => $slug,
            'taxonomy_ids' => [999999],
            'expected_updated_at' => (string)$versionStmt->fetchColumn(),
        ]);
    } catch (Throwable $e) {
        $missing = $thrownStatus($e) === 422 && str_contains($rootMessageOf($e), 'same tenant');
    }
    $check($missing, 'a taxonomy_id that does not exist in the tenant is a clean 422');

    // Cross-tenant reference: fabricate a term row in tenant B directly (the
    // governed seam is tenant A only) and prove an id that exists elsewhere is
    // still rejected as not-in-this-tenant.
    $db->prepare('INSERT INTO cms_akira_taxonomies (tenant_id, type, name, slug) VALUES (?, ?, ?, ?)')
        ->execute([$tenantB, 'category', 'Foreign', 'foreign-term']);
    $foreignId = (int)$db->lastInsertId();
    $crossTenant = false;
    try {
        $versionStmt->execute([$tenantA, $slug]);
        $call('akira.post.set_taxonomies@1', [
            'idempotency_key' => $keys[] = $prefix . '-cross-tenant',
            'slug' => $slug,
            'taxonomy_ids' => [$foreignId],
            'expected_updated_at' => (string)$versionStmt->fetchColumn(),
        ]);
    } catch (Throwable $e) {
        $crossTenant = $thrownStatus($e) === 422;
    }
    $db->prepare('DELETE FROM cms_akira_taxonomies WHERE tenant_id = ? AND id = ?')->execute([$tenantB, $foreignId]);
    $links->execute([$tenantA, $slug]);
    $check($crossTenant && count($links->fetchAll(PDO::FETCH_COLUMN)) === 0, 'a cross-tenant taxonomy_id cannot be assigned');

    $spoofDenied = false;
    try {
        $call('akira.post.set_taxonomies@1', [
            'idempotency_key' => $keys[] = $prefix . '-spoof',
            'tenant_id' => $tenantB,
            'slug' => $slug,
            'taxonomy_ids' => [(int)$catB['taxonomy']['id']],
            'expected_updated_at' => (string)$versionStmt->fetchColumn(),
        ]);
    } catch (Throwable $e) {
        $spoofDenied = $thrownStatus($e) === 422;
    }
    $check($spoofDenied, 'payload tenant spoofing is rejected before a write');

    $missingPost = false;
    try {
        $call('akira.post.set_taxonomies@1', [
            'idempotency_key' => $keys[] = $prefix . '-missing-post',
            'slug' => $prefix . '-does-not-exist',
            'taxonomy_ids' => [],
            'expected_updated_at' => (string)$versionStmt->fetchColumn(),
        ]);
    } catch (Throwable $e) {
        $missingPost = $thrownStatus($e) === 404;
    }
    $check($missingPost, 'assigning to an unknown post is a 404');

    $shaped = false;
    try {
        $call('akira.post.set_taxonomies@1', [
            'idempotency_key' => $keys[] = $prefix . '-shape',
            'slug' => $slug,
            'taxonomy_ids' => 'not-an-array',
            'expected_updated_at' => (string)$versionStmt->fetchColumn(),
        ]);
    } catch (Throwable $e) {
        $shaped = $thrownStatus($e) === 422;
    }
    $check($shaped, 'a non-array taxonomy_ids is rejected');

    app()->setUser(['id' => 999021, 'role' => 'editor']);
    $versionStmt->execute([$tenantA, $slug]);
    $editorAssigned = $call('akira.post.set_taxonomies@1', [
        'idempotency_key' => $keys[] = $prefix . '-editor-assign',
        'slug' => $slug,
        'taxonomy_ids' => [(int)$catA['taxonomy']['id']],
        'expected_updated_at' => (string)$versionStmt->fetchColumn(),
    ]);
    $check(($editorAssigned['ok'] ?? false) === true, 'editor role is allowed by the seeded set_taxonomies policy');

    app()->setUser(['id' => 999022, 'role' => 'author']);
    $authorDenied = false;
    try {
        $versionStmt->execute([$tenantA, $slug]);
        $call('akira.post.set_taxonomies@1', [
            'idempotency_key' => $keys[] = $prefix . '-author-denied',
            'slug' => $slug,
            'taxonomy_ids' => [(int)$catB['taxonomy']['id']],
            'expected_updated_at' => (string)$versionStmt->fetchColumn(),
        ]);
    } catch (Throwable $e) {
        $authorDenied = str_contains($e->getMessage(), 'authorization denied');
    }
    $check($authorDenied, 'author role is denied by the registry policy row');

    $setIdentity($tenantA, $admin);
    $audit = $db->prepare("SELECT new_data, old_data FROM audit_logs WHERE module = 'cms-akira-core' AND action = 'akira.post.set_taxonomies' AND entity_id = ? ORDER BY id");
    $audit->execute([(string)$created['post']['id']]);
    $auditRows = $audit->fetchAll(PDO::FETCH_ASSOC);
    $auditCount = count($auditRows);
    $firstNew = json_decode((string)($auditRows[0]['new_data'] ?? '{}'), true);
    $firstOld = json_decode((string)($auditRows[0]['old_data'] ?? '{}'), true);
    $expectedFirst = [(int)$catA['taxonomy']['id'], (int)$catB['taxonomy']['id']];
    $check(
        $auditCount >= 4
        && is_array($firstNew) && ($firstNew['taxonomy_ids'] ?? null) === $expectedFirst
        && is_array($firstOld) && ($firstOld['taxonomy_ids'] ?? null) === [],
        'every assignment commits durable audit evidence with before/after id sets'
    );

    // Published read still carries the additive keys (public shape unchanged).
    $versionStmt->execute([$tenantA, $slug]);
    $publish = $call('akira.post.publish@1', [
        'idempotency_key' => $keys[] = $prefix . '-publish',
        'slug' => $slug,
        'expected_updated_at' => (string)$versionStmt->fetchColumn(),
    ]);
    $versionStmt->execute([$tenantA, $slug]);
    $call('akira.post.set_taxonomies@1', [
        'idempotency_key' => $keys[] = $prefix . '-publish-assign',
        'slug' => $slug,
        'taxonomy_ids' => [(int)$catA['taxonomy']['id'], (int)$catB['taxonomy']['id']],
        'expected_updated_at' => (string)$versionStmt->fetchColumn(),
    ]);
    $publishedGet = $call('akira.post.get@1', ['slug' => $slug]);
    $check(
        ($publish['post']['status'] ?? '') === 'published'
        && ($publishedGet['ok'] ?? false) === true
        && count($publishedGet['data']['categories'] ?? []) === 2
        && ($publishedGet['data']['title'] ?? '') === 'Assignment Contract',
        'published post.get still projects assignments additively after publish'
    );

    $appLog = (string)@file_get_contents($root . '/storage/logs/app.log');
    $errorLog = (string)@file_get_contents($root . '/storage/logs/error.log');
    $check(
        !str_contains($appLog, '[error]') && trim($errorLog) === '',
        'post taxonomy mutation run has no error or invalidation-failure log',
        trim($errorLog)
    );
} catch (Throwable $e) {
    $check(false, 'post taxonomy scenario completes', $e::class . ': ' . $e->getMessage());
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    try {
        $db->prepare('DELETE FROM cms_akira_post_taxonomies WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare('DELETE FROM cms_akira_posts WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare('DELETE FROM cms_akira_post_revisions WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare('DELETE FROM cms_akira_taxonomies WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare('DELETE FROM kernel_idempotency_keys WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare("DELETE FROM audit_logs WHERE module = 'cms-akira-core' AND action = 'akira.post.set_taxonomies'")->execute();
    } catch (Throwable $e) {
        $check(false, 'post taxonomy fixture cleanup succeeds', $e->getMessage());
    }
    app()->tenant()->setTenantId($originalTenant);
    kernel_request_context_delete('tenant_id');
    @file_put_contents($root . '/storage/logs/app.log', '');
    @file_put_contents($root . '/storage/logs/error.log', '');
}

echo "\nCMS Akira P1 post taxonomy: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
