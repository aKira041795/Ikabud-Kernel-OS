<?php

/** CMS Akira Phase 5A native media contract and integration gate. */

declare(strict_types=1);

use Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry;

$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
require_once $root . '/tests/_support/env_guard.php';
require_once $root . '/tests/_support/tenant_fixture.php';
require_once dirname(__DIR__, 2) . '/cms-akira-core/helpers/governance.php';
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/handlers.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

$mutations = [
    'akira.media.upload@1',
    'akira.media.update@1',
    'akira.media.delete.request@1',
    'akira.media.delete.cancel@1',
    'akira.media.delete@1',
];
$registry = app()->capabilities();
foreach (cms_akira_media_capability_handlers() as $id => $handler) {
    if ($registry->has($id)) {
        continue;
    }
    $meta = in_array($id, $mutations, true)
        ? ['requires_protocol' => 'v2', 'effects' => ['invalidates' => [CAM_MEDIA_INVALIDATION]]]
        : [];
    $registry->register(
        $id,
        'cms-akira-media',
        static function (mixed $payload, string $capabilityId = '', string $provider = '') use ($handler): mixed {
            return moduleWithContext('cms-akira-media', static fn (): mixed => $handler($payload, $capabilityId, $provider));
        },
        50,
        ['first'],
        $meta,
    );
}

requireWritableCacheDirectory(
    $root . '/storage/cache/disyl-fragments',
    'media fragment-cache fixture root'
);

$tenantIds = [];
for ($attempt = 0; $attempt < 40 && count($tenantIds) < 2; $attempt++) {
    $candidate = random_int(8000000, 8999999);
    $exists = app()->controlDb()->prepare('SELECT 1 FROM kernel_tenants WHERE id = ?');
    $exists->execute([$candidate]);
    if ($exists->fetchColumn() === false && !in_array($candidate, $tenantIds, true)) {
        $tenantIds[] = $candidate;
    }
}
if (count($tenantIds) !== 2) {
    testEnvironmentSkip('could not allocate two unused tenant fixture ids for media isolation');
}
[$tenantA, $tenantB] = $tenantIds;
try {
    ensureTestTenant($tenantA, 'cms-akira-media');
    ensureTestTenant($tenantB, 'cms-akira-media');
} catch (Throwable $error) {
    cleanupTestTenant($tenantA);
    cleanupTestTenant($tenantB);
    testEnvironmentSkip('media tenant fixtures are unavailable: ' . $error->getMessage());
}

// CapabilityBus checks moduleIsActive($provider) without an explicit tenant.
// In the CI single-tenant configuration that means global module activation;
// tenant settings alone only satisfy the explicit-tenant form used above.
$moduleRegistryPath = $root . '/storage/modules.json';
$moduleRegistryExisted = is_file($moduleRegistryPath);
$originalModuleRegistry = $moduleRegistryExisted ? file_get_contents($moduleRegistryPath) : null;
$restoreModuleRegistry = static function () use ($moduleRegistryPath, $moduleRegistryExisted, $originalModuleRegistry): void {
    if ($moduleRegistryExisted && is_string($originalModuleRegistry)) {
        file_put_contents($moduleRegistryPath, $originalModuleRegistry);
    } elseif (!$moduleRegistryExisted) {
        @unlink($moduleRegistryPath);
    }
};
if (!moduleTenantSettingsModeEnabled()) {
    try {
        enableModule('cms-akira-media');
    } catch (Throwable $error) {
        $restoreModuleRegistry();
        cleanupTestTenant($tenantA);
        cleanupTestTenant($tenantB);
        testEnvironmentSkip('global cms-akira-media activation fixture is unavailable: ' . $error->getMessage());
    }
}
$originalTenant = app()->tenant()->current();
$db = app()->db();
$prefix = 'media-' . bin2hex(random_bytes(5));
$keys = [];
$admin = ['id' => 999501, 'role' => 'admin'];
$setIdentity = static function (int $tenant, array $user) use ($db): void {
    app()->tenant()->setTenantId($tenant);
    kernel_request_context_set('tenant_id', $tenant);
    tenantSetModuleActivationState($db, $tenant, ['cms-akira-media'], true, 'media-contract-' . $tenant);
    invalidateTenantModuleSettingsCache();
    app()->setUser($user);
};
$call = static function (string $id, array $payload = []) use ($admin): array {
    return app()->cap()->call($id, $payload, [
        'caller' => ['module' => 'cms-akira-media', 'user' => app()->user() ?? $admin],
        'mode' => 'first',
        'breaker_threshold' => 1000,
    ]);
};
$statusOf = static function (Throwable $error): ?int {
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        if ($cursor instanceof CamMediaMutationException) {
            return $cursor->httpStatus;
        }
    }
    return null;
};
$version = static function (string $mediaKey) use ($db): string {
    $tenant = (int) app()->tenant()->current();
    $stmt = $db->prepare('SELECT updated_at FROM cms_akira_media WHERE tenant_id = ? AND media_key = ?');
    $stmt->execute([$tenant, $mediaKey]);
    return (string) $stmt->fetchColumn();
};
$storagePath = static function (int $tenant, string $mediaKey) use ($db): ?string {
    $stmt = $db->prepare('SELECT storage_path FROM cms_akira_media WHERE tenant_id = ? AND media_key = ?');
    $stmt->execute([$tenant, $mediaKey]);
    $value = $stmt->fetchColumn();
    return is_string($value) ? $value : null;
};
$cleanupStorage = static function () use ($tenantA, $tenantB): void {
    foreach ([$tenantA, $tenantB] as $tenant) {
        $dir = camMediaStorageRoot($tenant);
        if (is_dir($dir)) {
            foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
                @unlink($dir . '/' . $entry);
            }
            @rmdir($dir);
        }
    }
};

$png = "\x89PNG\r\n\x1a\n" . random_bytes(16);
$jpg = "\xFF\xD8\xFF" . random_bytes(16);
$pdf = "%PDF-1.4\n" . random_bytes(16);

@file_put_contents($root . '/storage/logs/app.log', '');
@file_put_contents($root . '/storage/logs/error.log', '');

try {
    echo "=== CMS Akira Phase 5A native media ===\n";
    $setIdentity($tenantA, $admin);
    camSeedMediaMutationPolicies();
    camSeedMediaReadPolicies();
    $db->prepare('DELETE FROM cms_akira_media WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
    $cleanupStorage();

    $module = dirname(__DIR__);
    $manifest = kernelReadJsonFile($module . '/module.json');
    $ids = array_column($manifest['capabilities']['exposes'] ?? [], 'id');
    $expectedIds = array_keys(cms_akira_media_capability_handlers());
    $check($ids === $expectedIds, 'manifest and runtime expose exactly the eight native media capabilities');
    $check(($manifest['depends'] ?? []) === ['cms-akira-core'], 'only the native Akira core module dependency remains');
    $check(
        ($manifest['owns_tables'] ?? []) === ['cms_akira_media']
        && ($manifest['reads_tables'] ?? []) === ['cms_akira_media'],
        'owned and readable media tables are explicit'
    );
    $fixtureActivation = $db->prepare(
        "SELECT tenant_id, setting_value FROM tenant_module_settings WHERE tenant_id IN (?, ?) "
        . "AND module_id = 'cms-akira-media' AND setting_key = '_module_enabled' ORDER BY tenant_id"
    );
    $fixtureActivation->execute([$tenantA, $tenantB]);
    $fixtureActivationRows = $fixtureActivation->fetchAll(PDO::FETCH_KEY_PAIR);
    $check(
        ($manifest['_enabled'] ?? null) === false
        && !isset($manifest['entities'])
        && ($fixtureActivationRows[$tenantA] ?? null) === 'true'
        && ($fixtureActivationRows[$tenantB] ?? null) === 'true'
        && moduleIsActive('cms-akira-media', $tenantA)
        && moduleIsActive('cms-akira-media', $tenantB)
        && moduleIsActive('cms-akira-media'),
        'tenant activation is explicit, fixture tenants and the dispatch scope permit media, and media claims no Kernel Entity Authority'
    );
    foreach ($mutations as $id) {
        $entry = $manifest['capabilities']['exposes'][array_search($id, $ids, true)] ?? [];
        $check(
            ($entry['requires_protocol'] ?? '') === 'v2'
            && ($entry['effects']['invalidates'] ?? null) === [CAM_MEDIA_INVALIDATION],
            "{$id} is governed and has one canonical invalidation"
        );
    }
    $check(!isset($manifest['nav']), 'legacy /admin/cms-akira-media navigation entry is removed');
    $policies = new CapabilityAuthorizationRegistry($db);
    $check(
        $policies->requiresProtocol('akira.media.delete@1', '1', 'cms-akira-media') === 'v2',
        'activation policy seed is durable and protocol-v2'
    );

    $draftingRoles = 'contributor,author,editor,admin,administrator,superadmin';
    $mutationDeclarations = array_column(camMediaMutationPolicyRows(), 'allowed_roles', 'capability_id');
    $readDeclarations = array_column(camMediaReadPolicyRows(1), 'allowed_roles', 'capability_id');
    $check(
        camMediaContributionRoleCsv() === $draftingRoles
        && ($mutationDeclarations['akira.media.upload@1'] ?? '') === $draftingRoles
        && ($mutationDeclarations['akira.media.update@1'] ?? '') === $draftingRoles
        && ($mutationDeclarations['akira.media.delete.request@1'] ?? '') === $draftingRoles
        && ($mutationDeclarations['akira.media.delete.cancel@1'] ?? '') === $draftingRoles
        && ($mutationDeclarations['akira.media.delete@1'] ?? '') === 'admin,administrator,superadmin',
        'fresh mutation declarations give request/cancel the drafting set while delete is the administrator tier'
    );
    $check(
        ($readDeclarations['akira.media.library@1'] ?? '') === $draftingRoles
        && ($readDeclarations['akira.media.get@1'] ?? '') === $draftingRoles,
        'fresh library and detail declarations use the canonical drafting set'
    );

    $activeAkiraRows = array_values(array_filter(
        $policies->activePolicyRows(),
        static fn (array $row): bool => str_starts_with((string)($row['capability_id'] ?? ''), 'akira.')
    ));
    $listed = cac_cap_akira_policy_list_1(null)['rows'] ?? [];
    $check(
        $listed === $activeAkiraRows
        && count(array_filter($listed, static fn (array $row): bool => ($row['provider'] ?? '') === 'cms-akira-media')) > 0,
        'Permissions lists every active Akira provider, including media'
    );
    $editableMedia = current(array_filter(
        $listed,
        static fn (array $row): bool => ($row['provider'] ?? '') === 'cms-akira-media'
            && ($row['caller_module'] ?? null) === null
    ));
    $nullCallerEditable = false;
    if (is_array($editableMedia)) {
        $roles = array_values(array_unique(array_merge(
            array_filter(array_map('trim', explode(',', (string)($editableMedia['allowed_roles'] ?? '')))),
            ['admin', 'author']
        )));
        $db->beginTransaction();
        try {
            $nextVersion = $policies->replaceActiveRowRoles(
                (string)$editableMedia['capability_id'],
                (string)$editableMedia['capability_version'],
                (string)$editableMedia['provider'],
                '',
                $roles
            );
            $edited = $db->prepare(
                'SELECT allowed_roles FROM capability_authorization_policies WHERE policy_version = ? '
                . 'AND capability_id = ? AND capability_version = ? AND provider = ? AND caller_module IS NULL'
            );
            $edited->execute([$nextVersion, $editableMedia['capability_id'], $editableMedia['capability_version'], $editableMedia['provider']]);
            $nullCallerEditable = $edited->fetchColumn() === implode(',', $roles);
        } finally {
            $db->rollBack();
            CapabilityAuthorizationRegistry::invalidate();
        }
    }
    $check($nullCallerEditable, 'Permissions sanctioned replacement path accepts a media row whose caller is SQL NULL');

    $probeVersion = 0;
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $candidate = random_int(1500000000, 2000000000);
        $versionExists = $db->prepare('SELECT 1 FROM capability_authorization_policies WHERE policy_version = ? LIMIT 1');
        $versionExists->execute([$candidate]);
        if ($versionExists->fetchColumn() === false) {
            $probeVersion = $candidate;
            break;
        }
    }
    if ($probeVersion === 0) {
        throw new RuntimeException('Could not allocate an unused policy version for the media declaration probe.');
    }
    $probeRows = array_merge(camMediaMutationPolicyRows($probeVersion), camMediaReadPolicyRows($probeVersion));
    try {
        $policies->seedPolicy($probeRows);
        $probe = $db->prepare(
            'SELECT capability_id, allowed_roles FROM capability_authorization_policies '
            . 'WHERE policy_version = ? AND provider = ? ORDER BY capability_id'
        );
        $probe->execute([$probeVersion, 'cms-akira-media']);
        $freshRows = array_column($probe->fetchAll(PDO::FETCH_ASSOC), 'allowed_roles', 'capability_id');
        $authorUpload = $policies->authorize([
            'capability_id' => 'akira.media.upload@1', 'capability_version' => '1', 'provider' => 'cms-akira-media',
            'caller_module' => 'cms-akira-shell', 'actor_role' => 'author', 'tenant_id' => (string)$tenantA,
            'provider_activation' => true, 'dispatch_protocol' => 'v2', 'policy_version' => $probeVersion,
        ]);
        $authorDelete = $policies->authorize([
            'capability_id' => 'akira.media.delete@1', 'capability_version' => '1', 'provider' => 'cms-akira-media',
            'caller_module' => 'cms-akira-shell', 'actor_role' => 'author', 'tenant_id' => (string)$tenantA,
            'provider_activation' => true, 'dispatch_protocol' => 'v2', 'policy_version' => $probeVersion,
        ]);
        // Finalisation: the administrator tier must be ADMITTED, not merely un-refused.
        $administratorDelete = $policies->authorize([
            'capability_id' => 'akira.media.delete@1', 'capability_version' => '1', 'provider' => 'cms-akira-media',
            'caller_module' => 'cms-akira-shell', 'actor_role' => 'administrator', 'tenant_id' => (string)$tenantA,
            'provider_activation' => true, 'dispatch_protocol' => 'v2', 'policy_version' => $probeVersion,
        ]);
        $superadminDelete = $policies->authorize([
            'capability_id' => 'akira.media.delete@1', 'capability_version' => '1', 'provider' => 'cms-akira-media',
            'caller_module' => 'cms-akira-shell', 'actor_role' => 'superadmin', 'tenant_id' => (string)$tenantA,
            'provider_activation' => true, 'dispatch_protocol' => 'v2', 'policy_version' => $probeVersion,
        ]);
        $editorDelete = $policies->authorize([
            'capability_id' => 'akira.media.delete@1', 'capability_version' => '1', 'provider' => 'cms-akira-media',
            'caller_module' => 'cms-akira-shell', 'actor_role' => 'editor', 'tenant_id' => (string)$tenantA,
            'provider_activation' => true, 'dispatch_protocol' => 'v2', 'policy_version' => $probeVersion,
        ]);
        $check(
            count($freshRows) === count($probeRows)
            && ($authorUpload['allowed'] ?? false) === true
            && ($authorDelete['allowed'] ?? true) === false
            && ($editorDelete['allowed'] ?? true) === false
            && ($administratorDelete['allowed'] ?? false) === true
            && ($superadminDelete['allowed'] ?? false) === true,
            'fresh policy rows admit the administrator tier to finalise while contribution roles may only request'
        );

        $uploadDeclaration = camMediaMutationPolicyRows($probeVersion)[0];
        $policies->seedPolicy([[...$uploadDeclaration, 'allowed_roles' => 'admin']]);
        $policies->seedPolicy([$uploadDeclaration]);
        $narrowed = $db->prepare(
            'SELECT allowed_roles FROM capability_authorization_policies WHERE policy_version = ? AND capability_id = ? AND provider = ?'
        );
        $narrowed->execute([$probeVersion, 'akira.media.upload@1', 'cms-akira-media']);
        $check(
            $narrowed->fetchColumn() === 'admin',
            'seedPolicy applies narrowing and refuses a later widening until operator re-grant'
        );
    } finally {
        $db->prepare('DELETE FROM capability_authorization_policies WHERE policy_version = ? AND provider = ?')
            ->execute([$probeVersion, 'cms-akira-media']);
        CapabilityAuthorizationRegistry::invalidate();
    }

    $uploadPayload = [
        'idempotency_key' => $keys[] = $prefix . '-upload',
        'filename' => 'hero.png',
        'mime_type' => 'image/png',
        'content' => base64_encode($png),
        'alt' => 'Akira hero image',
        'width' => 640,
        'height' => 360,
    ];
    $uploaded = $call('akira.media.upload@1', $uploadPayload);
    $mediaKey = (string) ($uploaded['media']['key'] ?? '');
    $replayed = $call('akira.media.upload@1', $uploadPayload);
    $count = $db->prepare('SELECT COUNT(*) FROM cms_akira_media WHERE tenant_id = ? AND media_key = ?');
    $count->execute([$tenantA, $mediaKey]);
    $stored = $storagePath($tenantA, $mediaKey);
    $fileExists = $stored !== null && is_file(camMediaStorageRoot($tenantA) . '/' . $stored);
    $check($uploaded === $replayed && $mediaKey !== '' && (int) $count->fetchColumn() === 1 && $fileExists, 'upload is idempotent with one durable row and one stored file');
    $check(array_keys($uploaded['media'] ?? []) === ['key', 'filename', 'mime_type', 'size_bytes', 'alt', 'width', 'height', 'url'], 'upload returns an explicit render projection');

    $conflict = false;
    try {
        $changed = $uploadPayload;
        $changed['alt'] = 'Conflicting payload';
        $call('akira.media.upload@1', $changed);
    } catch (Throwable $error) {
        $conflict = $statusOf($error) === 409;
    }
    $check($conflict, 'same idempotency key with changed payload is rejected');

    $library = $call('akira.media.library@1');
    $check(
        ($library['total'] ?? 0) === 1
        && array_keys($library['rows'][0] ?? []) === ['key', 'filename', 'mime_type', 'size_bytes', 'alt', 'width', 'height', 'url', 'delete_requested_at', 'delete_requested_by', 'delete_request_reason'],
        'library is tenant-scoped and explicitly projected'
    );
    $get = $call('akira.media.get@1', ['media_key' => $mediaKey]);
    $check(
        ($get['ok'] ?? false) === true
        && array_keys($get['data'] ?? []) === ['key', 'filename', 'mime_type', 'size_bytes', 'alt', 'width', 'height', 'url', 'delete_requested_at', 'delete_requested_by', 'delete_request_reason', 'created_at', 'updated_at'],
        'get returns the explicit detail projection'
    );
    $resolved = $call('akira.media.resolve@1', ['media_key' => $mediaKey]);
    $check(
        ($resolved['ok'] ?? false) === true
        && ($resolved['data']['url'] ?? '') === '/api/v1/cms-akira-media/stream/' . $mediaKey
        && array_keys($resolved['data'] ?? []) === ['key', 'filename', 'mime_type', 'size_bytes', 'alt', 'width', 'height', 'url']
        && !array_intersect(['id', 'tenant_id', 'storage_path', 'created_at', 'updated_at', 'delete_requested_at', 'delete_requested_by', 'delete_request_reason'], array_keys($resolved['data'] ?? [])),
        'resolve returns the public render projection and drops every storage/tenant field'
    );
    $missing = $call('akira.media.resolve@1', ['media_key' => str_repeat('ab', 16)]);
    $check(($missing['ok'] ?? true) === false, 'missing media reference resolve fails closed');

    $fragmentStore = app()->templates()->fragmentStore();
    $fragmentStore->put($prefix . '-fragment', 'old media', [CAM_MEDIA_INVALIDATION], 300, (string) $tenantA);
    $updated = $call('akira.media.update@1', [
        'idempotency_key' => $keys[] = $prefix . '-update',
        'media_key' => $mediaKey,
        'alt' => 'Updated hero alt',
        'width' => 1280,
        'height' => 720,
        'expected_updated_at' => $version($mediaKey),
    ]);
    $check(
        ($updated['media']['alt'] ?? '') === 'Updated hero alt'
        && $fragmentStore->tryGet($prefix . '-fragment', [CAM_MEDIA_INVALIDATION], (string) $tenantA) === null,
        'successful mutation invalidates the single media entity-view key'
    );
    $audit = $db->prepare(
        "SELECT new_data FROM audit_logs WHERE module = 'cms-akira-media' AND action = 'akira.media.update' AND entity_id = ? ORDER BY id DESC LIMIT 1"
    );
    $audit->execute([$mediaKey]);
    $auditData = json_decode((string) $audit->fetchColumn(), true);
    $check(
        is_array($auditData) && ($auditData['correlation_id'] ?? '') === ($updated['correlation_id'] ?? null),
        'durable same-PDO audit carries the mutation correlation id'
    );

    $mimeDenied = false;
    try {
        $call('akira.media.upload@1', [
            'idempotency_key' => $keys[] = $prefix . '-bad-mime',
            'filename' => 'evil.html',
            'mime_type' => 'text/html',
            'content' => base64_encode('<!doctype html><script>alert(1)</script>'),
        ]);
    } catch (Throwable $error) {
        $mimeDenied = $statusOf($error) === 422;
    }
    $check($mimeDenied, 'non-allowlisted MIME fails closed');

    $extDenied = false;
    try {
        $call('akira.media.upload@1', [
            'idempotency_key' => $keys[] = $prefix . '-bad-ext',
            'filename' => 'hero.html',
            'mime_type' => 'image/png',
            'content' => base64_encode($png),
        ]);
    } catch (Throwable $error) {
        $extDenied = $statusOf($error) === 422;
    }
    $check($extDenied, 'filename extension must match the declared MIME');

    $sniffDenied = false;
    try {
        $call('akira.media.upload@1', [
            'idempotency_key' => $keys[] = $prefix . '-bad-sniff',
            'filename' => 'fake.png',
            'mime_type' => 'image/png',
            'content' => base64_encode($jpg),
        ]);
    } catch (Throwable $error) {
        $sniffDenied = $statusOf($error) === 422;
    }
    $check($sniffDenied, 'file magic bytes must match the declared MIME');

    $sizeDenied = false;
    try {
        $call('akira.media.upload@1', [
            'idempotency_key' => $keys[] = $prefix . '-oversize',
            'filename' => 'big.png',
            'mime_type' => 'image/png',
            'content' => base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('x', CAM_MEDIA_MAX_BYTES)),
        ]);
    } catch (Throwable $error) {
        $sizeDenied = $statusOf($error) === 422;
    }
    $check($sizeDenied, 'uploads over the size cap fail closed');

    $traversalDenied = false;
    try {
        $call('akira.media.upload@1', [
            'idempotency_key' => $keys[] = $prefix . '-traversal',
            'filename' => '../escape.png',
            'mime_type' => 'image/png',
            'content' => base64_encode($png),
        ]);
    } catch (Throwable $error) {
        $traversalDenied = $statusOf($error) === 422;
    }
    $check($traversalDenied, 'path-traversal filename fails closed');

    $escapeDenied = false;
    try {
        camMediaAbsolutePath($tenantA, '../.env');
    } catch (CamMediaMutationException) {
        $escapeDenied = true;
    }
    $check($escapeDenied, 'storage-path resolution rejects escapes outside the tenant root');

    $setIdentity($tenantB, $admin);
    $tenantBLibrary = $call('akira.media.library@1');
    $tenantBGet = $call('akira.media.get@1', ['media_key' => $mediaKey]);
    $check(($tenantBLibrary['rows'] ?? null) === [] && ($tenantBGet['ok'] ?? true) === false, 'shared-schema tenant B cannot read tenant A media');
    $tenantBWriteDenied = false;
    try {
        $call('akira.media.update@1', [
            'idempotency_key' => $keys[] = $prefix . '-tenant-b',
            'media_key' => $mediaKey,
            'alt' => 'Stolen',
            'expected_updated_at' => '2000-01-01 00:00:00',
        ]);
    } catch (Throwable $error) {
        $tenantBWriteDenied = $statusOf($error) === 404;
    }
    $check($tenantBWriteDenied, 'shared-schema tenant B cannot mutate tenant A media');
    $setIdentity($tenantA, $admin);

    $spoofDenied = false;
    try {
        $call('akira.media.upload@1', [
            'idempotency_key' => $keys[] = $prefix . '-spoof',
            'tenant_id' => $tenantB,
            'filename' => 'spoof.png',
            'mime_type' => 'image/png',
            'content' => base64_encode($png),
        ]);
    } catch (Throwable $error) {
        $spoofDenied = $statusOf($error) === 422;
    }
    $check($spoofDenied && ($call('akira.media.library@1', ['tenant_id' => $tenantB])['ok'] ?? true) === false, 'payload tenant identity is rejected on mutation and read paths');

    $requester = ['id' => 999502, 'role' => 'author'];
    app()->setUser($requester);
    $beforeRequestCount = $db->prepare('SELECT COUNT(*) FROM cms_akira_media WHERE tenant_id = ? AND media_key = ?');
    $beforeRequestCount->execute([$tenantA, $mediaKey]);
    $beforeRequestRows = (int) $beforeRequestCount->fetchColumn();
    $beforeRequestFile = $stored !== null && is_file(camMediaStorageRoot($tenantA) . '/' . $stored);
    $requested = $call('akira.media.delete.request@1', [
        'idempotency_key' => $keys[] = $prefix . '-request',
        'media_key' => $mediaKey,
        'delete_request_reason' => 'Replaced campaign asset',
        'expected_updated_at' => $version($mediaKey),
    ]);
    $pending = $db->prepare(
        'SELECT delete_requested_at, delete_requested_by, delete_request_reason FROM cms_akira_media '
        . 'WHERE tenant_id = ? AND media_key = ?'
    );
    $pending->execute([$tenantA, $mediaKey]);
    $pendingRow = $pending->fetch(PDO::FETCH_ASSOC);
    $afterRequestCount = $db->prepare('SELECT COUNT(*) FROM cms_akira_media WHERE tenant_id = ? AND media_key = ?');
    $afterRequestCount->execute([$tenantA, $mediaKey]);
    $afterRequestFile = $stored !== null && is_file(camMediaStorageRoot($tenantA) . '/' . $stored);
    $pendingResolve = $call('akira.media.resolve@1', ['media_key' => $mediaKey]);
    $check(
        ($requested['operation'] ?? '') === 'media.delete.request'
        && is_array($pendingRow) && ($pendingRow['delete_requested_at'] ?? null) !== null
        && (int) ($pendingRow['delete_requested_by'] ?? 0) === $requester['id']
        && ($pendingRow['delete_request_reason'] ?? '') === 'Replaced campaign asset',
        'author request records pending state and original requester'
    );
    $check(
        $beforeRequestRows === 1 && (int) $afterRequestCount->fetchColumn() === 1
        && $beforeRequestFile && $afterRequestFile,
        'request performs no row delete and no file unlink'
    );
    $check(
        ($pendingResolve['ok'] ?? false) === true
        && array_keys($pendingResolve['data'] ?? []) === ['key', 'filename', 'mime_type', 'size_bytes', 'alt', 'width', 'height', 'url'],
        'pending media keeps the unchanged public resolve projection'
    );
    $pendingLibrary = $call('akira.media.library@1');
    $check(
        ($pendingLibrary['rows'][0]['delete_requested_by'] ?? null) === $requester['id']
        && ($pendingLibrary['rows'][0]['delete_request_reason'] ?? '') === 'Replaced campaign asset',
        'library exposes pending request fields'
    );

    $originalRequestedAt = (string) ($pendingRow['delete_requested_at'] ?? '');
    app()->setUser(['id' => 999503, 'role' => 'editor']);
    $secondRequest = $call('akira.media.delete.request@1', [
        'idempotency_key' => $keys[] = $prefix . '-request-again',
        'media_key' => $mediaKey,
        'delete_request_reason' => 'Must not replace the original reason',
        'expected_updated_at' => $version($mediaKey),
    ]);
    $pending->execute([$tenantA, $mediaKey]);
    $afterSecondRequest = $pending->fetch(PDO::FETCH_ASSOC);
    $check(
        ($secondRequest['ok'] ?? false) === true
        && (string) ($afterSecondRequest['delete_requested_at'] ?? '') === $originalRequestedAt
        && (int) ($afterSecondRequest['delete_requested_by'] ?? 0) === $requester['id']
        && ($afterSecondRequest['delete_request_reason'] ?? '') === 'Replaced campaign asset',
        'second request succeeds without replacing original request state'
    );

    app()->setUser($requester);
    $roleDenied = false;
    try {
        $call('akira.media.delete@1', [
            'idempotency_key' => $keys[] = $prefix . '-role',
            'media_key' => $mediaKey,
            'expected_updated_at' => $version($mediaKey),
        ]);
    } catch (Throwable $error) {
        $roleDenied = str_contains($error->getMessage(), 'authorization denied');
    }
    $afterDeniedDelete = $db->prepare('SELECT COUNT(*) FROM cms_akira_media WHERE tenant_id = ? AND media_key = ?');
    $afterDeniedDelete->execute([$tenantA, $mediaKey]);
    $check(
        $roleDenied && (int) $afterDeniedDelete->fetchColumn() === 1
        && $stored !== null && is_file(camMediaStorageRoot($tenantA) . '/' . $stored),
        'author delete is refused while row and file remain untouched'
    );

    app()->setUser(['id' => 999503, 'role' => 'editor']);
    $otherCancelDenied = false;
    try {
        $call('akira.media.delete.cancel@1', [
            'idempotency_key' => $keys[] = $prefix . '-other-cancel',
            'media_key' => $mediaKey,
            'expected_updated_at' => $version($mediaKey),
        ]);
    } catch (Throwable $error) {
        $otherCancelDenied = $statusOf($error) === 403;
    }
    $pending->execute([$tenantA, $mediaKey]);
    $check($otherCancelDenied && ($pending->fetch(PDO::FETCH_ASSOC)['delete_requested_at'] ?? null) !== null, 'different non-admin cannot cancel another contributor request');

    app()->setUser($requester);
    $cancelled = $call('akira.media.delete.cancel@1', [
        'idempotency_key' => $keys[] = $prefix . '-own-cancel',
        'media_key' => $mediaKey,
        'expected_updated_at' => $version($mediaKey),
    ]);
    $pending->execute([$tenantA, $mediaKey]);
    $cancelledRow = $pending->fetch(PDO::FETCH_ASSOC);
    $check(
        ($cancelled['operation'] ?? '') === 'media.delete.cancel'
        && is_array($cancelledRow) && $cancelledRow['delete_requested_at'] === null
        && $cancelledRow['delete_requested_by'] === null && $cancelledRow['delete_request_reason'] === null,
        'original requester can cancel and clears all pending fields'
    );

    $call('akira.media.delete.request@1', [
        'idempotency_key' => $keys[] = $prefix . '-request-admin-cancel',
        'media_key' => $mediaKey,
        'expected_updated_at' => $version($mediaKey),
    ]);
    $setIdentity($tenantA, $admin);
    $call('akira.media.delete.cancel@1', [
        'idempotency_key' => $keys[] = $prefix . '-admin-cancel',
        'media_key' => $mediaKey,
        'expected_updated_at' => $version($mediaKey),
    ]);
    $pending->execute([$tenantA, $mediaKey]);
    $adminCancelledRow = $pending->fetch(PDO::FETCH_ASSOC);
    $check(is_array($adminCancelledRow) && $adminCancelledRow['delete_requested_at'] === null, 'administrator can cancel a contributor request');

    app()->setUser($requester);
    $call('akira.media.delete.request@1', [
        'idempotency_key' => $keys[] = $prefix . '-request-approval',
        'media_key' => $mediaKey,
        'expected_updated_at' => $version($mediaKey),
    ]);
    $setIdentity($tenantA, $admin);
    $deleted = $call('akira.media.delete@1', [
        'idempotency_key' => $keys[] = $prefix . '-delete',
        'media_key' => $mediaKey,
        'expected_updated_at' => $version($mediaKey),
    ]);
    $remaining = $db->prepare('SELECT COUNT(*) FROM cms_akira_media WHERE tenant_id = ? AND media_key = ?');
    $remaining->execute([$tenantA, $mediaKey]);
    $fileRemoved = $stored !== null && !is_file(camMediaStorageRoot($tenantA) . '/' . $stored);
    $check(($deleted['operation'] ?? '') === 'media.delete' && (int) $remaining->fetchColumn() === 0 && $fileRemoved, 'admin approval hard-removes the pending row and its stored file');
    $check(($call('akira.media.resolve@1', ['media_key' => $mediaKey])['ok'] ?? true) === false, 'deleted media reference resolve fails closed');

    $pdfUpload = $call('akira.media.upload@1', [
        'idempotency_key' => $keys[] = $prefix . '-pdf',
        'filename' => 'brochure.pdf',
        'mime_type' => 'application/pdf',
        'content' => base64_encode($pdf),
    ]);
    $pdfKey = (string) ($pdfUpload['media']['key'] ?? '');
    $pdfStored = $storagePath($tenantA, $pdfKey);
    $check(
        ($pdfUpload['media']['mime_type'] ?? '') === 'application/pdf'
        && $pdfStored !== null && str_ends_with($pdfStored, '.pdf'),
        'alternate allowlisted MIME derives its own server-side extension'
    );

    $topology = $db->query(
        "SELECT table_name, engine, table_collation FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'cms_akira_media'"
    )->fetchAll(PDO::FETCH_ASSOC);
    // information_schema returns these keys uppercase on MySQL 8 but lowercase on
    // MySQL 5.7 and MariaDB; normalise instead of reading one fixed case, which
    // raised "Undefined array key" and also dirtied error.log.
    $topologyRow = array_change_key_case(is_array($topology[0] ?? null) ? $topology[0] : [], CASE_UPPER);
    $check(
        count($topology) === 1
        && strtoupper((string) ($topologyRow['ENGINE'] ?? '')) === 'INNODB'
        && (string) ($topologyRow['TABLE_COLLATION'] ?? '') === 'utf8mb4_unicode_ci',
        'native media table is InnoDB utf8mb4_unicode_ci'
    );
    $migration = (string) file_get_contents($module . '/database/migrations/002_create_native_media.sql');
    $deleteRequestMigration = (string) file_get_contents($module . '/database/migrations/003_add_delete_requests.sql');
    $check(
        str_contains($migration, 'CHAR(32) CHARACTER SET ascii COLLATE ascii_bin')
        && str_contains($migration, 'UNIQUE KEY uq_media_tenant_key (tenant_id, media_key)')
        && !preg_match('/\b(CHECK|JSON_TABLE|WITH RECURSIVE|GENERATED ALWAYS)\b/i', $migration),
        'migration observes the MySQL-5.7 composite-index byte budget and syntax set'
    );
    $check(
        in_array('database/migrations/003_add_delete_requests.sql', $manifest['migrations'] ?? [], true)
        && substr_count($deleteRequestMigration, 'information_schema.columns') === 3
        && substr_count($deleteRequestMigration, 'DEALLOCATE PREPARE') === 3
        && str_contains($deleteRequestMigration, 'delete_requested_at DATETIME NULL')
        && str_contains($deleteRequestMigration, 'delete_requested_by INT UNSIGNED NULL')
        && str_contains($deleteRequestMigration, 'delete_request_reason VARCHAR(255) NULL')
        && !preg_match('/\b(CHECK|JSON_TABLE|WITH RECURSIVE|GENERATED ALWAYS)\b/i', $deleteRequestMigration),
        'delete-request migration is registered, independently idempotent, additive, and MySQL-5.7-safe'
    );
    $databaseManager = (string) file_get_contents($root . '/kernel/Services/DatabaseManager.php');
    $check(
        str_contains($databaseManager, 'dbForTenant')
        && str_contains($databaseManager, 'tenantRejectBaseDbConnection')
        && camDb()->getOwnsTables() === ['cms_akira_media'],
        'dedicated topology uses Kernel connection selection/base-DB rejection and the same ModuleDB closure'
    );
    $runner = new \Ikabud\Kernel\Database\MigrationRunner($db, $root . '/modules');
    $check($runner->status('cms-akira-media')['pending'] === [], 'member migration ledger rerun is converged');

    $source = implode("\n", array_map(static fn (string $file): string => (string) file_get_contents($file), [
        $module . '/module.json', $module . '/helpers.php', $module . '/handlers.php', $module . '/routes.php', $module . '/README.md',
    ]));
    $forbiddenA = 'cms' . '.media.';
    $forbiddenB = 'akira' . '.content.get@1';
    $legacyTheme = 'cms' . 'ActiveTheme';
    $legacyRender = 'cms' . 'Render';
    $check(
        !str_contains($source, $forbiddenA)
        && !str_contains($source, $forbiddenB)
        && !str_contains($source, $legacyTheme)
        && !str_contains($source, $legacyRender),
        'tracked media has no forbidden legacy authority, theme, or render residue'
    );

    $appLog = (string) @file_get_contents($root . '/storage/logs/app.log');
    $errorLog = (string) @file_get_contents($root . '/storage/logs/error.log');
    $check(!str_contains($appLog, '[error]') && trim($errorLog) === '', 'media run leaves application/error logs clean', trim($errorLog));
} catch (Throwable $error) {
    $check(false, 'Phase 5A media scenario completes', $error::class . ': ' . $error->getMessage());
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    try {
        $db->prepare('DELETE FROM cms_akira_media WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare('DELETE FROM kernel_idempotency_keys WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare("DELETE FROM audit_logs WHERE module = 'cms-akira-media'")->execute();
        app()->templates()->fragmentStore()->flushAll((string) $tenantA);
        app()->templates()->fragmentStore()->flushAll((string) $tenantB);
        $cleanupStorage();
    } catch (Throwable $error) {
        $check(false, 'media fixture cleanup succeeds', $error->getMessage());
    }
    app()->tenant()->setTenantId($originalTenant);
    kernel_request_context_delete('tenant_id');
    cleanupTestTenant($tenantA);
    cleanupTestTenant($tenantB);
    $restoreModuleRegistry();
    @file_put_contents($root . '/storage/logs/app.log', '');
    @file_put_contents($root . '/storage/logs/error.log', '');
}

echo "\nCMS Akira media: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
