<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Thin authenticated JSON capability-bridge HTTP handlers for the Akira Builder
 * admin (Phase 10B). Each handler authorizes the Kernel admin, reads a JSON
 * body / route parameter, and forwards the request to the akira.builder.*@1
 * capability handler map in helpers.php. No business logic is duplicated here;
 * all validation, optimistic concurrency, idempotency, audit, tenant isolation
 * and render behaviour lives in the capability handlers.
 */

/** Emit a JSON response with an explicit HTTP status (fail closed on every path).
 * @param array<string,mixed> $data
 */
function cabBuilderApiRespond(int $status, array $data): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json');
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Kernel-auth + admin role guard for the capability bridge.
 * @return array<string,mixed>|null the authenticated admin actor, or null after a JSON denial is emitted.
 */
function cabBuilderApiGuard(): ?array
{
    $user = app()->user();
    if (!is_array($user)) {
        cabBuilderApiRespond(401, ['ok' => false, 'error' => 'Authentication required.']);
        return null;
    }
    if (($user['role'] ?? '') !== 'admin') {
        cabBuilderApiRespond(403, ['ok' => false, 'error' => 'Administrator role required.']);
        return null;
    }
    return $user;
}

/**
 * @param array<string,mixed> $params
 * @return array<string,mixed>
 */
function cabBuilderApiPayload(array $params = []): array
{
    $body = cabBuilderApiRawBody();
    if ($body === '') {
        $parsed = [];
    } else {
        try {
            $parsed = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new CabBuilderException('Request body must be valid JSON.', 400);
        }
        if (!is_array($parsed)) {
            throw new CabBuilderException('Request body must be a JSON object.', 400);
        }
    }
    // The entity reference is resolved from the route parameter when present and
    // otherwise from the body; the tenant is ALWAYS supplied by Kernel context.
    $key = trim((string) ($params['key'] ?? ($parsed['key'] ?? '')));
    if ($key !== '') {
        $parsed['entity_key'] = $key;
    }
    unset($parsed['key']);
    $idempotency = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    if ($idempotency !== '') {
        $parsed['idempotency_key'] = $idempotency;
    }
    return $parsed;
}

/** Ensures an entity-type reference is present for governed entity mutations (never inferred from tenant context).
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function cabBuilderApiEnsureEntityType(array $payload): array
{
    if (!isset($payload['entity_type'])) {
        $payload['entity_type'] = 'post';
    }
    return $payload;
}

/** Reads the raw request body with a CLI/test override seam (no php://input in direct invocations). */
function cabBuilderApiRawBody(): string
{
    if (array_key_exists('cab_builder_http_raw_body', $GLOBALS) && is_string($GLOBALS['cab_builder_http_raw_body'])) {
        return $GLOBALS['cab_builder_http_raw_body'];
    }
    if (defined('CAB_BUILDER_HTTP_BODY_OVERRIDE')) {
        return (string) CAB_BUILDER_HTTP_BODY_OVERRIDE;
    }
    return (string) file_get_contents('php://input');
}

/**
 * Forward a capability call and emit its JSON result, mapping typed builder
 * failures to their HTTP status. Any unexpected throwable fails closed as 500.
 * @param array<string,mixed> $payload
 */
function cabBuilderApiInvoke(string $capability, array $payload): void
{
    try {
        $result = app()->cap()->call($capability, $payload, [
            'caller' => ['module' => CAB_BUILDER_MODULE_ID, 'user' => app()->user()],
            'mode' => 'first',
        ]);
    } catch (Throwable $error) {
        // Governed mutation providers surface typed failures as CabBuilderException,
        // which the capability bus wraps in a generic CapabilityCallException. Walk
        // the chain to recover the typed HTTP status; otherwise fail closed as 500.
        for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
            if ($cursor instanceof CabBuilderException) {
                cabBuilderApiRespond($cursor->httpStatus, ['ok' => false, 'error' => $cursor->getMessage()]);
                return;
            }
        }
        cabBuilderApiRespond(500, ['ok' => false, 'error' => 'Composition operation failed.']);
        return;
    }
    if (!is_array($result)) {
        cabBuilderApiRespond(500, ['ok' => false, 'error' => 'Composition operation failed.']);
        return;
    }
    if (($result['ok'] ?? false) === true) {
        cabBuilderApiRespond(200, $result);
        return;
    }
    $message = is_string($result['error'] ?? null) ? $result['error'] : 'Composition operation failed.';
    // Read-side capability handlers return ok:false rather than throwing; map the
    // common miss/unpublished/unregistered cases to 404, everything else to 422.
    $status = (str_contains($message, 'not found') || str_contains($message, 'not published') || str_contains($message, 'unregistered') || str_contains($message, 'unavailable')) ? 404 : 422;
    cabBuilderApiRespond($status, ['ok' => false, 'error' => $message]);
}

/** @param array<string,string> $params */
function akiraBuilderHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'module' => CAB_BUILDER_MODULE_ID, 'version' => '1.0.0', 'authority' => 'native'], JSON_UNESCAPED_SLASHES);
}

/**
 * Wrap rendered composition content in the active theme's declared public shell.
 */
function cabBuilderPublicThemePage(string $pageHtml, string $title, string $key, string $slug): ?string
{
    try {
        $themesRoot = realpath(defined('CMS_THEMES_PATH') ? (string) CMS_THEMES_PATH : dirname(__DIR__, 3) . '/storage/cms-themes');
        $themePath = is_string($themesRoot) ? realpath($themesRoot . '/' . $slug) : false;
        if ($themePath === false || !str_starts_with($themePath . DIRECTORY_SEPARATOR, (string) $themesRoot . DIRECTORY_SEPARATOR)) {
            return null;
        }
        $manifestRaw = @file_get_contents($themePath . '/theme.manifest.json');
        $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
        $layout = is_array($manifest) ? trim((string) ($manifest['shell'] ?? '')) : '';
        $layoutPath = $layout !== '' && !str_contains($layout, '..') ? realpath($themePath . '/' . ltrim($layout, '/')) : false;
        if ($layoutPath === false || !str_starts_with($layoutPath, $themePath . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $provider = new \Ikabud\Kernel\Services\DeclarativeThemeCustomizerProvider($slug, $themePath);
        if (!\Ikabud\Kernel\Services\ThemeCustomizerOrchestrator::validateProvider($provider, $slug, $themePath)) {
            return null;
        }
        $definition = $provider->definition();
        $customizer = app()->cap()->call('akira.theme.customizer.values@1', [], [
            'caller' => ['module' => CAB_BUILDER_MODULE_ID, 'user' => app()->user()], 'mode' => 'first',
        ]);
        $persisted = is_array($customizer) && ($customizer['ok'] ?? false) === true && ($customizer['theme_slug'] ?? '') === $slug && is_array($customizer['values'] ?? null)
            ? $customizer['values'] : [];
        $settings = [];
        foreach ($definition->sectionNames() as $section) {
            $settings[$section] = array_merge($definition->section($section)?->defaults ?? [], is_array($persisted[$section] ?? null) ? $persisted[$section] : []);
        }
        $context = new \Ikabud\Kernel\Contracts\ThemeRenderContext(
            theme: $slug,
            scope: \Ikabud\Kernel\Contracts\ThemeCustomizationScope::fromString('native_' . $slug),
            settings: $settings,
            tokens: $definition->tokens,
            site: ['title' => 'CMS Akira', 'tagline' => 'Governed publishing on Ikabud', 'url' => '/'],
            navigation: ['primary' => [['href' => '/', 'label' => 'Home'], ['href' => '/posts', 'label' => 'Posts']]],
            entityContext: ['kind' => CAB_BUILDER_VIEW, 'origin' => CAB_BUILDER_MODULE_ID, 'authenticated' => false],
            slotContributions: [],
        );
        $header = \Ikabud\Kernel\Services\ThemeCustomizerOrchestrator::renderProviderRegion($provider, 'header', $context, $themePath);
        $footer = \Ikabud\Kernel\Services\ThemeCustomizerOrchestrator::renderProviderRegion($provider, 'footer', $context, $themePath);
        if ($header['html'] === '' || $footer['html'] === '') {
            return null;
        }
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $scheme = function_exists('request_scheme') ? request_scheme() : 'http';
        return app()->render($layoutPath, [
            'page_title' => $title . ' — CMS Akira', 'seo_description' => '',
            'canonical_url' => $host === '' ? '' : $scheme . '://' . $host . '/p/' . rawurlencode($key),
            'current_year' => date('Y'), 'theme_slug' => $slug,
            'header_region' => $header['html'], 'page_region' => $pageHtml, 'footer_region' => $footer['html'],
        ]);
    } catch (Throwable) {
        return null;
    }
}

/**
 * Anonymous, tenant-scoped published composition page. Draft/preview access is deliberately impossible here.
 * @param array<string,string> $params
 */
function akiraBuilderPublicComposition(array $params = []): void
{
    $key = (string) ($params['key'] ?? '');
    $result = cab_builder_cap_render_1(['entity_type' => 'post', 'entity_key' => $key, 'source' => 'published']);
    $data = is_array($result['data'] ?? null) ? $result['data'] : [];
    $html = $data['html'] ?? null;
    $row = cabBuilderFind('post', $key);
    $page = is_string($html) && is_string($data['theme_slug'] ?? null) && is_array($row)
        ? cabBuilderPublicThemePage($html, (string) $row['title'], $key, $data['theme_slug']) : null;
    if (($result['ok'] ?? false) !== true || !is_string($page)) {
        http_response_code(404);
        header('Content-Type: text/html; charset=UTF-8');
        echo 'Not Found';
        return;
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo $page;
}

/** @param array<string,string> $params */
function akiraBuilderApiCompositions(array $params = []): void
{
    if (!cabBuilderApiGuard()) {
        return;
    }
    cabBuilderApiInvoke('akira.builder.compositions@1', [
        'limit' => max(1, min(100, (int) ($_GET['limit'] ?? 50))),
        'offset' => max(0, (int) ($_GET['offset'] ?? 0)),
    ]);
}

/** @param array<string,string> $params */
function akiraBuilderApiGet(array $params = []): void
{
    if (!cabBuilderApiGuard()) {
        return;
    }
    cabBuilderApiInvoke('akira.builder.get@1', ['entity_type' => 'post', 'entity_key' => (string) ($params['key'] ?? '')]);
}

/** @param array<string,string> $params */
function akiraBuilderApiRevisions(array $params = []): void
{
    if (!cabBuilderApiGuard()) {
        return;
    }
    cabBuilderApiInvoke('akira.builder.revisions@1', ['entity_type' => 'post', 'entity_key' => (string) ($params['key'] ?? '')]);
}

/** @param array<string,string> $params */
function akiraBuilderApiRender(array $params = []): void
{
    if (!cabBuilderApiGuard()) {
        return;
    }
    cabBuilderApiInvoke('akira.builder.render@1', [
        'entity_type' => 'post', 'entity_key' => (string) ($params['key'] ?? ''),
        'source' => in_array(($_GET['source'] ?? 'published'), ['preview', 'published'], true) ? (string) $_GET['source'] : 'published',
    ]);
}

/** @param array<string,string> $params */
function akiraBuilderApiCreate(array $params = []): void
{
    if (!cabBuilderApiGuard()) {
        return;
    }
    try {
        $payload = cabBuilderApiEnsureEntityType(cabBuilderApiPayload($params));
        cabBuilderApiInvoke('akira.builder.create@1', $payload);
    } catch (CabBuilderException $error) {
        cabBuilderApiRespond($error->httpStatus, ['ok' => false, 'error' => $error->getMessage()]);
    }
}

/** @param array<string,string> $params */
function akiraBuilderApiUpdate(array $params = []): void
{
    if (!cabBuilderApiGuard()) {
        return;
    }
    try {
        $payload = cabBuilderApiEnsureEntityType(cabBuilderApiPayload($params));
        cabBuilderApiInvoke('akira.builder.update@1', $payload);
    } catch (CabBuilderException $error) {
        cabBuilderApiRespond($error->httpStatus, ['ok' => false, 'error' => $error->getMessage()]);
    }
}

/** @param array<string,string> $params */
function akiraBuilderApiValidate(array $params = []): void
{
    if (!cabBuilderApiGuard()) {
        return;
    }
    try {
        $payload = cabBuilderApiPayload($params);
        cabBuilderApiInvoke('akira.builder.validate@1', $payload);
    } catch (CabBuilderException $error) {
        cabBuilderApiRespond($error->httpStatus, ['ok' => false, 'error' => $error->getMessage()]);
    }
}

/** @param array<string,string> $params */
function akiraBuilderApiPublish(array $params = []): void
{
    if (!cabBuilderApiGuard()) {
        return;
    }
    try {
        $payload = cabBuilderApiEnsureEntityType(cabBuilderApiPayload($params));
        cabBuilderApiInvoke('akira.builder.publish@1', $payload);
    } catch (CabBuilderException $error) {
        cabBuilderApiRespond($error->httpStatus, ['ok' => false, 'error' => $error->getMessage()]);
    }
}

/** @param array<string,string> $params */
function akiraBuilderApiUnpublish(array $params = []): void
{
    if (!cabBuilderApiGuard()) {
        return;
    }
    try {
        $payload = cabBuilderApiEnsureEntityType(cabBuilderApiPayload($params));
        cabBuilderApiInvoke('akira.builder.unpublish@1', $payload);
    } catch (CabBuilderException $error) {
        cabBuilderApiRespond($error->httpStatus, ['ok' => false, 'error' => $error->getMessage()]);
    }
}

/** @param array<string,string> $params */
function akiraBuilderApiDelete(array $params = []): void
{
    if (!cabBuilderApiGuard()) {
        return;
    }
    try {
        $payload = cabBuilderApiEnsureEntityType(cabBuilderApiPayload($params));
        cabBuilderApiInvoke('akira.builder.delete@1', $payload);
    } catch (CabBuilderException $error) {
        cabBuilderApiRespond($error->httpStatus, ['ok' => false, 'error' => $error->getMessage()]);
    }
}
