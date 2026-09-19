<?php

declare(strict_types=1);

/**
 * Phase A.2b — row context and row-click obey a declared field contract.
 *
 * Phase A.2a covered the two channels that serialize row data into a payload.
 * This item covers the remaining two, which leak row data into rendered
 * context and into URLs:
 *
 *   1. custom slot / `_children` (`renderWithRowContext()`) → `template_fields`
 *   2. action URL / row-click (`renderWithRowContext()` / `renderRowClickAttrs()`) → `url_key_fields`
 *
 * The rule, identical for both (and identical to A.1 / A.2a):
 *
 *   - declared list present        → authoritative; only those fields reach the
 *                                    context/URL; an empty list emits nothing
 *   - absent                       → the channel receives no row data
 *   - malformed (non-array /
 *     non-string members)          → emits nothing, never the whole row
 *   - a declared field the row
 *     does not have                → skipped, never substituted
 *
 * URL values are validated, not merely HTML-escaped. Permitted schemes are
 * `http`, `https`, `mailto`, `tel`, `ftp` and scheme-relative paths. Everything
 * else — `javascript:`, a control-character-obfuscated scheme, or a
 * protocol-relative `//` target — is refused: no href is rendered and no
 * row-click handler is attached.
 */

require_once __DIR__ . '/harness/TestHarness.php';

use Ikabud\Kernel\EntityContext\DefaultEntityRenderer;

$h = new TestHarness('entity-view-context-projection', TestHarness::MODE_INTEGRATION);
$h->fingerprint('kernel/EntityContext/DefaultEntityRenderer.php');
$h->fingerprint('kernel/EntityContext/RowRenderContext.php');

$renderer = new DefaultEntityRenderer();

// The fixture deliberately carries internal members (`tenant_id`, `cost`) next
// to the public ones, so a regression to "interpolate every row member" is
// detectable. `url` is a javascript: URI so a scheme regression is detectable.
$row = [
    'id' => 7,
    'name' => 'Alpha',
    'status' => 'draft',
    'tenant_id' => 'TENANT-SECRET-991',
    'cost' => 'COST-SECRET-992',
    'url' => 'javascript:alert(1)',
];

$internalNeedles = ['TENANT-SECRET-991', 'COST-SECRET-992', 'COST-SECRET', 'TENANT-SECRET'];
$containsInternal = static function (string $html) use ($internalNeedles): bool {
    foreach ($internalNeedles as $needle) {
        if (str_contains($html, $needle)) {
            return true;
        }
    }
    return false;
};

$attrs = ['source' => 'test_entity.all', 'view' => 'table'];
$slotAttrs = static fn (string $children): array => ['source' => 'test_entity.all', 'view' => 'table', '_children' => $children];

// ══════════════════════════════════════════════════════════════════════════════
// Channel 1 — custom slot (`_children` governed by `template_fields`)
// ══════════════════════════════════════════════════════════════════════════════

$h->section('Channel 1 — declared template_fields is authoritative');

$declaredSlot = $renderer->renderList([$row], [
    'fields' => ['name'],
    'view' => 'table',
    'template_fields' => ['id', 'name'],
], $slotAttrs('<i class="slot">#{id}:{name}:{status}</i>'));
$h->test('declared fields are interpolated with their row values', str_contains($declaredSlot, '#7:Alpha:'), 'slot was: ' . $declaredSlot);
$h->test('an undeclared row field is not interpolated', !str_contains($declaredSlot, 'draft') && !str_contains($declaredSlot, '{status}'));
$h->test('the declared channel leaks no internal row data', !$containsInternal($declaredSlot));

$h->section('Channel 1 — a declared field the row lacks is skipped, never substituted');

$missingSlot = $renderer->renderList([$row], [
    'fields' => ['name'],
    'view' => 'table',
    'template_fields' => ['id', 'does_not_exist'],
], $slotAttrs('<i class="slot">#{id}:{does_not_exist}</i>'));
$h->test('missing declared field resolves to nothing', str_contains($missingSlot, '#7:'));
$h->test('missing declared field never leaves a placeholder literal', !str_contains($missingSlot, 'does_not_exist') && !str_contains($missingSlot, '{does_not_exist}'));
$h->test('missing declared field never leaks internal row data', !$containsInternal($missingSlot));

$h->section('Channel 1 — an empty declared list emits no row data');

$emptySlot = $renderer->renderList([$row], [
    'fields' => ['name'],
    'view' => 'table',
    'template_fields' => [],
], $slotAttrs('<i class="slot">{name}:{tenant_id}:{id}</i>'));
$h->test('empty list interpolates no row data', !str_contains($emptySlot, 'Alpha') && !str_contains($emptySlot, '{name}'));
$h->test('empty list still renders the slot markup', str_contains($emptySlot, 'class="slot"'));
$h->test('empty list leaks no internal row data', !$containsInternal($emptySlot));

$h->section('Channel 1 — absent template_fields emits no row data');

$absentSlot = $renderer->renderList([$row], [
    'fields' => ['name'],
    'view' => 'table',
], $slotAttrs('<i class="slot">{name}:{id}:{tenant_id}</i>'));
$h->test('absent contract interpolates no row data', !str_contains($absentSlot, 'Alpha') && !str_contains($absentSlot, '{name}'));
$h->test('absent contract leaks no internal row data', !$containsInternal($absentSlot));

$h->section('Channel 1 — malformed template_fields emits nothing, never the whole row');

$malformedSlot = [
    'non-array string' => 'id,name',
    'scalar int' => 42,
    'non-string member' => ['id', 42],
    'nested member' => ['id', ['name']],
    'null member' => [null],
];
foreach ($malformedSlot as $label => $declaredValue) {
    $html = $renderer->renderList([$row], [
        'fields' => ['name'],
        'view' => 'table',
        'template_fields' => $declaredValue,
    ], $slotAttrs('<i class="slot">{id}:{name}:{tenant_id}</i>'));
    $h->test("malformed [{$label}] interpolates no row data", !str_contains($html, 'Alpha') && !str_contains($html, '{name}'));
    $h->test("malformed [{$label}] leaks no internal row data", !$containsInternal($html));
}

// ══════════════════════════════════════════════════════════════════════════════
// Channel 2a — action URL (`url_key_fields`)
// ══════════════════════════════════════════════════════════════════════════════

$h->section('Channel 2a — declared url_key_fields is authoritative for action URLs');

$declaredUrl = $renderer->renderList([$row], [
    'fields' => ['name'],
    'view' => 'table',
    'actions' => ['view'],
    'action_methods' => ['view' => 'GET'],
    'action_urls' => ['view' => '/posts/{id}?secret={tenant_id}'],
    'url_key_fields' => ['id'],
], $attrs);
$h->test('declared field reaches the action URL', str_contains($declaredUrl, 'href="/posts/7?secret="'), 'html was: ' . $declaredUrl);
$h->test('undeclared row field never reaches the action URL', !$containsInternal($declaredUrl) && !str_contains($declaredUrl, 'TENANT-SECRET-991'));

$h->section('Channel 2a — a permitted scheme is emitted, a disallowed one is refused');

$httpsUrl = $renderer->renderList([$row], [
    'fields' => ['name'],
    'view' => 'table',
    'actions' => ['view'],
    'action_methods' => ['view' => 'GET'],
    'action_urls' => ['view' => 'https://example.test/posts/{id}'],
    'url_key_fields' => ['id'],
], $attrs);
$h->test('https action URL is permitted', str_contains($httpsUrl, 'href="https://example.test/posts/7"'), 'html was: ' . $httpsUrl);

$jsUrl = $renderer->renderList([$row], [
    'fields' => ['name'],
    'view' => 'table',
    'actions' => ['view'],
    'action_methods' => ['view' => 'GET'],
    'action_urls' => ['view' => '{url}'],
    'url_key_fields' => ['url'],
], $attrs);
$h->test('javascript: action URL is refused', !str_contains($jsUrl, 'javascript:') && !str_contains($jsUrl, '<a href='), 'html was: ' . $jsUrl);

$tabUrl = $renderer->renderList([$row], [
    'fields' => ['name'],
    'view' => 'table',
    'actions' => ['view'],
    'action_methods' => ['view' => 'GET'],
    'action_urls' => ['view' => "java\tscript:alert(1)"],
], $attrs);
$h->test('control-character-obfuscated scheme is refused', !str_contains($tabUrl, '<a href='), 'html was: ' . $tabUrl);

$protoUrl = $renderer->renderList([$row], [
    'fields' => ['name'],
    'view' => 'table',
    'actions' => ['view'],
    'action_methods' => ['view' => 'GET'],
    'action_urls' => ['view' => '//evil.example/{id}'],
    'url_key_fields' => ['id'],
], $attrs);
$h->test('protocol-relative action URL is refused', !str_contains($protoUrl, 'evil.example') && !str_contains($protoUrl, '<a href='), 'html was: ' . $protoUrl);

$h->section('Channel 2a — absent / empty / malformed url_key_fields emits no row data');

$noUrlData = [
    'absent' => ['fields' => ['name'], 'view' => 'table', 'actions' => ['view'], 'action_methods' => ['view' => 'GET'], 'action_urls' => ['view' => '/posts/{id}']],
    'empty list' => ['fields' => ['name'], 'view' => 'table', 'actions' => ['view'], 'action_methods' => ['view' => 'GET'], 'action_urls' => ['view' => '/posts/{id}'], 'url_key_fields' => []],
    'non-array' => ['fields' => ['name'], 'view' => 'table', 'actions' => ['view'], 'action_methods' => ['view' => 'GET'], 'action_urls' => ['view' => '/posts/{id}'], 'url_key_fields' => 'id'],
    'non-string member' => ['fields' => ['name'], 'view' => 'table', 'actions' => ['view'], 'action_methods' => ['view' => 'GET'], 'action_urls' => ['view' => '/posts/{id}'], 'url_key_fields' => ['id', 42]],
];
foreach ($noUrlData as $label => $view) {
    $html = $renderer->renderList([$row], $view, $attrs);
    $h->test("[{$label}] action URL carries no row data", !str_contains($html, '/posts/7') && !$containsInternal($html), 'html was: ' . $html);
}

$h->section('Channel 2a — a declared field the row lacks is skipped, never substituted');

$missingUrl = $renderer->renderList([$row], [
    'fields' => ['name'],
    'view' => 'table',
    'actions' => ['view'],
    'action_methods' => ['view' => 'GET'],
    'action_urls' => ['view' => '/posts/{does_not_exist}'],
    'url_key_fields' => ['id', 'does_not_exist'],
], $attrs);
$h->test('missing action-URL field never leaves a placeholder literal', !str_contains($missingUrl, 'does_not_exist') && !str_contains($missingUrl, '{does_not_exist}'));
$h->test('missing action-URL field leaks no internal row data', !$containsInternal($missingUrl));

// ══════════════════════════════════════════════════════════════════════════════
// Channel 2b — row-click (`url_key_fields`)
// ══════════════════════════════════════════════════════════════════════════════

$h->section('Channel 2b — declared url_key_fields is authoritative for row-click');

$declaredClick = $renderer->renderList([$row], [
    'fields' => ['name'],
    'view' => 'table',
    'url_key_fields' => ['id'],
], $attrs + ['row-click' => '/rows/{id}']);
$h->test('declared field produces the row-click target', str_contains($declaredClick, 'window.location.href=') && str_contains($declaredClick, 'rows'), 'html was: ' . $declaredClick);
$h->test('declared row-click leaks no internal row data', !$containsInternal($declaredClick));

$mixedClick = $renderer->renderList([$row], [
    'fields' => ['name'],
    'view' => 'table',
    'url_key_fields' => ['id'],
], $attrs + ['row-click' => '/rows/{id}?secret={tenant_id}']);
$h->test('an undeclared field in a row-click target withholds the target', !str_contains($mixedClick, 'window.location.href=') && !$containsInternal($mixedClick));

$h->section('Channel 2b — absent / empty / malformed url_key_fields emits no row data');

$noClickData = [
    'absent' => ['fields' => ['name'], 'view' => 'table'],
    'empty list' => ['fields' => ['name'], 'view' => 'table', 'url_key_fields' => []],
    'non-array' => ['fields' => ['name'], 'view' => 'table', 'url_key_fields' => 'id'],
    'non-string member' => ['fields' => ['name'], 'view' => 'table', 'url_key_fields' => ['id', 42]],
];
foreach ($noClickData as $label => $view) {
    $html = $renderer->renderList([$row], $view, $attrs + ['row-click' => '/rows/{id}']);
    $h->test("[{$label}] row-click emits no target", !str_contains($html, 'window.location.href=') && !$containsInternal($html), 'html was: ' . $html);
}

$h->section('Channel 2b — a declared field the row lacks withholds the target');

$missingClick = $renderer->renderList([$row], [
    'fields' => ['name'],
    'view' => 'table',
    'url_key_fields' => ['id', 'does_not_exist'],
], $attrs + ['row-click' => '/rows/{does_not_exist}']);
$h->test('missing row-click field never leaves a placeholder literal', !str_contains($missingClick, 'does_not_exist') && !str_contains($missingClick, 'window.location.href='));

$h->section('Channel 2b — disallowed row-click schemes are refused');

$jsClick = $renderer->renderList([$row], ['fields' => ['name'], 'view' => 'table'], $attrs + ['row-click' => 'javascript:alert(1)']);
$h->test('javascript: row-click is refused', !str_contains($jsClick, 'window.location.href=') && !str_contains($jsClick, 'javascript:'));

$tabClick = $renderer->renderList([$row], ['fields' => ['name'], 'view' => 'table'], $attrs + ['row-click' => "java\nscript:alert(1)"]);
$h->test('control-character-obfuscated row-click scheme is refused', !str_contains($tabClick, 'window.location.href='));

$protoClick = $renderer->renderList([$row], [
    'fields' => ['name'],
    'view' => 'table',
    'url_key_fields' => ['id'],
], $attrs + ['row-click' => '//evil.example/{id}']);
$h->test('protocol-relative row-click is refused', !str_contains($protoClick, 'window.location.href=') && !str_contains($protoClick, 'evil.example'));

$httpsClick = $renderer->renderList([$row], [
    'fields' => ['name'],
    'view' => 'table',
    'url_key_fields' => ['id'],
], $attrs + ['row-click' => 'https://example.test/rows/{id}']);
$h->test('https row-click is permitted', str_contains($httpsClick, 'window.location.href=') && str_contains($httpsClick, 'example.test'), 'html was: ' . $httpsClick);

$h->done();
