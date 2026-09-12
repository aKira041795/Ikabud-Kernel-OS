<?php

/** CMS Akira P1 post revision snapshot + governed revert contract. */

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
    } elseif ($id === 'akira.post.revision.revert@1' || $id === 'akira.post.set_taxonomies@1') {
        $meta = ['requires_protocol' => 'v2', 'effects' => ['invalidates' => ['entity.list.post', 'entity.detail.post']]];
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
requireCapabilityAuthorizationPolicies($db, [
    ['capability_id' => 'akira.post.create@1', 'provider' => 'cms-akira-core'],
    ['capability_id' => 'akira.post.update@1', 'provider' => 'cms-akira-core'],
    ['capability_id' => 'akira.post.delete@1', 'provider' => 'cms-akira-core'],
    ['capability_id' => 'akira.post.revision.revert@1', 'provider' => 'cms-akira-core'],
]);
$prefix = 'prev-' . bin2hex(random_bytes(6));
$slug = $prefix . '-post';
$deleteSlug = $prefix . '-delete-post';
$keys = [];
$setIdentity = static function (int $tenant, array $user): void {
    app()->tenant()->setTenantId($tenant);
    kernel_request_context_set('tenant_id', $tenant);
    app()->setUser($user);
};
$admin = ['id' => 999030, 'role' => 'admin'];
$call = static function (string $capability, array $payload, ?array $user = null): array {
    $user ??= app()->user() ?? [];
    return app()->cap()->call($capability, $payload, [
        'caller' => ['module' => 'cms-akira-core', 'user' => $user],
        'mode' => 'first',
        'breaker_threshold' => 1000,
    ]);
};
$thrownStatus = static function (Throwable $error): ?int {
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        if ($cursor instanceof CacPostRevisionMutationException) {
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
    echo "=== CMS Akira P1 post revisions path ===\n";
    $setIdentity($tenantA, $admin);

    $manifest = kernelReadJsonFile(dirname(__DIR__) . '/module.json');
    $exposes = [];
    foreach ($manifest['capabilities']['exposes'] as $expose) {
        $exposes[$expose['id']] = $expose;
    }
    $listEntry = $exposes['akira.post.revisions.list@1'] ?? [];
    $getEntry = $exposes['akira.post.revision.get@1'] ?? [];
    $revertEntry = $exposes['akira.post.revision.revert@1'] ?? [];
    $check(
        $listEntry !== [] && $getEntry !== [] && $revertEntry !== []
        && ($revertEntry['requires_protocol'] ?? '') === 'v2'
        && ($revertEntry['effects']['invalidates'] ?? null) === ['entity.list.post', 'entity.detail.post'],
        'manifest exposes post revision list/get/revert with governed revert (protocol v2 + list/detail invalidation)'
    );
    $check(
        ($listEntry['requires_protocol'] ?? '') === '' && ($getEntry['requires_protocol'] ?? '') === ''
        && !isset($listEntry['effects']) && !isset($getEntry['effects']),
        'revision read capabilities stay ungoverned'
    );
    $check(
        in_array('cms_akira_post_revisions', $manifest['owns_tables'] ?? [], true)
        && in_array('cms_akira_post_revisions', $manifest['reads_tables'] ?? [], true)
        && in_array('database/migrations/007_create_post_revisions.sql', $manifest['migrations'] ?? [], true),
        'core module registers the revision table ownership and migration'
    );

    $policyDb = new CapabilityAuthorizationRegistry($db);
    $revertPolicy = $db->query(
        "SELECT caller_module, allowed_roles, requires_protocol FROM capability_authorization_policies "
        . "WHERE provider = 'cms-akira-core' AND capability_id = 'akira.post.revision.revert@1' AND policy_version = 1"
    )->fetch(PDO::FETCH_ASSOC);
    $readPolicyCount = $db->query(
        "SELECT COUNT(*) FROM capability_authorization_policies WHERE provider = 'cms-akira-core' "
        . "AND capability_id IN ('akira.post.revisions.list@1','akira.post.revision.get@1') AND policy_version = 1"
    )->fetchColumn();
    $check(
        is_array($revertPolicy)
        && ($revertPolicy['allowed_roles'] ?? '') === 'admin,editor,administrator,superadmin'
        && ($revertPolicy['caller_module'] ?? '') === 'cms-akira-core,cms-akira-shell'
        && ($revertPolicy['requires_protocol'] ?? '') === 'v2'
        && $policyDb->requiresProtocol('akira.post.revision.revert@1', '1', 'cms-akira-core') === 'v2',
        'revert mutation policy binds admin/editor/administrator/superadmin roles and core+shell callers'
    );
    $check((int)$readPolicyCount === 0, 'revision reads seed no policy rows (ungoverned)');

    $columnCount = $db->query(
        "SELECT COUNT(*) FROM information_schema.columns "
        . "WHERE table_schema = DATABASE() AND table_name = 'cms_akira_post_revisions' "
        . "AND column_name IN ('tenant_id','post_slug','revision_no','content_snapshot','action','actor_user_id','correlation_id','created_at')"
    )->fetchColumn();
    $index = $db->query(
        "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() "
        . "AND table_name = 'cms_akira_post_revisions' AND index_name = 'uq_tenant_post_revision'"
    )->fetchColumn();
    $engine = $db->query(
        "SELECT engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'cms_akira_post_revisions'"
    )->fetchColumn();
    $check(
        (int)$columnCount === 8 && (int)$index > 0 && strtoupper((string)$engine) === 'INNODB',
        'revision table carries tenant/slug/revision-no/snapshot/action/actor/correlation/created columns, the unique triple, and InnoDB'
    );

    $versionStmt = $db->prepare('SELECT updated_at FROM cms_akira_posts WHERE tenant_id = ? AND slug = ?');
    /** @return array<string, mixed>|null */
    $snapshotOf = static function (int $revisionNo) use ($db, $tenantA, $slug): ?array {
        $stmt = $db->prepare('SELECT content_snapshot, action FROM cms_akira_post_revisions WHERE tenant_id = ? AND post_slug = ? AND revision_no = ? LIMIT 1');
        $stmt->execute([$tenantA, $slug, $revisionNo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $snapshot = json_decode((string)($row['content_snapshot'] ?? ''), true);
        return is_array($snapshot) ? ['snapshot' => $snapshot, 'action' => (string)$row['action']] : null;
    };

    $createPayload = [
        'idempotency_key' => $keys[] = $prefix . '-create',
        'slug' => $slug,
        'title' => 'Original title',
        'subtitle' => 'Original subtitle',
        'content' => 'Original body',
        'image' => '/media/original.jpg',
    ];
    $created = $call('akira.post.create@1', $createPayload);
    $check(($created['ok'] ?? false) === true && ($created['post']['status'] ?? '') === 'draft', 'admin creates a draft Post');
    $revisionOne = $snapshotOf(1);
    $check(
        is_array($revisionOne) && $revisionOne['action'] === 'create'
        && ($revisionOne['snapshot']['title'] ?? '') === 'Original title'
        && ($revisionOne['snapshot']['content'] ?? '') === 'Original body'
        && ($revisionOne['snapshot']['image'] ?? '') === '/media/original.jpg',
        'create records revision 1 with the new content snapshot'
    );
    $revCorrelation = $db->prepare('SELECT correlation_id FROM cms_akira_post_revisions WHERE tenant_id = ? AND post_slug = ? AND revision_no = 1');
    $revCorrelation->execute([$tenantA, $slug]);
    $check((string)$revCorrelation->fetchColumn() === (string)($created['correlation_id'] ?? ''), 'revision row carries the same correlation_id as its audit/outcome');

    $replayed = $call('akira.post.create@1', $createPayload);
    $revCount = $db->prepare('SELECT COUNT(*) FROM cms_akira_post_revisions WHERE tenant_id = ? AND post_slug = ?');
    $revCount->execute([$tenantA, $slug]);
    $check($replayed === $created && (int)$revCount->fetchColumn() === 1, 'same-key replay returns the stored outcome with no duplicate revision');

    $versionStmt->execute([$tenantA, $slug]);
    $updated = $call('akira.post.update@1', [
        'idempotency_key' => $keys[] = $prefix . '-update',
        'slug' => $slug,
        'title' => 'Revised title',
        'content' => 'Revised body',
        'expected_updated_at' => (string)$versionStmt->fetchColumn(),
    ]);
    $revisionTwo = $snapshotOf(2);
    $check(
        is_array($revisionTwo) && $revisionTwo['action'] === 'update'
        && ($revisionTwo['snapshot']['title'] ?? '') === 'Revised title'
        && ($revisionTwo['snapshot']['content'] ?? '') === 'Revised body'
        && ($revisionTwo['snapshot']['subtitle'] ?? '') === 'Original subtitle',
        'update records revision 2 with the resulting content'
    );

    $versionStmt->execute([$tenantA, $slug]);
    $published = $call('akira.post.publish@1', [
        'idempotency_key' => $keys[] = $prefix . '-publish',
        'slug' => $slug,
        'expected_updated_at' => (string)$versionStmt->fetchColumn(),
    ]);
    $revisionThree = $snapshotOf(3);
    $check(
        ($published['post']['status'] ?? '') === 'published' && is_array($revisionThree)
        && $revisionThree['action'] === 'publish'
        && ($revisionThree['snapshot']['content'] ?? '') === 'Revised body',
        'publish records revision 3 with the unchanged content at its lifecycle point'
    );

    // Revert to revision 1 must restore content only; status stays published.
    $versionStmt->execute([$tenantA, $slug]);
    $revertPayload = [
        'idempotency_key' => $keys[] = $prefix . '-revert',
        'slug' => $slug,
        'revision_no' => 1,
        'expected_updated_at' => (string)$versionStmt->fetchColumn(),
    ];
    $reverted = $call('akira.post.revision.revert@1', $revertPayload);
    $rowAfter = $db->prepare('SELECT title, subtitle, content, image, status FROM cms_akira_posts WHERE tenant_id = ? AND slug = ?');
    $rowAfter->execute([$tenantA, $slug]);
    $rowAfterData = $rowAfter->fetch(PDO::FETCH_ASSOC);
    $revisionFour = $snapshotOf(4);
    $check(
        ($reverted['operation'] ?? '') === 'revert' && ($reverted['post']['revision_no'] ?? 0) === 1
        && is_array($rowAfterData) && ($rowAfterData['title'] ?? '') === 'Original title'
        && ($rowAfterData['content'] ?? '') === 'Original body'
        && ($rowAfterData['image'] ?? '') === '/media/original.jpg'
        && ($rowAfterData['status'] ?? '') === 'published',
        'revert restores revision-1 content and does not touch post status'
    );
    $check(
        is_array($revisionFour) && $revisionFour['action'] === 'revert'
        && ($revisionFour['snapshot']['title'] ?? '') === 'Original title',
        'revert records a new revision (action=revert) of the restored content'
    );
    $revertAudit = $db->prepare(
        "SELECT old_data, new_data FROM audit_logs WHERE module = 'cms-akira-core' AND action = 'akira.post.revision.revert' "
        . "AND entity_id = ? ORDER BY id DESC LIMIT 1"
    );
    $revertAudit->execute([(string)$reverted['post']['id']]);
    $revertAuditRow = $revertAudit->fetch(PDO::FETCH_ASSOC);
    $revertOld = is_array($revertAuditRow) ? json_decode((string)($revertAuditRow['old_data'] ?? ''), true) : null;
    $revertNew = is_array($revertAuditRow) ? json_decode((string)($revertAuditRow['new_data'] ?? ''), true) : null;
    $check(
        is_array($revertOld) && ($revertOld['title'] ?? '') === 'Revised title'
        && is_array($revertNew) && ($revertNew['revision_no'] ?? 0) === 1
        && ($revertNew['correlation_id'] ?? '') === ($reverted['correlation_id'] ?? ''),
        'revert writes durable audit old/new data linked by correlation id'
    );

    $revCount->execute([$tenantA, $slug]);
    $revertReplay = $call('akira.post.revision.revert@1', $revertPayload);
    $revCount->execute([$tenantA, $slug]);
    $check($revertReplay === $reverted && (int)$revCount->fetchColumn() === 4, 'same-key revert replays its stored outcome with no duplicate revision');

    // The delete path snapshots the pre-delete content.
    $call('akira.post.create@1', [
        'idempotency_key' => $keys[] = $prefix . '-del-create',
        'slug' => $deleteSlug,
        'title' => 'Doomed title',
        'content' => 'Doomed body',
        'image' => null,
    ]);
    $versionStmt->execute([$tenantA, $deleteSlug]);
    $call('akira.post.update@1', [
        'idempotency_key' => $keys[] = $prefix . '-del-update',
        'slug' => $deleteSlug,
        'title' => 'Doomed title v2',
        'content' => 'Doomed body v2',
        'expected_updated_at' => (string)$versionStmt->fetchColumn(),
    ]);
    $versionStmt->execute([$tenantA, $deleteSlug]);
    $deleted = $call('akira.post.delete@1', [
        'idempotency_key' => $keys[] = $prefix . '-del-delete',
        'slug' => $deleteSlug,
        'expected_updated_at' => (string)$versionStmt->fetchColumn(),
    ]);
    $delRev = $db->prepare('SELECT content_snapshot, action FROM cms_akira_post_revisions WHERE tenant_id = ? AND post_slug = ? AND revision_no = 3');
    $delRev->execute([$tenantA, $deleteSlug]);
    $delRevRow = $delRev->fetch(PDO::FETCH_ASSOC);
    $delSnapshot = is_array($delRevRow) ? json_decode((string)($delRevRow['content_snapshot'] ?? ''), true) : null;
    $check(
        ($deleted['operation'] ?? '') === 'delete' && is_array($delRevRow) && ($delRevRow['action'] ?? '') === 'delete'
        && is_array($delSnapshot) && ($delSnapshot['title'] ?? '') === 'Doomed title v2'
        && ($delSnapshot['content'] ?? '') === 'Doomed body v2',
        'delete records a revision of the pre-delete content'
    );
    $revNotFound = false;
    try {
        $versionStmt->execute([$tenantA, $deleteSlug]);
        $call('akira.post.revision.revert@1', [
            'idempotency_key' => $keys[] = $prefix . '-del-revert',
            'slug' => $deleteSlug,
            'revision_no' => 1,
            'expected_updated_at' => (string)$versionStmt->fetchColumn(),
        ]);
    } catch (Throwable $e) {
        $revNotFound = $thrownStatus($e) === 404;
    }
    $check($revNotFound, 'reverting a soft-deleted post fails closed as not found');

    // Read surface: editorial participants list/get history.
    $listed = $call('akira.post.revisions.list@1', ['slug' => $slug, 'limit' => 10, 'offset' => 0]);
    $actions = array_column(is_array($listed['rows'] ?? null) ? $listed['rows'] : [], 'action');
    $check(
        ($listed['ok'] ?? false) === true && ($listed['total'] ?? 0) === 4
        && $actions === ['revert', 'publish', 'update', 'create'],
        'revision list returns newest-first history rows for the post'
    );
    $got = $call('akira.post.revision.get@1', ['slug' => $slug, 'revision_no' => 2]);
    $check(
        ($got['ok'] ?? false) === true && ($got['data']['action'] ?? '') === 'update'
        && ($got['data']['content_snapshot']['content'] ?? '') === 'Revised body',
        'revision get returns one snapshot row'
    );
    $badRevision = $call('akira.post.revision.get@1', ['slug' => $slug, 'revision_no' => 999]);
    $check(($badRevision['ok'] ?? false) === false && ($badRevision['error'] ?? '') === 'Post revision not found', 'unknown revision get fails closed');

    app()->setUser(['id' => 999031, 'role' => 'viewer']);
    $viewerList = $call('akira.post.revisions.list@1', ['slug' => $slug]);
    $check(($viewerList['ok'] ?? false) === false && str_contains((string)($viewerList['error'] ?? ''), 'editorial participants'), 'non-editorial roles cannot read revision history');
    app()->setUser(['id' => 999032, 'role' => 'author']);
    $authorDenied = false;
    try {
        $call('akira.post.revision.revert@1', [
            'idempotency_key' => $keys[] = $prefix . '-author',
            'slug' => $slug,
            'revision_no' => 1,
            'expected_updated_at' => '2000-01-01 00:00:00',
        ]);
    } catch (Throwable $e) {
        $authorDenied = str_contains($rootMessageOf($e), 'authorization denied');
    }
    $check($authorDenied, 'CapabilityAuthorizationRegistry denies author revision reverts');

    $setIdentity($tenantA, $admin);
    $spoofDenied = false;
    try {
        $call('akira.post.revision.revert@1', [
            'idempotency_key' => $keys[] = $prefix . '-spoof',
            'slug' => $slug,
            'revision_no' => 1,
            'expected_updated_at' => '2000-01-01 00:00:00',
            'tenant_id' => $tenantB,
        ]);
    } catch (Throwable $e) {
        $spoofDenied = $thrownStatus($e) === 422;
    }
    $check($spoofDenied, 'payload tenant spoofing is rejected before a write');

    $staleDenied = false;
    try {
        $call('akira.post.revision.revert@1', [
            'idempotency_key' => $keys[] = $prefix . '-stale',
            'slug' => $slug,
            'revision_no' => 1,
            'expected_updated_at' => '2000-01-01 00:00:00',
        ]);
    } catch (Throwable $e) {
        $staleDenied = $thrownStatus($e) === 409;
    }
    $revCount->execute([$tenantA, $slug]);
    $check($staleDenied && (int)$revCount->fetchColumn() === 4, 'stale expected version rejects the revert with no extra revision');

    // Tenant B cannot reach tenant A revision history (denied at the governed seam).
    $setIdentity($tenantB, $admin);
    $tenantBDenied = false;
    try {
        $tenantBList = $call('akira.post.revisions.list@1', ['slug' => $slug]);
        $tenantBDenied = ($tenantBList['ok'] ?? true) === false;
    } catch (Throwable) {
        $tenantBDenied = true; // bus/provider seam denies tenant B before any read
    }
    $setIdentity($tenantA, $admin);
    $check($tenantBDenied, 'tenant B cannot list tenant A revision history');

    $appLog = (string)@file_get_contents($root . '/storage/logs/app.log');
    $errorLog = (string)@file_get_contents($root . '/storage/logs/error.log');
    $check(!str_contains($appLog, '[error]') && trim($errorLog) === '', 'revision run leaves application/error logs free of errors', trim($errorLog));
} catch (Throwable $e) {
    $details = [];
    for ($cursor = $e; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        $details[] = $cursor::class . ': ' . $cursor->getMessage();
    }
    $check(false, 'P1 revision scenario completes', implode(' <- ', $details));
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    try {
        $db->prepare('DELETE FROM cms_akira_posts WHERE tenant_id IN (?, ?) AND slug LIKE ?')->execute([$tenantA, $tenantB, $prefix . '%']);
        $db->prepare('DELETE FROM cms_akira_post_revisions WHERE tenant_id IN (?, ?) AND post_slug LIKE ?')->execute([$tenantA, $tenantB, $prefix . '%']);
        $db->prepare('DELETE FROM kernel_idempotency_keys WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare("DELETE FROM audit_logs WHERE module = 'cms-akira-core' AND entity_type = 'post'")->execute();
        app()->templates()->fragmentStore()->flushAll((string)$tenantA);
    } catch (Throwable $e) {
        $check(false, 'P1 revision fixture cleanup succeeds', $e->getMessage());
    }
    app()->tenant()->setTenantId($originalTenant);
    kernel_request_context_delete('tenant_id');
    @file_put_contents($root . '/storage/logs/app.log', '');
    @file_put_contents($root . '/storage/logs/error.log', '');
}

echo "\nCMS Akira P1 revisions: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
