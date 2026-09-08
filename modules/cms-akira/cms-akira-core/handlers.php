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
            'akira.post.get@1', 'akira.post.list@1', 'akira.post.create@1', 'akira.post.update@1',
            'akira.post.publish@1', 'akira.post.unpublish@1', 'akira.post.delete@1',
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
    cacPostEnforceMutationCsrf();
    $payload = cacInput();
    $payload = is_array($payload) ? $payload : [];
    $payload['idempotency_key'] = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    try {
        $result = app()->cap()->call('akira.post.create@1', $payload, [
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
    cacPostEnforceMutationCsrf();
    $payload = cacInput();
    $payload = is_array($payload) ? $payload : [];
    $payload['slug'] = $params['slug'] ?? null;
    $payload['idempotency_key'] = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    try {
        $result = app()->cap()->call('akira.post.update@1', $payload, [
            'caller' => ['module' => 'cms-akira-core', 'user' => app()->user()],
            'mode' => 'first',
        ]);
        app()->json($result, 200);
    } catch (Throwable $e) {
        cacPostMutationJson($e);
    }
}

function cacPostEnforceMutationCsrf(): void
{
    $authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    $cookieNames = [(string)config('app.cookie_name', 'guidance_token')];
    if (function_exists('declaredModuleAuthCookieNames')) {
        foreach (declaredModuleAuthCookieNames() as $cookieName) {
            if (is_string($cookieName)) {
                $cookieNames[] = $cookieName;
            }
        }
    }
    $hasAuthCookie = false;
    foreach (array_unique($cookieNames) as $cookieName) {
        if ($cookieName !== '' && isset($_COOKIE[$cookieName])) {
            $hasAuthCookie = true;
            break;
        }
    }
    if ($hasAuthCookie || preg_match('/^Bearer\\s+\\S+$/i', $authorization) !== 1) {
        app()->csrfEnforce();
    }
}

/**
 * @param array<string, mixed> $params
 */
function apiCmsAkiraPostLifecycle(array $params = []): void
{
    cacPostEnforceMutationCsrf();
    $operation = (string)($params['operation'] ?? '');
    if (!in_array($operation, ['publish', 'unpublish', 'delete'], true)) {
        app()->json(['ok' => false, 'error' => 'Unknown lifecycle operation.'], 404);
        return;
    }
    $payload = cacInput();
    $payload = is_array($payload) ? $payload : [];
    $payload['slug'] = $params['slug'] ?? null;
    $payload['idempotency_key'] = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    try {
        $capabilityId = match ($operation) {
            'publish' => 'akira.post.publish@1',
            'unpublish' => 'akira.post.unpublish@1',
            'delete' => 'akira.post.delete@1',
        };
        $result = app()->cap()->call($capabilityId, $payload, [
            'caller' => ['module' => 'cms-akira-core', 'user' => app()->user()],
            'mode' => 'first',
        ]);
        app()->json($result, 200);
    } catch (Throwable $e) {
        cacPostMutationJson($e);
    }
}

/** @param array<string, mixed> $params */
function apiCmsAkiraPostPublish(array $params = []): void
{
    apiCmsAkiraPostLifecycle(['operation' => 'publish'] + $params);
}

/** @param array<string, mixed> $params */
function apiCmsAkiraPostUnpublish(array $params = []): void
{
    apiCmsAkiraPostLifecycle(['operation' => 'unpublish'] + $params);
}

/** @param array<string, mixed> $params */
function apiCmsAkiraPostDelete(array $params = []): void
{
    apiCmsAkiraPostLifecycle(['operation' => 'delete'] + $params);
}

function cacPostThemeSlug(): string
{
    $contextSlug = function_exists('kernel_request_context_get')
        ? trim((string)kernel_request_context_get('active_theme_slug', ''))
        : '';
    return $contextSlug !== '' ? $contextSlug : 'cms-akira-posts';
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
 * Optional published-composition override for the public Post detail path.
 *
 * cms-akira-builder (an extension of core) may attach a published composition
 * to this published post's key. When builder is enabled and a valid published
 * composition renders, its HTML replaces the canonical body render for this
 * post. There is deliberately NO manifest dependency (core never depends on
 * builder): the capability is probed at runtime and every failure mode falls
 * back to the canonical entity.detail.post body render below, so the public
 * path is byte-identical when builder is absent, disabled, has no published
 * composition for the key, or cannot render (theme/view unavailable). No
 * cross-member SQL is performed: composition state is read exclusively through
 * the builder render capability. The caller only reaches this seam after
 * akira.post.get@1 resolves the post (status='published', not deleted), so a
 * draft post can never be surfaced through a composition override.
 */
function cacPostDetailCompositionHtml(string $slug): ?string
{
    if (!app()->capabilities()->has('akira.builder.render@1')) {
        return null;
    }
    try {
        $render = app()->cap()->call('akira.builder.render@1', [
            'entity_type' => 'post',
            'entity_key' => $slug,
            'source' => 'published',
        ], [
            'caller' => ['module' => 'cms-akira-core', 'user' => app()->user()],
            'mode' => 'first',
        ]);
    } catch (Throwable $error) {
        return null;
    }
    if (!is_array($render) || ($render['ok'] ?? false) !== true) {
        return null;
    }
    $html = is_array($render['data'] ?? null) ? ($render['data']['html'] ?? null) : null;
    return is_string($html) && $html !== '' ? $html : null;
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

    // Published-composition override (builder seam, runtime-probed, fail-closed):
    // when a valid published composition renders for this published post, it
    // replaces the canonical body render; otherwise fall through to the body
    // render below (unchanged public behavior).
    $compositionHtml = cacPostDetailCompositionHtml($slug);
    if ($compositionHtml !== null) {
        header('Content-Type: text/html; charset=UTF-8');
        echo $compositionHtml;
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
