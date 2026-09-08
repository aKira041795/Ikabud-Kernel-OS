<?php

declare(strict_types=1);

function akiraShellEscape(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @return array<string,mixed>|null */
function akiraShellAdmin(): ?array
{
    $user = app()->user();
    if (!is_array($user)) {
        return null;
    }
    if (($user['role'] ?? '') !== 'admin') {
        return [];
    }
    return app()->requireAnyRole('admin');
}

/** @param array<string,mixed> $data */
function akiraShellPage(string $title, string $body, array $data = []): string
{
    $nav = '<nav aria-label="Akira administration"><a href="/cms-akira-shell">Dashboard</a> '
        . '<a href="/cms-akira-shell/posts">Posts</a> '
        . '<a href="/cms-akira-shell/health">Module health</a> '
        . '<a href="/auth/logout">Sign out</a></nav>';
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . akiraShellEscape($title) . '</title></head><body>' . $nav
        . '<main><h1>' . akiraShellEscape($title) . '</h1>' . $body . '</main></body></html>';
}

function akiraShellCsrfField(): string
{
    $token = '';
    if (method_exists(app(), 'csrfToken')) {
        $token = (string)app()->csrfToken();
    } elseif (isset($_SESSION['_csrf_token'])) {
        $token = (string)$_SESSION['_csrf_token'];
    }
    return '<input type="hidden" name="_csrf_token" value="' . akiraShellEscape($token) . '">';
}

/** @return array<string,mixed> */
function akiraShellInput(): array
{
    $ctx = function_exists('module') ? module('cms-akira-shell') : null;
    if ($ctx !== null) {
        $input = $ctx->input();
        if (is_array($input)) {
            return $input;
        }
    }
    return $_POST;
}

/** @param array<string,mixed> $payload */
function akiraShellCall(string $capability, array $payload = []): mixed
{
    return app()->cap()->call($capability, $payload, [
        'caller' => ['module' => 'cms-akira-shell', 'user' => app()->user()],
        'mode' => 'first',
    ]);
}

/** @return array<string,mixed> */
function akiraShellPostPayload(?string $slug = null): array
{
    $input = akiraShellInput();
    if ($slug !== null) {
        $input['slug'] = $slug;
    }
    $input['idempotency_key'] = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($input['idempotency_key'] ?? '')));
    return $input;
}

function akiraShellRedirect(string $path): void
{
    header('Location: ' . $path, true, 303);
}

/**
 * CSP-safe static assets for the Akira Builder admin bundle. These live under
 * public/admin/assets/cms-akira-builder (committed build output, served as
 * static 'self') — the prebuilt bundle never requires 'unsafe-eval'.
 * @return array{js:list<string>,css:list<string>}
 */
function akiraShellBuilderAssets(): array
{
    $dir = realpath(dirname(__DIR__, 3) . '/public/admin/assets/cms-akira-builder');
    $base = '/admin/assets/cms-akira-builder';
    if ($dir === false) {
        return ['js' => [], 'css' => []];
    }
    $js = [];
    $css = [];
    foreach (glob($dir . '/assets/*.js') ?: [] as $file) {
        $js[] = $base . '/assets/' . rawurlencode(basename($file));
    }
    foreach (glob($dir . '/assets/*.css') ?: [] as $file) {
        $css[] = $base . '/assets/' . rawurlencode(basename($file));
    }
    sort($js);
    sort($css);
    return ['js' => $js, 'css' => $css];
}

/**
 * Boots the Akira builder admin React app inside the authenticated shell.
 * Serves the committed static bundle + a mount container + an authorized JSON
 * bootstrap (available Akira posts for attachment and the composition key).
 * @param array<string,mixed> $bootstrap
 */
function akiraShellBuilderAdmin(array $bootstrap): string
{
    $assets = akiraShellBuilderAssets();
    $styles = '';
    foreach ($assets['css'] as $href) {
        $styles .= '<link rel="stylesheet" href="' . akiraShellEscape($href) . '">';
    }
    $json = akiraShellEscape((string) json_encode($bootstrap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $scripts = '';
    foreach ($assets['js'] as $src) {
        $scripts .= '<script type="module" src="' . akiraShellEscape($src) . '"></script>';
    }
    return '<div id="cms-akira-builder-root"></div>'
        . '<script id="cms-akira-builder-bootstrap" type="application/json">' . $json . '</script>'
        . $styles . $scripts;
}

function akiraShellMutation(string $capability, string $slug = ''): void
{
    if (akiraShellAdmin() === null) {
        akiraShellRedirect('/login');
        return;
    }
    if (akiraShellAdmin() === []) {
        http_response_code(403);
        echo akiraShellPage('Access denied', '<p>Your Kernel role cannot administer CMS Akira.</p>');
        return;
    }
    app()->csrfEnforce();
    try {
        akiraShellCall($capability, akiraShellPostPayload($slug !== '' ? $slug : null));
        akiraShellRedirect('/cms-akira-shell/posts');
    } catch (Throwable $e) {
        http_response_code(422);
        echo akiraShellPage('Post operation failed', '<p>' . akiraShellEscape($e->getMessage()) . '</p>');
    }
}
