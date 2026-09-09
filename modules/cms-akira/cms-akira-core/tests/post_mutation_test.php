<?php

/** CMS Akira P2 mutation, governance, idempotency, audit and freshness gate. */

declare(strict_types=1);

use Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry;
use Ikabud\Kernel\Http\CsrfManager;

$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = 'akiracms.test';
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
cacRegisterPostEntityViews(app()->entityViews());

$tenantA = (int) app()->tenant()->current();
$tenantB = 992102;
$originalTenant = app()->tenant()->current();
$db = app()->db();
$prefix = 'p2-' . bin2hex(random_bytes(6));
$keys = [];
$setIdentity = static function (int $tenant, array $user): void {
    app()->tenant()->setTenantId($tenant);
    kernel_request_context_set('tenant_id', $tenant);
    app()->setUser($user);
};
$admin = ['id' => 999001, 'role' => 'admin'];
$call = static function (string $capability, array $payload, ?array $user = null): array {
    $user ??= app()->user() ?? [];
    return app()->cap()->call($capability, $payload, [
        'caller' => ['module' => 'cms-akira-core', 'user' => $user],
        'mode' => 'first',
    ]);
};
$thrownStatus = static function (Throwable $error): ?int {
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        if ($cursor instanceof CacPostMutationException) {
            return $cursor->httpStatus;
        }
    }
    return null;
};

@file_put_contents($root . '/storage/logs/app.log', '');
@file_put_contents($root . '/storage/logs/error.log', '');

try {
    echo "=== CMS Akira P2 governed mutation path ===\n";
    $setIdentity($tenantA, $admin);

    $manifest = kernelReadJsonFile(dirname(__DIR__) . '/module.json');
    $exposes = [];
    foreach ($manifest['capabilities']['exposes'] as $expose) {
        $exposes[$expose['id']] = $expose;
    }
    foreach ([
        'akira.post.create@1', 'akira.post.update@1', 'akira.post.publish@1',
        'akira.post.unpublish@1', 'akira.post.delete@1',
    ] as $id) {
        $entry = $exposes[$id] ?? [];
        $check(($entry['requires_protocol'] ?? '') === 'v2', "{$id} declares protocol v2");
        $check(($entry['effects']['invalidates'] ?? null) === ['entity.list.post'], "{$id} declares one canonical invalidation tag");
    }
    $check(($manifest['entities']['post']['authority'] ?? false) === true
        && app()->entityAuthority()->isAuthoritative('post', 'cms-akira-core'), 'Post Entity Authority is registered');

    $policyDb = new CapabilityAuthorizationRegistry($db);
    $check(
        $policyDb->hasPolicyFor('akira.post.create@1', '1', 'cms-akira-core')
        && $policyDb->requiresProtocol('akira.post.delete@1', '1', 'cms-akira-core') === 'v2',
        'activation seeds idempotent admin mutation policies'
    );
    $policyRows = $db->query("SELECT capability_id, caller_module, allowed_roles FROM capability_authorization_policies WHERE provider = 'cms-akira-core' AND capability_id LIKE 'akira.post.%'")->fetchAll(PDO::FETCH_ASSOC);
    $policyRoles = array_column($policyRows, 'allowed_roles', 'capability_id');
    $policyCallers = array_column($policyRows, 'caller_module', 'capability_id');
    // P1 increment 3 added the akira.post.set_taxonomies@1 assignment policy and
    // P1 increment 4 adds the akira.post.revision.revert@1 revert policy to the
    // akira.post.* family, so the governed post policy set is now seven rows
    // (five lifecycle + one assignment + one revision revert). The lifecycle
    // rows' role/caller expectations below are unchanged.
    $check(count($policyRows) === 7
        && ($policyRoles['akira.post.create@1'] ?? '') === 'contributor,author,editor,admin,administrator,superadmin'
        && ($policyRoles['akira.post.update@1'] ?? '') === 'contributor,author,editor,admin,administrator,superadmin'
        && ($policyRoles['akira.post.delete@1'] ?? '') === 'admin'
        && ($policyRoles['akira.post.set_taxonomies@1'] ?? '') === 'admin,editor,administrator,superadmin'
        && ($policyRoles['akira.post.revision.revert@1'] ?? '') === 'admin,editor,administrator,superadmin'
        && ($policyCallers['akira.post.create@1'] ?? '') === 'cms-akira-core,cms-akira-shell'
        && ($policyCallers['akira.post.publish@1'] ?? '') === 'cms-akira-core'
        && ($policyCallers['akira.post.set_taxonomies@1'] ?? '') === 'cms-akira-core,cms-akira-shell'
        && ($policyCallers['akira.post.revision.revert@1'] ?? '') === 'cms-akira-core,cms-akira-shell', 'registry policy binds roles and actual production callers');

    $routes = require dirname(__DIR__) . '/routes.php';
    $handlersSource = (string)file_get_contents(dirname(__DIR__) . '/handlers.php');
    $check(
        ($routes['POST']['/api/v1/cms-akira/posts'] ?? '') === 'cms-akira-core:apiCmsAkiraPostCreate'
        && ($routes['PUT']['/api/v1/cms-akira/posts/{slug}'] ?? '') === 'cms-akira-core:apiCmsAkiraPostUpdate'
        && ($routes['POST']['/api/v1/cms-akira/posts/{slug}/publish'] ?? '') === 'cms-akira-core:apiCmsAkiraPostPublish'
        && ($routes['POST']['/api/v1/cms-akira/posts/{slug}/unpublish'] ?? '') === 'cms-akira-core:apiCmsAkiraPostUnpublish'
        && ($routes['DELETE']['/api/v1/cms-akira/posts/{slug}'] ?? '') === 'cms-akira-core:apiCmsAkiraPostDelete',
        'named create, update, and lifecycle routes are active'
    );
    $check(str_contains($handlersSource, 'cacPostEnforceMutationCsrf();'), 'mutation handlers enforce session CSRF while accepting kernel-validated Bearer requests');
    $csrfDenied = false;
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    CsrfManager::enforce(static function (array $data) use (&$csrfDenied): void {
        $csrfDenied = ($data['ok'] ?? true) === false;
    });
    $_SERVER['HTTP_X_CSRF_TOKEN'] = CsrfManager::token();
    $csrfAccepted = true;
    CsrfManager::enforce(static function () use (&$csrfAccepted): void {
        $csrfAccepted = false;
    });
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    $check($csrfDenied && $csrfAccepted, 'kernel CSRF rejects missing and accepts matching tokens');

    $createKey = $keys[] = $prefix . '-create';
    $createPayload = [
        'idempotency_key' => $createKey,
        'slug' => 'p2-fresh-post',
        'title' => 'Before',
        'subtitle' => 'P2',
        'content' => 'Before body',
        'image' => '/media/p2.jpg',
    ];
    $created = $call('akira.post.create@1', $createPayload);
    $check(($created['ok'] ?? false) === true && ($created['post']['status'] ?? '') === 'draft', 'admin creates a draft Post');
    $replayed = $call('akira.post.create@1', $createPayload);
    $count = $db->prepare('SELECT COUNT(*) FROM cms_akira_posts WHERE tenant_id = ? AND slug = ?');
    $count->execute([$tenantA, 'p2-fresh-post']);
    $check($replayed === $created && (int)$count->fetchColumn() === 1, 'same key replays stored outcome with one write');

    $conflict = false;
    try {
        $changed = $createPayload;
        $changed['title'] = 'Conflict';
        $call('akira.post.create@1', $changed);
    } catch (Throwable $e) {
        $conflict = $thrownStatus($e) === 409;
    }
    $check($conflict, 'same key with a different canonical payload is rejected as 409');

    $versionStmt = $db->prepare('SELECT updated_at FROM cms_akira_posts WHERE tenant_id = ? AND slug = ?');
    $versionStmt->execute([$tenantA, 'p2-fresh-post']);
    $publishPayload = [
        'idempotency_key' => $keys[] = $prefix . '-publish',
        'slug' => 'p2-fresh-post',
        'expected_updated_at' => (string)$versionStmt->fetchColumn(),
    ];
    $published = $call('akira.post.publish@1', $publishPayload);
    $check(($published['post']['status'] ?? '') === 'published' && $call('akira.post.publish@1', $publishPayload) === $published, 'publish transitions once and replays idempotently');

    $before = app()->entityViews()->resolveDetail('post', 'p2-fresh-post', 'detail');
    $fragmentStore = app()->templates()->fragmentStore();
    $fragmentStore->put($prefix . '-detail-fragment', 'Before HTML', ['entity.detail.post'], 300, (string)$tenantA);
    $updateKey = $keys[] = $prefix . '-update';
    $versionStmt->execute([$tenantA, 'p2-fresh-post']);
    $updated = $call('akira.post.update@1', [
        'idempotency_key' => $updateKey,
        'slug' => 'p2-fresh-post',
        'title' => 'After',
        'content' => 'After body',
        'expected_updated_at' => (string)$versionStmt->fetchColumn(),
    ]);
    $fragmentInvalidated = $fragmentStore->tryGet($prefix . '-detail-fragment', ['entity.detail.post'], (string)$tenantA) === null;
    $after = app()->entityViews()->resolveDetail('post', 'p2-fresh-post', 'detail');
    $check(
        $fragmentInvalidated && ($before['entity']['title'] ?? '') === 'Before' && ($after['entity']['title'] ?? '') === 'After',
        'cache-then-mutate-then-render returns fresh content'
    );
    $check(($updated['operation'] ?? '') === 'update', 'update returns its committed outcome');

    $audit = $db->prepare("SELECT new_data FROM audit_logs WHERE module = 'cms-akira-core' AND action = 'akira.post.update' AND entity_id = ? ORDER BY id DESC LIMIT 1");
    $audit->execute([(string)$updated['post']['id']]);
    $auditPayload = json_decode((string)$audit->fetchColumn(), true);
    $check(
        is_array($auditPayload) && ($auditPayload['correlation_id'] ?? '') === $updated['correlation_id'],
        'durable audit independently carries the returned correlation_id'
    );

    $database = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    $topology = $db->query("SELECT table_name, engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('cms_akira_posts','kernel_idempotency_keys','audit_logs')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $check(
        $database !== '' && count($topology) === 3 && count(array_filter($topology, static fn ($engine): bool => strtoupper((string)$engine) === 'INNODB')) === 3,
        'posts, idempotency and audit are InnoDB on the same application PDO/database'
    );

    $setIdentity($tenantB, $admin);
    $tenantDenied = false;
    try {
        $call('akira.post.update@1', ['idempotency_key' => $keys[] = $prefix . '-tenant-b', 'slug' => 'p2-fresh-post', 'title' => 'Stolen']);
    } catch (Throwable $e) {
        $tenantDenied = $thrownStatus($e) === 404;
    }
    $setIdentity($tenantA, $admin);
    $check($tenantDenied, 'tenant B cannot update tenant A by slug');

    $tenantSpoof = false;
    try {
        $call('akira.post.update@1', ['idempotency_key' => $keys[] = $prefix . '-spoof', 'slug' => 'p2-fresh-post', 'tenant_id' => $tenantB, 'title' => 'Stolen']);
    } catch (Throwable $e) {
        $tenantSpoof = $thrownStatus($e) === 422;
    }
    $check($tenantSpoof, 'payload tenant spoofing is rejected before a write');

    app()->setUser(['id' => 999002, 'role' => 'editor']);
    $editorDenied = false;
    try {
        $call('akira.post.publish@1', [
            'idempotency_key' => $keys[] = $prefix . '-editor',
            'slug' => 'p2-fresh-post',
            'expected_updated_at' => '2000-01-01 00:00:00',
        ]);
    } catch (Throwable $e) {
        $editorDenied = str_contains($e->getMessage(), 'authorization denied');
    }
    $check($editorDenied, 'CapabilityAuthorizationRegistry denies non-admin lifecycle calls');

    app()->setUser([]);
    $anonymousDenied = false;
    try {
        $call('akira.post.delete@1', [
            'idempotency_key' => $keys[] = $prefix . '-anonymous',
            'slug' => 'p2-fresh-post',
            'expected_updated_at' => '2000-01-01 00:00:00',
        ], []);
    } catch (Throwable $e) {
        $anonymousDenied = str_contains($e->getMessage(), 'authorization denied');
    }
    $check($anonymousDenied, 'CapabilityAuthorizationRegistry denies unauthenticated lifecycle calls');

    $setIdentity($tenantA, $admin);
    $jwtKey = $keys[] = $prefix . '-jwt';
    $jwtResult = $call('akira.post.create@1', [
        'idempotency_key' => $jwtKey, 'slug' => 'p2-jwt-ignored', 'title' => 'Kernel actor', 'content' => 'Safe',
        'role' => 'editor', 'user' => ['role' => 'editor'], 'jwt' => ['tenant_id' => $tenantB],
    ]);
    $jwtRow = $db->prepare('SELECT tenant_id, title FROM cms_akira_posts WHERE id = ?');
    $jwtRow->execute([$jwtResult['post']['id']]);
    $jwtStored = $jwtRow->fetch(PDO::FETCH_ASSOC);
    $check(
        (int)($jwtStored['tenant_id'] ?? 0) === $tenantA && ($jwtStored['title'] ?? '') === 'Kernel actor',
        'payload JWT/role claims are ignored in favor of kernel identity'
    );

    // A processing claim represents a concurrent winner. The contender must not write.
    $concurrentKey = $keys[] = $prefix . '-concurrent';
    $concurrentPayload = ['slug' => 'p2-concurrent', 'title' => 'One', 'content' => 'One'];
    $concurrentHash = app()->cap()->call('kernel.idempotency.hash@1', ['payload' => ['operation' => 'create', 'post' => $concurrentPayload]]);
    $claim = app()->cap()->call('kernel.idempotency.claim@1', [
        'key' => $concurrentKey, 'tenant_id' => $tenantA, 'payload_hash' => $concurrentHash, 'db' => $db, 'wait_cap_seconds' => 0,
    ]);
    $contenderDenied = false;
    try {
        $call('akira.post.create@1', ['idempotency_key' => $concurrentKey] + $concurrentPayload);
    } catch (Throwable $e) {
        $contenderDenied = $thrownStatus($e) === 425;
    }
    $concurrentCount = $db->prepare('SELECT COUNT(*) FROM cms_akira_posts WHERE tenant_id = ? AND slug = ?');
    $concurrentCount->execute([$tenantA, 'p2-concurrent']);
    $check(
        ($claim['status'] ?? '') === 'new' && $contenderDenied && (int)$concurrentCount->fetchColumn() === 0,
        'concurrent same-key contender maps to 425 and performs no second write'
    );
    app()->cap()->call('kernel.idempotency.release@1', ['key' => $concurrentKey, 'tenant_id' => $tenantA, 'db' => $db]);

    // Prime a cached row, fail with an idempotency conflict, then remove storage
    // directly. A surviving cached DTO proves the failed call did not invalidate.
    $failureSlug = 'p2-failure-cache';
    $failureKey = $keys[] = $prefix . '-failure-create';
    $call('akira.post.create@1', ['idempotency_key' => $failureKey, 'slug' => $failureSlug, 'title' => 'Cached', 'content' => 'Cached']);
    $versionStmt->execute([$tenantA, $failureSlug]);
    $call('akira.post.publish@1', [
        'idempotency_key' => $keys[] = $prefix . '-failure-publish',
        'slug' => $failureSlug,
        'expected_updated_at' => (string)$versionStmt->fetchColumn(),
    ]);
    $cached = app()->entityViews()->resolveDetail('post', $failureSlug, 'detail');
    $fragmentStore->put($prefix . '-failure-fragment', 'Cached HTML', ['entity.detail.post'], 300, (string)$tenantA);
    $failedMutation = false;
    $changedFailure = ['idempotency_key' => $failureKey, 'slug' => $failureSlug, 'title' => 'Different', 'content' => 'Cached'];
    try {
        $call('akira.post.create@1', $changedFailure);
    } catch (Throwable $e) {
        $failedMutation = $thrownStatus($e) === 409;
    }
    $cachedFragment = $fragmentStore->tryGet($prefix . '-failure-fragment', ['entity.detail.post'], (string)$tenantA);
    $check(
        $failedMutation && ($cached['entity']['title'] ?? '') === 'Cached' && $cachedFragment === 'Cached HTML',
        'failed mutation does not invalidate the entity cache'
    );

    $badKey = $keys[] = $prefix . '-bad-write';
    $badWrite = false;
    try {
        $call('akira.post.create@1', ['idempotency_key' => $badKey, 'slug' => 'p2-fresh-post', 'title' => 'Duplicate', 'content' => 'Duplicate']);
    } catch (Throwable) {
        $badWrite = true;
    }
    $idemCheck = $db->prepare('SELECT COUNT(*) FROM kernel_idempotency_keys WHERE tenant_id = ? AND idempotency_key_hash = ?');
    $idemCheck->execute([$tenantA, hash('sha256', $badKey)]);
    $check($badWrite && (int)$idemCheck->fetchColumn() === 0, 'failed write rolls back and releases its processing claim');

    $lifecycleSlug = 'p2-lifecycle';
    $call('akira.post.create@1', [
        'idempotency_key' => $keys[] = $prefix . '-life-create',
        'slug' => $lifecycleSlug,
        'title' => 'Lifecycle',
        'content' => 'Lifecycle body',
    ]);
    $versionStmt->execute([$tenantA, $lifecycleSlug]);
    $lifeVersion = (string)$versionStmt->fetchColumn();
    $call('akira.post.publish@1', [
        'idempotency_key' => $keys[] = $prefix . '-life-publish',
        'slug' => $lifecycleSlug,
        'expected_updated_at' => $lifeVersion,
    ]);
    $fragmentStore->put($prefix . '-life-fragment', 'Published', ['entity.detail.post'], 300, (string)$tenantA);
    $versionStmt->execute([$tenantA, $lifecycleSlug]);
    $unpublished = $call('akira.post.unpublish@1', [
        'idempotency_key' => $keys[] = $prefix . '-life-unpublish',
        'slug' => $lifecycleSlug,
        'expected_updated_at' => (string)$versionStmt->fetchColumn(),
    ]);
    $check(
        ($unpublished['post']['status'] ?? '') === 'draft'
        && app()->entityViews()->resolveDetail('post', $lifecycleSlug, 'detail')['entity'] === null
        && $fragmentStore->tryGet($prefix . '-life-fragment', ['entity.detail.post'], (string)$tenantA) === null,
        'unpublish hides the Post and invalidates fresh projections'
    );
    $invalidTransition = false;
    try {
        $versionStmt->execute([$tenantA, $lifecycleSlug]);
        $call('akira.post.unpublish@1', [
            'idempotency_key' => $keys[] = $prefix . '-life-deny',
            'slug' => $lifecycleSlug,
            'expected_updated_at' => (string)$versionStmt->fetchColumn(),
        ]);
    } catch (Throwable $e) {
        $invalidTransition = $thrownStatus($e) === 409;
    }
    $check($invalidTransition, 'invalid lifecycle transition is denied');

    $versionStmt->execute([$tenantA, $lifecycleSlug]);
    $call('akira.post.publish@1', [
        'idempotency_key' => $keys[] = $prefix . '-life-republish',
        'slug' => $lifecycleSlug,
        'expected_updated_at' => (string)$versionStmt->fetchColumn(),
    ]);
    $versionStmt->execute([$tenantA, $lifecycleSlug]);
    $deleted = $call('akira.post.delete@1', [
        'idempotency_key' => $keys[] = $prefix . '-life-delete',
        'slug' => $lifecycleSlug,
        'expected_updated_at' => (string)$versionStmt->fetchColumn(),
    ]);
    $deletedRow = $db->prepare('SELECT deleted_at FROM cms_akira_posts WHERE tenant_id = ? AND slug = ?');
    $deletedRow->execute([$tenantA, $lifecycleSlug]);
    $check(
        ($deleted['operation'] ?? '') === 'delete'
        && $deletedRow->fetchColumn() !== null
        && app()->entityViews()->resolveDetail('post', $lifecycleSlug, 'detail')['entity'] === null,
        'delete is audited soft deletion and disappears from public reads'
    );
    $lifeAudits = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE module = 'cms-akira-core' AND action IN ('akira.post.publish','akira.post.unpublish','akira.post.delete') AND entity_id = ?");
    $lifeAudits->execute([(string)$deleted['post']['id']]);
    $check((int)$lifeAudits->fetchColumn() === 4, 'every successful lifecycle mutation commits durable audit evidence');

    $staleDenied = false;
    try {
        $call('akira.post.update@1', [
            'idempotency_key' => $keys[] = $prefix . '-stale',
            'slug' => 'p2-fresh-post',
            'title' => 'Stale',
            'expected_updated_at' => '2000-01-01 00:00:00',
        ]);
    } catch (Throwable $e) {
        $staleDenied = $thrownStatus($e) === 409;
    }
    $check($staleDenied, 'optimistic concurrency rejects a stale version');

    $appLog = (string)@file_get_contents($root . '/storage/logs/app.log');
    $errorLog = (string)@file_get_contents($root . '/storage/logs/error.log');
    $check(
        !str_contains($appLog, '[error]') && !str_contains($appLog, 'failed open') && trim($errorLog) === '',
        'mutation run has no error or invalidation-failure log',
        trim($errorLog)
    );
} catch (Throwable $e) {
    $check(false, 'P2 scenario completes', $e::class . ': ' . $e->getMessage());
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    try {
        $db->prepare('DELETE FROM cms_akira_posts WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare('DELETE FROM cms_akira_post_revisions WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare('DELETE FROM kernel_idempotency_keys WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare("DELETE FROM audit_logs WHERE module = 'cms-akira-core' AND entity_type = 'post'")->execute();
        app()->templates()->fragmentStore()->flushAll((string)$tenantA);
        app()->templates()->fragmentStore()->flushAll((string)$tenantB);
    } catch (Throwable $e) {
        $check(false, 'P2 fixture cleanup succeeds', $e->getMessage());
    }
    app()->tenant()->setTenantId($originalTenant);
    kernel_request_context_delete('tenant_id');
    @file_put_contents($root . '/storage/logs/app.log', '');
    @file_put_contents($root . '/storage/logs/error.log', '');
}

echo "\nCMS Akira P2: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
