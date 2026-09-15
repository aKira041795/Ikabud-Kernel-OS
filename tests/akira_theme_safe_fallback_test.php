<?php

declare(strict_types=1);

use Ikabud\Kernel\EntityContext\DefaultEntityRenderer;
use Ikabud\Kernel\EntityContext\EntityViewResolver;

require_once __DIR__ . '/harness/TestHarness.php';

$h = new TestHarness('akira-theme-safe-fallback', TestHarness::MODE_INTEGRATION);
$h->fingerprint('kernel/EntityContext/DefaultEntityRenderer.php');
$h->fingerprint('kernel/EntityContext/EntityViewResolver.php');
$h->fingerprint('kernel/DiSyL/Component/ComponentRenderer.php');

$renderer = new DefaultEntityRenderer();
$internalValues = [
    'tenant_id' => 'INTERNAL-TENANT-991',
    'cost' => 'INTERNAL-COST-992',
    'notes' => 'INTERNAL-NOTES-993',
    'tokens' => 'INTERNAL-TOKENS-994',
    'provider_secret' => 'INTERNAL-PROVIDER-995',
];
$rows = [[
    'id' => 17,
    'title' => 'Public title',
    'status' => 'published',
] + $internalValues];
$internalNeedles = array_values($internalValues);

/** @param list<string> $needles */
$containsNone = static function (string $html, array $needles): bool {
    foreach ($needles as $needle) {
        if (str_contains($html, $needle)) {
            return false;
        }
    }
    return true;
};

$h->section('Absent wildcard metadata');
foreach (['table', 'compact', 'card_grid'] as $mode) {
    $html = $renderer->renderList($rows, ['fields' => '*', 'view' => $mode], ['view' => $mode]);
    $h->test("{$mode}: allowlisted value renders", str_contains($html, 'Public title'));
    $h->test(
        "{$mode}: tenant_id, cost, notes, tokens and provider key are absent",
        $containsNone($html, $internalNeedles),
        $html
    );
}

$h->section('Explicit empty metadata');
$emptyView = ['fields' => '*', 'visible_fields' => []];
foreach (['table', 'compact', 'card_grid'] as $mode) {
    $html = $renderer->renderList($rows, $emptyView + ['view' => $mode], ['view' => $mode]);
    $h->test("{$mode}: explicit empty renders no row values", !str_contains($html, 'Public title') && $containsNone($html, $internalNeedles), $html);
}
$detailEmpty = $renderer->renderDetail($rows[0], $emptyView, []);
$h->test('detail: explicit empty renders no entity values', !str_contains($detailEmpty, 'Public title') && $containsNone($detailEmpty, $internalNeedles), $detailEmpty);

$h->section('Explicit visible metadata intersection');
$explicit = $renderer->renderList($rows, [
    'fields' => ['title', 'status', 'cost', 'notes'],
    'visible_fields' => ['title', 'cost'],
    'view' => 'table',
], ['view' => 'table']);
$h->test('explicit visible set renders requested title', str_contains($explicit, 'Public title'));
$h->test('explicit visible set renders requested cost', str_contains($explicit, 'INTERNAL-COST-992'));
$h->test('explicit visible set excludes requested fields outside set', !str_contains($explicit, 'published') && !str_contains($explicit, 'INTERNAL-NOTES-993'), $explicit);

$h->section('Malformed metadata fails closed without throwing');
foreach (['bad-string', 42, ['title', new stdClass()]] as $index => $malformed) {
    $thrown = null;
    $html = '';
    try {
        $html = $renderer->renderList($rows, ['fields' => '*', 'visible_fields' => $malformed, 'view' => 'table'], ['view' => 'table']);
        $html .= $renderer->renderDetail($rows[0], ['fields' => '*', 'visible_fields' => $malformed], []);
    } catch (Throwable $e) {
        $thrown = $e;
    }
    $h->test("malformed case {$index}: no exception escapes", $thrown === null, $thrown?->getMessage() ?? '');
    $h->test("malformed case {$index}: non-allowlisted values stay hidden", $containsNone($html, $internalNeedles), $html);
}

$h->section('Cross-row consistency and escaping');
$crossRows = [
    ['id' => 1, 'name' => 'First'],
    ['id' => 2, 'name' => 'Second', 'status' => '<script>ROW-TWO</script>'],
];
$cross = $renderer->renderList($crossRows, [
    'fields' => ['name', 'status'],
    'view' => 'table',
], ['view' => 'table']);
$h->test('field absent from row zero still renders for row one', str_contains($cross, '&lt;script&gt;ROW-TWO&lt;/script&gt;'), $cross);
$h->test('later-row value remains escaped', !str_contains($cross, '<script>ROW-TWO</script>'), $cross);
$h->test('same field list applies to both rows', substr_count($cross, 'data-label="Status"') === 2, $cross);

$h->section('Resolver metadata and row-independent display fields');
$resolver = EntityViewResolver::getInstance();
$resolver->reset();
$resolver->registerView('akira_absent_fixture', 'table', ['fields' => '*']);
$absentContract = $resolver->viewContract('akira_absent_fixture', 'table');
$h->test('registerView preserves absent visible_fields as absent', is_array($absentContract) && !array_key_exists('visible_fields', $absentContract));

$resolver->registerView('akira_empty_fixture', 'table', ['fields' => '*', 'visible_fields' => []]);
$emptyContract = $resolver->viewContract('akira_empty_fixture', 'table');
$h->test('registerView preserves explicit empty visible_fields', is_array($emptyContract) && array_key_exists('visible_fields', $emptyContract) && $emptyContract['visible_fields'] === []);

$resolver->registerView('akira_resolve_fixture', 'table', ['fields' => '*', 'visible_fields' => ['status']]);
app()->capabilities()->register(
    'entity.list.akira_resolve_fixture',
    '_akira_safe_fallback_fixture',
    static fn (): array => ['rows' => [[
        'tenant_id' => 'resolver-internal',
        'status' => 'ready',
        'synthetic_provider_key' => 'resolver-provider',
    ]], 'total' => 1],
    100
);
$resolved = $resolver->resolve('akira_resolve_fixture', 'table');
$h->assertSame(['status'], $resolved['display_fields'] ?? null, 'resolve display_fields comes from visible contract metadata');
$h->test('resolver rows cannot add internal display fields', !in_array('tenant_id', $resolved['display_fields'] ?? [], true) && !in_array('synthetic_provider_key', $resolved['display_fields'] ?? [], true));

$resolver->registerView('akira_safe_fixture', 'table', ['fields' => '*']);
app()->capabilities()->register(
    'entity.list.akira_safe_fixture',
    '_akira_safe_allowlist_fixture',
    static fn (): array => ['rows' => [['tenant_id' => 'only-row-key']], 'total' => 1],
    100
);
$safeResolved = $resolver->resolve('akira_safe_fixture', 'table');
$h->assertSame(DefaultEntityRenderer::SAFE_FALLBACK_FIELDS, $safeResolved['display_fields'] ?? null, 'absent metadata resolves to governed allowlist, not row keys');

$h->done();
