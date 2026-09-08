<?php

/**
 * CMS Akira public Post-path — optional published-composition override seam.
 *
 * Integration gate (recorded 10A/10B follow-up): when cms-akira-builder is
 * enabled and a PUBLISHED composition is attached to a published post's key,
 * the public GET /posts/{slug} detail path serves the composition render
 * (akira.builder.render@1 -> ARK -> DiSyL, entity.detail.composition) instead
 * of the canonical entity.detail.post body render. Every other case falls back
 * to the canonical body render, byte-identical to the pre-seam behavior:
 *   - builder absent (not enabled)  -> body render (baseline, no dep on builder)
 *   - no composition for the key    -> body render
 *   - draft-only composition        -> body render (draft never public)
 *   - render cannot complete        -> body render (fail closed)
 *   - cross-tenant                  -> body render (no leak)
 *
 * The seam lives in cms-akira-core/handlers.php (cacPostDetailCompositionHtml)
 * and reads composition state ONLY through the builder render capability — no
 * cross-member SQL. Kernel READ-ONLY.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/handlers.php';
require_once $root . '/modules/cms-akira/cms-akira-theme/helpers.php';
require_once $root . '/modules/cms-akira/cms-akira-builder/helpers.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? "  ✓ " : "  ✗ ") . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

$registry = app()->capabilities();
$register = static function (string $module, array $handlers): void {
    foreach ($handlers as $id => $handler) {
        if (app()->capabilities()->has($id)) {
            continue;
        }
        app()->capabilities()->register($id, $module, static function (mixed $payload, string $capabilityId = '', string $provider = '') use ($handler, $module): mixed {
            return moduleWithContext($module, static fn (): mixed => $handler($payload, $capabilityId, $provider));
        }, 50, ['first']);
    }
};

// Phase 1: register ONLY core (builder absent) -> baseline body render.
$register('cms-akira-core', cms_akira_core_capability_handlers());

$tenantA = 991200;
$tenantB = 991201;
$db = app()->db();

// ── fixtures: clean + seed posts + compositions/revisions (direct SQL) ─────
$db->prepare('DELETE FROM cms_akira_composition_revisions WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
$db->prepare('DELETE FROM cms_akira_compositions WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
$db->prepare('DELETE FROM cms_akira_posts WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);

$insertPost = $db->prepare(
    'INSERT INTO cms_akira_posts (tenant_id, slug, title, subtitle, content, image, status, published_at) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$insertPost->execute([$tenantA, 'composed-post', 'Composed body title', '', 'BODY_A_SECRET', null, 'published', '2026-09-08 00:00:00']);
$insertPost->execute([$tenantA, 'draft-comp-post', 'Draft comp title', '', 'BODY_DRAFT_SECRET', null, 'published', '2026-09-08 00:00:00']);
$insertPost->execute([$tenantA, 'plain-post', 'Plain title', '', 'BODY_PLAIN_SECRET', null, 'published', '2026-09-08 00:00:00']);
$insertPost->execute([$tenantB, 'composed-post', 'Tenant B composed', '', 'BODY_B_SECRET', null, 'published', '2026-09-08 00:00:00']);

$heading = static fn (string $text): string => json_encode(['version' => 1, 'blocks' => [
    ['type' => 'heading', 'props' => ['text' => $text, 'level' => 2], 'children' => []],
]], JSON_UNESCAPED_SLASHES);

/** Seed a composition for (tenant, slug). published=true also creates the published revision pointer. */
$seedComposition = static function (int $tenant, string $slug, string $title, string $marker, bool $published) use ($db, $heading): void {
    $tree = $heading($marker);
    $db->prepare('INSERT INTO cms_akira_compositions (tenant_id, entity_type, entity_key, title, tree, status, version) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$tenant, 'post', $slug, $title, $tree, $published ? 'published' : 'draft', $published ? 2 : 1]);
    $cid = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO cms_akira_composition_revisions (tenant_id, composition_id, tree, base, author_id, change_note) VALUES (?, ?, ?, NULL, ?, ?)')
        ->execute([$tenant, $cid, $tree, 1, $published ? 'publish' : 'draft edit']);
    $rid = (int) $db->lastInsertId();
    if ($published) {
        $db->prepare('UPDATE cms_akira_compositions SET published_revision_id = ? WHERE id = ?')->execute([$rid, $cid]);
    }
};

$seedComposition($tenantA, 'composed-post', 'Composed override title', 'COMPOSED_A_MARKER', true);
$seedComposition($tenantA, 'draft-comp-post', 'Draft only title', 'DRAFT_ONLY_MARKER', false);
// tenant B intentionally has NO composition for 'composed-post'.

/** Render the public detail route for a slug under a tenant. */
$detail = static function (int $tenant, string $slug) use (&$check): string {
    http_response_code(200);
    app()->tenant()->setTenantId($tenant);
    kernel_request_context_set('tenant_id', $tenant);
    ob_start();
    try {
        pageCmsAkiraPostDetail(['slug' => $slug]);
        return (string) ob_get_clean();
    } catch (Throwable $error) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        $check(false, "detail {$slug} threw", $error->getMessage());
        return '';
    }
};

echo "=== CMS Akira public Post-path composition-override seam ===\n";

// Baseline: builder absent -> canonical body render (article-page), never composition-page.
$baseHtml = $detail($tenantA, 'composed-post');
$check(str_contains($baseHtml, 'data-ark-renderer="article-page"') && str_contains($baseHtml, 'BODY_A_SECRET'), 'builder absent -> canonical article-page body render (baseline unchanged)', '');
$check(!str_contains($baseHtml, 'data-ark-renderer="composition-page"'), 'builder absent -> no composition render', '');

// Enable builder + theme providers (the extension that owns composition state).
$register('cms-akira-theme', cms_akira_theme_capability_handlers());
$register('cms-akira-builder', cms_akira_builder_capability_handlers());
$check($registry->has('akira.builder.render@1') && $registry->has('akira.theme.resolve@1'), 'builder render + theme resolve capabilities active', '');

// Published composition present -> composition render replaces body.
$compHtml = $detail($tenantA, 'composed-post');
$check(str_contains($compHtml, 'data-ark-renderer="composition-page"') && str_contains($compHtml, 'Composed override title'), 'published composition overrides the public body render', '');
$check(str_contains($compHtml, 'COMPOSED_A_MARKER') && !str_contains($compHtml, 'BODY_A_SECRET'), 'published composition HTML served (marker present, post body absent)', '');
$check(!str_contains($compHtml, 'data-ark-renderer="article-page"'), 'canonical article-page not rendered when composition overrides', '');

// Draft-only composition -> canonical body render (draft never public).
$draftHtml = $detail($tenantA, 'draft-comp-post');
$check(str_contains($draftHtml, 'data-ark-renderer="article-page"') && str_contains($draftHtml, 'BODY_DRAFT_SECRET'), 'draft-only composition -> canonical article-page body render', '');
$check(!str_contains($draftHtml, 'DRAFT_ONLY_MARKER') && !str_contains($draftHtml, 'data-ark-renderer="composition-page"'), 'draft composition never surfaced publicly', '');

// No composition -> canonical body render.
$plainHtml = $detail($tenantA, 'plain-post');
$check(str_contains($plainHtml, 'data-ark-renderer="article-page"') && str_contains($plainHtml, 'BODY_PLAIN_SECRET'), 'no composition -> canonical article-page body render', '');

// Tenant isolation: tenant A's published composition must NOT leak to tenant B's identical slug.
$tenantBHtml = $detail($tenantB, 'composed-post');
$check(str_contains($tenantBHtml, 'data-ark-renderer="article-page"') && str_contains($tenantBHtml, 'BODY_B_SECRET'), 'tenant isolation -> tenant B identical slug renders its own body, not tenant A composition', '');
$check(!str_contains($tenantBHtml, 'COMPOSED_A_MARKER') && !str_contains($tenantBHtml, 'data-ark-renderer="composition-page"'), 'no cross-tenant composition leak', '');

echo "\nCMS Akira public Post-path composition-override seam: {$passed} passed, {$failed} failed\n";

exit($failed === 0 ? 0 : 1);
