<?php

/** CMS Akira P1 governed taxonomy (category/tag) mutation contract. */

declare(strict_types=1);

use Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry;

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
        'akira.taxonomy.create@1', 'akira.taxonomy.update@1', 'akira.taxonomy.delete@1',
    ], true)) {
        $meta = ['requires_protocol' => 'v2', 'effects' => ['invalidates' => ['entity.list.taxonomy']]];
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
app()->entityAuthority()->registerAuthority('taxonomy', 'cms-akira-core', ['authority' => true]);

$tenantA = (int) app()->tenant()->current();
$tenantB = 992102;
$originalTenant = app()->tenant()->current();
$db = app()->db();
$prefix = 'tax-' . bin2hex(random_bytes(6));
$keys = [];
$setIdentity = static function (int $tenant, array $user): void {
    app()->tenant()->setTenantId($tenant);
    kernel_request_context_set('tenant_id', $tenant);
    app()->setUser($user);
};
$admin = ['id' => 999010, 'role' => 'admin'];
$call = static function (string $capability, array $payload, ?array $user = null): array {
    $user ??= app()->user() ?? [];
    return app()->cap()->call($capability, $payload, [
        'caller' => ['module' => 'cms-akira-shell', 'user' => $user],
        'mode' => 'first',
    ]);
};
$thrownStatus = static function (Throwable $error): ?int {
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        if ($cursor instanceof CacTaxonomyMutationException) {
            return $cursor->httpStatus;
        }
    }
    return null;
};

@file_put_contents($root . '/storage/logs/app.log', '');
@file_put_contents($root . '/storage/logs/error.log', '');

try {
    echo "=== CMS Akira P1 governed taxonomy mutation path ===\n";
    $setIdentity($tenantA, $admin);

    $manifest = kernelReadJsonFile(dirname(__DIR__) . '/module.json');
    $exposes = [];
    foreach ($manifest['capabilities']['exposes'] as $expose) {
        $exposes[$expose['id']] = $expose;
    }
    foreach ([
        'akira.taxonomy.create@1', 'akira.taxonomy.update@1', 'akira.taxonomy.delete@1',
    ] as $id) {
        $entry = $exposes[$id] ?? [];
        $check(($entry['requires_protocol'] ?? '') === 'v2', "{$id} declares protocol v2");
        $check(($entry['effects']['invalidates'] ?? null) === ['entity.list.taxonomy'], "{$id} declares one canonical invalidation tag");
    }
    foreach ([
        'akira.taxonomy.get@1', 'akira.taxonomy.list@1',
    ] as $id) {
        $entry = $exposes[$id] ?? [];
        $check(($entry['requires_protocol'] ?? '') === '' && !isset($entry['effects']), "{$id} stays ungoverned (no protocol, no effects)");
    }
    $check(($manifest['entities']['taxonomy']['authority'] ?? false) === true
        && app()->entityAuthority()->isAuthoritative('taxonomy', 'cms-akira-core'), 'Taxonomy Entity Authority is registered');
    $check(in_array('cms_akira_taxonomies', $manifest['owns_tables'] ?? [], true)
        && in_array('cms_akira_taxonomies', $manifest['reads_tables'] ?? [], true), 'taxonomy table declared as owned and read by core');
    $check(in_array('database/migrations/004_create_taxonomies.sql', $manifest['migrations'] ?? [], true), 'taxonomy migration registered');

    $policyDb = new CapabilityAuthorizationRegistry($db);
    foreach ([
        'akira.taxonomy.create@1', 'akira.taxonomy.update@1', 'akira.taxonomy.delete@1',
    ] as $id) {
        $check(
            $policyDb->hasPolicyFor($id, '1', 'cms-akira-core')
            && $policyDb->requiresProtocol($id, '1', 'cms-akira-core') === 'v2',
            "activation seeds idempotent taxonomy policy for {$id}"
        );
    }
    $policyRows = $db->query("SELECT capability_id, caller_module, allowed_roles FROM capability_authorization_policies WHERE provider = 'cms-akira-core' AND capability_id LIKE 'akira.taxonomy.%' AND is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    $policyRoles = array_column($policyRows, 'allowed_roles', 'capability_id');
    $policyCallers = array_column($policyRows, 'caller_module', 'capability_id');
    $check(count($policyRows) === 4
        && ($policyRoles['akira.taxonomy.list@1'] ?? '') === 'contributor,author,editor,admin,administrator,superadmin'
        && ($policyRoles['akira.taxonomy.create@1'] ?? '') === 'admin,editor,administrator,superadmin'
        && ($policyRoles['akira.taxonomy.update@1'] ?? '') === 'admin,editor,administrator,superadmin'
        && ($policyRoles['akira.taxonomy.delete@1'] ?? '') === 'admin,editor,administrator,superadmin'
        && ($policyCallers['akira.taxonomy.create@1'] ?? '') === 'cms-akira-core,cms-akira-shell'
        && ($policyCallers['akira.taxonomy.delete@1'] ?? '') === 'cms-akira-core,cms-akira-shell', 'taxonomy policies bind editor+ roles and the shell caller');
    $readPolicies = $db->query("SELECT COUNT(*) FROM capability_authorization_policies WHERE provider = 'cms-akira-core' AND capability_id LIKE 'akira.taxonomy.%' AND is_active = 1 AND (capability_id LIKE '%.list@1' OR capability_id LIKE '%.get@1')")->fetchColumn();
    $check((int)$readPolicies === 1, 'taxonomy list carries the active shell read policy');

    $createPayload = [
        'idempotency_key' => $keys[] = $prefix . '-create',
        'type' => 'category',
        'name' => 'Product News',
        'slug' => 'product-news',
    ];
    $created = $call('akira.taxonomy.create@1', $createPayload);
    $check(($created['ok'] ?? false) === true && ($created['taxonomy']['type'] ?? '') === 'category'
        && ($created['taxonomy']['slug'] ?? '') === 'product-news', 'admin creates a category term');
    $replayed = $call('akira.taxonomy.create@1', $createPayload);
    $count = $db->prepare('SELECT COUNT(*) FROM cms_akira_taxonomies WHERE tenant_id = ? AND type = ? AND slug = ?');
    $count->execute([$tenantA, 'category', 'product-news']);
    $check($replayed === $created && (int)$count->fetchColumn() === 1, 'same key replays stored outcome with one write');

    $versionStmt = $db->prepare('SELECT updated_at FROM cms_akira_taxonomies WHERE tenant_id = ? AND type = ? AND slug = ?');
    $versionStmt->execute([$tenantA, 'category', 'product-news']);
    $updated = $call('akira.taxonomy.update@1', [
        'idempotency_key' => $keys[] = $prefix . '-update',
        'id' => (int)$created['taxonomy']['id'],
        'name' => 'Product Newsroom',
        'slug' => 'product-newsroom',
        'expected_updated_at' => (string)$versionStmt->fetchColumn(),
    ]);
    $check(($updated['operation'] ?? '') === 'update' && ($updated['taxonomy']['slug'] ?? '') === 'product-newsroom', 'term rename commits through its governed update');
    $renamedRow = $db->prepare('SELECT name, slug FROM cms_akira_taxonomies WHERE tenant_id = ? AND id = ?');
    $renamedRow->execute([$tenantA, (int)$created['taxonomy']['id']]);
    $check($renamedRow->fetch(PDO::FETCH_ASSOC) === ['name' => 'Product Newsroom', 'slug' => 'product-newsroom'], 'renamed row reflects the committed write');

    $versionStmt->execute([$tenantA, 'category', 'product-newsroom']);
    $childKey = $keys[] = $prefix . '-child';
    $child = $call('akira.taxonomy.create@1', [
        'idempotency_key' => $childKey,
        'type' => 'category',
        'name' => 'Releases',
        'slug' => 'releases',
        'parent_id' => (int)$created['taxonomy']['id'],
    ]);
    $check(($child['ok'] ?? false) === true, 'category term nests under a same-tenant same-type parent');

    $crossTypeParent = false;
    try {
        $call('akira.taxonomy.create@1', [
            'idempotency_key' => $keys[] = $prefix . '-cross-type',
            'type' => 'tag',
            'name' => 'Broken Nest',
            'slug' => 'broken-nest',
            'parent_id' => (int)$created['taxonomy']['id'],
        ]);
    } catch (Throwable $e) {
        $crossTypeParent = $thrownStatus($e) === 422;
    }
    $check($crossTypeParent, 'tag cannot nest under a category parent');

    $staleDenied = false;
    try {
        $call('akira.taxonomy.update@1', [
            'idempotency_key' => $keys[] = $prefix . '-stale',
            'id' => (int)$created['taxonomy']['id'],
            'name' => 'Stale',
            'slug' => 'stale',
            'expected_updated_at' => '2000-01-01 00:00:00',
        ]);
    } catch (Throwable $e) {
        $staleDenied = $thrownStatus($e) === 409;
    }
    $check($staleDenied, 'optimistic concurrency rejects a stale version');

    $spoofDenied = false;
    try {
        $call('akira.taxonomy.create@1', [
            'idempotency_key' => $keys[] = $prefix . '-spoof',
            'tenant_id' => $tenantB,
            'type' => 'tag',
            'name' => 'Spoof',
            'slug' => 'spoof',
        ]);
    } catch (Throwable $e) {
        $spoofDenied = $thrownStatus($e) === 422;
    }
    $check($spoofDenied, 'payload tenant spoofing is rejected before a write');

    $badSlug = false;
    try {
        $call('akira.taxonomy.create@1', [
            'idempotency_key' => $keys[] = $prefix . '-bad-slug',
            'type' => 'tag',
            'name' => 'Bad Slug',
            'slug' => 'Bad_Slug!',
        ]);
    } catch (Throwable $e) {
        $badSlug = $thrownStatus($e) === 422;
    }
    $check($badSlug, 'non-canonical slugs are rejected');

    $duplicate = false;
    try {
        $call('akira.taxonomy.create@1', [
            'idempotency_key' => $keys[] = $prefix . '-dup',
            'type' => 'category',
            'name' => 'Product Newsroom Again',
            'slug' => 'product-newsroom',
        ]);
    } catch (Throwable $e) {
        $duplicate = $thrownStatus($e) === 422;
    }
    $check($duplicate, 'duplicate (tenant, type, slug) is rejected as a clean 422');

    $setIdentity($tenantB, $admin);
    $tenantDenied = false;
    $tenantDenyMessage = '';
    try {
        $call('akira.taxonomy.delete@1', [
            'idempotency_key' => $keys[] = $prefix . '-tenant-b',
            'id' => (int)$created['taxonomy']['id'],
            'expected_updated_at' => '2000-01-01 00:00:00',
        ]);
    } catch (Throwable $e) {
        $tenantDenied = true;
        $tenantDenyMessage = $e->getMessage();
    }
    $setIdentity($tenantA, $admin);
    $check($tenantDenied, 'tenant B cannot delete tenant A term by id (denied at the governed seam)', $tenantDenyMessage);

    app()->setUser(['id' => 999011, 'role' => 'editor']);
    $editorCreate = $call('akira.taxonomy.create@1', [
        'idempotency_key' => $keys[] = $prefix . '-editor-create',
        'type' => 'tag',
        'name' => 'Editorial',
        'slug' => 'editorial',
    ]);
    $check(($editorCreate['ok'] ?? false) === true, 'editor role is allowed by the seeded taxonomy policy');

    app()->setUser(['id' => 999012, 'role' => 'author']);
    $authorDenied = false;
    try {
        $call('akira.taxonomy.create@1', [
            'idempotency_key' => $keys[] = $prefix . '-author-denied',
            'type' => 'tag',
            'name' => 'Nope',
            'slug' => 'nope',
        ]);
    } catch (Throwable $e) {
        $authorDenied = str_contains($e->getMessage(), 'authorization denied');
    }
    $check($authorDenied, 'author role is denied by the registry policy row');

    $setIdentity($tenantA, $admin);
    $audit = $db->prepare("SELECT action, COUNT(*) FROM audit_logs WHERE module = 'cms-akira-core' AND entity_type = 'taxonomy' AND entity_id = ? GROUP BY action");
    $audit->execute([(string)$created['taxonomy']['id']]);
    $auditCounts = $audit->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    $check(
        ($auditCounts['akira.taxonomy.create'] ?? 0) === 1 && ($auditCounts['akira.taxonomy.update'] ?? 0) === 1,
        'every governed create/update commits durable audit evidence'
    );

    $versionStmt->execute([$tenantA, 'category', 'product-newsroom']);
    $deleted = $call('akira.taxonomy.delete@1', [
        'idempotency_key' => $keys[] = $prefix . '-delete-root',
        'id' => (int)$created['taxonomy']['id'],
        'expected_updated_at' => (string)$versionStmt->fetchColumn(),
    ]);
    $deletedRow = $db->prepare('SELECT COUNT(*) FROM cms_akira_taxonomies WHERE tenant_id = ? AND id = ?');
    $deletedRow->execute([$tenantA, (int)$created['taxonomy']['id']]);
    $orphanRow = $db->prepare('SELECT parent_id FROM cms_akira_taxonomies WHERE tenant_id = ? AND id = ?');
    $orphanRow->execute([$tenantA, (int)$child['taxonomy']['id']]);
    $check(
        ($deleted['operation'] ?? '') === 'delete' && (int)$deletedRow->fetchColumn() === 0 && $orphanRow->fetchColumn() === null,
        'delete removes the row and orphans its children to the root'
    );
    $deleteAudit = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE module = 'cms-akira-core' AND action = 'akira.taxonomy.delete' AND entity_id = ?");
    $deleteAudit->execute([(string)$created['taxonomy']['id']]);
    $check((int)$deleteAudit->fetchColumn() === 1, 'delete commits durable audit evidence');

    $list = $call('akira.taxonomy.list@1', ['type' => 'tag', 'limit' => 50]);
    $tags = array_column(is_array($list['rows'] ?? null) ? $list['rows'] : [], 'slug');
    $check(($list['ok'] ?? false) === true && in_array('editorial', $tags, true), 'governed list read returns the committed tag');

    $get = $call('akira.taxonomy.get@1', ['type' => 'tag', 'slug' => 'editorial']);
    $check(($get['ok'] ?? false) === true && ($get['data']['name'] ?? '') === 'Editorial', 'governed get read resolves one term');

    $appLog = (string)@file_get_contents($root . '/storage/logs/app.log');
    $errorLog = (string)@file_get_contents($root . '/storage/logs/error.log');
    $check(
        !str_contains($appLog, '[error]') && trim($errorLog) === '',
        'taxonomy mutation run has no error or invalidation-failure log',
        trim($errorLog)
    );
} catch (Throwable $e) {
    $check(false, 'taxonomy scenario completes', $e::class . ': ' . $e->getMessage());
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    try {
        $db->prepare('DELETE FROM cms_akira_taxonomies WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare('DELETE FROM kernel_idempotency_keys WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare("DELETE FROM audit_logs WHERE module = 'cms-akira-core' AND entity_type = 'taxonomy'")->execute();
    } catch (Throwable $e) {
        $check(false, 'taxonomy fixture cleanup succeeds', $e->getMessage());
    }
    app()->tenant()->setTenantId($originalTenant);
    kernel_request_context_delete('tenant_id');
    @file_put_contents($root . '/storage/logs/app.log', '');
    @file_put_contents($root . '/storage/logs/error.log', '');
}

echo "\nCMS Akira P1 taxonomy: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
