<?php

declare(strict_types=1);

/**
 * Phase A.1 — malformed view metadata renders nothing.
 *
 * One rule, no contradiction, proven against BOTH resolvers:
 *
 *   1. explicit visible_fields, including []        → authoritative ([] renders nothing)
 *   2. absent visible_fields, valid non-wildcard    → the declared list
 *   3. absent visible_fields, wildcard fields       → the reviewed allowlist (a floor)
 *   4. malformed metadata (non-array / non-string)  → render nothing, never the allowlist
 *
 * `EntityViewResolver::resolveDisplayFields()` and
 * `DefaultEntityRenderer::resolveDisplayFields()` are private, so this test drives
 * them through their public seams: `resolve()['display_fields']` and the rendered
 * HTML of `renderList()` / `renderDetail()`.
 *
 * The values used in the fixture are chosen so a substitution regression is
 * detectable: `title`, `status` and `price` are members of the allowlist, so if a
 * malformed contract is repaired into SAFE_FALLBACK_FIELDS they will appear.
 */

require_once __DIR__ . '/harness/TestHarness.php';

use Ikabud\Kernel\EntityContext\DefaultEntityRenderer;
use Ikabud\Kernel\EntityContext\EntityViewResolver;

$h = new TestHarness('entity-view-malformed-metadata', TestHarness::MODE_INTEGRATION);
$h->fingerprint('kernel/EntityContext/DefaultEntityRenderer.php');
$h->fingerprint('kernel/EntityContext/EntityViewResolver.php');

$renderer = new DefaultEntityRenderer();
$resolver = EntityViewResolver::getInstance();
$resolver->reset();

// Allowlist members, deliberately present in the fixture. If malformed metadata is
// repaired rather than rejected these values render.
$allowlisted = [
    'id' => 17,
    'title' => 'Public title',
    'status' => 'published',
    'price' => '9.99',
];
$internal = [
    'tenant_id' => 'INTERNAL-TENANT-991',
    'cost' => 'INTERNAL-COST-992',
];
$row = $allowlisted + $internal;

$allowlistedNeedles = ['Public title', 'published', '9.99'];
$internalNeedles = array_values($internal);

/** @param list<string> $needles */
$containsAny = static function (string $html, array $needles): bool {
    foreach ($needles as $needle) {
        if (str_contains($html, $needle)) {
            return true;
        }
    }
    return false;
};

// ── Case 1: explicit visible_fields, including [] ──────────────────────────────

$h->section('Case 1 — explicit visible_fields is authoritative, including []');

$explicitEmptyList = $renderer->renderList([$row], ['fields' => '*', 'visible_fields' => []], []);
$explicitEmptyDetail = $renderer->renderDetail($row, ['fields' => '*', 'visible_fields' => []], []);
$h->test('renderer list: explicit [] renders no allowlisted values', !$containsAny($explicitEmptyList, $allowlistedNeedles));
$h->test('renderer list: explicit [] renders no internal values', !$containsAny($explicitEmptyList, $internalNeedles));
$h->test('renderer detail: explicit [] renders no allowlisted values', !$containsAny($explicitEmptyDetail, $allowlistedNeedles));
$h->test('renderer detail: explicit [] renders no internal values', !$containsAny($explicitEmptyDetail, $internalNeedles));

$rendererIntersect = $renderer->renderList([$row], ['fields' => ['title', 'status'], 'visible_fields' => []], []);
$h->test('renderer list: explicit [] intersects any fields to nothing', !$containsAny($rendererIntersect, $allowlistedNeedles));

$h->assertSame([], entityViewResolveFields($resolver, 'explicit_empty_fixture', [
    'fields' => '*',
    'visible_fields' => [],
]), 'resolver: explicit [] resolves to no display fields');

// ── Case 2: absent visible_fields, valid non-wildcard fields ────────────────────

$h->section('Case 2 — absent visible_fields with a valid non-wildcard fields list');

$declared = $renderer->renderList([$row], ['fields' => ['title', 'status']], []);
$h->test('renderer list: declared field renders its allowlisted value', str_contains($declared, 'Public title'));
$h->test('renderer list: declared list does not leak internal values', !$containsAny($declared, $internalNeedles));
$h->test('renderer detail: declared field renders its allowlisted value', str_contains($renderer->renderDetail($row, ['fields' => ['title']], []), 'Public title'));

$h->assertSame(['title', 'status'], entityViewResolveFields($resolver, 'declared_fixture', [
    'fields' => ['title', 'status'],
]), 'resolver: absent visible_fields uses the declared field list');

// ── Case 3: absent visible_fields, wildcard fields → reviewed allowlist ────────

$h->section('Case 3 — absent visible_fields with wildcard fields falls to the reviewed allowlist');

$wildcard = $renderer->renderList([$row], ['fields' => '*'], []);
$h->test('renderer list: wildcard renders an allowlisted value', str_contains($wildcard, 'Public title'));
$h->test('renderer list: wildcard hides internal values', !$containsAny($wildcard, $internalNeedles));

$h->assertSame(
    DefaultEntityRenderer::SAFE_FALLBACK_FIELDS,
    entityViewResolveFields($resolver, 'wildcard_fixture', ['fields' => '*']),
    'resolver: absent visible_fields + wildcard fields resolves to the reviewed allowlist'
);

// The allowlist is a reviewed, dated artifact. Each member must survive review
// on its merits, not by name: `id` is the resolver's own key/action-anchor field;
// title/name/label/excerpt/description/url/image/author_name are public editorial
// presentation; status and published_at/created_at are public lifecycle values;
// `price` is the public catalog price. Internal cost, tenant_id, notes and
// provider metadata are deliberately absent.
$h->assertSame(
    ['id', 'title', 'name', 'label', 'excerpt', 'description', 'url', 'image', 'status', 'price', 'published_at', 'created_at', 'author_name'],
    DefaultEntityRenderer::SAFE_FALLBACK_FIELDS,
    'the allowlist matches the reviewed member-by-member set; adding a member requires review'
);

// ── Case 4: malformed metadata renders nothing ─────────────────────────────────

$h->section('Case 4 — malformed metadata renders nothing (never the allowlist)');

$malformedVisible = [
    'string (non-array)' => 'title,status',
    'scalar (int)' => 42,
    'non-string member' => ['title', 42],
    'nested member' => ['title', ['status']],
    'null member' => [null],
];
foreach ($malformedVisible as $label => $visible) {
    $view = ['fields' => '*', 'visible_fields' => $visible];
    $listHtml = $renderer->renderList([$row], $view, []);
    $detailHtml = $renderer->renderDetail($row, $view, []);
    $h->test("renderer list [{$label}]: no allowlisted value renders", !$containsAny($listHtml, $allowlistedNeedles), $listHtml);
    $h->test("renderer list [{$label}]: no internal value renders", !$containsAny($listHtml, $internalNeedles));
    $h->test("renderer detail [{$label}]: nothing renders", !$containsAny($detailHtml, $allowlistedNeedles));
}

$malformedFieldLists = [
    'fields is a non-wildcard string' => 'title,status',
    'fields mixes non-strings' => ['title', 42],
    'fields is a scalar' => 42,
];
foreach ($malformedFieldLists as $label => $fields) {
    $view = ['fields' => $fields];
    $listHtml = $renderer->renderList([$row], $view, []);
    $h->test("renderer list [{$label}]: nothing renders", !$containsAny($listHtml, $allowlistedNeedles), $listHtml);
    $h->assertSame([], entityViewResolveFields($resolver, 'malformed_fields_' . md5($label), $view), "resolver [{$label}]: renders nothing");
}

$malformedResolverCases = [
    'visible_fields string' => ['fields' => '*', 'visible_fields' => 'title,status'],
    'visible_fields scalar' => ['fields' => '*', 'visible_fields' => 42],
    'visible_fields non-string member' => ['fields' => '*', 'visible_fields' => ['title', 42]],
    'visible_fields nested member' => ['fields' => '*', 'visible_fields' => ['title', ['status']]],
];
foreach ($malformedResolverCases as $label => $contract) {
    $h->assertSame([], entityViewResolveFields($resolver, 'malformed_visible_' . md5($label), $contract), "resolver [{$label}]: renders nothing");
}

$h->done();

/**
 * Resolve the display fields for a contract through the public resolver seam.
 *
 * Registers the view and a capability that returns the fixture row, then reads
 * the `display_fields` the resolver actually committed to for the query.
 *
 * @param array<string, mixed> $contract
 * @return list<string>|null Null means the resolver rejected the source entirely.
 */
function entityViewResolveFields(EntityViewResolver $resolver, string $entityType, array $contract): ?array
{
    $capability = 'entity.list.' . $entityType;
    if (app()->capabilities()->has($capability)) {
        return null;
    }
    app()->capabilities()->register(
        $capability,
        '_entity_view_malformed_fixture',
        static fn (): array => ['rows' => [[
            'id' => 17,
            'title' => 'Public title',
            'status' => 'published',
            'price' => '9.99',
            'tenant_id' => 'INTERNAL-TENANT-991',
            'cost' => 'INTERNAL-COST-992',
        ]], 'total' => 1],
        100
    );
    $resolver->registerView($entityType, 'table', $contract);
    $resolved = $resolver->resolve($entityType, 'table');
    return is_array($resolved['display_fields'] ?? null) ? $resolved['display_fields'] : null;
}
