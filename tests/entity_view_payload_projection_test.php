<?php

declare(strict_types=1);

/**
 * Phase A.2a — serialized payloads obey a declared field contract.
 *
 * Two emission channels serialize row data into a payload:
 *
 *   1. row-action POST hidden inputs (`renderRowActions()`)  → `action_payload_fields`
 *   2. inline edit embedded context (`renderCellEditable()`) → `editable_context_fields`
 *
 * The rule, identical for both channels:
 *
 *   - declared list present        → authoritative; only those fields are emitted
 *   - declared list empty          → emits nothing
 *   - absent                       → emits no row data
 *   - malformed (non-array /
 *     non-string members)          → emits nothing, never "all scalar members"
 *   - declared field missing in row→ skipped, never substituted
 *
 * Two further requirements from the plan, proven here:
 *
 *   3. POST rendering is omitted when a trusted CSRF token cannot be acquired
 *      (fail closed) — see tests/default_entity_renderer_post_row_action_test.php
 *      for the token-present path; the token-absent path is asserted here.
 *   4. URL values are scheme-/target-validated, not merely HTML-escaped.
 */

require_once __DIR__ . '/harness/TestHarness.php';

use Ikabud\Kernel\EntityContext\DefaultEntityRenderer;

// CSRF fail-closed is proven in a child process with NO bootstrap, so the
// `app()` helper does not exist at all. That is the exact condition the
// renderer must survive: no trusted token source, therefore no POST form.
if (($argv[1] ?? '') === '--csrf-child') {
    $basePath = dirname(__DIR__);
    if (!defined('BASE_PATH')) { define('BASE_PATH', $basePath); }
    if (!defined('KERNEL_PATH')) { define('KERNEL_PATH', $basePath . '/kernel'); }
    if (!defined('STORAGE_PATH')) { define('STORAGE_PATH', $basePath . '/storage'); }

    spl_autoload_register(static function (string $class) use ($basePath): void {
        $prefix = 'Ikabud\\Kernel\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $path = $basePath . '/kernel/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (file_exists($path)) {
            require_once $path;
        }
    });

    $childRenderer = new \Ikabud\Kernel\EntityContext\DefaultEntityRenderer();
    $childHtml = $childRenderer->renderList(
        [['id' => 7, 'name' => 'Alpha', 'tenant_id' => 'TENANT-SECRET-991']],
        [
            'fields' => ['name'],
            'view' => 'table',
            'actions' => ['archive'],
            'action_methods' => ['archive' => 'POST'],
            'action_payload_fields' => ['id'],
            'renderers' => ['name' => 'string'],
        ],
        ['source' => 'csrf_child.all', 'view' => 'table']
    );
    echo str_contains($childHtml, '<form method="post"') ? 'FORM_PRESENT' : 'FORM_WITHHELD';
    exit(0);
}

$h = new TestHarness('entity-view-payload-projection', TestHarness::MODE_INTEGRATION);
$h->fingerprint('kernel/EntityContext/DefaultEntityRenderer.php');
$h->fingerprint('kernel/EntityContext/RowRenderContext.php');

$renderer = new DefaultEntityRenderer();

// The fixture deliberately carries internal members (`tenant_id`, `cost`) and a
// non-scalar member (`meta`) alongside the public ones, so a regression to
// "serialize every scalar member" is detectable.
$row = [
    'id' => 7,
    'name' => 'Alpha',
    'status' => 'draft',
    'quantity' => 3,
    'tenant_id' => 'TENANT-SECRET-991',
    'cost' => 'COST-SECRET-992',
    'url' => 'javascript:alert(1)',
    'meta' => ['skip' => true],
];

$baseView = [
    'fields' => ['name', 'status'],
    'view' => 'table',
    'actions' => ['archive'],
    'action_methods' => ['archive' => 'POST'],
    'renderers' => ['name' => 'string', 'status' => 'string'],
];

$attrs = ['source' => 'test_entity.all', 'view' => 'table'];

/**
 * Extract the hidden input names from rendered row-action HTML, in order.
 *
 * @return list<string>
 */
function payloadHiddenNames(string $html): array
{
    preg_match_all('/<input type="hidden" name="([^"]+)" value="[^"]*">/', $html, $m);
    // The CSRF token is not row data; it travels on every form regardless.
    return array_values(array_filter($m[1], static fn (string $name): bool => $name !== '_token'));
}

/** Decode the `x-data="ikbInlineEdit({...})"` config embedded by renderCellEditable(). */
function editableConfig(string $html): array
{
    if (!preg_match('/x-data="ikbInlineEdit\((.*)\)" class="inline"/s', $html, $m)) {
        return [];
    }
    $json = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

// ══════════════════════════════════════════════════════════════════════════════
// Channel 1 — row-action POST hidden inputs (action_payload_fields)
// ══════════════════════════════════════════════════════════════════════════════

$h->section('Channel 1 — declared action_payload_fields is authoritative');

$declared = $renderer->renderList([$row], $baseView + [
    'action_payload_fields' => ['id', 'name', 'quantity'],
], $attrs);
$h->assertSame(['id', 'name', 'quantity'], payloadHiddenNames($declared), 'declared list emits exactly the declared fields');
$h->test('declared field values are present', str_contains($declared, 'name="name" value="Alpha"') && str_contains($declared, 'name="quantity" value="3"'));
$h->test('undeclared internal scalar is not serialized', !str_contains($declared, 'tenant_id') && !str_contains($declared, 'TENANT-SECRET-991'));
$h->test('undeclared internal scalar (cost) is not serialized', !str_contains($declared, 'COST-SECRET-992'));

$h->section('Channel 1 — a declared field the row lacks is skipped, never substituted');

$missing = $renderer->renderList([$row], $baseView + [
    'action_payload_fields' => ['id', 'does_not_exist'],
], $attrs);
$h->assertSame(['id'], payloadHiddenNames($missing), 'missing declared field is skipped');
$h->test('missing field is not emitted with a placeholder value', !str_contains($missing, 'does_not_exist') && !str_contains($missing, '{does_not_exist}'));

$h->section('Channel 1 — an empty declared list emits nothing');

$empty = $renderer->renderList([$row], $baseView + [
    'action_payload_fields' => [],
], $attrs);
$h->assertSame([], payloadHiddenNames($empty), 'empty declared list emits no hidden inputs');
$h->test('empty declared list still renders the form and token', str_contains($empty, '<form method="post"') && str_contains($empty, 'name="_token"'));
$h->test('empty declared list emits no internal row data', !str_contains($empty, 'TENANT-SECRET-991') && !str_contains($empty, 'COST-SECRET-992'));
$h->test('the action URL still carries the row id', str_contains($empty, 'action="?id=7&amp;action=archive"'));

$h->section('Channel 1 — absent action_payload_fields emits no row data');

$absent = $renderer->renderList([$row], $baseView, $attrs);
$h->assertSame([], payloadHiddenNames($absent), 'absent contract emits no payload');
$h->test('absent contract leaks no internal row data', !str_contains($absent, 'TENANT-SECRET-991') && !str_contains($absent, 'COST-SECRET-992'));

$h->section('Channel 1 — malformed action_payload_fields emits nothing');

$malformed = [
    'non-array string' => 'id,name',
    'scalar int' => 42,
    'non-string member' => ['id', 42],
    'nested member' => ['id', ['name']],
    'null member' => [null],
];
foreach ($malformed as $label => $declaredValue) {
    $html = $renderer->renderList([$row], $baseView + ['action_payload_fields' => $declaredValue], $attrs);
    $h->assertSame([], payloadHiddenNames($html), "malformed [{$label}] emits no hidden inputs, never all scalars");
    $h->test("malformed [{$label}] does not leak internal row data", !str_contains($html, 'TENANT-SECRET-991') && !str_contains($html, 'COST-SECRET-992'));
}

$h->section('Channel 1 — non-scalar declared members cannot become hidden inputs');

$nonScalar = $renderer->renderList([$row], $baseView + [
    'action_payload_fields' => ['id', 'meta'],
], $attrs);
$h->assertSame(['id'], payloadHiddenNames($nonScalar), 'declared non-scalar member is skipped');

// ══════════════════════════════════════════════════════════════════════════════
// Channel 2 — inline edit embedded row context (editable_context_fields)
// ══════════════════════════════════════════════════════════════════════════════

$editableContract = static fn (mixed $declared = null, bool $hasKey = true): array => array_filter([
    'editable' => true,
    'update_capability' => 'test.entity.update@1',
    'editable_context_fields' => $hasKey ? $declared : null,
], static fn ($v): bool => $v !== null);

$h->section('Channel 2 — declared editable_context_fields is authoritative');

$declaredCell = $renderer->renderCellEditable('Alpha', 'string', 'name', $row, $editableContract(['id', 'name'], true));
$declaredConfig = editableConfig($declaredCell);
$h->assertSame(['id', 'name'], array_keys($declaredConfig['rowData'] ?? []), 'rowData contains exactly the declared fields');
$h->test('declared rowData values are correct', ($declaredConfig['rowData']['id'] ?? null) === 7 && ($declaredConfig['rowData']['name'] ?? null) === 'Alpha');
$h->test('undeclared internal row member is not embedded', !array_key_exists('tenant_id', $declaredConfig['rowData'] ?? []) && !str_contains($declaredCell, 'TENANT-SECRET-991'));
$h->test('undeclared internal cost is not embedded', !str_contains($declaredCell, 'COST-SECRET-992'));

$h->section('Channel 2 — a declared field the row lacks is skipped, never substituted');

$missingCell = $renderer->renderCellEditable('Alpha', 'string', 'name', $row, $editableContract(['id', 'does_not_exist'], true));
$h->assertSame(['id'], array_keys(editableConfig($missingCell)['rowData'] ?? []), 'missing declared field is skipped');
$h->test('missing field is not embedded as a placeholder', !str_contains($missingCell, 'does_not_exist') && !str_contains($missingCell, '{does_not_exist}'));

$h->section('Channel 2 — an empty declared list embeds nothing');

$emptyCell = $renderer->renderCellEditable('Alpha', 'string', 'name', $row, $editableContract([], true));
$h->assertSame([], editableConfig($emptyCell)['rowData'] ?? null, 'empty declared list embeds no row data');
$h->test('empty declared list still renders the editable component', str_contains($emptyCell, 'ikbInlineEdit'));
$h->test('empty declared list leaks no internal row data', !str_contains($emptyCell, 'TENANT-SECRET-991') && !str_contains($emptyCell, 'COST-SECRET-992'));

$h->section('Channel 2 — absent editable_context_fields embeds no row data');

$absentCell = $renderer->renderCellEditable('Alpha', 'string', 'name', $row, [
    'editable' => true,
    'update_capability' => 'test.entity.update@1',
]);
$h->assertSame([], editableConfig($absentCell)['rowData'] ?? null, 'absent contract embeds no row data');
$h->test('absent contract leaks no internal row data', !str_contains($absentCell, 'TENANT-SECRET-991') && !str_contains($absentCell, 'COST-SECRET-992'));

$h->section('Channel 2 — malformed editable_context_fields embeds nothing');

$malformedEditable = [
    'non-array string' => 'id,name',
    'scalar int' => 42,
    'non-string member' => ['id', 42],
    'nested member' => ['id', ['name']],
    'null member' => [null],
];
foreach ($malformedEditable as $label => $declaredValue) {
    $cell = $renderer->renderCellEditable('Alpha', 'string', 'name', $row, $editableContract($declaredValue, true));
    $h->assertSame([], editableConfig($cell)['rowData'] ?? null, "malformed [{$label}] embeds no row data, never all scalars");
    $h->test("malformed [{$label}] leaks no internal row data", !str_contains($cell, 'TENANT-SECRET-991') && !str_contains($cell, 'COST-SECRET-992'));
}

$h->section('Channel 2 — the view contract supplies the list when the field contract omits it');

$viewContractHtml = $renderer->renderList([$row], [
    'fields' => ['name'],
    'view' => 'table',
    'renderers' => ['name' => 'string'],
    'editable_context_fields' => ['id'],
    'field_contracts' => [
        'name' => ['editable' => true, 'update_capability' => 'test.entity.update@1'],
    ],
], $attrs);
$h->assertSame(['id'], array_keys(editableConfig($viewContractHtml)['rowData'] ?? []), 'view-level editable_context_fields governs the per-field contract');
$h->test('per-field contract overrides the view-level list', array_keys(editableConfig($renderer->renderCellEditable('Alpha', 'string', 'name', $row, $editableContract(['id', 'name'], true)))['rowData'] ?? []) === ['id', 'name']);

// ══════════════════════════════════════════════════════════════════════════════
// Requirement 3 — POST rendering is omitted when no trusted CSRF token exists
// ══════════════════════════════════════════════════════════════════════════════

$h->section('CSRF fail-closed — no token, no submittable POST form');

// Spawn the same file with no bootstrap. `app()` does not exist there, so the
// renderer cannot acquire a trusted token and must withhold the form.
$childOutput = trim((string) shell_exec(PHP_BINARY . ' ' . escapeshellarg(__FILE__) . ' --csrf-child 2>/dev/null'));
$h->test('no trusted token source → no POST form is rendered', $childOutput === 'FORM_WITHHELD', 'child said: ' . $childOutput);
$h->test('no trusted token source → no row data leaks through the withheld form', !str_contains($childOutput, 'TENANT-SECRET-991'));

// ══════════════════════════════════════════════════════════════════════════════
// Requirement 4 — URL values are scheme-/target-validated, not merely escaped
// ══════════════════════════════════════════════════════════════════════════════

$h->section('URL validation — dangerous action URLs are withheld');

$getView = [
    'fields' => ['name'],
    'view' => 'table',
    'actions' => ['view'],
    'action_methods' => ['view' => 'GET'],
    'renderers' => ['name' => 'string'],
    // A.2b: action-URL interpolation is governed by url_key_fields. Declaring
    // `url` here keeps the javascript: row value in scope so the scheme check
    // (not the projection) is what withholds it, preserving this test's invariant.
    'url_key_fields' => ['id', 'url'],
];

$jsAction = $renderer->renderList([$row], $getView + ['action_urls' => ['view' => '{url}']], $attrs);
$h->test('javascript: action URL is not emitted', !str_contains($jsAction, 'javascript:'), 'javascript: survived validation');
$h->test('javascript: action URL is not rendered as a link/control', !str_contains($jsAction, '<a href=') && !str_contains($jsAction, '<form'));
$h->test('an action URL whose scheme is hidden by a control character is not emitted', !str_contains($renderer->renderList([$row], $getView + ['action_urls' => ['view' => "java\tscript:alert(1)"]], $attrs), '<a href='));

$schemeRelative = $renderer->renderList([$row], $getView + ['action_urls' => ['view' => '//evil.example/{id}']], $attrs);
$h->test('protocol-relative action URL is not emitted', !str_contains($schemeRelative, 'evil.example') && !str_contains($schemeRelative, '<a href='), 'protocol-relative URL survived validation');

$relativeAction = $renderer->renderList([$row], $getView + ['action_urls' => ['view' => '/posts/{id}']], $attrs);
$h->test('a relative action URL is still emitted', str_contains($relativeAction, 'href="/posts/7"'));
$h->test('the default relative action URL is still emitted', str_contains($renderer->renderList([$row], $baseView + ['action_payload_fields' => ['id']], $attrs), 'action="?id=7&amp;action=archive"'));

$h->section('URL validation — dangerous row-click targets are withheld');

$safeClick = $renderer->renderList([$row], ['fields' => ['name'], 'view' => 'table', 'url_key_fields' => ['id']], $attrs + ['row-click' => '/rows/{id}']);
$h->test('safe row-click emits a click handler', str_contains($safeClick, 'window.location.href=') && str_contains($safeClick, 'rows'));

$jsClick = $renderer->renderList([$row], ['fields' => ['name'], 'view' => 'table'], $attrs + ['row-click' => 'javascript:alert(1)']);
$h->test('javascript: row-click is withheld', !str_contains($jsClick, 'window.location.href=') && !str_contains($jsClick, 'javascript:'));
$h->test('a row-click whose scheme is hidden by a control character is withheld', !str_contains($renderer->renderList([$row], ['fields' => ['name'], 'view' => 'table'], $attrs + ['row-click' => "java\nscript:alert(1)"]), 'window.location.href='));

$schemeRelativeClick = $renderer->renderList([$row], ['fields' => ['name'], 'view' => 'table'], $attrs + ['row-click' => '//evil.example/{id}']);
$h->test('protocol-relative row-click is withheld', !str_contains($schemeRelativeClick, 'window.location.href=') && !str_contains($schemeRelativeClick, 'evil.example'));

$h->done();
