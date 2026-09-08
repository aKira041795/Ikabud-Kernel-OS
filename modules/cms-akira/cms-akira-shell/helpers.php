<?php

declare(strict_types=1);

function akiraShellEscape(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @param array<string,mixed> $overrides
 * @return array<string,mixed>
 */
function cms_akira_shellLoginPageContext(array $overrides = []): array
{
    return array_merge([
        'page_title' => 'CMS Akira Sign In',
        'app_name' => 'CMS Akira',
        'login_endpoint' => '/api/v1/auth/login',
        'login_username_label' => 'Username or Email',
        'login_button_text' => 'Enter Akira',
        'login_loading_text' => 'Opening your workspace...',
        'login_forgot_url' => external_base_url() . '/forgot-password',
    ], $overrides);
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
    $active = (string)($data['active'] ?? '');
    $links = [
        'dashboard' => ['/cms-akira-shell', 'Dashboard'],
        'posts' => ['/cms-akira-shell/posts', 'Posts'],
        'compositions' => ['/cms-akira-shell/compositions', 'Compositions'],
        'health' => ['/cms-akira-shell/health', 'Module health'],
    ];
    $nav = '';
    foreach ($links as $key => [$url, $label]) {
        $classes = $key === $active ? 'bg-akira-600 text-white shadow-lg shadow-akira-950/20' : 'text-slate-300 hover:bg-white/10 hover:text-white';
        $nav .= '<a href="' . $url . '" class="flex items-center rounded-xl px-4 py-3 text-sm font-medium transition ' . $classes . '">' . $label . '</a>';
    }
    $user = app()->user();
    $display = is_array($user) ? (string)($user['display_name'] ?? $user['username'] ?? 'Administrator') : 'Administrator';

    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . akiraShellEscape($title) . ' — CMS Akira</title><script src="https://cdn.tailwindcss.com"></script>'
        . '<script defer src="https://unpkg.com/alpinejs@3.14.3/dist/cdn.min.js"></script><script>tailwind.config={theme:{extend:{colors:{akira:{50:\'#f5f3ff\',100:\'#ede9fe\',500:\'#8b5cf6\',600:\'#7c3aed\',700:\'#6d28d9\',950:\'#2e1065\'}}}}}</script></head>'
        . '<body class="min-h-screen bg-slate-50 text-slate-800" x-data="{menu:false}"><a href="#akira-main" class="sr-only focus:not-sr-only">Skip to content</a>'
        . '<div class="min-h-screen lg:flex"><aside :class="menu ? \'block\' : \'hidden\'" class="fixed inset-y-0 left-0 z-40 w-64 bg-gradient-to-b from-slate-950 to-akira-950 p-5 text-white lg:static lg:block">'
        . '<div class="mb-8 flex items-center gap-3"><span class="flex h-10 w-10 items-center justify-center rounded-xl bg-akira-600 text-lg font-black">A</span><div><strong class="block">CMS Akira</strong><span class="text-xs text-slate-400">Content workspace</span></div></div>'
        . '<nav aria-label="Akira administration" class="space-y-1">' . $nav . '</nav><div class="absolute bottom-5 left-5 right-5 border-t border-white/10 pt-4"><a href="/auth/logout" class="text-sm text-slate-300 hover:text-white">Sign out</a></div></aside>'
        . '<div class="min-w-0 flex-1"><header class="flex h-16 items-center justify-between border-b border-slate-200 bg-white/90 px-4 shadow-sm lg:px-8"><button @click="menu=!menu" class="rounded-lg border border-slate-200 p-2 lg:hidden" aria-label="Toggle navigation">☰</button><span class="text-sm text-slate-500">Akira administration</span><span class="text-sm font-semibold text-slate-700">' . akiraShellEscape($display) . '</span></header>'
        . '<main id="akira-main" class="mx-auto max-w-7xl p-4 sm:p-6 lg:p-8"><div class="mb-7"><h1 class="text-3xl font-bold tracking-tight text-slate-950">' . akiraShellEscape($title) . '</h1></div>' . $body . '</main></div></div>'
        . '<div id="akira-toast" class="fixed bottom-4 right-4" aria-live="polite"></div></body></html>';
}

/** @param array<string,mixed> $resolved */
function akiraShellEntityList(array $resolved): string
{
    $rows = is_array($resolved['rows'] ?? null) ? $resolved['rows'] : [];
    $view = is_array($resolved['view'] ?? null) ? $resolved['view'] : [];
    $view['view'] = 'table';
    $view['empty_state'] = 'No posts yet. Create your first post to begin publishing.';
    return app()->entityRenderers()->renderList($rows, $view, [
        'source' => 'post',
        'view' => 'table',
        'class' => 'akira-entity-list',
    ], ['base_url' => '']);
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
