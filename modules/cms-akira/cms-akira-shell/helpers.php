<?php

declare(strict_types=1);

function akiraShellEscape(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @return array<string,mixed> */
function akiraPublicContext(string $title, string $path, string $description = ''): array
{
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $scheme = function_exists('request_scheme') ? request_scheme() : 'http';
    $origin = $host !== '' ? $scheme . '://' . $host : '';
    $description = trim(preg_replace('/\s+/', ' ', strip_tags($description)) ?? '');
    if (strlen($description) > 160) {
        $description = substr($description, 0, 157) . '...';
    }
    $canonical = $path !== '' ? $origin . $path : '';
    $user = app()->user();

    return [
        'page_title' => $title . ' — CMS Akira',
        'seo_title' => $title,
        'seo_description' => $description,
        'canonical_url' => $canonical,
        'current_year' => date('Y'),
        'show_admin_bar' => is_array($user) && $user !== [],
    ];
}

/**
 * Render a public page through the tenant's validated active ARK package.
 * Returns null when resolution, validation, entity rendering, a declared
 * region, or the public layout is unavailable so the caller can use P5-1.
 *
 * @param array<string,mixed> $context
 */
function akiraPublicThemeRender(string $viewId, array $context): ?string
{
    try {
        $resolved = akiraShellCall('akira.theme.resolve@1');
        $slug = is_array($resolved) && ($resolved['ok'] ?? false) === true
            ? trim((string)($resolved['theme_slug'] ?? '')) : '';
        if ($slug === '' || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/D', $slug) !== 1) {
            return null;
        }
        $themesRoot = realpath(defined('CMS_THEMES_PATH') ? (string)CMS_THEMES_PATH : dirname(__DIR__, 3) . '/storage/cms-themes');
        $themePath = is_string($themesRoot) ? realpath($themesRoot . '/' . $slug) : false;
        if ($themePath === false || !str_starts_with($themePath . DIRECTORY_SEPARATOR, $themesRoot . DIRECTORY_SEPARATOR)) {
            return null;
        }
        $manifestRaw = @file_get_contents($themePath . '/theme.manifest.json');
        $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
        $layout = is_array($manifest) ? trim((string)($manifest['shell'] ?? '')) : '';
        if ($layout === '' || str_contains($layout, '..')) {
            return null;
        }
        $layoutPath = realpath($themePath . '/' . ltrim($layout, '/'));
        if ($layoutPath === false || !str_starts_with($layoutPath, $themePath . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $rendererContext = $viewId === 'entity.list.post'
            ? ['posts' => $context['posts'] ?? []]
            : ['post' => $context['post'] ?? []];
        $pageHtml = app()->arkRenderers()->render($viewId, $rendererContext, $slug);
        if (!is_string($pageHtml)) {
            return null;
        }

        $provider = new \Ikabud\Kernel\Services\DeclarativeThemeCustomizerProvider($slug, $themePath);
        if (!\Ikabud\Kernel\Services\ThemeCustomizerOrchestrator::validateProvider($provider, $slug, $themePath)) {
            return null;
        }
        $definition = $provider->definition();
        $settings = [];
        foreach ($definition->sectionNames() as $section) {
            $settings[$section] = $definition->section($section)?->defaults ?? [];
        }
        $scope = \Ikabud\Kernel\Contracts\ThemeCustomizationScope::fromString('native_' . $slug);
        $themeContext = new \Ikabud\Kernel\Contracts\ThemeRenderContext(
            theme: $slug,
            scope: $scope,
            settings: $settings,
            tokens: $definition->tokens,
            site: ['title' => 'CMS Akira', 'tagline' => 'Governed publishing on Ikabud', 'url' => '/'],
            navigation: ['primary' => [
                ['href' => '/', 'label' => 'Home'],
                ['href' => '/posts', 'label' => 'Posts'],
            ]],
            entityContext: [
                'kind' => $viewId,
                'origin' => 'cms-akira-shell',
                'authenticated' => (bool)($context['show_admin_bar'] ?? false),
            ],
            slotContributions: [],
        );
        $header = \Ikabud\Kernel\Services\ThemeCustomizerOrchestrator::renderProviderRegion($provider, 'header', $themeContext, $themePath);
        $footer = \Ikabud\Kernel\Services\ThemeCustomizerOrchestrator::renderProviderRegion($provider, 'footer', $themeContext, $themePath);
        if ($header['html'] === '' || $footer['html'] === '') {
            return null;
        }

        return app()->render($layoutPath, $context + [
            'theme_slug' => $slug,
            'header_region' => $header['html'],
            'page_region' => $pageHtml,
            'footer_region' => $footer['html'],
        ]);
    } catch (Throwable) {
        return null;
    }
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
function akiraShellParticipant(): ?array
{
    $user = app()->user();
    if (!is_array($user)) {
        return null;
    }
    $roles = function_exists('cawPostLifecycleParticipantRoles') ? cawPostLifecycleParticipantRoles() : [];
    return in_array((string) ($user['role'] ?? ''), $roles, true) ? $user : [];
}

/** @return array<string,mixed>|null */
function akiraShellAdmin(): ?array
{
    $user = app()->user();
    if (!is_array($user)) {
        return null;
    }
    return in_array((string) ($user['role'] ?? ''), ['admin', 'administrator', 'superadmin'], true) ? $user : [];
}

function akiraShellIsAdmin(): bool
{
    $admin = akiraShellAdmin();
    return is_array($admin) && $admin !== [];
}

/** @param array<string,mixed> $data */
function akiraShellPage(string $title, string $body, array $data = []): string
{
    $active = (string)($data['active'] ?? '');
    $links = [
        'dashboard' => ['/cms-akira-shell', 'Dashboard'],
        'posts' => ['/cms-akira-shell/posts', 'Posts'],
        'categories' => ['/cms-akira-shell/categories', 'Categories'],
        'content-types' => ['/cms-akira-shell/content-types', 'Content types'],
    ];
    if (akiraShellIsAdmin()) {
        $links['compositions'] = ['/cms-akira-shell/compositions', 'Compositions'];
        $links['health'] = ['/cms-akira-shell/health', 'Module health'];
    }
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
    $view['fields'] = ['title', 'status', 'updated_at'];
    $view['actions'] = akiraShellIsAdmin() ? ['edit', 'delete'] : ['edit'];
    $view['key_field'] = 'slug';
    $view['action_urls'] = [
        'edit' => '/cms-akira-shell/posts/{slug}/edit',
        'delete' => '/cms-akira-shell/posts/{slug}/delete',
    ];
    $view['action_methods'] = ['delete' => 'POST'];
    $view['action_confirm'] = ['delete' => 'Delete this post? This cannot be undone.'];
    $view['action_labels'] = ['edit' => 'Edit', 'delete' => 'Delete'];
    $view['renderers'] = ['status' => 'badge', 'updated_at' => 'datetime'];
    $view['empty_state'] = 'No posts found. Create your first post or adjust the filters.';
    return app()->entityRenderers()->renderList($rows, $view, [
        'source' => 'post',
        'view' => 'table',
        'class' => 'akira-entity-list',
    ], ['base_url' => '', 'current_user_role' => (string) ((app()->user()['role'] ?? ''))]);
}

function akiraShellCsrfField(): string
{
    return app()->csrfField();
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

/** @return array<string,mixed> */
function akiraShellQuery(): array
{
    return $_GET;
}

function akiraShellJsString(string $value): string
{
    return (string)json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

function akiraShellNotice(): string
{
    if ((akiraShellQuery()['saved'] ?? '') !== '1') {
        return '';
    }
    return '<div x-data="{show:true}" x-show="show" x-init="setTimeout(()=>show=false,4000)" class="mb-5 flex items-center justify-between rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">Post saved successfully.<button @click="show=false" aria-label="Dismiss">×</button></div>';
}

/** @param list<array<string,mixed>> $rows */
function akiraShellRecentPosts(array $rows): string
{
    if ($rows === []) {
        return '<p class="px-6 py-12 text-center text-sm text-slate-400">No posts yet.</p>';
    }
    $html = '';
    foreach ($rows as $row) {
        $status = (string)($row['status'] ?? 'draft');
        $html .= '<a class="flex items-center justify-between gap-4 border-b border-slate-100 px-6 py-4 last:border-0 hover:bg-slate-50" href="/cms-akira-shell/posts/' . rawurlencode((string)($row['slug'] ?? '')) . '/edit"><span><strong class="block text-sm text-slate-900">' . akiraShellEscape($row['title'] ?? '') . '</strong><span class="text-xs text-slate-400">Updated ' . akiraShellEscape($row['updated_at'] ?? '') . '</span></span><span class="rounded-full px-2.5 py-1 text-xs font-semibold ' . ($status === 'published' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700') . '">' . akiraShellEscape(ucfirst($status)) . '</span></a>';
    }
    return $html;
}

function akiraShellPagination(int $page, int $limit, int $total, string $search, string $status, int $category = 0): string
{
    $pages = max(1, (int)ceil($total / $limit));
    if ($pages <= 1) {
        return '';
    }
    $query = static fn (int $target): string => http_build_query(array_filter([
        'q' => $search, 'status' => $status, 'category' => $category > 0 ? $category : '', 'page' => $target,
    ], static fn (string $value): bool => $value !== ''));
    return '<nav aria-label="Post pagination" class="mt-5 flex items-center justify-between text-sm"><span class="text-slate-500">Page ' . $page . ' of ' . $pages . '</span><div class="flex gap-2">'
        . ($page > 1 ? '<a class="rounded-xl border bg-white px-4 py-2" href="?' . akiraShellEscape($query($page - 1)) . '">Previous</a>' : '')
        . ($page < $pages ? '<a class="rounded-xl border bg-white px-4 py-2" href="?' . akiraShellEscape($query($page + 1)) . '">Next</a>' : '') . '</div></nav>';
}

/** @return array<string,mixed>|null */
function akiraShellFetchPost(string $slug): ?array
{
    $result = akiraShellCall('akira.post.get@1', ['slug' => $slug, 'include_unpublished' => true]);
    return is_array($result) && ($result['ok'] ?? false) === true && is_array($result['data'] ?? null)
        ? $result['data'] : null;
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
function akiraShellWorkflow(string $slug): array
{
    try {
        $result = akiraShellCall('akira.workflow.evaluate@1', ['entity_type' => 'post', 'entity_key' => $slug]);
        return is_array($result) && ($result['ok'] ?? false) === true && is_array($result['data'] ?? null)
            ? $result['data'] : ['status' => 'unavailable', 'allowed_actions' => []];
    } catch (Throwable) {
        return ['status' => 'unavailable', 'allowed_actions' => []];
    }
}

/** @param list<array<string,mixed>> $actions */
function akiraShellWorkflowActions(string $slug, array $actions): string
{
    if ($slug === '') {
        return '<p class="mt-4 text-xs text-slate-500">Save the draft to begin its workflow.</p>';
    }
    if ($actions === []) {
        return '<p class="mt-4 text-xs text-slate-500">No workflow actions are currently allowed.</p>';
    }
    $html = '<div data-akira-workflow-actions class="mt-4 space-y-2"><p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Allowed actions</p>';
    foreach ($actions as $action) {
        $name = (string)($action['action'] ?? '');
        if ($name === '') {
            continue;
        }
        $label = (string)($action['label'] ?? ucfirst($name));
        $html .= '<button type="submit" name="action" value="' . akiraShellEscape($name) . '" formaction="/cms-akira-shell/posts/' . rawurlencode($slug) . '/workflow" formmethod="post" class="w-full rounded-xl border border-akira-200 bg-akira-50 px-4 py-2.5 text-sm font-semibold text-akira-700 hover:bg-akira-100">' . akiraShellEscape($label) . '</button>';
    }
    return $html . '</div>';
}

/** @return array<string,mixed> */
function akiraShellPostPayload(?string $slug = null): array
{
    $input = akiraShellInput();
    if ($slug !== null) {
        $input['slug'] = $slug;
    }
    $input['idempotency_key'] = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($input['idempotency_key'] ?? '')));
    if ($input['idempotency_key'] === '') {
        $input['idempotency_key'] = 'shell-' . bin2hex(random_bytes(12));
    }
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

// ── Public page-cache invalidation ───────────────────────────────────────
// The Akira public pages ('/' home, '/posts' archive, '/posts/{slug}' single)
// are served by this module and full-page cached for anonymous visitors. Akira
// governed content mutations run in authenticated admin requests (never
// page-cached themselves), so without an explicit invalidation the cached
// public pages would keep serving stale content after publish/unpublish/
// delete/etc. Module-scoped invalidation is safe here: the admin pages under
// /cms-akira-shell/* require auth and are therefore never page-cached — only
// public pages are cleared. Invalidation (not warm-up) is the required
// behavior so mutations never slow down on cache population.

/**
 * Invalidate the Akira public page cache after a successful governed content
 * mutation. Always clears the module scope; a per-URL entry for the affected
 * post is also dropped when a slug is known.
 *
 * No-op when the page-cache helpers are unavailable (page cache disabled).
 * Must only be called on the success/commit path — never on failure.
 */
function akiraShellInvalidatePublicCache(?string $slug = null): void
{
    if (!function_exists('pageCacheInvalidateModule')) {
        return; // page cache not available/disabled
    }
    pageCacheInvalidateModule('cms-akira-shell');
    if ($slug !== null && $slug !== '' && function_exists('pageCacheInvalidateUrl')) {
        pageCacheInvalidateUrl('/posts/' . $slug);
    }
}

function akiraShellSavePost(?string $existingSlug): void
{
    if (!akiraShellAuthorize()) {
        return;
    }
    app()->csrfEnforce();
    $input = akiraShellPostPayload($existingSlug);
    unset($input['action'], $input['expected_status']);
    $capability = $existingSlug === null ? 'akira.post.create@1' : 'akira.post.update@1';
    $savedSlug = $existingSlug !== null ? $existingSlug : trim((string)($input['slug'] ?? ''));
    try {
        akiraShellCall($capability, $input);
        // Categories persist AFTER the post exists/updates (P1 increment 3):
        // the content write keeps its exact lifecycle semantics and the
        // assignment runs as a separate governed idempotent write against the
        // freshly-persisted post version (its updated_at is re-read, never the
        // form's stale value). Only editorial roles reach this second write.
        if (akiraShellIsTaxonomyManager() && $savedSlug !== '') {
            $fresh = akiraShellFetchPost($savedSlug);
            if (is_array($fresh)) {
                akiraShellCall('akira.post.set_taxonomies@1', akiraShellPostSetTaxonomyPayload(
                    $savedSlug,
                    ['taxonomy_ids' => $input['taxonomy_ids'] ?? null, 'expected_updated_at' => (string)($fresh['updated_at'] ?? '')]
                ));
            }
        }
        akiraShellInvalidatePublicCache($savedSlug !== '' ? $savedSlug : null);
        akiraShellRedirect('/cms-akira-shell/posts?saved=1');
    } catch (Throwable $e) {
        http_response_code(422);
        if ($existingSlug !== null) {
            $input['slug'] = $existingSlug;
            $input['updated_at'] = (string)($input['expected_updated_at'] ?? '');
        }
        echo akiraShellPage($existingSlug === null ? 'Create post' : 'Edit post', akiraShellPostForm($input, $e->getMessage()), ['active' => 'posts']);
    }
}

function akiraShellWorkflowTransition(string $slug): void
{
    if (!akiraShellAuthorize()) {
        return;
    }
    app()->csrfEnforce();
    $input = akiraShellPostPayload($slug);
    try {
        akiraShellCall('akira.workflow.transition@1', [
            'entity_type' => 'post',
            'entity_key' => $slug,
            'action' => (string)($input['action'] ?? ''),
            'expected_status' => (string)($input['expected_status'] ?? ''),
            'idempotency_key' => (string)($input['idempotency_key'] ?? ''),
        ]);
        // Only publish/unpublish transitions flip the post's published status
        // (the public visibility boundary); the other workflow moves never touch
        // public pages, so they skip the cache clear.
        if (in_array((string)($input['action'] ?? ''), ['publish', 'unpublish'], true)) {
            akiraShellInvalidatePublicCache($slug);
        }
        akiraShellRedirect('/cms-akira-shell/posts/' . rawurlencode($slug) . '/edit');
    } catch (Throwable $e) {
        http_response_code(422);
        $post = akiraShellFetchPost($slug) ?? ['slug' => $slug];
        echo akiraShellPage('Edit post', akiraShellPostForm($post, $e->getMessage()), ['active' => 'posts']);
    }
}

function akiraShellMutation(string $capability, string $slug = ''): void
{
    if (!akiraShellAuthorize()) {
        return;
    }
    app()->csrfEnforce();
    try {
        akiraShellCall($capability, akiraShellPostPayload($slug !== '' ? $slug : null));
        akiraShellInvalidatePublicCache($slug !== '' ? $slug : null);
        akiraShellRedirect('/cms-akira-shell/posts');
    } catch (Throwable $e) {
        http_response_code(422);
        echo akiraShellPage('Post operation failed', '<p>' . akiraShellEscape($e->getMessage()) . '</p>');
    }
}

// ── Taxonomy administration (P1 content model) ──────────────────────────
// Managed via governed akira.taxonomy.* capabilities only. Role authority for
// the manage actions lives in the seeded policy rows (admin/editor/administrator/
// superadmin); this helper only mirrors that allowlist for presentation.

function akiraShellIsTaxonomyManager(): bool
{
    $user = app()->user();
    if (!is_array($user)) {
        return false;
    }
    return in_array((string)($user['role'] ?? ''), ['admin', 'editor', 'administrator', 'superadmin'], true);
}

function akiraShellTaxonomyNotice(): string
{
    $saved = (string)(akiraShellQuery()['saved'] ?? '');
    if (!in_array($saved, ['create', 'update', 'delete'], true)) {
        return '';
    }
    $message = match ($saved) {
        'create' => 'Taxonomy term created.',
        'update' => 'Taxonomy term updated.',
        default => 'Taxonomy term deleted.',
    };
    return '<div x-data="{show:true}" x-show="show" x-init="setTimeout(()=>show=false,4000)" class="mb-5 flex items-center justify-between rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">' . akiraShellEscape($message) . '<button @click="show=false" aria-label="Dismiss">×</button></div>';
}

/** @return array<string, mixed> */
function akiraShellTaxonomyPayload(?int $id = null): array
{
    $input = akiraShellInput();
    if ($id !== null) {
        $input['id'] = $id;
    }
    $input['idempotency_key'] = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($input['idempotency_key'] ?? '')));
    if ($input['idempotency_key'] === '') {
        $input['idempotency_key'] = 'taxonomy-' . bin2hex(random_bytes(12));
    }
    return $input;
}


/**
 * Editor-facing error text for a governed content type capability failure.
 */
function akiraShellContentTypePayloadError(Throwable $error): string
{
    // Kernel wraps provider exceptions in CapabilityCallException('Capability
    // call failed', ..., $previous); the editor-facing message is the root
    // cause raised by the governed capability handler (clear 422 text such as
    // "type must be one of ..."). Descend the chain for display only.
    $cursor = $error;
    while ($cursor->getPrevious() instanceof Throwable) {
        $cursor = $cursor->getPrevious();
    }
    $message = trim($cursor->getMessage());
    return $message !== '' ? $message : $error->getMessage();
}

// ── Content types administration (P1 content model) ─────────────────────
// Managed via governed akira.content_type.* capabilities only. Role authority
// for the manage actions lives in the seeded policy rows (admin/editor/
// administrator/superadmin — same editorial allowlist as taxonomy); this helper
// only mirrors that allowlist for presentation.

function akiraShellIsContentTypeManager(): bool
{
    $user = app()->user();
    if (!is_array($user)) {
        return false;
    }
    return in_array((string)($user['role'] ?? ''), ['admin', 'editor', 'administrator', 'superadmin'], true);
}

function akiraShellContentTypeNotice(): string
{
    $saved = (string)(akiraShellQuery()['saved'] ?? '');
    if (!in_array($saved, ['create', 'update', 'delete'], true)) {
        return '';
    }
    $message = match ($saved) {
        'create' => 'Content type created.',
        'update' => 'Content type updated.',
        default => 'Content type deleted.',
    };
    return '<div x-data="{show:true}" x-show="show" x-init="setTimeout(()=>show=false,4000)" class="mb-5 flex items-center justify-between rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">' . akiraShellEscape($message) . '<button @click="show=false" aria-label="Dismiss">×</button></div>';
}

/** @return array<string, mixed> */
function akiraShellContentTypePayload(?int $id = null): array
{
    $input = akiraShellInput();
    if ($id !== null) {
        $input['id'] = $id;
    }
    $input['idempotency_key'] = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($input['idempotency_key'] ?? '')));
    if ($input['idempotency_key'] === '') {
        $input['idempotency_key'] = 'content-type-' . bin2hex(random_bytes(12));
    }
    return $input;
}

/** @return array<string, mixed>|null */
function akiraShellContentTypeSchemaArray(string $schema): ?array
{
    $decoded = json_decode($schema, true);
    return is_array($decoded) ? $decoded : null;
}

function akiraShellContentTypeFieldCount(string $schema): int
{
    $decoded = akiraShellContentTypeSchemaArray($schema);
    $fields = is_array($decoded) ? ($decoded['fields'] ?? null) : null;
    return is_array($fields) ? count($fields) : 0;
}

function akiraShellContentTypePrettySchema(string $schema): string
{
    $decoded = akiraShellContentTypeSchemaArray($schema);
    if ($decoded === null) {
        return $schema;
    }
    $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($pretty) ? $pretty : $schema;
}

// ── Post category assignment (P1 content model increment 3) ───────────────
// The editor picks categories from the governed akira.taxonomy.list@1 read and
// every assignment write flows through akira.post.set_taxonomies@1 (after the
// post content save, in the same request, against the freshly persisted post
// version). Read helpers below only project what the governed pipeline already
// returns; role authority mirrors the seeded policy allowlist for presentation.

/** @return list<array<string, mixed>> */
function akiraShellCategories(): array
{
    try {
        $resolved = akiraShellCall('akira.taxonomy.list@1', ['type' => 'category', 'limit' => 500, 'offset' => 0]);
    } catch (Throwable) {
        return [];
    }
    $rows = is_array($resolved['rows'] ?? null) ? $resolved['rows'] : [];
    return array_values(array_filter($rows, 'is_array'));
}

/**
 * @param array<string, mixed> $input
 * @return list<int>
 */
function akiraShellPostedTaxonomyIds(array $input): array
{
    $raw = $input['taxonomy_ids'] ?? null;
    if (!is_array($raw)) {
        return [];
    }
    $ids = [];
    foreach ($raw as $entry) {
        if ((is_int($entry) || (is_string($entry) && ctype_digit($entry))) && (int)$entry > 0) {
            $ids[] = (int)$entry;
        }
    }
    return array_values(array_unique($ids));
}

/**
 * @param array<string, mixed> $post
 * @return list<int>
 */
function akiraShellAssignedTaxonomyIds(array $post): array
{
    $raw = $post['taxonomy_ids'] ?? null;
    if (!is_array($raw)) {
        return [];
    }
    $ids = [];
    foreach ($raw as $entry) {
        if ((is_int($entry) || (is_string($entry) && ctype_digit($entry))) && (int)$entry > 0) {
            $ids[] = (int)$entry;
        }
    }
    return array_values(array_unique($ids));
}

/**
 * @param list<array<string, mixed>> $categories
 * @param list<int> $assignedIds
 */
function akiraShellCategoryPanel(array $categories, array $assignedIds = [], string $label = 'Categories'): string
{
    $checked = array_fill_keys($assignedIds, true);
    $control = 'accent-akira-600 h-4 w-4 rounded border-slate-300 text-akira-600 focus:ring-akira-500';
    if ($categories === []) {
        return '<div class="mb-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="font-bold">' . akiraShellEscape($label) . '</h2>'
            . '<p class="mt-2 text-xs leading-5 text-slate-500">No categories exist yet. <a class="font-semibold text-akira-700 hover:underline" href="/cms-akira-shell/categories">Create a category</a> before filing this post.</p></div>';
    }
    $items = '';
    foreach ($categories as $category) {
        $id = (int)($category['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $name = akiraShellEscape((string)($category['name'] ?? ''));
        $slug = akiraShellEscape((string)($category['slug'] ?? ''));
        $isChecked = isset($checked[$id]) ? ' checked' : '';
        $items .= '<label class="flex cursor-pointer items-start gap-2 rounded-xl px-2 py-1.5 hover:bg-slate-50"><input type="checkbox" name="taxonomy_ids[]" value="' . $id . '"' . $isChecked . ' class="' . $control . ' mt-0.5"><span><span class="block text-sm font-medium text-slate-800">' . $name . '</span><code class="text-[11px] text-slate-400">' . $slug . '</code></span></label>';
    }
    return '<div class="mb-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><h2 class="font-bold">' . akiraShellEscape($label) . '</h2><a class="text-xs font-semibold text-akira-700 hover:underline" href="/cms-akira-shell/categories">Manage</a></div>'
        . '<div class="mt-3 grid max-h-64 gap-1 overflow-y-auto pr-1">' . $items . '</div>'
        . '<p class="mt-2 text-[11px] text-slate-400">Saved with the post through the governed assignment capability.</p></div>';
}

/**
 * @param list<array<string, mixed>> $categories
 */
function akiraShellCategoryChips(array $categories): string
{
    if ($categories === []) {
        return '<span class="text-xs text-slate-300">—</span>';
    }
    $chips = '';
    foreach ($categories as $category) {
        $id = (int)($category['id'] ?? 0);
        $name = akiraShellEscape((string)($category['name'] ?? ''));
        if ($name === '' || $id <= 0) {
            continue;
        }
        $chips .= '<a href="/cms-akira-shell/posts?category=' . $id . '" title="Filter posts in ' . $name . '" class="inline-flex items-center rounded-full bg-akira-100 px-2.5 py-0.5 text-xs font-semibold text-akira-700 hover:bg-akira-600 hover:text-white">' . $name . '</a>';
    }
    return $chips === '' ? '<span class="text-xs text-slate-300">—</span>' : '<span class="flex flex-wrap gap-1">' . $chips . '</span>';
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function akiraShellPostSetTaxonomyPayload(string $slug, array $input): array
{
    $input['slug'] = $slug;
    $input['taxonomy_ids'] = akiraShellPostedTaxonomyIds($input);
    $input['idempotency_key'] = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($input['idempotency_key'] ?? '')));
    if ($input['idempotency_key'] === '') {
        $input['idempotency_key'] = 'post-taxonomy-' . bin2hex(random_bytes(10));
    }
    return array_intersect_key($input, array_flip(['slug', 'taxonomy_ids', 'expected_updated_at', 'idempotency_key']));
}

/**
 * Bespoke posts table: same admin surface as the Categories/Content types
 * tables but fed by the governed entity-view pipeline (each row carries its
 * taxonomy_ids + category labels from entity.list.post@1).
 *
 * @param list<array<string, mixed>> $rows
 */
function akiraShellPostTable(array $rows): string
{
    if ($rows === []) {
        return '<div class="rounded-[26px] border border-slate-200 bg-white p-12 text-center text-sm text-slate-400 shadow-sm">No posts found. Create your first post or adjust the filters.</div>';
    }
    $body = '';
    foreach ($rows as $row) {
        $body .= akiraShellPostRow($row);
    }
    return '<div class="overflow-hidden rounded-[26px] border border-slate-200 bg-white shadow-sm">'
        . '<div class="grid grid-cols-[minmax(0,1.6fr)_110px_minmax(0,1fr)_170px_auto] items-center gap-4 border-b border-slate-100 bg-slate-50/60 px-6 py-3 text-xs font-semibold uppercase tracking-wide text-slate-400"><span>Post</span><span>Status</span><span>Categories</span><span>Updated</span><span class="text-right">Actions</span></div>'
        . $body . '</div>';
}

/** @param array<string, mixed> $row */
function akiraShellPostRow(array $row): string
{
    $slug = (string)($row['slug'] ?? '');
    $title = akiraShellEscape((string)($row['title'] ?? ''));
    $status = (string)($row['status'] ?? 'draft');
    $updated = akiraShellEscape((string)($row['updated_at'] ?? ''));
    $categories = is_array($row['categories'] ?? null) ? $row['categories'] : [];
    $statusBadge = $status === 'published' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700';
    $editUrl = '/cms-akira-shell/posts/' . rawurlencode($slug) . '/edit';
    $actions = '<div class="flex justify-end gap-2"><a class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50" href="' . $editUrl . '">Edit</a>';
    if (akiraShellIsAdmin()) {
        $actions .= '<form method="post" action="/cms-akira-shell/posts/' . rawurlencode($slug) . '/delete" onsubmit="return confirm(\'Delete this post? This cannot be undone.\')">' . akiraShellCsrfField()
            . '<input type="hidden" name="expected_updated_at" value="' . akiraShellEscape((string)($row['updated_at'] ?? '')) . '">'
            . '<input type="hidden" name="idempotency_key" value="post-delete-' . bin2hex(random_bytes(8)) . '">'
            . '<button class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-100" type="submit">Delete</button></form>';
    }
    $actions .= '</div>';
    return '<div class="grid grid-cols-[minmax(0,1.6fr)_110px_minmax(0,1fr)_170px_auto] items-center gap-4 border-b border-slate-100 px-6 py-4 last:border-0 hover:bg-slate-50/50">'
        . '<span><a class="block text-sm font-semibold text-slate-900 hover:text-akira-700" href="' . $editUrl . '">' . $title . '</a><code class="text-xs text-slate-400">' . akiraShellEscape($slug) . '</code></span>'
        . '<span><span class="rounded-full px-2.5 py-1 text-xs font-semibold ' . $statusBadge . '">' . akiraShellEscape(ucfirst($status)) . '</span></span>'
        . akiraShellCategoryChips($categories)
        . '<span class="text-xs text-slate-400">' . $updated . '</span>'
        . $actions . '</div>';
}

// ── Post revisions (P1 content model increment 4) ───────────────────────
// The editor's Revisions section reads history through the ungoverned
// akira.post.revisions.list@1 capability (restricted to editorial
// participants) and every revert POST flows through the governed
// akira.post.revision.revert@1 capability. Role authority for the revert
// action lives in the seeded policy rows (admin/editor/administrator/
// superadmin); this helper only mirrors that allowlist for presentation.

/** @return Throwable */
function akiraShellRootException(Throwable $error): Throwable
{
    $cursor = $error;
    while ($cursor->getPrevious() instanceof Throwable) {
        $cursor = $cursor->getPrevious();
    }
    return $cursor;
}

function akiraShellRootErrorMessage(Throwable $error): string
{
    $root = akiraShellRootException($error);
    $message = trim($root->getMessage());
    return $message !== '' ? $message : $error->getMessage();
}

function akiraShellIsPostRevisionManager(): bool
{
    $user = app()->user();
    if (!is_array($user)) {
        return false;
    }
    return in_array((string)($user['role'] ?? ''), ['admin', 'editor', 'administrator', 'superadmin'], true);
}

/**
 * @return list<array<string, mixed>>
 */
function akiraShellRevisionRows(string $slug): array
{
    try {
        $resolved = akiraShellCall('akira.post.revisions.list@1', ['slug' => $slug, 'limit' => 200, 'offset' => 0]);
    } catch (Throwable) {
        return [];
    }
    $rows = is_array($resolved['rows'] ?? null) ? $resolved['rows'] : [];
    return array_values(array_filter($rows, 'is_array'));
}

/**
 * Revisions card shown below the editor form. It is purely additive: the card
 * never renders for a brand-new post and every revert is a separate confirmed
 * POST carrying the canonical Kernel CSRF field plus the current post version.
 *
 * @param list<array<string, mixed>> $rows
 */
function akiraShellRevisionTable(string $slug, string $updatedAt, array $rows, bool $canRevert): string
{
    if ($rows === []) {
        return '<div class="rounded-2xl border border-dashed border-slate-200 px-5 py-8 text-center text-sm text-slate-400">No revisions recorded yet. Every governed save on this post will snapshot its content here.</div>';
    }
    $body = '';
    foreach ($rows as $row) {
        $revisionNo = (int)($row['revision_no'] ?? 0);
        $action = (string)($row['action'] ?? '');
        $actor = (int)($row['actor_user_id'] ?? 0);
        $createdAt = akiraShellEscape((string)($row['created_at'] ?? ''));
        if ($revisionNo <= 0) {
            continue;
        }
        $badge = $action === 'revert'
            ? 'bg-violet-100 text-violet-700'
            : (in_array($action, ['publish', 'unpublish', 'delete'], true)
                ? 'bg-sky-100 text-sky-700'
                : 'bg-slate-100 text-slate-600');
        $actorLabel = $actor > 0 ? 'User #' . $actor : 'System';
        $revert = '';
        if ($canRevert && $action !== 'delete') {
            $revert = '<form method="post" action="/cms-akira-shell/posts/' . rawurlencode($slug) . '/revisions/' . $revisionNo . '/revert" onsubmit="return confirm(\'Revert this post\\\'s content to revision ' . $revisionNo . '? Status and workflow state are untouched.\')">' . akiraShellCsrfField()
                . '<input type="hidden" name="expected_updated_at" value="' . akiraShellEscape($updatedAt) . '">'
                . '<input type="hidden" name="idempotency_key" value="post-revision-revert-' . bin2hex(random_bytes(8)) . '">'
                . '<button class="rounded-xl border border-akira-200 bg-akira-50 px-3 py-2 text-xs font-semibold text-akira-700 hover:bg-akira-100" type="submit">Revert to this revision</button></form>';
        } elseif (!$canRevert) {
            $revert = '<span class="text-xs text-slate-300">Read only</span>';
        }
        $body .= '<div class="grid grid-cols-[64px_1fr_1fr_170px_auto] items-center gap-4 border-b border-slate-100 px-5 py-3 last:border-0 hover:bg-slate-50/50">'
            . '<span class="font-mono text-sm font-semibold text-slate-700">#' . $revisionNo . '</span>'
            . '<span><span class="rounded-full px-2.5 py-1 text-xs font-semibold ' . $badge . '">' . akiraShellEscape($action) . '</span></span>'
            . '<span class="text-sm text-slate-600">' . akiraShellEscape($actorLabel) . '</span>'
            . '<span class="text-xs text-slate-400">' . $createdAt . '</span>'
            . '<span class="flex justify-end">' . $revert . '</span></div>';
    }
    return '<div class="overflow-hidden rounded-2xl border border-slate-200">'
        . '<div class="grid grid-cols-[64px_1fr_1fr_170px_auto] items-center gap-4 border-b border-slate-100 bg-slate-50/60 px-5 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-400"><span>Version</span><span>Action</span><span>Actor</span><span>Created</span><span class="text-right">Revert</span></div>'
        . $body . '</div>';
}

/** @return string */
function akiraShellRevisionFlash(string $slug): string
{
    if ((akiraShellQuery()['saved'] ?? '') !== 'revision') {
        return '';
    }
    return '<div x-data="{show:true}" x-show="show" x-init="setTimeout(()=>show=false,4000)" class="mb-5 flex items-center justify-between rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">Post content reverted to the selected revision.<button @click="show=false" aria-label="Dismiss">×</button></div>';
}

function akiraShellRevisionPanel(string $slug, string $updatedAt): string
{
    // Presentation safety mirror of the seeded policy allowlist; the real
    // authority is akira.post.revision.revert@1's policy row.
    if ($slug === '' || $updatedAt === '') {
        return '';
    }
    if (!function_exists('app') || !is_object(app()) || !method_exists(app(), 'user')) {
        return '';
    }
    $canRevert = akiraShellIsPostRevisionManager();
    $rows = akiraShellRevisionRows($slug);
    return '<section class="mt-8 rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm" data-akira-post-revisions>'
        . '<div class="flex flex-wrap items-center justify-between gap-2"><div><h2 class="text-xl font-bold text-slate-950">Revisions</h2><p class="mt-1 text-sm text-slate-500">Every governed write snapshots this post\\\'s content in one tenant transaction. Reverting restores content only — status and workflow state stay untouched.</p></div></div>'
        . '<div class="mt-5">' . akiraShellRevisionFlash($slug) . akiraShellRevisionTable($slug, $updatedAt, $rows, $canRevert) . '</div></section>';
}
