<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** @param array<string, string> $params */
function catThemeHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'module' => 'cms-akira-theme',
        'version' => '1.0.0',
        'authority' => 'native',
    ], JSON_UNESCAPED_SLASHES);
}

/** @param Throwable $error */
function catThemeJsonError(Throwable $error): void
{
    $status = 500;
    $message = 'Theme operation failed.';
    $retryAfter = null;
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        if ($cursor instanceof CatThemeException) {
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
}

/** @param array<string, string> $params */
function catThemeResolveJson(array $params = []): void
{
    if (catThemeAdmin() === null) {
        app()->json(['ok' => false, 'error' => 'Authentication required.'], 401);
        return;
    }
    if (catThemeAdmin() === []) {
        app()->json(['ok' => false, 'error' => 'Administrator role required.'], 403);
        return;
    }
    app()->json(cat_cap_akira_theme_resolve_1([]));
}

/** @param array<string, string> $params */
function catThemeRegistryJson(array $params = []): void
{
    if (catThemeAdmin() === null) {
        app()->json(['ok' => false, 'error' => 'Authentication required.'], 401);
        return;
    }
    if (catThemeAdmin() === []) {
        app()->json(['ok' => false, 'error' => 'Administrator role required.'], 403);
        return;
    }
    app()->json(cat_cap_akira_theme_registry_1([]));
}

/** @param array<string, string> $params */
function catThemeValidateJson(array $params = []): void
{
    if (catThemeAdmin() === null) {
        app()->json(['ok' => false, 'error' => 'Authentication required.'], 401);
        return;
    }
    if (catThemeAdmin() === []) {
        app()->json(['ok' => false, 'error' => 'Administrator role required.'], 403);
        return;
    }
    $slug = (string) ($params['slug'] ?? '');
    try {
        $slug = catThemeSlug($slug);
    } catch (Throwable $error) {
        app()->json(['ok' => false, 'error' => $error->getMessage()], 422);
        return;
    }
    app()->json(cat_cap_akira_theme_validate_1(['theme_slug' => $slug]));
}

/** @param array<string, string> $params */
function catThemeActivateJson(array $params = []): void
{
    if (catThemeAdmin() === null) {
        app()->json(['ok' => false, 'error' => 'Authentication required.'], 401);
        return;
    }
    if (catThemeAdmin() === []) {
        app()->json(['ok' => false, 'error' => 'Administrator role required.'], 403);
        return;
    }
    catThemeEnforceMutationCsrf();
    $payload = catThemeInput();
    $payload['theme_slug'] = $params['slug'] ?? ($payload['theme_slug'] ?? null);
    $payload['idempotency_key'] = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($payload['idempotency_key'] ?? '')));
    try {
        $result = app()->cap()->call('akira.theme.activate@1', $payload, [
            'caller' => ['module' => 'cms-akira-theme', 'user' => app()->user()],
            'mode' => 'first',
        ]);
        app()->json($result, 200);
    } catch (Throwable $error) {
        catThemeJsonError($error);
    }
}

/** @param array<string, string> $params */
function catThemeAdminPage(array $params = []): void
{
    if (catThemeAdmin() === null) {
        header('Location: /login', true, 303);
        return;
    }
    if (catThemeAdmin() === []) {
        http_response_code(403);
        echo catThemePage('Access denied', '<p>Your Kernel role cannot administer CMS Akira themes.</p>');
        return;
    }

    $themes = catThemeRegistryRows();
    $resolved = catThemeResolveActive();
    $active = $resolved['ok'] ? (string) $resolved['theme_slug'] : CAT_THEME_FALLBACK;

    $items = '';
    foreach ($themes as $theme) {
        $slug = (string) $theme['slug'];
        $label = catThemeEscape($theme['label'] ?? $slug);
        $state = ($theme['validated'] ?? false) ? 'valid' : 'invalid';
        $activeMark = ($theme['active'] ?? false) ? ' (active)' : '';
        $items .= '<li>' . $label . ' <code>' . catThemeEscape($slug) . '</code> — ' . $state . $activeMark
            . ' <form method="post" action="/cms-akira-theme/activate" style="display:inline">'
            . catThemeCsrfField()
            . '<input type="hidden" name="theme_slug" value="' . catThemeEscape($slug) . '">'
            . '<input type="hidden" name="idempotency_key" value="theme-' . bin2hex(random_bytes(12)) . '">'
            . '<button type="submit">Activate</button></form></li>';
    }

    $body = '<p>Active theme: <code>' . catThemeEscape($active) . '</code></p><ul>' . $items . '</ul>'
        . '<p><a href="/api/v1/cms-akira-theme/themes">Themes JSON</a> · '
        . '<a href="/api/v1/cms-akira-theme/resolve">Resolve JSON</a></p>';
    echo catThemePage('CMS Akira Themes', $body);
}

/** @param array<string, string> $params */
function catThemeActivateForm(array $params = []): void
{
    if (catThemeAdmin() === null) {
        header('Location: /login', true, 303);
        return;
    }
    if (catThemeAdmin() === []) {
        http_response_code(403);
        echo catThemePage('Access denied', '<p>Your Kernel role cannot administer CMS Akira themes.</p>');
        return;
    }
    app()->csrfEnforce();
    $payload = catThemeInput();
    $payload['idempotency_key'] = trim((string) ($payload['idempotency_key'] ?? ''));
    try {
        app()->cap()->call('akira.theme.activate@1', $payload, [
            'caller' => ['module' => 'cms-akira-theme', 'user' => app()->user()],
            'mode' => 'first',
        ]);
        header('Location: /cms-akira-theme', true, 303);
    } catch (Throwable $error) {
        http_response_code(422);
        echo catThemePage('Theme activation failed', '<p>' . catThemeEscape($error->getMessage()) . '</p>');
    }
}
