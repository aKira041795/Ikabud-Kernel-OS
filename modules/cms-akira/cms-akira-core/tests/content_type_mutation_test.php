<?php

/** CMS Akira P1 governed content-type registry (declared field schema) mutation contract. */

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
app()->entityAuthority()->registerAuthority('content_type', 'cms-akira-core', ['authority' => true]);

$tenantA = (int) app()->tenant()->current();
$tenantB = 992103;
$originalTenant = app()->tenant()->current();
$db = app()->db();
requireCapabilityAuthorizationPolicies($db, [
    ['capability_id' => 'akira.content_type.create@1', 'provider' => 'cms-akira-core'],
    ['capability_id' => 'akira.content_type.update@1', 'provider' => 'cms-akira-core'],
    ['capability_id' => 'akira.content_type.delete@1', 'provider' => 'cms-akira-core'],
]);
$prefix = 'ct-' . bin2hex(random_bytes(6));
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
        'caller' => ['module' => 'cms-akira-shell', 'user' => $user],
        'mode' => 'first',
    ]);
};
$thrownStatus = static function (Throwable $error): ?int {
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        if ($cursor instanceof CacContentTypeMutationException) {
            return $cursor->httpStatus;
        }
    }
    return null;
};

@file_put_contents($root . '/storage/logs/app.log', '');
@file_put_contents($root . '/storage/logs/error.log', '');

try {
    echo "=== CMS Akira P1 governed content-type registry mutation path ===\n";
    $setIdentity($tenantA, $admin);

    $manifest = kernelReadJsonFile(dirname(__DIR__) . '/module.json');
    $exposes = [];
    foreach ($manifest['capabilities']['exposes'] as $expose) {
        $exposes[$expose['id']] = $expose;
    }
    foreach ([
        'akira.content_type.create@1', 'akira.content_type.update@1', 'akira.content_type.delete@1',
    ] as $id) {
        $entry = $exposes[$id] ?? [];
        $check(($entry['requires_protocol'] ?? '') === 'v2', "{$id} declares protocol v2");
        $check(($entry['effects']['invalidates'] ?? null) === ['entity.list.content_type'], "{$id} declares one canonical invalidation tag");
    }
    foreach ([
        'akira.content_type.get@1', 'akira.content_type.list@1',
    ] as $id) {
        $entry = $exposes[$id] ?? [];
        $check(($entry['requires_protocol'] ?? '') === '' && !isset($entry['effects']), "{$id} stays ungoverned (no protocol, no effects)");
    }
    $check(($manifest['entities']['content_type']['authority'] ?? false) === true
        && app()->entityAuthority()->isAuthoritative('content_type', 'cms-akira-core'), 'Content type Entity Authority is registered');
    $check(in_array('cms_akira_content_types', $manifest['owns_tables'] ?? [], true)
        && in_array('cms_akira_content_types', $manifest['reads_tables'] ?? [], true), 'content types table declared as owned and read by core');
    $check(in_array('database/migrations/005_create_content_types.sql', $manifest['migrations'] ?? [], true), 'content types migration registered');

    $policyDb = new CapabilityAuthorizationRegistry($db);
    foreach ([
        'akira.content_type.create@1', 'akira.content_type.update@1', 'akira.content_type.delete@1',
    ] as $id) {
        $check(
            $policyDb->hasPolicyFor($id, '1', 'cms-akira-core')
            && $policyDb->requiresProtocol($id, '1', 'cms-akira-core') === 'v2',
            "activation seeds idempotent content type policy for {$id}"
        );
    }
    $policyRows = $db->query("SELECT capability_id, caller_module, allowed_roles FROM capability_authorization_policies WHERE provider = 'cms-akira-core' AND capability_id IN ('akira.content_type.create@1','akira.content_type.update@1','akira.content_type.delete@1') AND policy_version = 1")->fetchAll(PDO::FETCH_ASSOC);
    $policyRoles = array_column($policyRows, 'allowed_roles', 'capability_id');
    $policyCallers = array_column($policyRows, 'caller_module', 'capability_id');
    $check(count($policyRows) === 3
        && ($policyRoles['akira.content_type.create@1'] ?? '') === 'admin,editor,administrator,superadmin'
        && ($policyRoles['akira.content_type.update@1'] ?? '') === 'admin,editor,administrator,superadmin'
        && ($policyRoles['akira.content_type.delete@1'] ?? '') === 'admin,editor,administrator,superadmin'
        && ($policyCallers['akira.content_type.create@1'] ?? '') === 'cms-akira-core,cms-akira-shell'
        && ($policyCallers['akira.content_type.delete@1'] ?? '') === 'cms-akira-core,cms-akira-shell', 'content type policies bind editor+ roles and the shell caller');
    $readPolicies = $db->query("SELECT COUNT(*) FROM capability_authorization_policies WHERE provider = 'cms-akira-core' AND capability_id LIKE 'akira.content_type.%' AND requires_protocol = 'v2' AND (capability_id LIKE '%.list@1' OR capability_id LIKE '%.get@1')")->fetchColumn();
    $check((int)$readPolicies === 0, 'content type reads carry no protocol-v2 mutation policy rows');

    $schema = '{"fields":{"title":{"type":"text","label":"Title","required":true},"summary":{"type":"textarea","label":"Summary"},"views":{"type":"number","required":true},"featured":{"type":"boolean","label":"Featured"},"published":{"type":"date"},"category":{"type":"select"},"cover":{"type":"image","label":"Cover image"}}}';
    $createPayload = [
        'idempotency_key' => $keys[] = $prefix . '-create',
        'slug' => 'news',
        'label' => 'News article',
        'field_schema' => $schema,
    ];
    $created = $call('akira.content_type.create@1', $createPayload);
    $check(($created['ok'] ?? false) === true && ($created['content_type']['slug'] ?? '') === 'news', 'admin declares a content type with a full field schema');
    $replayed = $call('akira.content_type.create@1', $createPayload);
    $count = $db->prepare('SELECT COUNT(*) FROM cms_akira_content_types WHERE tenant_id = ? AND slug = ?');
    $count->execute([$tenantA, 'news']);
    $check($replayed === $created && (int)$count->fetchColumn() === 1, 'same key replays stored outcome with one write');

    $schemaStmt = $db->prepare('SELECT label, field_schema FROM cms_akira_content_types WHERE tenant_id = ? AND slug = ?');
    $schemaStmt->execute([$tenantA, 'news']);
    $storedRow = $schemaStmt->fetch(PDO::FETCH_ASSOC);
    $storedSchema = is_array($storedRow) ? (string)($storedRow['field_schema'] ?? '') : '';
    $decodedStored = json_decode($storedSchema, true);
    $storedFields = is_array($decodedStored) && is_array($decodedStored['fields'] ?? null) ? $decodedStored['fields'] : null;
    $storedTitle = is_array($storedFields) && is_array($storedFields['title'] ?? null) ? $storedFields['title'] : null;
    $check(is_array($storedRow) && ($storedRow['label'] ?? '') === 'News article', 'stored content type carries the declared label');
    $check(is_array($storedFields) && is_array($storedTitle) && ($storedTitle['type'] ?? '') === 'text'
        && ($storedTitle['required'] ?? false) === true
        && ($storedTitle['label'] ?? '') === 'Title'
        && count($storedFields) === 7, 'field_schema is stored as the canonical declared JSON object');

    $versionStmt = $db->prepare('SELECT updated_at FROM cms_akira_content_types WHERE tenant_id = ? AND slug = ?');
    $versionStmt->execute([$tenantA, 'news']);
    $updatedSchema = '{"fields":{"title":{"type":"text","required":true},"summary":{"type":"textarea"},"author":{"type":"text","label":"Author"}}}';
    $updated = $call('akira.content_type.update@1', [
        'idempotency_key' => $keys[] = $prefix . '-update',
        'id' => (int)$created['content_type']['id'],
        'label' => 'Newsroom item',
        'slug' => 'newsroom',
        'field_schema' => $updatedSchema,
        'expected_updated_at' => (string)$versionStmt->fetchColumn(),
    ]);
    $check(($updated['operation'] ?? '') === 'update' && ($updated['content_type']['slug'] ?? '') === 'newsroom', 'declared model update commits through its governed update');
    $updatedRow = $db->prepare('SELECT label, field_schema FROM cms_akira_content_types WHERE tenant_id = ? AND id = ?');
    $updatedRow->execute([$tenantA, (int)$created['content_type']['id']]);
    $updatedFields = $updatedRow->fetch(PDO::FETCH_ASSOC);
    $check(is_array($updatedFields) && ($updatedFields['label'] ?? '') === 'Newsroom item'
        && str_contains((string)($updatedFields['field_schema'] ?? ''), '"author"'), 'updated row reflects the committed declared model');

    // ── Declared-model validation on write ───────────────────────────────
    $invalidType = false;
    $invalidTypeMessage = '';
    try {
        $call('akira.content_type.create@1', [
            'idempotency_key' => $keys[] = $prefix . '-bad-type',
            'slug' => 'bad-field-type',
            'label' => 'Bad type',
            'field_schema' => '{"fields":{"title":{"type":"richtext"}}}',
        ]);
    } catch (Throwable $e) {
        $invalidType = $thrownStatus($e) === 422;
        $invalidTypeMessage = $e->getMessage();
    }
    $check($invalidType, 'unsupported field type is rejected as a clean 422', $invalidTypeMessage);

    $duplicateName = false;
    try {
        $call('akira.content_type.create@1', [
            'idempotency_key' => $keys[] = $prefix . '-dup-name',
            'slug' => 'dup-field-name',
            'label' => 'Duplicate name',
            'field_schema' => '{"fields":{"title":{"type":"text"},"title":{"type":"number"}}}',
        ]);
    } catch (Throwable $e) {
        $duplicateName = $thrownStatus($e) === 422;
    }
    $check($duplicateName, 'duplicate field names in the raw schema are rejected as a clean 422');

    $malformed = false;
    try {
        $call('akira.content_type.create@1', [
            'idempotency_key' => $keys[] = $prefix . '-malformed',
            'slug' => 'malformed-schema',
            'label' => 'Malformed',
            'field_schema' => '{"fields":{"title":{"type":"text",}}}',
        ]);
    } catch (Throwable $e) {
        $malformed = $thrownStatus($e) === 422;
    }
    $check($malformed, 'malformed field_schema JSON is rejected as a clean 422');

    $noFields = false;
    try {
        $call('akira.content_type.create@1', [
            'idempotency_key' => $keys[] = $prefix . '-no-fields',
            'slug' => 'no-fields',
            'label' => 'No fields',
            'field_schema' => '{"nope":true}',
        ]);
    } catch (Throwable $e) {
        $noFields = $thrownStatus($e) === 422;
    }
    $check($noFields, 'field_schema without a non-empty fields object is rejected as a clean 422');

    // A successful governed create resets the per-capability circuit breaker so
    // the negative-write assertions below never trip it (mirrors the taxonomy
    // suite which keeps create-capability failures under the breaker threshold).
    $reset = $call('akira.content_type.create@1', [
        'idempotency_key' => $keys[] = $prefix . '-reset',
        'slug' => 'fact-sheet',
        'label' => 'Fact sheet',
        'field_schema' => '{"fields":{"headline":{"type":"text","required":true}}}',
    ]);
    $check(($reset['ok'] ?? false) === true, 'governed create continues after rejected schemas');

    $badRequired = false;
    try {
        $call('akira.content_type.create@1', [
            'idempotency_key' => $keys[] = $prefix . '-bad-required',
            'slug' => 'bad-required',
            'label' => 'Bad required',
            'field_schema' => '{"fields":{"title":{"type":"text","required":"yes"}}}',
        ]);
    } catch (Throwable $e) {
        $badRequired = $thrownStatus($e) === 422;
    }
    $check($badRequired, 'non-boolean required member is rejected as a clean 422');

    $invalidRows = $db->prepare('SELECT COUNT(*) FROM cms_akira_content_types WHERE tenant_id = ? AND slug IN (?, ?, ?, ?, ?)');
    $invalidRows->execute([$tenantA, 'bad-field-type', 'dup-field-name', 'malformed-schema', 'no-fields', 'bad-required']);
    $check((int)$invalidRows->fetchColumn() === 0, 'invalid schemas never reach the tenant DB (no row)');

    $staleDenied = false;
    try {
        $call('akira.content_type.update@1', [
            'idempotency_key' => $keys[] = $prefix . '-stale',
            'id' => (int)$created['content_type']['id'],
            'label' => 'Stale',
            'slug' => 'stale',
            'field_schema' => $updatedSchema,
            'expected_updated_at' => '2000-01-01 00:00:00',
        ]);
    } catch (Throwable $e) {
        $staleDenied = $thrownStatus($e) === 409;
    }
    $check($staleDenied, 'optimistic concurrency rejects a stale version');

    $spoofDenied = false;
    try {
        $call('akira.content_type.create@1', [
            'idempotency_key' => $keys[] = $prefix . '-spoof',
            'tenant_id' => $tenantB,
            'slug' => 'spoof',
            'label' => 'Spoof',
            'field_schema' => '{"fields":{"title":{"type":"text"}}}',
        ]);
    } catch (Throwable $e) {
        $spoofDenied = $thrownStatus($e) === 422;
    }
    $check($spoofDenied, 'payload tenant spoofing is rejected before a write');

    $badSlug = false;
    try {
        $call('akira.content_type.create@1', [
            'idempotency_key' => $keys[] = $prefix . '-bad-slug',
            'slug' => 'Bad_Slug!',
            'label' => 'Bad Slug',
            'field_schema' => '{"fields":{"title":{"type":"text"}}}',
        ]);
    } catch (Throwable $e) {
        $badSlug = $thrownStatus($e) === 422;
    }
    $check($badSlug, 'non-canonical slugs are rejected');

    $duplicate = false;
    try {
        $call('akira.content_type.create@1', [
            'idempotency_key' => $keys[] = $prefix . '-dup',
            'slug' => 'newsroom',
            'label' => 'Newsroom Again',
            'field_schema' => '{"fields":{"title":{"type":"text"}}}',
        ]);
    } catch (Throwable $e) {
        $duplicate = $thrownStatus($e) === 422;
    }
    $check($duplicate, 'duplicate (tenant, slug) is rejected as a clean 422');

    $setIdentity($tenantB, $admin);
    $tenantDenied = false;
    $tenantDenyMessage = '';
    try {
        $call('akira.content_type.delete@1', [
            'idempotency_key' => $keys[] = $prefix . '-tenant-b',
            'id' => (int)$created['content_type']['id'],
            'expected_updated_at' => '2000-01-01 00:00:00',
        ]);
    } catch (Throwable $e) {
        $tenantDenied = true;
        $tenantDenyMessage = $e->getMessage();
    }
    $setIdentity($tenantA, $admin);
    $check($tenantDenied, 'tenant B cannot delete tenant A content type by id (denied at the governed seam)', $tenantDenyMessage);

    app()->setUser(['id' => 999021, 'role' => 'editor']);
    $editorCreate = $call('akira.content_type.create@1', [
        'idempotency_key' => $keys[] = $prefix . '-editor-create',
        'slug' => 'press-release',
        'label' => 'Press release',
        'field_schema' => '{"fields":{"headline":{"type":"text","required":true},"body":{"type":"textarea","required":true}}}',
    ]);
    $check(($editorCreate['ok'] ?? false) === true, 'editor role is allowed by the seeded content type policy');

    app()->setUser(['id' => 999022, 'role' => 'author']);
    $authorDenied = false;
    try {
        $call('akira.content_type.create@1', [
            'idempotency_key' => $keys[] = $prefix . '-author-denied',
            'slug' => 'nope',
            'label' => 'Nope',
            'field_schema' => '{"fields":{"title":{"type":"text"}}}',
        ]);
    } catch (Throwable $e) {
        $authorDenied = str_contains($e->getMessage(), 'authorization denied');
    }
    $check($authorDenied, 'author role is denied by the registry policy row');

    $setIdentity($tenantA, $admin);
    $audit = $db->prepare("SELECT action, COUNT(*) FROM audit_logs WHERE module = 'cms-akira-core' AND entity_type = 'content_type' AND entity_id = ? GROUP BY action");
    $audit->execute([(string)$created['content_type']['id']]);
    $auditCounts = $audit->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    $check(
        ($auditCounts['akira.content_type.create'] ?? 0) === 1 && ($auditCounts['akira.content_type.update'] ?? 0) === 1,
        'every governed create/update commits durable audit evidence'
    );

    $versionStmt->execute([$tenantA, 'newsroom']);
    $deleted = $call('akira.content_type.delete@1', [
        'idempotency_key' => $keys[] = $prefix . '-delete',
        'id' => (int)$created['content_type']['id'],
        'expected_updated_at' => (string)$versionStmt->fetchColumn(),
    ]);
    $deletedRow = $db->prepare('SELECT COUNT(*) FROM cms_akira_content_types WHERE tenant_id = ? AND id = ?');
    $deletedRow->execute([$tenantA, (int)$created['content_type']['id']]);
    $check(
        ($deleted['operation'] ?? '') === 'delete' && (int)$deletedRow->fetchColumn() === 0,
        'delete removes the declared model row (no references exist yet)'
    );
    $deleteAudit = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE module = 'cms-akira-core' AND action = 'akira.content_type.delete' AND entity_id = ?");
    $deleteAudit->execute([(string)$created['content_type']['id']]);
    $check((int)$deleteAudit->fetchColumn() === 1, 'delete commits durable audit evidence');

    $list = $call('akira.content_type.list@1', ['limit' => 50]);
    $slugs = array_column(is_array($list['rows'] ?? null) ? $list['rows'] : [], 'slug');
    $check(($list['ok'] ?? false) === true && in_array('press-release', $slugs, true), 'governed list read returns the committed declared model');

    $get = $call('akira.content_type.get@1', ['slug' => 'press-release']);
    $getSchema = is_array($get['data'] ?? null) ? json_decode((string)($get['data']['field_schema'] ?? ''), true) : null;
    $check(($get['ok'] ?? false) === true && ($get['data']['label'] ?? '') === 'Press release'
        && is_array($getSchema) && ($getSchema['fields']['headline']['required'] ?? false) === true, 'governed get read resolves one declared model');

    $appLog = (string)@file_get_contents($root . '/storage/logs/app.log');
    $errorLog = (string)@file_get_contents($root . '/storage/logs/error.log');
    $check(
        !str_contains($appLog, '[error]') && trim($errorLog) === '',
        'content type mutation run has no error or invalidation-failure log',
        trim($errorLog)
    );
} catch (Throwable $e) {
    $check(false, 'content type scenario completes', $e::class . ': ' . $e->getMessage());
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    try {
        $db->prepare('DELETE FROM cms_akira_content_types WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare('DELETE FROM kernel_idempotency_keys WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare("DELETE FROM audit_logs WHERE module = 'cms-akira-core' AND entity_type = 'content_type'")->execute();
    } catch (Throwable $e) {
        $check(false, 'content type fixture cleanup succeeds', $e->getMessage());
    }
    app()->tenant()->setTenantId($originalTenant);
    kernel_request_context_delete('tenant_id');
    @file_put_contents($root . '/storage/logs/app.log', '');
    @file_put_contents($root . '/storage/logs/error.log', '');
}

echo "\nCMS Akira P1 content types: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
