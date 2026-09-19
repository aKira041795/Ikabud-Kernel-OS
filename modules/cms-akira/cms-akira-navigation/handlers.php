<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** @param array<string, string> $params */
function akiraNavigationHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'module' => 'cms-akira-navigation',
        'version' => '1.0.0',
        'authority' => 'native',
    ], JSON_UNESCAPED_SLASHES);
}

/** @return array<string, mixed>|null */
function akiraNavigationAdmin(): ?array
{
    $user = app()->user();
    if (!is_array($user) || (int) ($user['id'] ?? $user['sub'] ?? 0) <= 0) {
        return null;
    }
    $role = (string) ($user['role'] ?? '');
    if (!in_array($role, ['admin', 'administrator', 'superadmin'], true)) {
        return [];
    }
    if ($role === 'superadmin' && (string) ($user['source'] ?? '') !== 'kernel') {
        return [];
    }
    return $user;
}

/** @return array<string, mixed> */
function akiraNavigationInput(): array
{
    $input = canCtx()->input();
    return is_array($input) ? $input : [];
}

/** @return array{status: int, message: string} */
function akiraNavigationFailureDetail(Throwable $error): array
{
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        if ($cursor instanceof CanNavigationMutationException) {
            return ['status' => $cursor->httpStatus, 'message' => $cursor->getMessage()];
        }
    }
    return ['status' => 500, 'message' => 'Navigation operation failed.'];
}

/** @param array<string, mixed> $context */
function akiraNavigationRender(array $context = []): void
{
    $fragment = canCtx()->render(__DIR__ . '/templates/admin.disyl', $context + [
        'page_title' => 'Navigation',
        'menus' => [],
        'csrf_field' => app()->csrfField(),
        'idempotency_key' => 'navigation-menu-' . bin2hex(random_bytes(16)),
        'saved' => false,
        'error' => '',
    ]);

    echo akiraNavigationShellChrome('Navigation', $fragment, 'navigation');
}

/**
 * Wrap the rendered navigation content fragment in the shared shell chrome
 * through the capability bus. The shell owns the only page builder, so this
 * never rebuilds the sidebar, copies the navigation list, or recreates the old
 * three-link header. If the capability is unavailable or denied, the request
 * degrades to a chrome-free document that still carries the fragment, so a shell
 * failure can never blank the page.
 */
function akiraNavigationShellChrome(string $title, string $fragment, string $active): string
{
    try {
        $result = app()->cap()->call('akira.shell.admin_page@1', [
            'title' => $title,
            'body' => $fragment,
            'active' => $active,
        ], [
            'caller' => ['module' => 'cms-akira-navigation', 'user' => app()->user()],
            'mode' => 'first',
        ]);
    } catch (Throwable $error) {
        return akiraNavigationShellFallback($fragment);
    }

    $html = is_array($result) ? ($result['html'] ?? null) : null;
    return is_string($html) && $html !== '' ? $html : akiraNavigationShellFallback($fragment);
}

function akiraNavigationShellFallback(string $fragment): string
{
    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Navigation — CMS Akira</title></head>'
        . '<body style="margin:2rem auto;max-width:64rem;font-family:system-ui,sans-serif;line-height:1.5;color:#1e293b">'
        . '<p role="alert"><strong>The shared Akira administration shell could not be rendered.</strong> '
        . 'This page is shown without the sidebar.</p><hr>' . $fragment
        . '<p><a href="/cms-akira-shell">Return to the Akira dashboard</a></p></body></html>';
}

/** @param array<string, string> $params */
function akiraNavigationAdminPage(array $params = []): void
{
    $admin = akiraNavigationAdmin();
    if ($admin === null) {
        header('Location: /login', true, 303);
        return;
    }
    if ($admin === []) {
        http_response_code(403);
        akiraNavigationRender(['error' => 'Your Kernel role cannot administer navigation.']);
        return;
    }

    try {
        $result = app()->cap()->call('akira.navigation.menus@1', [], [
            'caller' => ['module' => 'cms-akira-navigation', 'user' => $admin],
            'mode' => 'first',
        ]);
        if (!is_array($result) || ($result['ok'] ?? false) !== true || !is_array($result['rows'] ?? null)) {
            throw new RuntimeException(is_array($result) ? (string) ($result['error'] ?? 'Navigation read failed.') : 'Navigation read failed.');
        }
        akiraNavigationRender([
            'menus' => $result['rows'],
            'saved' => (string) ($_GET['created'] ?? '') === '1',
        ]);
    } catch (Throwable $error) {
        http_response_code(503);
        akiraNavigationRender(['error' => $error->getMessage()]);
    }
}

/** @param array<string, string> $params */
function akiraNavigationMenuCreateForm(array $params = []): void
{
    $admin = akiraNavigationAdmin();
    if ($admin === null) {
        header('Location: /login', true, 303);
        return;
    }
    if ($admin === []) {
        http_response_code(403);
        akiraNavigationRender(['error' => 'Your Kernel role cannot administer navigation.']);
        return;
    }

    app()->csrfEnforce();
    $payload = akiraNavigationInput();
    $payload['idempotency_key'] = trim((string) ($payload['idempotency_key'] ?? ''));
    try {
        app()->cap()->call('akira.navigation.menu.create@1', $payload, [
            'caller' => ['module' => 'cms-akira-navigation', 'user' => $admin],
            'mode' => 'first',
        ]);
        header('Location: /cms-akira-navigation?created=1', true, 303);
    } catch (Throwable $error) {
        $detail = akiraNavigationFailureDetail($error);
        http_response_code($detail['status']);
        akiraNavigationRender(['error' => $detail['message']]);
    }
}
