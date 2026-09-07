<?php

/** CMS Akira Core P1 route handlers. */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** @param array<string, mixed> $params */
function pageCmsAkiraCoreHome(array $params = []): void
{
    $user = cmsRequireCap('settings.manage');
    echo cmsRender('modules/cms-akira-core/pages/home.disyl', array_merge(cmsAdminContext($user, 'cms-akira-core', [
        ['label' => 'CMS Akira Core', 'url' => ''],
    ]), [
        'page_title' => 'CMS Akira Core',
        'providers' => [],
        'provider_total' => 4,
        'provider_enabled' => 4,
        'provider_fallback' => 0,
    ]));
}

/** @param array<string, mixed> $params */
function pageAkiraArkStatus(array $params = []): void
{
    $user = cmsRequireCap('dashboard.view');
    $themeSlug = cacPostThemeSlug();
    $list = app()->arkRenderers()->resolve('entity.list.post', $themeSlug);
    $detail = app()->arkRenderers()->resolve('entity.detail.post', $themeSlug);
    echo cmsRender('modules/cms-akira-core/pages/ark-status.disyl', array_merge(cmsAdminContext($user, 'ark-status', [
        ['label' => 'CMS Akira Core', 'url' => '/admin/cms-akira-core'],
        ['label' => 'ARK Status', 'url' => ''],
    ]), [
        'page_title' => 'ARK Status',
        'ark' => [
            'read_only' => true,
            'theme' => ['name' => $themeSlug, 'registered' => $list !== null && $detail !== null],
            'profile' => ['name' => 'ark-workbench', 'registered' => true],
        ],
        'ark_ok' => $list !== null && $detail !== null,
    ]));
}

/** @param array<string, mixed> $params */
function apiCmsAkiraCoreHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'module' => 'cms-akira-core', 'version' => '1.0.0']);
}

/** @param array<string, mixed> $params */
function apiCmsAkiraCoreProvidersHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'data' => [
            'cms.post.get@1', 'cms.post.list@1', 'cms.post.create@1', 'cms.post.update@1',
            'entity.list.post@1', 'entity.get.post@1',
        ],
    ]);
}

/** @return never */
function cacPostMutationJson(Throwable $error): void
{
    $status = 500;
    $message = 'Post mutation failed.';
    $retryAfter = null;
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        if ($cursor instanceof CacPostMutationException) {
            $status = $cursor->httpStatus;
            $message = $cursor->getMessage();
            $retryAfter = $cursor->retryAfter;
            break;
        }
    }
    if ($retryAfter !== null) {
        header('Retry-After: ' . $retryAfter);
    }
    app()->json(['ok' => false, 'error' => $message], $status);
    exit;
}

/**
 * POST /api/v1/cms-akira/posts (kernel-CSRF protected).
 *
 * @param array<string, mixed> $params
 */
function apiCmsAkiraPostCreate(array $params = []): void
{
    app()->csrfEnforce();
    $payload = cacInput();
    $payload = is_array($payload) ? $payload : [];
    $payload['idempotency_key'] = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    try {
        $result = app()->cap()->call('cms.post.create@1', $payload, [
            'caller' => ['module' => 'cms-akira-core', 'user' => app()->user()],
            'mode' => 'first',
        ]);
        app()->json($result, 201);
    } catch (Throwable $e) {
        cacPostMutationJson($e);
    }
}

/**
 * PUT /api/v1/cms-akira/posts/{slug} (kernel-CSRF protected).
 *
 * @param array<string, mixed> $params
 */
function apiCmsAkiraPostUpdate(array $params = []): void
{
    app()->csrfEnforce();
    $payload = cacInput();
    $payload = is_array($payload) ? $payload : [];
    $payload['slug'] = $params['slug'] ?? null;
    $payload['idempotency_key'] = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    try {
        $result = app()->cap()->call('cms.post.update@1', $payload, [
            'caller' => ['module' => 'cms-akira-core', 'user' => app()->user()],
            'mode' => 'first',
        ]);
        app()->json($result, 200);
    } catch (Throwable $e) {
        cacPostMutationJson($e);
    }
}

function cacPostThemeSlug(): string
{
    $contextSlug = function_exists('kernel_request_context_get')
        ? trim((string)kernel_request_context_get('active_theme_slug', ''))
        : '';
    if ($contextSlug !== '') {
        return $contextSlug;
    }
    if (function_exists('cmsActiveTheme')) {
        try {
            $active = trim((string)cmsActiveTheme());
            if ($active !== '') {
                return $active;
            }
        } catch (Throwable $e) {
        }
    }
    return 'cms-akira-posts';
}

function cacPostViewRegistrationValid(string $view): bool
{
    if (!in_array($view, ['list', 'detail'], true)) {
        return false;
    }
    $contracts = app()->entityViews()->registeredViewContracts();
    $contract = $contracts['post.' . $view] ?? null;
    $fields = is_array($contract) ? ($contract['fields'] ?? null) : null;
    return is_array($contract)
        && ($contract['provider'] ?? null) === 'cms-akira-core'
        && is_array($fields)
        && $fields !== []
        && !in_array('*', $fields, true);
}

function cacPostRouteError(int $status = 404): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<h1>Not Found</h1>';
}

/**
 * GET /posts: bridge -> domain capability -> projection -> ARK -> DiSyL.
 *
 * @param array<string, mixed> $params
 */
function pageCmsAkiraPosts(array $params = []): void
{
    // Mandatory fail-closed guard before EntityViewResolver or ARK resolution.
    if (!cacPostViewRegistrationValid('list')) {
        cacPostRouteError();
        return;
    }

    $input = cacInput();
    $input = is_array($input) ? $input : [];
    $resolved = app()->entityViews()->resolve('post', 'list', [
        'limit' => $input['limit'] ?? 25,
        'offset' => $input['offset'] ?? 0,
        'sort_field' => $input['sort'] ?? 'published_at',
        'sort_direction' => $input['direction'] ?? 'desc',
    ]);
    if (($resolved['error'] ?? null) !== null) {
        cacPostRouteError();
        return;
    }

    $themeSlug = cacPostThemeSlug();
    if (app()->arkRenderers()->resolve('entity.list.post', $themeSlug) === null) {
        cacPostRouteError();
        return;
    }
    $html = app()->arkRenderers()->render('entity.list.post', ['posts' => $resolved['rows']], $themeSlug);
    if (!is_string($html)) {
        cacPostRouteError();
        return;
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
}

/**
 * GET /posts/{slug}: bridge -> domain capability -> projection -> ARK -> DiSyL.
 *
 * @param array<string, mixed> $params
 */
function pageCmsAkiraPostDetail(array $params = []): void
{
    // Mandatory fail-closed guard before EntityViewResolver or ARK resolution.
    if (!cacPostViewRegistrationValid('detail')) {
        cacPostRouteError();
        return;
    }
    $slug = cacPostValidSlug($params['slug'] ?? null);
    if ($slug === null) {
        cacPostRouteError();
        return;
    }

    $resolved = app()->entityViews()->resolveDetail('post', $slug, 'detail');
    if (($resolved['error'] ?? null) !== null || !is_array($resolved['entity'] ?? null)) {
        cacPostRouteError();
        return;
    }

    $themeSlug = cacPostThemeSlug();
    if (app()->arkRenderers()->resolve('entity.detail.post', $themeSlug) === null) {
        cacPostRouteError();
        return;
    }
    $html = app()->arkRenderers()->render('entity.detail.post', ['post' => $resolved['entity']], $themeSlug);
    if (!is_string($html)) {
        cacPostRouteError();
        return;
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
}
