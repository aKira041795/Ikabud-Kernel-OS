<?php

/** CMS Akira Phase 8 native AI contract and integration gate. */

declare(strict_types=1);

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
$register = static function (string $moduleId, array $handlers) use ($registry): void {
    foreach ($handlers as $id => $handler) {
        if (!$registry->has($id)) {
            $registry->register($id, $moduleId, static function (mixed $payload, string $capabilityId = '', string $provider = '') use ($moduleId, $handler): mixed {
                return moduleWithContext($moduleId, static fn (): mixed => $handler($payload, $capabilityId, $provider));
            }, 50, ['first']);
        }
    }
};
$register('cms-akira-core', cms_akira_core_capability_handlers());
$register(CAA_AI_MODULE_ID, cms_akira_ai_capability_handlers());

$tenantA = 995801;
$tenantB = 995802;
requireTenantModulesActive($tenantA, ['cms-akira-core', CAA_AI_MODULE_ID]);
$originalTenant = app()->tenant()->current();
$db = app()->db();
$setTenant = static function (int $tenantId): void {
    app()->tenant()->setTenantId($tenantId);
    kernel_request_context_set('tenant_id', $tenantId);
};
$call = static fn (array $payload): array => app()->cap()->call('akira.ai.summary.suggest@1', $payload, [
    'caller' => ['module' => CAA_AI_MODULE_ID],
    'mode' => 'first',
    'breaker_threshold' => 1000,
]);

@file_put_contents($root . '/storage/logs/app.log', '');
@file_put_contents($root . '/storage/logs/error.log', '');

try {
    echo "=== CMS Akira Phase 8 native AI ===\n";
    $setTenant($tenantA);
    $db->prepare('DELETE FROM cms_akira_posts WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
    $db->prepare("DELETE FROM tenant_module_settings WHERE tenant_id IN (?, ?) AND module_id = ? AND setting_key = ?")
        ->execute([$tenantA, $tenantB, CAA_AI_MODULE_ID, CAA_AI_LOCAL_SETTING]);
    $insert = $db->prepare(
        'INSERT INTO cms_akira_posts (tenant_id, slug, title, subtitle, content, image, status, published_at) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $body = '<p>Akira publishing makes local content clear. Akira tools keep publishing deterministic.</p>';
    $insert->execute([$tenantA, 'local-ai-post', 'Akira Publishing Guide', 'Deterministic local tools', $body, '', 'published', '2026-09-07 00:00:00']);
    $insert->execute([$tenantB, 'tenant-b-secret', 'TENANT_B_SECRET', 'Private projection', '<p>NEVER_LEAK_THIS_CONTENT</p>', '', 'published', '2026-09-07 00:00:00']);

    $module = dirname(__DIR__);
    $manifest = kernelReadJsonFile($module . '/module.json');
    $ids = array_column($manifest['capabilities']['exposes'] ?? [], 'id');
    $routes = require $module . '/routes.php';
    $check(($manifest['kind'] ?? '') === 'extension' && ($manifest['extends'] ?? '') === 'cms-akira-core', 'AI is a native core extension');
    $check(($manifest['depends'] ?? []) === ['cms-akira-core'] && ($manifest['capabilities']['depends'] ?? []) === ['entity.get.post@1'], 'only core and its Post projection are dependencies');
    $check(($manifest['owns_tables'] ?? null) === [] && ($manifest['reads_tables'] ?? null) === [], 'AI owns and reads no module tables');
    $check(($manifest['migrations'] ?? null) === ['database/migrations/001_initial.sql'], 'only the table-free marker migration is declared');
    $check($ids === ['akira.ai.summary.suggest@1'] && array_keys(cms_akira_ai_capability_handlers()) === $ids, 'single manifest capability matches runtime authority');
    $exposure = $manifest['capabilities']['exposes'][0] ?? [];
    $check(($exposure['modes'] ?? []) === ['first'] && !isset($exposure['requires_protocol']) && !isset($exposure['effects']), 'suggest is first-mode and read-only');
    $check(($manifest['_enabled'] ?? null) === false && !isset($manifest['nav']) && !isset($manifest['admin_contributions']) && !isset($manifest['entities']), 'activation remains explicit with zero host or Entity Authority');
    $check(array_keys($routes['GET'] ?? []) === ['/api/v1/cms-akira-ai/health'], 'legacy admin routing is absent');

    $first = $call(['id' => 'local-ai-post']);
    $second = $call(['id' => 'local-ai-post']);
    $suggestion = $first['data'] ?? [];
    $check(($first['ok'] ?? false) === true && ($suggestion['status'] ?? '') === 'ok', 'existing tenant Post returns a typed ok suggestion', json_encode($first));
    $check($first === $second, 'identical Post projection produces a stable result');
    $check(($suggestion['summary'] ?? '') === 'Akira publishing makes local content clear. Akira tools keep publishing deterministic.', 'summary is deterministic and extractive');
    $check(($suggestion['keywords'] ?? []) === ['akira', 'publishing', 'deterministic', 'local', 'tools', 'guide', 'makes', 'content'], 'keywords use stable frequency and source ordering', json_encode($suggestion['keywords'] ?? []));
    $check(array_keys($suggestion) === ['status', 'summary', 'keywords'], 'ok result exposes only the frozen fields');
    $check(!str_contains(json_encode($suggestion, JSON_THROW_ON_ERROR), '<p>'), 'suggestion text contains no source markup');

    $missing = $call(['id' => 'does-not-exist']);
    $check(($missing['ok'] ?? false) === true && ($missing['data'] ?? null) === ['status' => 'unavailable', 'reason' => 'entity_unavailable'], 'missing entity is typed non-fatal unavailable');
    $invalid = $call(['id' => '../tenant-b-secret', 'tenant_id' => $tenantB]);
    $check(($invalid['ok'] ?? false) === true && ($invalid['data']['reason'] ?? '') === 'invalid_entity_reference', 'invalid key and payload tenant identity are non-fatal and rejected');

    $crossTenant = $call(['id' => 'tenant-b-secret']);
    $crossJson = json_encode($crossTenant, JSON_THROW_ON_ERROR);
    $check(($crossTenant['data'] ?? null) === ['status' => 'unavailable', 'reason' => 'entity_unavailable'], 'tenant A cannot resolve tenant B entity');
    $check(!str_contains($crossJson, 'TENANT_B_SECRET') && !str_contains($crossJson, 'NEVER_LEAK_THIS_CONTENT'), 'cross-tenant response leaks no projected content');

    $projection = [
        'title' => 'Allowed title', 'subtitle' => 'Allowed subtitle', 'image' => '',
        'body' => '<p>Allowed body words</p>', 'metadata' => '2026-09-07',
        'actions' => ['view'], 'url' => '/posts/allowed',
    ];
    $localA = caaAiSuggestFromProjection($projection);
    $localB = caaAiSuggestFromProjection($projection);
    $check($localA === $localB && $localA['status'] === 'ok', 'pure projection algorithm is offline-testable');
    $leaky = caaAiSuggestFromProjection($projection + ['raw_content' => 'RAW_SECRET']);
    $check($leaky === ['status' => 'unavailable', 'reason' => 'projection_unavailable'], 'projection allowlist fails closed on undeclared fields');
    $check(!str_contains(json_encode($leaky, JSON_THROW_ON_ERROR), 'RAW_SECRET'), 'unavailable projection result does not echo unlisted data');

    $check(tenantWriteModuleSetting($db, $tenantA, CAA_AI_MODULE_ID, CAA_AI_LOCAL_SETTING, false), 'tenant local mode setting can be disabled');
    $disabled = $call(['id' => 'local-ai-post']);
    $check(($disabled['ok'] ?? false) === true && ($disabled['data'] ?? null) === ['status' => 'unavailable', 'reason' => 'local_mode_disabled'], 'config-off is typed non-fatal unavailable');
    $check(tenantWriteModuleSetting($db, $tenantA, CAA_AI_MODULE_ID, CAA_AI_LOCAL_SETTING, true), 'tenant local mode can be restored');
    $check(($call(['slug' => 'local-ai-post'])['data']['status'] ?? '') === 'ok', 'slug alias resolves through the same tenant projection boundary');

    $source = '';
    foreach (['helpers.php', 'handlers.php', 'routes.php', 'module.json'] as $file) {
        $source .= (string) file_get_contents($module . '/' . $file);
    }
    $check(!preg_match('/(?:curl_|file_get_contents\s*\(\s*["\']https?:|new\s+(?:OpenAI|Anthropic)|PDO\s*\()/i', $source), 'runtime has no remote transport, SDK, or direct SQL client');
    $check(!preg_match('/cms_akira_posts|tenant_id|akira\.content\.get@1|cmsRequireCap|cmsRender|cmsActiveTheme/', $source), 'runtime has no foreign table, payload tenant, or legacy CMS residue');

    $log = (string) @file_get_contents($root . '/storage/logs/app.log') . (string) @file_get_contents($root . '/storage/logs/error.log');
    $check(trim($log) === '', 'successful and unavailable paths leave logs clean', trim($log));
} finally {
    $setTenant($tenantA);
    $db->prepare('DELETE FROM cms_akira_posts WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
    $db->prepare("DELETE FROM tenant_module_settings WHERE tenant_id IN (?, ?) AND module_id = ? AND setting_key = ?")
        ->execute([$tenantA, $tenantB, CAA_AI_MODULE_ID, CAA_AI_LOCAL_SETTING]);
    if (is_int($originalTenant) && $originalTenant > 0) {
        $setTenant($originalTenant);
    }
}

echo "\nCMS Akira AI: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
