<?php

/** CMS Akira Phase 4A native theme authority and tenant-scoped activation gate. */

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
    'akira.theme.activate@1',
    'akira.theme.customize@1',
];
$registry = app()->capabilities();
foreach (cms_akira_theme_capability_handlers() as $id => $handler) {
    if ($registry->has($id)) {
        continue;
    }
    $meta = in_array($id, $mutations, true)
        ? ['requires_protocol' => 'v2', 'effects' => ['invalidates' => [CAT_THEME_INVALIDATION]]]
        : [];
    $registry->register(
        $id,
        'cms-akira-theme',
        static function (mixed $payload, string $capabilityId = '', string $provider = '') use ($handler): mixed {
            return moduleWithContext('cms-akira-theme', static fn (): mixed => $handler($payload, $capabilityId, $provider));
        },
        50,
        ['first'],
        $meta,
    );
}

$tenantA = 994701;
$tenantB = 994702;
$originalTenant = app()->tenant()->current();
$db = app()->db();
$prefix = 'theme-' . bin2hex(random_bytes(5));
$admin = ['id' => 999701, 'role' => 'admin'];
$setIdentity = static function (int $tenant, array $user): void {
    app()->tenant()->setTenantId($tenant);
    kernel_request_context_set('tenant_id', $tenant);
    kernel_request_context_delete('_tenant_module_settings_cache');
    kernel_request_context_delete('active_theme_slug');
    app()->setUser($user);
};
$call = static function (string $id, array $payload = []) use ($admin): array {
    return app()->cap()->call($id, $payload, [
        'caller' => ['module' => 'cms-akira-theme', 'user' => app()->user() ?? $admin],
        'mode' => 'first',
        'breaker_threshold' => 1000,
    ]);
};
$statusOf = static function (Throwable $error): ?int {
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        if ($cursor instanceof CatThemeException) {
            return $cursor->httpStatus;
        }
    }
    return null;
};
$settingValue = static function (int $tenant) use ($db): ?string {
    $stmt = $db->prepare('SELECT setting_value FROM tenant_module_settings WHERE tenant_id = ? AND module_id = ? AND setting_key = ?');
    $stmt->execute([$tenant, 'cms-akira-theme', CAT_THEME_SETTING_ACTIVE]);
    $raw = $stmt->fetchColumn();
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_string($decoded) ? $decoded : $raw;
};

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . '/' . $entry;
        is_dir($child) ? $removeTree($child) : @unlink($child);
    }
    @rmdir($path);
};

@file_put_contents($root . '/storage/logs/app.log', '');
@file_put_contents($root . '/storage/logs/error.log', '');

try {
    echo "=== CMS Akira Phase 4A native theme ===\n";
    $setIdentity($tenantA, $admin);
    $db->prepare('DELETE FROM tenant_module_settings WHERE tenant_id IN (?, ?) AND module_id = ?')->execute([$tenantA, $tenantB, 'cms-akira-theme']);
    foreach ([$tenantA, $tenantB] as $fixtureTenant) {
        tenantWriteModuleSetting($db, $fixtureTenant, 'cms-akira-theme', '_module_enabled', true);
    }
    kernel_request_context_delete('_tenant_module_settings_cache');
    $db->prepare('DELETE FROM kernel_idempotency_keys WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
    $db->prepare("DELETE FROM audit_logs WHERE module = 'cms-akira-theme'")->execute();
    app()->templates()->fragmentStore()->flushAll((string) $tenantA);
    app()->templates()->fragmentStore()->flushAll((string) $tenantB);

    $module = dirname(__DIR__);
    $manifest = kernelReadJsonFile($module . '/module.json');
    $ids = array_column($manifest['capabilities']['exposes'] ?? [], 'id');
    $expectedIds = array_keys(cms_akira_theme_capability_handlers());
    $check($ids === $expectedIds, 'manifest and runtime expose the native theme and customizer capabilities');
    $check(($manifest['depends'] ?? []) === ['cms-akira-core'], 'only the native Akira core module dependency remains');
    $check(($manifest['owns_tables'] ?? null) === [] && ($manifest['reads_tables'] ?? null) === [], 'theme is table-free with explicit empty owns/reads');
    $check(($manifest['migrations'] ?? []) === ['database/migrations/001_initial.sql'], 'only the table-free 001 ledger marker remains (no 002)');
    $check(($manifest['_enabled'] ?? null) === false && !isset($manifest['entities']), 'tenant activation is explicit and theme claims no Kernel Entity Authority');
    $check(!isset($manifest['nav']) && !isset($manifest['admin_contributions']) && !isset($manifest['compatibility']) && !isset($manifest['uninstall']), 'legacy nav/admin/compat/uninstall scaffolding removed');
    $activateEntry = $manifest['capabilities']['exposes'][array_search('akira.theme.activate@1', $ids, true)] ?? [];
    $check(
        ($activateEntry['requires_protocol'] ?? '') === 'v2'
        && ($activateEntry['effects']['invalidates'] ?? null) === [CAT_THEME_INVALIDATION],
        'activate is governed and declares the single canonical theme.active invalidation'
    );

    $policies = new CapabilityAuthorizationRegistry($db);
    $check(
        $policies->requiresProtocol('akira.theme.activate@1', '1', 'cms-akira-theme') === 'v2'
        && $policies->requiresProtocol('akira.theme.customize@1', '1', 'cms-akira-theme') === 'v2',
        'activation and customization policy seeds are durable and protocol-v2'
    );

    // ── Resolve: setting → context → fallback, unvalidated rejected ──
    $fallback = $call('akira.theme.resolve@1', []);
    $check(
        ($fallback['ok'] ?? false) === true && ($fallback['theme_slug'] ?? '') === CAT_THEME_FALLBACK && ($fallback['resolved_from'] ?? '') === 'fallback',
        'resolve falls back to the canonical cms-akira-posts theme'
    );

    $db->prepare('INSERT INTO tenant_module_settings (tenant_id, module_id, setting_key, setting_value) VALUES (?, ?, ?, ?)')
        ->execute([$tenantA, 'cms-akira-theme', CAT_THEME_SETTING_ACTIVE, json_encode('ark-renderer-fixture')]);
    kernel_request_context_delete('_tenant_module_settings_cache');
    $fromSetting = $call('akira.theme.resolve@1', []);
    $check(
        ($fromSetting['ok'] ?? false) === true && ($fromSetting['theme_slug'] ?? '') === 'ark-renderer-fixture' && ($fromSetting['resolved_from'] ?? '') === 'setting',
        'resolve prefers the validated tenant-scoped module setting'
    );

    $db->prepare('DELETE FROM tenant_module_settings WHERE tenant_id = ? AND module_id = ? AND setting_key = ?')
        ->execute([$tenantA, 'cms-akira-theme', CAT_THEME_SETTING_ACTIVE]);
    kernel_request_context_delete('_tenant_module_settings_cache');
    kernel_request_context_set('active_theme_slug', 'ark-renderer-fixture');
    $fromContext = $call('akira.theme.resolve@1', []);
    $check(
        ($fromContext['ok'] ?? false) === true && ($fromContext['theme_slug'] ?? '') === 'ark-renderer-fixture' && ($fromContext['resolved_from'] ?? '') === 'request',
        'resolve honors a validated active_theme_slug request context'
    );
    kernel_request_context_delete('active_theme_slug');

    $db->prepare('INSERT INTO tenant_module_settings (tenant_id, module_id, setting_key, setting_value) VALUES (?, ?, ?, ?)')
        ->execute([$tenantA, 'cms-akira-theme', CAT_THEME_SETTING_ACTIVE, json_encode('evil-theme')]);
    kernel_request_context_delete('_tenant_module_settings_cache');
    $unvalidated = $call('akira.theme.resolve@1', []);
    $check(
        ($unvalidated['ok'] ?? false) === true && ($unvalidated['theme_slug'] ?? '') === CAT_THEME_FALLBACK,
        'an unvalidated setting slug fails closed to the validated fallback'
    );
    $db->prepare('DELETE FROM tenant_module_settings WHERE tenant_id = ? AND module_id = ? AND setting_key = ?')
        ->execute([$tenantA, 'cms-akira-theme', CAT_THEME_SETTING_ACTIVE]);
    kernel_request_context_delete('_tenant_module_settings_cache');

    $spoofDenied = $call('akira.theme.resolve@1', ['tenant_id' => $tenantB]);
    $check(($spoofDenied['ok'] ?? true) === false, 'resolve rejects payload-supplied tenant identity');

    // ── Registry: projected allowlist + deterministic ordering ──
    $listing = $call('akira.theme.registry@1', []);
    $slugs = array_column($listing['themes'] ?? [], 'slug');
    $check(
        ($listing['ok'] ?? false) === true
        && $slugs === ['akira-ark', 'ark-renderer-fixture', 'cms-akira-posts']
        && ($listing['total'] ?? -1) === 3,
        'registry lists the shipped Akira ARK theme and fixtures in deterministic slug order'
    );
    $check(
        array_keys(($listing['themes'][0] ?? [])) === ['slug', 'name', 'label', 'version', 'description', 'supported_surfaces', 'renderers', 'validated', 'active'],
        'registry projection is an explicit field allowlist'
    );
    $check(
        array_column($listing['themes'] ?? [], 'validated') === [true, true, true],
        'registry validates every listed theme'
    );

    // ── Validate: manifest/registry/lint/traversal/projected/fallback ──
    $akiraArk = $call('akira.theme.validate@1', ['theme_slug' => 'akira-ark']);
    $check(
        ($akiraArk['ok'] ?? false) === true
        && ($akiraArk['data']['valid'] ?? false) === true
        && ($akiraArk['data']['errors'] ?? null) === [],
        'validate accepts the shipped Akira ARK declarative package'
    );

    $valid = $call('akira.theme.validate@1', ['theme_slug' => 'cms-akira-posts']);
    $check(
        ($valid['ok'] ?? false) === true && ($valid['data']['valid'] ?? false) === true && ($valid['data']['errors'] ?? null) === [],
        'validate accepts the canonical cms-akira-posts theme'
    );
    $check(($valid['data']['fallback_available'] ?? false) === true, 'validate confirms deterministic fallback availability');
    $check(($valid['data']['checks']['disyl_lint'] ?? false) === true && ($valid['data']['checks']['manifest_shape'] ?? false) === true, 'validate lints DiSyL and confirms manifest shape');

    $badSlug = $call('akira.theme.validate@1', ['theme_slug' => '../etc']);
    $check(($badSlug['ok'] ?? true) === false, 'validate rejects a path-traversal slug');

    $hostileDir = $root . '/storage/cms-themes/hostile-theme-' . $prefix;
    @mkdir($hostileDir . '/public', 0777, true);
    file_put_contents($hostileDir . '/theme.manifest.json', json_encode([
        'name' => 'hostile-theme', 'version' => '1.0.0', 'label' => 'Hostile', 'supported_surfaces' => ['public'],
    ], JSON_UNESCAPED_SLASHES));
    file_put_contents($hostileDir . '/renderer-registry.json', json_encode([
        'renderers' => [
            '*' => ['template' => 'public/x.disyl', 'context_keys' => ['posts']],
            'entity.list.post' => ['template' => '../outside.disyl', 'context_keys' => ['tenant_id', 'posts']],
            'entity.detail.post' => ['template' => 'public/x.disyl', 'context_keys' => ['post']],
        ],
    ], JSON_UNESCAPED_SLASHES));
    file_put_contents($hostileDir . '/public/x.disyl', "<!-- Context: posts,post -->\n<section>{for p in posts}{p.title | esc_html}{/for}</section>\n");
    $hostileSlug = 'hostile-theme-' . $prefix;
    $hostile = $call('akira.theme.validate@1', ['theme_slug' => $hostileSlug]);
    $hostileErrors = implode("\n", $hostile['data']['errors'] ?? []);
    $check(
        ($hostile['data']['valid'] ?? true) === false
        && str_contains($hostileErrors, 'not a known entity view id')
        && str_contains($hostileErrors, 'escapes the theme directory')
        && str_contains($hostileErrors, 'non-projected key'),
        'hostile renderer registry (unknown view id, traversal template, projected-key violation) is rejected'
    );
    $removeTree($hostileDir);

    // ── Activate: governed v2, idempotent, audited, invalidates ──
    $activatePayload = [
        'idempotency_key' => $prefix . '-activate',
        'theme_slug' => 'ark-renderer-fixture',
    ];
    $activated = $call('akira.theme.activate@1', $activatePayload);
    $replayed = $call('akira.theme.activate@1', $activatePayload);
    $check(
        $activated === $replayed && $settingValue($tenantA) === 'ark-renderer-fixture',
        'activation is idempotent and persists one tenant-scoped module setting'
    );
    $check(
        ($activated['theme']['slug'] ?? '') === 'ark-renderer-fixture' && ($activated['operation'] ?? '') === 'theme.activate',
        'activation returns an explicit projected outcome'
    );

    $activeResolve = $call('akira.theme.resolve@1', []);
    $check(($activeResolve['theme_slug'] ?? '') === 'ark-renderer-fixture' && ($activeResolve['resolved_from'] ?? '') === 'setting', 'resolve observes the activated setting');

    $audit = $db->prepare(
        "SELECT new_data FROM audit_logs WHERE module = 'cms-akira-theme' AND action = 'akira.theme.activate' AND entity_id = ? ORDER BY id DESC LIMIT 1"
    );
    $audit->execute(['ark-renderer-fixture']);
    $auditData = json_decode((string) $audit->fetchColumn(), true);
    $check(
        is_array($auditData) && ($auditData['correlation_id'] ?? '') === ($activated['correlation_id'] ?? null),
        'durable same-PDO audit carries the mutation correlation id'
    );

    $fragmentStore = app()->templates()->fragmentStore();
    $fragmentStore->put($prefix . '-fragment', 'old theme', [CAT_THEME_INVALIDATION], 300, (string) $tenantA);
    $call('akira.theme.activate@1', [
        'idempotency_key' => $prefix . '-activate-2',
        'theme_slug' => 'cms-akira-posts',
    ]);
    $check(
        $fragmentStore->tryGet($prefix . '-fragment', [CAT_THEME_INVALIDATION], (string) $tenantA) === null,
        'successful activation invalidates the single canonical theme.active tag'
    );

    $count = $db->prepare("SELECT COUNT(*) FROM tenant_module_settings WHERE tenant_id = ? AND module_id = ? AND setting_key = ?");
    $count->execute([$tenantA, 'cms-akira-theme', CAT_THEME_SETTING_ACTIVE]);
    $check((int) $count->fetchColumn() === 1, 're-activation updates the same single setting row');

    $conflict = false;
    try {
        $changed = $activatePayload;
        $changed['theme_slug'] = 'cms-akira-posts';
        $call('akira.theme.activate@1', $changed);
    } catch (Throwable $error) {
        $conflict = $statusOf($error) === 409;
    }
    $check($conflict, 'same idempotency key with changed payload is rejected');

    $invalidActivate = false;
    try {
        $call('akira.theme.activate@1', [
            'idempotency_key' => $prefix . '-bad-theme',
            'theme_slug' => 'evil-theme',
        ]);
    } catch (Throwable $error) {
        $invalidActivate = $statusOf($error) === 422;
    }
    $check($invalidActivate, 'activation rejects an unvalidated theme slug');

    // ── Customizer: schema reads + governed tenant persistence ──
    $call('akira.theme.activate@1', [
        'idempotency_key' => $prefix . '-activate-ark',
        'theme_slug' => 'akira-ark',
    ]);
    $schema = $call('akira.theme.customizer.schema@1');
    $check(
        ($schema['ok'] ?? false) === true
        && isset($schema['data']['sections']['header']['controls']['brand'])
        && isset($schema['data']['sections']['footer']['controls']['message']),
        'customizer schema read projects the active declarative provider definition'
    );
    $customizePayload = [
        'idempotency_key' => $prefix . '-customize',
        'theme_slug' => 'akira-ark',
        'values' => ['header' => ['brand' => 'Tenant A Studio'], 'footer' => ['message' => 'Governed footer']],
    ];
    $customized = $call('akira.theme.customize@1', $customizePayload);
    $customizedReplay = $call('akira.theme.customize@1', $customizePayload);
    $values = $call('akira.theme.customizer.values@1');
    $check(
        $customized === $customizedReplay && ($values['values']['header']['brand'] ?? '') === 'Tenant A Studio',
        'customization is idempotent and the read returns tenant-scoped values'
    );
    $customizerSetting = $db->prepare('SELECT setting_value FROM tenant_module_settings WHERE tenant_id = ? AND module_id = ? AND setting_key = ?');
    $customizerSetting->execute([$tenantA, CAT_THEME_MODULE_ID, CAT_THEME_SETTING_CUSTOMIZER]);
    $storedCustomizer = json_decode((string)$customizerSetting->fetchColumn(), true);
    $check(($storedCustomizer['theme_slug'] ?? '') === 'akira-ark', 'customization persists only in tenant module settings');
    $customizerAudit = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE module = 'cms-akira-theme' AND action = 'akira.theme.customize' AND entity_id = 'akira-ark'");
    $customizerAudit->execute();
    $check((int)$customizerAudit->fetchColumn() === 1, 'customization records one durable audit row on replay');
    $invalidCustomizer = false;
    try {
        $call('akira.theme.customize@1', [
            'idempotency_key' => $prefix . '-invalid-customize',
            'theme_slug' => 'akira-ark',
            'values' => ['header' => ['unknown' => 'value']],
        ]);
    } catch (Throwable $error) {
        $invalidCustomizer = $statusOf($error) === 422 && str_contains($error->getPrevious()?->getMessage() ?? $error->getMessage(), 'unknown field');
    }
    $check($invalidCustomizer, 'invalid customizer values fail with a clear 422 error');
    app()->setUser(['id' => 999703, 'role' => 'author']);
    $authorDenied = false;
    try {
        $call('akira.theme.customize@1', $customizePayload + ['idempotency_key' => $prefix . '-author-customize']);
    } catch (Throwable $error) {
        $authorDenied = str_contains($error->getMessage(), 'authorization denied');
    }
    $check($authorDenied, 'customization policy denies authors');
    $setIdentity($tenantA, $admin);

    // ── Tenant isolation (shared schema) ──
    $setIdentity($tenantB, $admin);
    $tenantBResolve = $call('akira.theme.resolve@1', []);
    $check(
        ($tenantBResolve['ok'] ?? false) === true && ($tenantBResolve['theme_slug'] ?? '') === CAT_THEME_FALLBACK,
        'shared-schema tenant B does not inherit tenant A activation'
    );
    $setIdentity($tenantA, $admin);
    $spoofActivate = false;
    try {
        $call('akira.theme.activate@1', [
            'idempotency_key' => $prefix . '-spoof',
            'tenant_id' => $tenantB,
            'theme_slug' => 'cms-akira-posts',
        ]);
    } catch (Throwable $error) {
        $spoofActivate = $statusOf($error) === 422;
    }
    $check($spoofActivate, 'payload tenant identity is rejected on activation');

    app()->setUser(['id' => 999702, 'role' => 'editor']);
    $roleDenied = false;
    try {
        $call('akira.theme.activate@1', [
            'idempotency_key' => $prefix . '-role',
            'theme_slug' => 'cms-akira-posts',
        ]);
    } catch (Throwable $error) {
        $roleDenied = str_contains($error->getMessage(), 'authorization denied');
    }
    $check($roleDenied, 'governed activation policy denies a non-admin actor');
    $setIdentity($tenantA, $admin);

    // ── No-table proof ──
    $tables = $db->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'cms_akira_theme%'"
    );
    $check((int) $tables->fetchColumn() === 0, 'theme activation creates no cms_akira_theme table');
    $runner = new \Ikabud\Kernel\Database\MigrationRunner($db, $root . '/modules');
    $status = $runner->status('cms-akira-theme');
    $check(($status['pending'] ?? null) === [], 'theme migration ledger is converged with only the table-free marker');

    // ── Authority: this member's declared map is exact ──
    $source = implode("\n", array_map(static fn (string $file): string => (string) file_get_contents($file), [
        $module . '/module.json', $module . '/helpers.php', $module . '/handlers.php', $module . '/routes.php', $module . '/README.md',
    ]));
    $forbidden = ['cms' . 'ActiveTheme', 'cms' . 'Render', 'cms' . 'AdminContext', 'cms' . 'RequireCap', 'akira' . '.content.get@1', '/cms/' . 'admin/'];
    $hits = array_values(array_filter($forbidden, static fn (string $token): bool => str_contains($source, $token)));
    $check($hits === [], 'tracked theme has no forbidden legacy authority, render, or admin residue', implode(', ', $hits));

    $appLog = (string) @file_get_contents($root . '/storage/logs/app.log');
    $errorLog = (string) @file_get_contents($root . '/storage/logs/error.log');
    $check(!str_contains($appLog, '[error]') && trim($errorLog) === '', 'Phase 4A scenario leaves application/error logs clean', trim($errorLog));
} catch (Throwable $error) {
    $chain = [];
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        $chain[] = $cursor::class . ': ' . $cursor->getMessage();
    }
    $check(false, 'Phase 4A theme scenario completes', implode(' <- ', $chain));
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    try {
        $db->prepare('DELETE FROM tenant_module_settings WHERE tenant_id IN (?, ?) AND module_id = ?')->execute([$tenantA, $tenantB, 'cms-akira-theme']);
        $db->prepare('DELETE FROM kernel_idempotency_keys WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare("DELETE FROM audit_logs WHERE module = 'cms-akira-theme'")->execute();
        app()->templates()->fragmentStore()->flushAll((string) $tenantA);
        app()->templates()->fragmentStore()->flushAll((string) $tenantB);
        $hostileGlob = glob($root . '/storage/cms-themes/hostile-theme-' . $prefix . '*') ?: [];
        foreach ($hostileGlob as $hostilePath) {
            $removeTree($hostilePath);
        }
    } catch (Throwable $error) {
        $check(false, 'theme fixture cleanup succeeds', $error->getMessage());
    }
    app()->tenant()->setTenantId($originalTenant);
    kernel_request_context_delete('tenant_id');
    kernel_request_context_delete('_tenant_module_settings_cache');
    kernel_request_context_delete('active_theme_slug');
    @file_put_contents($root . '/storage/logs/app.log', '');
    @file_put_contents($root . '/storage/logs/error.log', '');
}

echo "\nCMS Akira theme: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
