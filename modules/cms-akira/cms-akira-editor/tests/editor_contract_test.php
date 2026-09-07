<?php

declare(strict_types=1);

$module = dirname(__DIR__);
require_once $module . '/helpers.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

$manifest = json_decode((string) file_get_contents($module . '/module.json'), true, 512, JSON_THROW_ON_ERROR);
$routes = require $module . '/routes.php';
$ids = array_column($manifest['capabilities']['exposes'] ?? [], 'id');
$expected = [
    'akira.editor.render@1',
    'akira.editor.normalize@1',
    'akira.editor.sanitize@1',
    'akira.editor.validate@1',
    'akira.editor.assets@1',
];
$projection = [
    'title' => 'Projected Post',
    'subtitle' => 'Public fields only',
    'image' => '/media/post.jpg',
    'body' => "<p onclick=\"bad()\">Hello <strong>Akira</strong></p>\r\n<script>alert(1)</script><a href=\"javascript:alert(2)\">bad</a><a href=\"/safe\" target=\"_blank\">safe</a>",
    'metadata' => '2026-09-07 00:00:00',
    'actions' => ['view'],
    'url' => '/posts/projected-post',
];
$payload = ['post' => $projection];

$check($ids === $expected, 'manifest exposes only the five native versioned capabilities');
$check(($manifest['depends'] ?? []) === ['cms-akira-core'], 'module depends on cms-akira-core');
$check(($manifest['capabilities']['depends'] ?? []) === ['entity.get.post@1'], 'content source is the Post detail projection');
$check(($manifest['owns_tables'] ?? null) === [] && ($manifest['reads_tables'] ?? null) === [] && ($manifest['migrations'] ?? null) === [], 'module is table-free');
$check(($manifest['routes'] ?? false) === true && ($routes['GET']['/api/v1/cms-akira-editor/health'] ?? '') === 'cms-akira-editor:akiraEditorHealth', 'only native health routing is declared');
$check(!isset($manifest['nav']) && !isset($manifest['admin_contributions']), 'module has no host-admin residue');
$check(($manifest['_enabled'] ?? null) === false, 'tenant activation remains explicit');
$check(array_keys(cms_akira_editor_capability_handlers()) === $expected, 'runtime handlers exactly match manifest authority');

$normalizeA = cae_cap_akira_editor_normalize_1($payload);
$normalizeB = cae_cap_akira_editor_normalize_1($payload);
$sanitizeA = cae_cap_akira_editor_sanitize_1($payload);
$sanitizeB = cae_cap_akira_editor_sanitize_1($payload);
$normalized = (string) ($normalizeA['data']['content'] ?? '');
$check($normalizeA === $normalizeB, 'normalize is deterministic for identical projections');
$check($sanitizeA === $sanitizeB, 'sanitize is deterministic for identical projections');
$check(($normalizeA['data']['content'] ?? null) === ($sanitizeA['data']['content'] ?? null), 'normalize and sanitize share canonical parity');
$check(!str_contains($normalized, '<script') && !str_contains($normalized, 'onclick') && !str_contains($normalized, 'javascript:'), 'canonical policy removes executable markup');
$check(str_contains($normalized, '<strong>Akira</strong>') && str_contains($normalized, 'href="/safe"') && str_contains($normalized, 'rel="noopener noreferrer"'), 'canonical policy preserves safe formatting and links');

$render = cae_cap_akira_editor_render_1($payload);
$check(($render['ok'] ?? false) === true && ($render['data']['html'] ?? null) === $normalized, 'render emits the canonical safe body');
$check(($render['data']['source'] ?? '') === 'akira.post.projection', 'render identifies its projection source');
$valid = cae_cap_akira_editor_validate_1($payload);
$empty = cae_cap_akira_editor_validate_1(['post' => ['body' => '  ']]);
$check(($valid['data']['valid'] ?? false) === true && ($valid['data']['errors'] ?? null) === [], 'validate accepts a populated projected body');
$check(($empty['ok'] ?? false) === true && ($empty['data']['valid'] ?? true) === false, 'validate reports an empty projected body');
$check((cae_cap_akira_editor_validate_1('bad')['ok'] ?? true) === false, 'validate rejects a non-object payload');
$check((cae_cap_akira_editor_render_1(['post' => $projection + ['tenant_id' => 7]])['ok'] ?? true) === false, 'internal domain fields fail closed');
$check((cae_cap_akira_editor_render_1(['post' => ['content' => 'domain body']])['ok'] ?? true) === false, 'domain content rows are not accepted as projections');

$assets = cae_cap_akira_editor_assets_1([]);
$assetData = $assets['data'] ?? [];
$check(($assets['ok'] ?? false) === true && ($assetData['external'] ?? true) === false, 'assets require no external provider');
$check(($assetData['js'] ?? []) === ['/assets/modules/cms-akira-editor/editor.js'] && ($assetData['css'] ?? []) === ['/assets/modules/cms-akira-editor/editor.css'], 'assets are Akira-owned stable URLs');
$check(is_file($module . '/assets/editor.js') && is_file($module . '/assets/editor.css'), 'declared editor assets are present');

$source = (string) file_get_contents($module . '/helpers.php') . (string) file_get_contents($module . '/module.json');
$check(!preg_match('/[\"\']editor\.(?:render|normalize|sanitize|validate|assets)@1/', $source), 'no unowned capability id is declared');
$first = caeEditorCanonicalHtml($projection['body']);
$second = caeEditorCanonicalHtml($first);
$check($first === $second, 'canonical preparation is idempotent');

echo "\nCMS Akira editor: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
