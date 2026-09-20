<?php

declare(strict_types=1);

function akiraShellSeedKernelProvenancePolicy(): void
{
    if (!class_exists(\Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry::class)) {
        return;
    }
    \Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry::seedPolicyForCurrentScope([[
        'policy_version' => 30,
        'capability_id' => 'kernel.provenance.list@1',
        'capability_version' => '1',
        'provider' => 'kernel',
        'caller_module' => 'cms-akira-shell',
        'allowed_roles' => 'admin,administrator,superadmin',
        'provider_activation_required' => false,
        'requires_protocol' => 'v1',
        'is_active' => true,
    ]]);
}

if (!function_exists('cacRequestPath') || cacRequestMayMutate()
    || cacRequestPath() === '' || cacRequestPath() === '/cms-akira-shell/provenance') {
    akiraShellSeedKernelProvenancePolicy();
}

/**
 * Seed the one shell chrome render authority. Module-owned administration
 * surfaces (the shell itself, cms-akira-seo, cms-akira-navigation and
 * cms-akira-theme) render their
 * content fragments through akira.shell.admin_page@1; the caller_module
 * allowlist must name every consumer or fail-closed dispatch refuses it with
 * `disabled_caller` and the surface silently degrades.
 *
 * The policy version is the tenant's ACTIVE version, never a literal. The
 * permissions surface clones the active set into N+1, so a row pinned to a
 * superseded version resolves as `missing_policy_row` and refuses every
 * operator even though the row exists and is active.
 */
function akiraShellSeedAdminPagePolicy(): void
{
    if (!function_exists('cacActivePolicyVersion')) {
        return;
    }
    \Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry::seedPolicyForCurrentScope([[
        'policy_version' => cacActivePolicyVersion(),
        'capability_id' => 'akira.shell.admin_page@1',
        'capability_version' => '1',
        'provider' => 'cms-akira-shell',
        'caller_module' => 'cms-akira-shell,cms-akira-seo,cms-akira-navigation,cms-akira-theme',
        'allowed_roles' => 'admin,administrator,superadmin',
        'provider_activation_required' => true,
        'requires_protocol' => 'v1',
        'is_active' => true,
    ]]);
}

if (!function_exists('cacRequestPath') || cacRequestMayMutate()
    || cacRequestPath() === ''
    || in_array(cacRequestPath(), ['/cms-akira-theme', '/cms-akira-shell/health'], true)
    || str_starts_with(cacRequestPath(), '/cms-akira-shell/compositions')) {
    akiraShellSeedAdminPagePolicy();
}

/**
 * Capability handler map for the shell's exposed capabilities. The chrome
 * capability is a thin wrapper over akiraShellPage(): the shell owns exactly
 * one page builder, and no consumer copies the navigation list or builds a
 * second page.
 *
 * @return array<string, string>
 */
function cms_akira_shell_capability_handlers(): array
{
    return [
        'akira.shell.admin_page@1' => 'akiraShellCapAdminPage',
    ];
}

/**
 * Render an already-built admin content fragment inside the one shell chrome.
 * This is deliberately the whole implementation: it delegates to
 * akiraShellPage() instead of reconstructing the sidebar, palette, Alpine
 * config or layout. A second page builder here would recreate the defect this
 * capability exists to remove.
 *
 * @param mixed $payload
 * @return array{html: string}
 */
function akiraShellCapAdminPage(mixed $payload = [], string $capabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    return ['html' => akiraShellPage(
        (string) ($data['title'] ?? ''),
        (string) ($data['body'] ?? ''),
        ['active' => (string) ($data['active'] ?? '')]
    )];
}

/*
 * The kernel full-page cache restores a hardcoded `Content-Type: text/html` on
 * a cache hit (src/helpers/page-cache.php:334). A sitemap or robots file served
 * as text/html is rejected by crawlers, so these two non-HTML public resources
 * must not be page-cached. The cache's own documented bypass is ?nocache=1;
 * setting it here, at module-load time (before dispatch computes page-cache
 * eligibility), keeps the correct handler headers on every request. Only these
 * two exact paths are affected.
 */
function akiraPublicBypassPageCacheForNonHtml(): void
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (is_string($path) && in_array(rtrim($path, '/'), ['/sitemap.xml', '/robots.txt'], true)) {
        $_GET['nocache'] = '1';
    }
}

akiraPublicBypassPageCacheForNonHtml();

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
 * Absolute origin (scheme://host) for the current request, derived from the
 * request's own Host and scheme. This is deliberately not a configured
 * constant: a sitemap or robots file must always describe the host that asked
 * for it, so tenant A can never emit tenant B's URLs.
 *
 * The Host header is caller-controlled, so it is validated before use. An
 * implausible authority yields '' and the caller emits no absolute URL rather
 * than an attacker-shaped one.
 */
function akiraPublicRequestOrigin(): string
{
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    $valid = preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::[0-9]{1,5})?$/', $host) === 1
        || preg_match('/^\[[0-9a-f:.]+\](?::[0-9]{1,5})?$/', $host) === 1;
    if (!$valid) {
        return '';
    }
    $scheme = function_exists('request_scheme') ? strtolower(request_scheme()) : 'http';
    return ($scheme === 'https' ? 'https' : 'http') . '://' . $host;
}

/** XML-escape a scalar for safe inclusion in the sitemap document. */
function akiraPublicSitemapEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

/** Hard cap on sitemap entries; beyond it the response says it was truncated. */
function akiraPublicSitemapMaxEntries(): int
{
    return 500;
}

/**
 * Normalise a projected post timestamp to a W3C datetime for <lastmod>, or
 * null when the value is absent or not a real calendar date. An unparseable
 * timestamp omits the element rather than inventing a date.
 */
function akiraPublicSitemapLastmod(mixed $value): ?string
{
    $value = trim((string) $value);
    if ($value === '' || preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2}:\d{2}))?/', $value, $matches) !== 1) {
        return null;
    }
    $date = $matches[1];
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d') !== $date) {
        return null;
    }
    return isset($matches[2]) ? $date . 'T' . $matches[2] : $date;
}

/**
 * Build the urlset document from already-projected published post rows. URLs
 * come from the canonical `url` field the entity view exposes — never a slug
 * rebuilt here — and every value is XML-escaped.
 *
 * @param list<array<string,mixed>> $posts
 */
function akiraPublicSitemapUrlset(array $posts, string $origin, bool $truncated = false): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    if ($truncated) {
        $xml .= '<!-- truncated at ' . akiraPublicSitemapMaxEntries() . ' entries -->' . "\n";
    }
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($posts as $post) {
        if ($origin === '') {
            continue;
        }
        $url = trim((string) ($post['url'] ?? ''));
        // The canonical projection is an absolute path. Anything else is not a
        // locatable public post and is skipped rather than guessed.
        if ($url === '' || !str_starts_with($url, '/') || str_starts_with($url, '//')) {
            continue;
        }
        $xml .= '  <url><loc>' . akiraPublicSitemapEscape($origin . $url) . '</loc>';
        $lastmod = akiraPublicSitemapLastmod($post['metadata'] ?? null);
        if ($lastmod !== null) {
            $xml .= '<lastmod>' . akiraPublicSitemapEscape($lastmod) . '</lastmod>';
        }
        $xml .= '</url>' . "\n";
    }
    return $xml . '</urlset>' . "\n";
}

/**
 * Read the tenant's published posts for syndication through the existing
 * published-only entity read. include_unpublished is deliberately absent, so
 * the capability applies the same tenant-scoped status='published' AND
 * deleted_at IS NULL boundary the public pages use. No second filter exists.
 *
 * @return array{posts: list<array<string,mixed>>, truncated: bool}
 */
function akiraPublicSitemapPosts(): array
{
    $max = akiraPublicSitemapMaxEntries();
    $pageSize = 50;
    $posts = [];
    $offset = 0;
    $truncated = false;
    while (count($posts) < $max) {
        $limit = min($pageSize, $max - count($posts));
        $result = akiraShellCall('entity.list.post@1', [
            'limit' => $limit,
            'offset' => $offset,
            'sort_field' => 'published_at',
            'sort_direction' => 'asc',
        ]);
        $rows = is_array($result) && ($result['ok'] ?? false) === true && is_array($result['rows'] ?? null)
            ? array_values(array_filter($result['rows'], 'is_array'))
            : [];
        if ($rows === []) {
            break;
        }
        foreach ($rows as $row) {
            if (count($posts) >= $max) {
                break;
            }
            $posts[] = $row;
        }
        // Advance by the requested window, not the projected count: the entity
        // projection can drop an unsafe stored slug, and counting projections
        // would then skip a real post.
        $offset += $limit;
        $total = (int) ($result['total'] ?? count($posts));
        if ($offset >= $total) {
            break;
        }
        if (count($posts) >= $max) {
            $truncated = true;
            break;
        }
    }
    return ['posts' => $posts, 'truncated' => $truncated];
}

/** Full sitemap document for the current request. */
function akiraPublicSitemapXml(): string
{
    $origin = akiraPublicRequestOrigin();
    $data = akiraPublicSitemapPosts();
    return akiraPublicSitemapUrlset($data['posts'], $origin, $data['truncated']);
}

/** robots.txt body for the current request. */
function akiraPublicRobotsTxt(?string $origin = null): string
{
    $origin = $origin ?? akiraPublicRequestOrigin();
    $lines = ['User-agent: *', 'Allow: /', ''];
    if ($origin !== '') {
        $lines[] = 'Sitemap: ' . $origin . '/sitemap.xml';
        $lines[] = '';
    }
    return implode("\n", $lines);
}

/**
 * Documented render-time defaults for the public behaviour projection. Core's
 * cacSiteSettingsDefaults() is the source of truth whenever it is loaded; the
 * literal fallback only keeps this file usable when loaded standalone.
 *
 * @return array<string,string>
 */
function akiraShellSettingDefaults(): array
{
    if (function_exists('cacSiteSettingsDefaults')) {
        /** @var array<string,string> $defaults */
        $defaults = cacSiteSettingsDefaults();
        return $defaults;
    }
    return [
        'public_posts_archive' => 'enabled',
        'public_post_single' => 'enabled',
        'public_archive_page_size' => '12',
        'public_archive_sort' => 'newest',
    ];
}

/**
 * Read the public render-safe behaviour projection through Core. Values that
 * are absent or blank fall back to the documented default, so a partial module
 * outage never takes the existing public site down.
 *
 * @return array<string,string>
 */
function akiraPublicSiteSettings(): array
{
    $settings = akiraShellSettingDefaults();
    try {
        $result = akiraShellCall('akira.site.settings.public@1');
        $stored = is_array($result) && ($result['ok'] ?? false) === true && is_array($result['settings'] ?? null)
            ? $result['settings'] : [];
        foreach ($settings as $key => $default) {
            $value = $stored[$key] ?? null;
            $settings[$key] = is_string($value) && trim($value) !== '' ? trim($value) : $default;
        }
    } catch (Throwable) {
        // Keep the documented defaults when the provider is unavailable.
    }
    return $settings;
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
        $customizer = akiraShellCall('akira.theme.customizer.values@1');
        $persisted = is_array($customizer) && ($customizer['ok'] ?? false) === true
            && ($customizer['theme_slug'] ?? '') === $slug && is_array($customizer['values'] ?? null)
            ? $customizer['values'] : [];
        $settings = [];
        $tokens = $definition->tokens;
        foreach ($definition->sectionNames() as $section) {
            $defaults = $definition->section($section)?->defaults ?? [];
            $overrides = is_array($persisted[$section] ?? null) ? $persisted[$section] : [];
            $resolved = $defaults;
            foreach ($overrides as $key => $value) {
                // Empty customizer fields mean "use the theme default". In
                // particular, never let a blank become an empty CSS value.
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $resolved[$key] = $value;
                }
            }
            $settings[$section] = $resolved;

            // Token controls use template-safe ids (color_primary) which map
            // declaratively to CSS token ids (--color-primary). No theme-
            // specific PHP map or persistence access is needed here.
            foreach ($resolved as $key => $value) {
                $tokenKey = isset($tokens[$key]) ? $key : '--' . str_replace('_', '-', (string) $key);
                if (isset($tokens[$tokenKey]) && trim((string) $value) !== '') {
                    $tokens[$tokenKey]['default'] = $value;
                }
            }
        }
        $scope = \Ikabud\Kernel\Contracts\ThemeCustomizationScope::fromString('native_' . $slug);
        $themeContext = new \Ikabud\Kernel\Contracts\ThemeRenderContext(
            theme: $slug,
            scope: $scope,
            settings: $settings,
            tokens: $tokens,
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
    $requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $requestPath = is_string($requestPath) ? rtrim($requestPath, '/') : '';
    $links = [
        ['id' => 'dashboard', 'route' => '/cms-akira-shell', 'label' => 'Dashboard', 'order' => 0],
        ['id' => 'posts', 'route' => '/cms-akira-shell/posts', 'label' => 'Posts', 'order' => 10],
        ['id' => 'categories', 'route' => '/cms-akira-shell/categories', 'label' => 'Categories', 'order' => 20],
        ['id' => 'content-types', 'route' => '/cms-akira-shell/content-types', 'label' => 'Content types', 'order' => 30],
        ['id' => 'media', 'route' => '/cms-akira-shell/media', 'label' => 'Media', 'order' => 35],
    ];
    if (akiraShellIsAdmin()) {
        $links = array_merge($links, [
            ['id' => 'compositions', 'route' => '/cms-akira-shell/compositions', 'label' => 'Compositions', 'order' => 40],
            ['id' => 'permissions', 'route' => '/cms-akira-shell/permissions', 'label' => 'Permissions', 'order' => 50],
            ['id' => 'settings', 'route' => '/cms-akira-shell/settings', 'label' => 'Site settings', 'order' => 55],
            ['id' => 'users', 'route' => '/cms-akira-shell/users', 'label' => 'Users', 'order' => 60],
            ['id' => 'health', 'route' => '/cms-akira-shell/health', 'label' => 'Module health', 'order' => 70],
        ]);
    }
    foreach (kernelContributionsForHostLocation('cms-akira-shell', 'sidebar', null, kernelContributionRequestContext()) as $contribution) {
        $links[] = [
            'id' => (string) ($contribution['active_key'] ?: $contribution['id']),
            'route' => (string) $contribution['route'],
            'label' => (string) $contribution['label'],
            'order' => (int) $contribution['order'],
        ];
    }
    usort($links, static fn (array $a, array $b): int => [$a['order'], $a['id']] <=> [$b['order'], $b['id']]);
    $nav = '';
    foreach ($links as $link) {
        [$key, $url, $label] = [$link['id'], $link['route'], $link['label']];
        $isActive = $key === $active || rtrim((string)$url, '/') === $requestPath;
        $classes = $isActive ? 'bg-akira-600 text-white shadow-lg shadow-akira-950/20' : 'text-slate-300 hover:bg-white/10 hover:text-white';
        $current = $isActive ? ' aria-current="page"' : '';
        $nav .= '<a href="' . $url . '"' . $current . ' class="flex items-center rounded-xl px-4 py-3 text-sm font-medium transition ' . $classes . '">' . $label . '</a>';
    }
    $user = app()->user();
    $display = is_array($user) ? (string)($user['display_name'] ?? $user['username'] ?? 'Administrator') : 'Administrator';

    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . akiraShellEscape($title) . ' — CMS Akira</title><script src="https://cdn.tailwindcss.com"></script>'
        . '<script defer src="https://unpkg.com/alpinejs@3.14.3/dist/cdn.min.js"></script><script>tailwind.config={theme:{extend:{colors:{akira:{50:\'#f5f3ff\',100:\'#ede9fe\',500:\'#8b5cf6\',600:\'#7c3aed\',700:\'#6d28d9\',950:\'#2e1065\'}}}}}</script></head>'
        . '<body class="min-h-screen bg-slate-50 text-slate-800" x-data="{menu:false}"><a href="#akira-main" class="sr-only focus:not-sr-only">Skip to content</a>'
        . '<div class="min-h-screen lg:flex"><aside :class="menu ? \'flex\' : \'hidden\'" class="fixed inset-y-0 left-0 z-40 w-64 flex-col bg-gradient-to-b from-slate-950 to-akira-950 p-5 text-white lg:sticky lg:top-0 lg:flex lg:h-screen">'
        . '<div class="mb-8 flex shrink-0 items-center gap-3"><span class="flex h-10 w-10 items-center justify-center rounded-xl bg-akira-600 text-lg font-black">A</span><div><strong class="block">CMS Akira</strong><span class="text-xs text-slate-400">Content workspace</span></div></div>'
        . '<nav aria-label="Akira administration" class="min-h-0 flex-1 space-y-1 overflow-y-auto">' . $nav . '</nav><div class="mt-auto shrink-0 border-t border-white/10 pt-4"><a href="/auth/logout" class="text-sm text-slate-300 hover:text-white">Sign out</a></div></aside>'
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

/** @param list<array<string,mixed>> $rows */
function akiraShellModuleManager(array $rows): string
{
    $notice = (akiraShellQuery()['saved'] ?? '') === '1'
        ? '<div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">Module state updated.</div>' : '';
    $html = '';
    foreach ($rows as $row) {
        $id = (string)($row['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $installed = ($row['installed'] ?? false) === true;
        $enabled = ($row['enabled'] ?? false) === true;
        $profile = ($row['kind'] ?? '') === 'profile';
        $required = ($row['required'] ?? false) === true;
        $installedLabel = $installed ? 'Installed' : 'Not installed';
        $enabledLabel = $enabled ? 'Enabled' : 'Disabled';
        $action = '';
        if (!$installed) {
            $action = 'install';
        } elseif (!$profile && !$required) {
            $action = $enabled ? 'disable' : 'enable';
        }
        $button = $action === ''
            ? '<span class="text-xs text-slate-400">' . ($required ? 'Required by entry module' : 'Bundle installed') . '</span>'
            : '<form method="post" action="/cms-akira-shell/modules/' . rawurlencode($id) . '">' . akiraShellCsrfField()
                . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="idempotency_key" value="module-' . bin2hex(random_bytes(10)) . '">'
                . '<button type="submit" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700">' . akiraShellEscape(ucfirst($action)) . '</button></form>';
        $html .= '<div data-akira-module-row data-module-id="' . akiraShellEscape($id) . '" data-installed="' . ($installed ? '1' : '0') . '" data-enabled="' . ($enabled ? '1' : '0') . '" class="grid grid-cols-[minmax(0,1.5fr)_130px_130px_170px_auto] items-center gap-4 border-b border-slate-100 px-6 py-4 last:border-0">'
            . '<span><strong class="block text-sm text-slate-900">' . akiraShellEscape((string)($row['name'] ?? $id)) . '</strong><code class="text-xs text-slate-400">' . akiraShellEscape($id) . '</code></span>'
            . '<span class="text-sm text-slate-600">' . akiraShellEscape((string)($row['kind'] ?? 'module')) . '</span>'
            . '<span class="text-xs font-semibold ' . ($installed ? 'text-emerald-700' : 'text-slate-400') . '">' . $installedLabel . '</span>'
            . '<span><span class="rounded-full px-2.5 py-1 text-xs font-semibold ' . ($enabled ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500') . '">' . $enabledLabel . '</span><small class="ml-2 text-slate-400">' . akiraShellEscape((string)($row['activation_state'] ?? '')) . '</small></span>'
            . '<span class="flex justify-end">' . $button . '</span></div>';
    }
    if ($html === '') {
        $html = '<p class="p-10 text-center text-sm text-slate-400">No Akira suite packages were discovered.</p>';
    }
    return $notice . '<p class="mb-5 text-sm text-slate-500">Installed state comes from the Kernel install ledger; enabled state comes from this tenant’s activation settings.</p>'
        . '<section class="overflow-hidden rounded-[26px] border border-slate-200 bg-white shadow-sm"><div class="grid grid-cols-[minmax(0,1.5fr)_130px_130px_170px_auto] gap-4 border-b border-slate-100 bg-slate-50 px-6 py-3 text-xs font-semibold uppercase tracking-wide text-slate-400"><span>Package</span><span>Kind</span><span>Install</span><span>Activation</span><span></span></div>' . $html . '</section>';
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

/** @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function akiraShellAdminPostList(array $payload): array
{
    try {
        $result = akiraShellCall('akira.post.admin.list@1', $payload);
        return is_array($result) ? $result : ['ok' => false, 'rows' => [], 'total' => 0];
    } catch (\Ikabud\Kernel\Capabilities\CapabilityCallException) {
        return ['ok' => false, 'rows' => [], 'total' => 0, 'authorization_denied' => true];
    }
}

/** @param array<string,mixed> $result */
function akiraShellAdminReadDenied(array $result): bool
{
    if (($result['authorization_denied'] ?? false) !== true) {
        return false;
    }
    http_response_code(403);
    echo akiraShellPage('Access denied', '<p>The active tenant policy denies this administration read.</p>');
    return true;
}

/** @return array<string,mixed>|null */
function akiraShellFetchPost(string $slug): ?array
{
    $result = akiraShellCall('akira.post.admin.get@1', ['slug' => $slug, 'include_unpublished' => true]);
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

/** Render each available dashboard widget through its own capability. */
function akiraShellDashboardWidgets(): string
{
    try {
        $registry = akiraShellCall('akira.extension.widgets@1');
    } catch (Throwable) {
        return '';
    }
    if (!is_array($registry) || ($registry['ok'] ?? false) !== true || !is_array($registry['widgets'] ?? null)) {
        return '';
    }
    $html = '';
    foreach ($registry['widgets'] as $widget) {
        if (!is_array($widget)) {
            continue;
        }
        try {
            $rendered = akiraShellCall((string) ($widget['render_capability'] ?? ''));
        } catch (Throwable) {
            continue;
        }
        if (!is_array($rendered) || ($rendered['ok'] ?? false) !== true || !is_string($rendered['html'] ?? null)) {
            continue;
        }
        $html .= '<article class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-4 font-bold text-slate-950">'
            . akiraShellEscape($widget['label'] ?? '') . '</h2>' . $rendered['html'] . '</article>';
    }
    return $html === '' ? '' : '<section class="mt-6 grid gap-4 lg:grid-cols-2">' . $html . '</section>';
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

/**
 * Read the real workflow-run state through governed capabilities only. The shell
 * owns no workflow tables: posts are enumerated with akira.post.admin.list@1,
 * current lifecycle state with akira.workflow.evaluate@1, and run history with
 * akira.workflow.runs@1.
 *
 * @return array{rows:list<array<string,mixed>>,run_total:int,post_total:int,runs_available:bool,error:string}
 */
function akiraShellWorkflowConsoleData(): array
{
    $result = akiraShellAdminPostList([
        'filters' => ['include_unpublished' => true],
        'limit' => 100,
        'offset' => 0,
        'sort_field' => 'created_at',
        'sort_direction' => 'desc',
    ]);
    if (($result['authorization_denied'] ?? false) === true) {
        return [
            'rows' => [],
            'run_total' => 0,
            'post_total' => 0,
            'runs_available' => false,
            'error' => 'The active tenant policy denies the workflow console read.',
        ];
    }
    $posts = is_array($result['rows'] ?? null) ? array_values(array_filter($result['rows'], 'is_array')) : [];
    $rows = [];
    $runTotal = 0;
    $runsAvailable = true;
    foreach ($posts as $post) {
        $slug = trim((string) ($post['slug'] ?? ''));
        if ($slug === '') {
            continue;
        }
        $workflow = akiraShellWorkflow($slug);
        $runs = akiraShellWorkflowRuns($slug);
        $runsOk = ($runs['ok'] ?? false) === true;
        $runsAvailable = $runsAvailable && $runsOk;
        $projected = is_array($runs['runs'] ?? null) ? array_values(array_filter($runs['runs'], 'is_array')) : [];
        $runTotal += count($projected);
        $rows[] = [
            'slug' => $slug,
            'title' => (string) ($post['title'] ?? $slug),
            'status' => (string) ($workflow['status'] ?? 'unavailable'),
            'allowed_actions' => is_array($workflow['allowed_actions'] ?? null) ? $workflow['allowed_actions'] : [],
            'runs' => $projected,
            'runs_error' => $runsOk ? '' : (string) ($runs['error'] ?? 'Workflow run introspection unavailable.'),
        ];
    }
    return [
        'rows' => $rows,
        'run_total' => $runTotal,
        'post_total' => count($rows),
        'runs_available' => $runsAvailable,
        'error' => '',
    ];
}

/** @return array<string,mixed> */
function akiraShellWorkflowRuns(string $slug): array
{
    try {
        $result = akiraShellCall('akira.workflow.runs@1', ['entity_type' => 'post', 'entity_key' => $slug]);
        return is_array($result) ? $result : ['ok' => false, 'runs' => [], 'total' => 0, 'error' => 'Workflow run introspection unavailable.'];
    } catch (Throwable $error) {
        return ['ok' => false, 'runs' => [], 'total' => 0, 'error' => akiraShellRootErrorMessage($error)];
    }
}

/** @param array{rows:list<array<string,mixed>>,run_total:int,post_total:int,runs_available:bool,error:string} $console */
function akiraShellWorkflowConsoleHtml(array $console): string
{
    $rows = $console['rows'];
    $runTotal = $console['run_total'];
    $postTotal = $console['post_total'];
    $runsAvailable = $console['runs_available'];
    $error = trim($console['error']);
    $notice = (akiraShellQuery()['saved'] ?? '') === '1'
        ? '<div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">Workflow transition applied and re-read from the store.</div>' : '';
    $errorHtml = $error === '' ? '' : '<div role="alert" class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">' . akiraShellEscape($error) . '</div>';
    $runsNote = $runsAvailable
        ? '<p class="mb-5 text-sm text-slate-500">' . $postTotal . ' post' . ($postTotal === 1 ? '' : 's') . ' listed; ' . $runTotal . ' workflow run' . ($runTotal === 1 ? '' : 's') . ' recorded through <code>akira.workflow.runs@1</code>.</p>'
        : '<p class="mb-5 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">Workflow run introspection was unavailable for at least one subject; run counts below are not authoritative.</p>';
    $emptyState = '<div data-akira-workflow-empty class="rounded-[26px] border border-slate-200 bg-white p-12 text-center text-sm text-slate-400 shadow-sm">No posts are available to review. An empty workflow queue is a real state, not an error.</div>';
    if ($rows === []) {
        return $notice . $errorHtml . $runsNote . $emptyState;
    }

    $body = '';
    foreach ($rows as $row) {
        $slug = (string) ($row['slug'] ?? '');
        if ($slug === '') {
            continue;
        }
        $status = (string) ($row['status'] ?? 'unavailable');
        $actions = is_array($row['allowed_actions'] ?? null) ? $row['allowed_actions'] : [];
        $runs = is_array($row['runs'] ?? null) ? $row['runs'] : [];
        $runsError = trim((string) ($row['runs_error'] ?? ''));

        $runsCell = '';
        if ($runs === []) {
            $runsCell = '<span data-akira-workflow-run-empty class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-500">No runs recorded</span>';
        } else {
            foreach ($runs as $run) {
                $runId = (int) ($run['run_id'] ?? 0);
                $runStatus = (string) ($run['status'] ?? '');
                $started = trim((string) ($run['started_at'] ?? ($run['created_at'] ?? '')));
                $runsCell .= '<span data-akira-workflow-run="' . $runId . '" class="mt-1 block text-xs text-slate-600">#' . $runId . ' · ' . akiraShellEscape($runStatus) . ($started !== '' ? ' · ' . akiraShellEscape($started) : '') . '</span>';
            }
        }
        if ($runsError !== '') {
            $runsCell .= '<span class="mt-1 block text-xs text-amber-700">' . akiraShellEscape($runsError) . '</span>';
        }

        $actionsCell = '';
        if ($actions === []) {
            $actionsCell = '<span class="text-xs text-slate-400">No action available</span>';
        } else {
            foreach ($actions as $action) {
                $name = (string) ($action['action'] ?? '');
                if ($name === '') {
                    continue;
                }
                $label = (string) ($action['label'] ?? ucfirst($name));
                $actionsCell .= '<form method="post" action="/cms-akira-shell/workflow/' . rawurlencode($slug) . '/transition" class="mt-1">' . akiraShellCsrfField()
                    . '<input type="hidden" name="action" value="' . akiraShellEscape($name) . '">'
                    . '<input type="hidden" name="expected_status" value="' . akiraShellEscape($status) . '">'
                    . '<input type="hidden" name="idempotency_key" value="workflow-console-' . bin2hex(random_bytes(10)) . '">'
                    . '<button type="submit" class="rounded-xl border border-akira-200 bg-akira-50 px-3 py-2 text-xs font-semibold text-akira-700 hover:bg-akira-100">' . akiraShellEscape($label) . '</button></form>';
            }
        }

        $statusClass = match ($status) {
            'published' => 'bg-emerald-100 text-emerald-700',
            'review' => 'bg-amber-100 text-amber-700',
            'approved' => 'bg-sky-100 text-sky-700',
            default => 'bg-slate-100 text-slate-600',
        };
        $body .= '<tr data-akira-workflow-row="' . akiraShellEscape($slug) . '" class="border-b border-slate-100 align-top">'
            . '<td class="px-5 py-4"><strong class="block text-sm text-slate-900">' . akiraShellEscape((string) ($row['title'] ?? $slug)) . '</strong><code class="text-xs text-slate-400">' . akiraShellEscape($slug) . '</code></td>'
            . '<td class="px-5 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-semibold ' . $statusClass . '">' . akiraShellEscape(ucfirst($status)) . '</span></td>'
            . '<td class="px-5 py-4">' . $runsCell . '</td>'
            . '<td class="px-5 py-4 text-right">' . $actionsCell . '</td></tr>';
    }
    if ($body === '') {
        return $notice . $errorHtml . $runsNote . $emptyState;
    }

    return $notice . $errorHtml . $runsNote
        . '<section class="overflow-hidden rounded-[26px] border border-slate-200 bg-white shadow-sm"><table class="w-full text-left"><thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-400"><tr><th class="px-5 py-3">Post</th><th class="px-5 py-3">Current state</th><th class="px-5 py-3">Workflow runs</th><th class="px-5 py-3 text-right">Advance</th></tr></thead><tbody>' . $body . '</tbody></table></section>'
        . '<p class="mt-4 text-xs text-slate-400">Current state is read through <code>akira.workflow.evaluate@1</code> and run history through <code>akira.workflow.runs@1</code>; this console never queries workflow tables directly.</p>';
}

/**
 * Read the real search index state through governed capabilities only. The shell
 * owns no search tables: the current index population and the matching documents
 * are both read through akira.search.query@1.
 *
 * @return array<string,mixed>
 */
function akiraShellSearchData(string $term = '', int $page = 1): array
{
    $population = akiraShellSearchQuery(['entity_type' => 'post', 'page' => 1, 'limit' => 1]);
    $populationOk = ($population['ok'] ?? false) === true;
    $results = null;
    if ($term !== '') {
        $results = akiraShellSearchQuery([
            'term' => $term, 'entity_type' => 'post', 'page' => $page, 'limit' => 25,
        ]);
    }
    return [
        'indexed_total' => $populationOk ? (int) ($population['total'] ?? 0) : 0,
        'population_ok' => $populationOk,
        'population_error' => $populationOk ? '' : (string) ($population['error'] ?? 'Search query unavailable.'),
        'term' => $term,
        'page' => $page,
        'results' => is_array($results) ? $results : null,
    ];
}

/** @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function akiraShellSearchQuery(array $payload): array
{
    try {
        $result = akiraShellCall('akira.search.query@1', $payload);
        return is_array($result) ? $result : ['ok' => false, 'rows' => [], 'total' => 0, 'error' => 'Search query unavailable.'];
    } catch (Throwable $error) {
        return ['ok' => false, 'rows' => [], 'total' => 0, 'error' => akiraShellRootErrorMessage($error)];
    }
}

/** @param array<string,mixed> $console */
function akiraShellSearchHtml(array $console, string $error = ''): string
{
    $indexed = (int) ($console['indexed_total'] ?? 0);
    $populationOk = ($console['population_ok'] ?? false) === true;
    $populationError = trim((string) ($console['population_error'] ?? ''));
    $term = (string) ($console['term'] ?? '');
    $page = max(1, (int) ($console['page'] ?? 1));
    $results = is_array($console['results'] ?? null) ? $console['results'] : null;

    $notice = (akiraShellQuery()['rebuilt'] ?? '') === '1'
        ? '<div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">Search index rebuilt from published Posts and re-read from the store.</div>' : '';
    $errorHtml = $error === '' ? '' : '<div role="alert" class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">' . akiraShellEscape($error) . '</div>';

    if (!$populationOk) {
        $population = '<div role="alert" class="mb-5 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">Index population could not be read through <code>akira.search.query@1</code>: ' . akiraShellEscape($populationError) . '</div>';
    } elseif ($indexed === 0) {
        $population = '<div data-akira-search-empty class="mb-5 rounded-[26px] border border-slate-200 bg-white p-8 text-center shadow-sm"><span class="inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700">0 documents indexed</span><p class="mt-3 text-sm text-slate-500">The tenant search index is empty. That is a real state, not an error. Rebuild the index from published Posts to populate it.</p></div>';
    } else {
        $population = '<div class="mb-5 rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><span class="inline-flex rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">' . $indexed . ' document' . ($indexed === 1 ? '' : 's') . ' indexed</span><p class="mt-3 text-sm text-slate-500">Population is the live count returned by <code>akira.search.query@1</code> for this tenant, not a cached or assumed value.</p></div>';
    }

    $form = '<form method="get" action="/cms-akira-shell/search" class="mb-5 grid gap-3 rounded-[26px] border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-[1fr_auto]">'
        . '<input type="search" name="q" value="' . akiraShellEscape($term) . '" placeholder="Search indexed documents…" class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm focus:border-akira-500 focus:outline-none">'
        . '<button class="rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white" type="submit">Search</button></form>';

    $rebuildForm = '<form method="post" action="/cms-akira-shell/search/rebuild" class="mb-5 rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm">' . akiraShellCsrfField()
        . '<h2 class="font-bold text-slate-950">Rebuild index</h2><p class="mt-1 text-sm text-slate-500">Deterministically re-indexes published Posts for this tenant through the governed <code>akira.search.rebuild@1</code> capability. This is an explicit, audited mutation and is never triggered by viewing this page.</p>'
        . '<input type="hidden" name="q" value="' . akiraShellEscape($term) . '">'
        . '<input type="hidden" name="idempotency_key" value="search-rebuild-' . bin2hex(random_bytes(10)) . '">'
        . '<button type="submit" class="mt-4 rounded-2xl bg-akira-600 px-5 py-3 text-sm font-semibold text-white hover:bg-akira-700">Rebuild index now</button></form>';

    $resultsHtml = $term !== '' ? akiraShellSearchResults($term, $page, $results) : '';

    return $notice . $errorHtml . $population . $form . $resultsHtml . $rebuildForm
        . '<p class="mt-4 text-xs text-slate-400">This console reads through <code>akira.search.query@1</code> and mutates only through <code>akira.search.rebuild@1</code>; it never queries the search table directly.</p>';
}

/** @param array<string,mixed>|null $results */
function akiraShellSearchResults(string $term, int $page, ?array $results): string
{
    if ($results === null) {
        return '';
    }
    if (($results['ok'] ?? false) !== true) {
        return '<div role="alert" class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">Search failed: ' . akiraShellEscape((string) ($results['error'] ?? 'query unavailable')) . '</div>';
    }
    $rows = is_array($results['rows'] ?? null) ? array_values(array_filter($results['rows'], 'is_array')) : [];
    $total = (int) ($results['total'] ?? count($rows));
    if ($rows === []) {
        return '<div data-akira-search-no-results class="mb-5 rounded-[26px] border border-slate-200 bg-white p-8 text-center text-sm text-slate-400 shadow-sm">No indexed documents match &ldquo;' . akiraShellEscape($term) . '&rdquo;. The query returned real state; try another term.</div>';
    }
    $body = '';
    foreach ($rows as $row) {
        $key = (string) ($row['document_key'] ?? '');
        $body .= '<tr data-akira-search-row="' . akiraShellEscape($key) . '" class="border-b border-slate-100 last:border-0">'
            . '<td class="px-5 py-4"><strong class="block text-sm text-slate-900">' . akiraShellEscape((string) ($row['title'] ?? $key)) . '</strong><code class="text-xs text-slate-400">' . akiraShellEscape($key) . '</code></td>'
            . '<td class="px-5 py-4 text-sm text-slate-600">' . akiraShellEscape((string) ($row['summary'] ?? '')) . '</td>'
            . '<td class="px-5 py-4"><span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700">' . akiraShellEscape((string) ($row['status'] ?? '')) . '</span></td>'
            . '<td class="px-5 py-4 text-xs text-slate-400">' . akiraShellEscape((string) ($row['indexed_at'] ?? '')) . '</td></tr>';
    }
    return '<section class="mb-5 overflow-hidden rounded-[26px] border border-slate-200 bg-white shadow-sm"><div class="flex items-center justify-between border-b border-slate-100 px-5 py-3"><h2 class="font-bold text-slate-950">Results for &ldquo;' . akiraShellEscape($term) . '&rdquo;</h2><span class="rounded-full bg-akira-100 px-3 py-1 text-xs font-semibold text-akira-700">' . $total . ' match' . ($total === 1 ? '' : 'es') . '</span></div>'
        . '<table class="w-full text-left"><thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-400"><tr><th class="px-5 py-3">Document</th><th class="px-5 py-3">Summary</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Indexed</th></tr></thead><tbody>' . $body . '</tbody></table></section>'
        . akiraShellSearchPagination($term, $page, $total, 25);
}

function akiraShellSearchPagination(string $term, int $page, int $total, int $limit): string
{
    $pages = max(1, (int) ceil($total / max(1, $limit)));
    if ($pages <= 1) {
        return '';
    }
    $link = static fn (int $target): string => '/cms-akira-shell/search?' . http_build_query(['q' => $term, 'page' => $target]);
    return '<nav class="mb-5 flex items-center justify-between text-sm"><span class="text-slate-500">Page ' . $page . ' of ' . $pages . '</span><div class="flex gap-2">'
        . ($page > 1 ? '<a class="rounded-xl border bg-white px-4 py-2" href="' . akiraShellEscape($link($page - 1)) . '">Previous</a>' : '')
        . ($page < $pages ? '<a class="rounded-xl border bg-white px-4 py-2" href="' . akiraShellEscape($link($page + 1)) . '">Next</a>' : '') . '</div></nav>';
}

/**
 * The current request path exactly as the kernel routed it: decoded, without a
 * query string, and without a trailing slash. Used only by the module not-found
 * seam so redirect resolution is an exact match against the requested path.
 */
function akiraShellRequestPath(): string
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = '/';
    }
    $path = rawurldecode($path);
    $path = rtrim($path, '/');
    return $path === '' ? '/' : $path;
}

/**
 * Ask Core for the single stored target of an exact source match. A missing
 * store, missing table or unavailable provider fails closed to the original
 * 404 — never to a guessed or chained location.
 */
function akiraShellRedirectResolve(string $path): ?string
{
    if ($path === '' || strlen($path) > 255) {
        return null;
    }
    try {
        $result = akiraShellCall('akira.redirect.resolve@1', ['path' => $path]);
    } catch (Throwable) {
        return null;
    }
    if (!is_array($result) || ($result['ok'] ?? false) !== true) {
        return null;
    }
    $target = $result['target'] ?? null;
    return is_string($target) && $target !== '' ? $target : null;
}

/**
 * Render the redirect console from the real stored rows. The explicit empty
 * state is honest: zero rows is a real state, not an error. The form is the
 * only write surface and every target it submits is re-validated by Core.
 *
 * @param list<array<string,mixed>> $rows
 */
function akiraShellRedirectsHtml(array $rows, string $notice = '', string $error = '', string $source = '', string $target = ''): string
{
    $errorHtml = $error === '' ? ''
        : '<div role="alert" class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><strong>Could not save:</strong> ' . akiraShellEscape($error) . '</div>';

    $form = '<form method="post" action="/cms-akira-shell/redirects" class="mb-5 rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm">'
        . akiraShellCsrfField()
        . '<input type="hidden" name="idempotency_key" value="akira-redirect-' . bin2hex(random_bytes(12)) . '">'
        . '<h2 class="text-lg font-bold text-slate-950">Add a post-path redirect</h2>'
        . '<p class="mt-1 text-sm text-slate-500">Maps one retired post path to one same-origin internal path. Resolution is exact-match and single-hop; a target that is itself another redirect is never followed. Absolute, protocol-relative and scheme-qualified targets are refused.</p>'
        . '<label class="mt-5 block text-sm font-semibold text-slate-800" for="redirect-source">Source post path</label>'
        . '<input id="redirect-source" name="source_path" value="' . akiraShellEscape($source) . '" placeholder="/posts/retired-slug" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-sm">'
        . '<label class="mt-4 block text-sm font-semibold text-slate-800" for="redirect-target">Internal target path</label>'
        . '<input id="redirect-target" name="target_path" value="' . akiraShellEscape($target) . '" placeholder="/posts/current-slug" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-sm">'
        . '<button type="submit" class="mt-6 rounded-xl bg-akira-600 px-5 py-3 text-sm font-semibold text-white">Save redirect</button></form>';

    if ($rows === []) {
        $table = '<div data-akira-redirect-empty class="rounded-[26px] border border-slate-200 bg-white p-8 text-center text-sm text-slate-400 shadow-sm">No post-path redirects are stored for this tenant. That is a real state, not an error.</div>';
    } else {
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr data-akira-redirect-row="' . akiraShellEscape((string) ($row['source_path'] ?? '')) . '" class="border-b border-slate-100 last:border-0">'
                . '<td class="px-5 py-4"><code class="text-sm text-slate-900">' . akiraShellEscape((string) ($row['source_path'] ?? '')) . '</code></td>'
                . '<td class="px-5 py-4"><code class="text-sm text-akira-700">' . akiraShellEscape((string) ($row['target_path'] ?? '')) . '</code></td>'
                . '<td class="px-5 py-4 text-xs text-slate-400">' . akiraShellEscape((string) ($row['updated_at'] ?? '')) . '</td></tr>';
        }
        $table = '<section class="overflow-hidden rounded-[26px] border border-slate-200 bg-white shadow-sm"><table class="w-full text-left"><thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-400"><tr><th class="px-5 py-3">Source path</th><th class="px-5 py-3">Target path</th><th class="px-5 py-3">Updated</th></tr></thead><tbody>' . $body . '</tbody></table></section>';
    }

    return $notice . $errorHtml . $form . $table
        . '<p class="mt-4 text-xs text-slate-400">Rows are read through <code>akira.redirect.list@1</code> and written only through <code>akira.redirect.create@1</code>; this console never queries the redirect table directly.</p>';
}

/**
 * Read the real backup population from ModuleBackupService through the owning
 * akira.backup.list@1 capability. The shell owns no filesystem path and no
 * backup table; an empty result is reported as a real, honest state.
 *
 * @return array<string,mixed>
 */
function akiraShellBackupData(): array
{
    try {
        $result = akiraShellCall('akira.backup.list@1', []);
        if (!is_array($result) || ($result['ok'] ?? false) !== true) {
            return [
                'ok' => false, 'backups' => [], 'total' => 0,
                'module_id' => 'cms-akira-core',
                'error' => 'The kernel backup service could not be read.',
            ];
        }
        $backups = [];
        foreach (is_array($result['backups'] ?? null) ? $result['backups'] : [] as $row) {
            if (is_array($row)) {
                $backups[] = $row;
            }
        }
        return [
            'ok' => true,
            'backups' => $backups,
            'total' => (int) ($result['total'] ?? count($backups)),
            'module_id' => (string) ($result['module_id'] ?? 'cms-akira-core'),
            'error' => '',
        ];
    } catch (Throwable $error) {
        return [
            'ok' => false, 'backups' => [], 'total' => 0,
            'module_id' => 'cms-akira-core',
            'error' => akiraShellRootErrorMessage($error),
        ];
    }
}

function akiraShellFormatBytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return number_format($bytes / 1048576, 2) . ' MB';
}

/** @param array<string,mixed> $export */
function akiraShellExportPanelHtml(array $export): string
{
    if (($export['empty'] ?? false) === true) {
        return '<div data-akira-export-empty class="mb-5 rounded-[26px] border border-amber-200 bg-amber-50 p-6 text-sm text-amber-900"><strong>Nothing to export.</strong> ' . akiraShellEscape((string) ($export['message'] ?? 'No posts exist to export.')) . '</div>';
    }
    if (($export['ok'] ?? false) !== true) {
        return '<div data-akira-export-error role="alert" class="mb-5 rounded-[26px] border border-red-200 bg-red-50 p-6 text-sm text-red-700"><strong>Export failed.</strong> ' . akiraShellEscape((string) ($export['error'] ?? 'The export could not be produced.')) . '</div>';
    }
    $records = (int) ($export['record_count'] ?? 0);
    $size = (int) ($export['size_bytes'] ?? 0);
    $excerpt = (string) ($export['excerpt'] ?? '');
    $truncated = ($export['excerpt_truncated'] ?? false) === true;
    $filename = (string) ($export['filename'] ?? '');
    return '<div data-akira-export-result class="mb-5 overflow-hidden rounded-[26px] border border-emerald-200 bg-white shadow-sm">'
        . '<div class="flex flex-wrap items-center justify-between gap-3 border-b border-emerald-100 bg-emerald-50 px-5 py-3"><strong class="text-sm text-emerald-800">Export produced and read back from KernelExport</strong>'
        . '<span class="text-xs font-semibold text-emerald-700">' . $records . ' record' . ($records === 1 ? '' : 's') . ' &middot; ' . akiraShellEscape(akiraShellFormatBytes($size)) . '</span></div>'
        . '<div class="px-5 py-4 text-xs text-slate-500"><code>' . akiraShellEscape($filename) . '</code>' . ($truncated ? ' — bounded excerpt, file larger than shown' : ' — full file shown') . '</div>'
        . '<pre data-akira-export-excerpt class="max-h-80 overflow-auto border-t border-slate-100 bg-slate-950 px-5 py-4 text-xs leading-relaxed text-slate-100">' . akiraShellEscape($excerpt) . ($truncated ? "\n… (truncated)" : '') . '</pre></div>';
}

/**
 * Render the backup and export console from real service state.
 *
 * @param array<string,mixed> $console
 * @param array<string,mixed>|null $export
 */
function akiraShellBackupHtml(array $console, string $error = '', ?array $export = null, ?array $bundle = null, string $bundleError = ''): string
{
    $ok = ($console['ok'] ?? false) === true;
    $backups = is_array($console['backups'] ?? null) ? array_values(array_filter($console['backups'], 'is_array')) : [];
    $total = (int) ($console['total'] ?? count($backups));
    $moduleId = (string) ($console['module_id'] ?? 'cms-akira-core');

    $created = trim((string) (akiraShellQuery()['created'] ?? ''));
    $notice = '';
    if ($created !== '') {
        $found = null;
        foreach ($backups as $row) {
            if (($row['file_name'] ?? '') === $created) {
                $found = $row;
                break;
            }
        }
        if (is_array($found)) {
            $notice = '<div data-akira-backup-created class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><strong>Backup created and read back from the service:</strong> <code>' . akiraShellEscape((string) $found['file_name']) . '</code> &middot; ' . akiraShellEscape(akiraShellFormatBytes((int) ($found['file_size_bytes'] ?? 0))) . ' &middot; ' . akiraShellEscape((string) ($found['created_at'] ?? '')) . '</div>';
        } else {
            $notice = '<div data-akira-backup-created-missing class="mb-5 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">A backup was requested but <code>' . akiraShellEscape($created) . '</code> is not present in the service listing. No artifact is claimed.</div>';
        }
    }
    $errorHtml = $error === '' ? '' : '<div role="alert" class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">' . akiraShellEscape($error) . '</div>';

    if (!$ok) {
        $population = '<div role="alert" class="mb-5 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">The backup listing could not be read from <code>ModuleBackupService</code>: ' . akiraShellEscape((string) ($console['error'] ?? 'unavailable')) . '</div>';
    } elseif ($total === 0) {
        $population = '<div data-akira-backup-empty class="mb-5 rounded-[26px] border border-slate-200 bg-white p-8 text-center shadow-sm"><span class="inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700">0 backups</span><p class="mt-3 text-sm text-slate-500">No backups exist for <code>' . akiraShellEscape($moduleId) . '</code>. That is the real state of the kernel backup store, not an error. Create one below.</p></div>';
    } else {
        $population = '<div class="mb-5 rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><span class="inline-flex rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">' . $total . ' backup' . ($total === 1 ? '' : 's') . '</span><p class="mt-3 text-sm text-slate-500">Count and rows come from the live listing returned by <code>akira.backup.list@1</code>, which reads <code>ModuleBackupService</code> directly.</p></div>';
    }

    $rowsHtml = '';
    foreach ($backups as $row) {
        $fileName = (string) ($row['file_name'] ?? '');
        $rowsHtml .= '<tr data-akira-backup-row="' . akiraShellEscape($fileName) . '" class="border-b border-slate-100 last:border-0">'
            . '<td class="px-5 py-4"><code class="text-sm text-slate-800">' . akiraShellEscape($fileName) . '</code></td>'
            . '<td class="px-5 py-4 text-sm text-slate-600">' . akiraShellEscape(akiraShellFormatBytes((int) ($row['file_size_bytes'] ?? 0))) . '</td>'
            . '<td class="px-5 py-4 text-xs text-slate-400">' . akiraShellEscape((string) ($row['created_at'] ?? '')) . '</td></tr>';
    }
    $table = $rowsHtml === '' ? '' : '<section class="mb-5 overflow-hidden rounded-[26px] border border-slate-200 bg-white shadow-sm"><table class="w-full text-left"><thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-400"><tr><th class="px-5 py-3">Artifact</th><th class="px-5 py-3">Size</th><th class="px-5 py-3">Created</th></tr></thead><tbody>' . $rowsHtml . '</tbody></table></section>';

    $createForm = '<form method="post" action="/cms-akira-shell/backups" class="mb-5 rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm">' . akiraShellCsrfField()
        . '<h2 class="font-bold text-slate-950">Create backup</h2><p class="mt-1 text-sm text-slate-500">Runs <code>ModuleBackupService</code> over the Akira Core tables for this tenant. This is an explicit, audited POST and is never triggered by viewing this page; the same idempotency key never creates two backups.</p>'
        . '<label class="mt-4 block text-xs font-semibold uppercase tracking-wide text-slate-500" for="backup-reason">Reason</label>'
        . '<input id="backup-reason" name="reason" value="Console backup" maxlength="190" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-sm">'
        . '<input type="hidden" name="idempotency_key" value="akira-backup-' . bin2hex(random_bytes(12)) . '">'
        . '<button type="submit" class="mt-4 rounded-2xl bg-akira-600 px-5 py-3 text-sm font-semibold text-white hover:bg-akira-700">Create backup now</button></form>';

    $exportForm = '<form method="post" action="/cms-akira-shell/exports" class="mb-5 rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm">' . akiraShellCsrfField()
        . '<h2 class="font-bold text-slate-950">Export posts (CSV)</h2><p class="mt-1 text-sm text-slate-500">Reads the tenant&rsquo;s posts through <code>akira.post.admin.list@1</code> and renders them through <code>KernelExport</code>. The excerpt below is read back from the produced file; the export is never triggered by a GET.</p>'
        . '<input type="hidden" name="format" value="csv">'
        . '<button type="submit" class="mt-4 rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800">Produce CSV export</button></form>';

    $exportPanel = is_array($export) ? akiraShellExportPanelHtml($export) : '';

    return $notice . $errorHtml . $population . $table . $exportPanel . $createForm . $exportForm . akiraShellBundlePanelHtml($bundle, $bundleError)
        . '<p class="mt-4 text-xs text-slate-400">Backups are listed and created only through <code>akira.backup.list@1</code> and <code>akira.backup.create@1</code>; exports only through <code>akira.export.create@1</code>; bundles only through <code>akira.bundle.diff@1</code> and <code>akira.bundle.apply@1</code>. This console never touches the filesystem, a table, or the destructive reset service.</p>';
}

/**
 * Render the recovery half of the console: a dry-run diff and an explicit,
 * additive apply. Refusals are surfaced with their named entries; nothing is
 * silently skipped. The panel never deletes and never claims a write it did
 * not make.
 *
 * @param array<string,mixed>|null $bundle
 */
function akiraShellBundlePanelHtml(?array $bundle, string $error = ''): string
{
    $errorHtml = $error === '' ? '' : '<div data-akira-bundle-error role="alert" class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><strong>Bundle operation failed.</strong> ' . akiraShellEscape($error) . '</div>';

    $result = '';
    $bundleJson = '';
    $additive = false;
    if (is_array($bundle)) {
        $bundleJson = is_array($bundle['bundle'] ?? null)
            ? (string) json_encode($bundle['bundle'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '';
        $plan = is_array($bundle['plan'] ?? null) ? $bundle['plan'] : [];
        $counts = is_array($bundle['counts'] ?? null) ? $bundle['counts'] : [];
        $refusal = trim((string) ($bundle['refusal'] ?? ''));
        $operation = (string) ($bundle['operation'] ?? 'diff');

        if ($refusal !== '') {
            $result = '<div data-akira-bundle-refused role="alert" class="mb-4 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900"><strong>Refused — nothing was written.</strong> ' . akiraShellEscape($refusal) . '</div>';
        } elseif ($operation === 'apply' && ($bundle['replayed'] ?? false) === true) {
            $result = '<div data-akira-bundle-replayed class="mb-4 rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900"><strong>Replay detected.</strong> This idempotency key already applied this bundle; no row changed.</div>';
        } elseif ($operation === 'apply') {
            $applied = is_array($bundle['applied'] ?? null) ? $bundle['applied'] : [];
            $result = '<div data-akira-bundle-applied class="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><strong>Applied additively and audited.</strong> '
                . count(is_array($applied['add'] ?? null) ? $applied['add'] : []) . ' added, '
                . count(is_array($applied['update'] ?? null) ? $applied['update'] : []) . ' updated, '
                . (int) ($counts['skip'] ?? 0) . ' skipped, 0 removed.</div>';
        } else {
            $additive = ($bundle['additive'] ?? false) === true;
            $result = '<div data-akira-bundle-diff class="mb-4 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700"><strong>Dry run — no row changed.</strong> '
                . (int) ($counts['add'] ?? 0) . ' to add, ' . (int) ($counts['update'] ?? 0) . ' to update, '
                . (int) ($counts['skip'] ?? 0) . ' skipped, ' . (int) ($counts['remove'] ?? 0) . ' to remove.</div>';
        }

        foreach (['add', 'update', 'skip', 'remove'] as $bucket) {
            $entries = is_array($plan[$bucket] ?? null) ? $plan[$bucket] : [];
            if ($entries === []) {
                continue;
            }
            $result .= '<p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">' . akiraShellEscape($bucket) . '</p>'
                . '<p data-akira-bundle-' . akiraShellEscape($bucket) . ' class="mb-4 break-all font-mono text-xs text-slate-600">' . akiraShellEscape(implode(', ', array_map('strval', $entries))) . '</p>';
        }
    }

    $form = '<form data-akira-bundle-diff-form method="post" action="/cms-akira-shell/bundles/diff" class="mb-5 rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm">' . akiraShellCsrfField()
        . '<h2 class="font-bold text-slate-950">Recovery bundle diff (dry run)</h2><p class="mt-1 text-sm text-slate-500">Paste an exported JSON bundle. The diff is computed by payload hash against this tenant and writes nothing. A plan that would remove a tenant entry is refused and applied by nothing.</p>'
        . '<textarea name="bundle" rows="8" spellcheck="false" class="mt-4 w-full rounded-xl border border-slate-300 px-4 py-3 font-mono text-xs" placeholder="{&quot;entries&quot;:[{&quot;kind&quot;:&quot;post&quot;,&quot;key&quot;:&quot;example&quot;,&quot;hash&quot;:&quot;…&quot;,&quot;payload&quot;:{…}}]}">' . akiraShellEscape($bundleJson) . '</textarea>'
        . '<button type="submit" class="mt-4 rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800">Compute dry-run diff</button></form>';

    $applyForm = '';
    if ($additive && is_array($bundle) && (($bundle['counts']['add'] ?? 0) > 0 || ($bundle['counts']['update'] ?? 0) > 0)) {
        $applyForm = '<form data-akira-bundle-apply-form method="post" action="/cms-akira-shell/bundles/apply" class="mb-5 rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm">' . akiraShellCsrfField()
            . '<h2 class="font-bold text-slate-950">Apply additively</h2><p class="mt-1 text-sm text-slate-500">Adds and updates only, through the governed Post capabilities, audited. The idempotency key below is reused so a repeated submission replays instead of duplicating.</p>'
            . '<textarea name="bundle" hidden>' . akiraShellEscape($bundleJson) . '</textarea>'
            . '<input type="hidden" name="idempotency_key" value="akira-bundle-' . bin2hex(random_bytes(12)) . '">'
            . '<button type="submit" class="mt-4 rounded-2xl bg-akira-600 px-5 py-3 text-sm font-semibold text-white hover:bg-akira-700">Apply additively</button></form>';
    }

    return $errorHtml . $result . $form . $applyForm;
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
    // The bootstrap lives inside <script type="application/json">, whose content is
    // RAW TEXT — the browser never decodes HTML entities there. HTML-escaping the
    // JSON (htmlspecialchars) therefore produced '&quot;' sequences, JSON.parse threw
    // and the app silently fell back to defaults (losing mode=edit and entity_key).
    // Escape at the JSON level instead: the HEX flags keep the payload valid JSON
    // while making a '</script>' breakout impossible.
    $json = (string) json_encode($bootstrap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
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

/**
 * Derive a post's stable key from its canonical URL.
 *
 * Entity view projections expose presentation meaning, so the public list
 * contract carries `url` but not the domain-internal `slug`. Consumers that need
 * a key therefore take it from the canonical URL shape the module publishes,
 * which keeps presentation from reaching into domain internals. Returns '' when
 * the URL does not resolve to a canonical slug.
 */
function akiraShellPostKeyFromUrl(string $url): string
{
    $path = (string) parse_url(trim($url), PHP_URL_PATH);
    if ($path === '') {
        return '';
    }

    // Only the canonical post shape yields a key; the archive URL ("/posts/") and
    // any other path are rejected rather than resolving to a collection segment.
    if (preg_match('#^/posts/([a-z0-9]+(?:-[a-z0-9]+)*)/?$#', $path, $matches) !== 1) {
        return '';
    }

    return $matches[1];
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

function akiraShellMediaNotice(): string
{
    $message = match ((string) (akiraShellQuery()['saved'] ?? '')) {
        'upload' => 'Media uploaded.',
        'request' => 'Deletion requested. The media remains available until an administrator approves it.',
        'cancel' => 'Deletion request cancelled.',
        'delete' => 'Media deleted.',
        default => '',
    };
    return $message === '' ? '' : '<div role="status" class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">' . $message . '</div>';
}

/**
 * @return array{rows:list<array<string,mixed>>,modules:list<string>,actions:list<string>,module:string,action:string,page:int,pages:int,total:int,unattributed_count:int}
 */
function akiraShellProvenanceSnapshot(string $module = '', string $action = '', int $page = 1): array
{
    $result = app()->cap()->call('kernel.provenance.list@1', [
        'module' => $module,
        'action' => $action,
        'page' => max(1, $page),
        'per_page' => 50,
    ], [
        'caller' => ['module' => 'cms-akira-shell', 'user' => app()->user()],
        'mode' => 'first',
    ]);
    $result = is_array($result) ? $result : [];

    return [
        'rows' => array_values(array_filter((array) ($result['rows'] ?? []), 'is_array')),
        'modules' => array_values(array_map('strval', (array) ($result['modules'] ?? []))),
        'actions' => array_values(array_map('strval', (array) ($result['actions'] ?? []))),
        'module' => $module,
        'action' => $action,
        'page' => max(1, (int) ($result['page'] ?? $page)),
        'pages' => max(1, (int) ($result['pages'] ?? 1)),
        'total' => max(0, (int) ($result['total'] ?? 0)),
        'unattributed_count' => max(0, (int) ($result['unattributed_count'] ?? 0)),
    ];
}

/** @param array<string,mixed> $row */
function akiraShellProvenanceActorHtml(array $row): string
{
    $source = trim((string) ($row['actor_source'] ?? ''));
    $kernelId = isset($row['actor_user_id']) ? (int) $row['actor_user_id'] : 0;
    $moduleId = isset($row['actor_module_user_id']) ? (int) $row['actor_module_user_id'] : 0;
    if ($kernelId > 0) {
        $username = trim((string) ($row['username'] ?? ''));
        $label = $username !== '' ? $username : 'kernel user #' . $kernelId;
        return '<strong class="block text-slate-900">' . akiraShellEscape($label) . '</strong>'
            . '<span class="text-xs text-slate-500">kernel · user #' . $kernelId . '</span>';
    }
    if ($moduleId > 0) {
        return '<strong class="block text-slate-900">' . akiraShellEscape($source !== '' ? $source : 'module') . '</strong>'
            . '<span class="text-xs text-slate-500">module user #' . $moduleId . '</span>';
    }
    $sourceLabel = $source !== '' ? $source : 'source missing (legacy row)';
    return '<strong class="block text-red-800">Unattributed</strong>'
        . '<span class="text-xs font-semibold text-red-700">' . akiraShellEscape($sourceLabel) . '</span>';
}

/** @param array{rows:list<array<string,mixed>>,modules:list<string>,actions:list<string>,module:string,action:string,page:int,pages:int,total:int,unattributed_count:int} $snapshot */
function akiraShellProvenanceHtml(array $snapshot): string
{
    $option = static function (string $value, string $selected): string {
        return '<option value="' . akiraShellEscape($value) . '"' . ($value === $selected ? ' selected' : '') . '>'
            . akiraShellEscape($value) . '</option>';
    };
    $moduleOptions = '<option value="">All modules</option>';
    foreach ($snapshot['modules'] as $value) {
        $moduleOptions .= $option($value, $snapshot['module']);
    }
    $actionOptions = '<option value="">All actions</option>';
    foreach ($snapshot['actions'] as $value) {
        $actionOptions .= $option($value, $snapshot['action']);
    }

    $rows = '';
    foreach ($snapshot['rows'] as $row) {
        $entityType = trim((string) ($row['entity_type'] ?? ''));
        $entityId = trim((string) ($row['entity_id'] ?? ''));
        $entity = ($entityType !== '' ? $entityType : 'unspecified entity')
            . ($entityId !== '' ? ' · ' . $entityId : '');
        $rows .= '<tr class="border-b border-slate-100 align-top last:border-0" data-provenance-row>'
            . '<td class="whitespace-nowrap p-4 text-xs text-slate-500">' . akiraShellEscape($row['created_at'] ?? '') . '</td>'
            . '<td class="p-4">' . akiraShellProvenanceActorHtml($row) . '</td>'
            . '<td class="p-4"><code class="text-xs font-semibold text-akira-700">' . akiraShellEscape($row['action'] ?? '') . '</code>'
            . '<span class="mt-1 block text-xs text-slate-500">Recorded capability/action</span></td>'
            . '<td class="p-4 text-sm"><strong class="block text-slate-800">' . akiraShellEscape($row['module'] ?? '') . '</strong>'
            . '<span class="text-xs text-slate-500">' . akiraShellEscape($entity) . '</span></td></tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="4" class="p-10 text-center text-sm text-slate-500">No changes match these filters.</td></tr>';
    }

    $queryFor = static function (int $page) use ($snapshot): string {
        return http_build_query(array_filter([
            'module' => $snapshot['module'], 'action' => $snapshot['action'], 'page' => $page,
        ], static fn (mixed $value): bool => $value !== ''));
    };
    $pagination = '<span>Page ' . $snapshot['page'] . ' of ' . $snapshot['pages'] . '</span><span class="flex gap-2">'
        . ($snapshot['page'] > 1 ? '<a class="rounded-xl border bg-white px-3 py-2" href="?' . akiraShellEscape($queryFor($snapshot['page'] - 1)) . '">Previous</a>' : '')
        . ($snapshot['page'] < $snapshot['pages'] ? '<a class="rounded-xl border bg-white px-3 py-2" href="?' . akiraShellEscape($queryFor($snapshot['page'] + 1)) . '">Next</a>' : '') . '</span>';
    $unattributedClass = $snapshot['unattributed_count'] > 0
        ? 'border-red-300 bg-red-50 text-red-900' : 'border-emerald-300 bg-emerald-50 text-emerald-900';

    return '<p class="mb-5 max-w-3xl text-sm text-slate-600">Read-only chronological audit trail. Actor identity remains distinct across Kernel users, module-owned users, and unattributed changes.</p>'
        . '<section data-unattributed-count class="mb-5 rounded-2xl border p-5 ' . $unattributedClass . '"><span class="text-sm font-semibold uppercase tracking-wide">Unattributed changes</span>'
        . '<strong class="mt-1 block text-4xl">' . $snapshot['unattributed_count'] . '</strong><p class="mt-1 text-sm">Rows with neither a Kernel user nor a module-owned user. These are never presented as a system actor.</p></section>'
        . '<form method="get" class="mb-5 grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-[1fr_1fr_auto]">'
        . '<label class="text-sm font-semibold">Module<select name="module" class="mt-1 block w-full rounded-xl border border-slate-200 px-3 py-2 font-normal">' . $moduleOptions . '</select></label>'
        . '<label class="text-sm font-semibold">Action<select name="action" class="mt-1 block w-full rounded-xl border border-slate-200 px-3 py-2 font-normal">' . $actionOptions . '</select></label>'
        . '<button class="self-end rounded-xl bg-akira-600 px-5 py-2.5 text-sm font-semibold text-white">Filter</button></form>'
        . '<div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm"><table class="w-full text-left"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="p-4">Timestamp</th><th class="p-4">Actor / identification source</th><th class="p-4">Capability / action</th><th class="p-4">Module / entity</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
        . '<nav aria-label="Provenance pagination" class="mt-4 flex items-center justify-between text-sm text-slate-600"><span>' . $snapshot['total'] . ' matching changes</span><span class="flex items-center gap-4">' . $pagination . '</span></nav>';
}

/** @return list<string> */
function akiraShellAuthorityRoleSet(mixed $roles): array
{
    $values = is_array($roles) ? $roles : explode(',', (string) $roles);
    $values = array_values(array_unique(array_filter(array_map(
        static fn (mixed $role): string => trim((string) $role),
        $values
    ), static fn (string $role): bool => $role !== '')));
    sort($values);
    return $values;
}

/**
 * Reconcile manifest declarations with effective policies and route authority.
 *
 * @param list<array<string,mixed>> $policyRows
 * @param array<string,array<string,mixed>>|null $modules
 * @return array{policies:list<array<string,mixed>>,deltas:list<array<string,mixed>>,undeclared_posts:list<array<string,string>>}
 */
function akiraShellAuthoritySnapshot(array $policyRows, ?array $modules = null): array
{
    $policies = [];
    $byCapability = [];
    foreach ($policyRows as $row) {
        if (array_key_exists('is_active', $row) && (int) $row['is_active'] !== 1) {
            continue;
        }
        $capability = trim((string) ($row['capability_id'] ?? ''));
        if ($capability === '') {
            continue;
        }
        $row['allowed_role_set'] = akiraShellAuthorityRoleSet($row['allowed_roles'] ?? '');
        $policies[] = $row;
        $byCapability[$capability] ??= $row;
    }
    usort($policies, static fn (array $a, array $b): int => [($a['capability_id'] ?? ''), ($a['provider'] ?? '')] <=> [($b['capability_id'] ?? ''), ($b['provider'] ?? '')]);

    $modules ??= discoverModules();
    ksort($modules);
    $deltas = [];
    $undeclared = [];
    foreach ($modules as $moduleId => $manifest) {
        $moduleId = (string) ($manifest['id'] ?? $moduleId);
        if (($manifest['suite'] ?? '') !== 'cms-akira') {
            continue;
        }
        $routeAuthority = is_array($manifest['capabilities']['routes'] ?? null)
            ? $manifest['capabilities']['routes'] : [];
        $contributions = is_array($manifest['admin_contributions'] ?? null)
            ? $manifest['admin_contributions'] : [];
        foreach ($contributions as $contribution) {
            if (!is_array($contribution)) {
                continue;
            }
            $location = trim((string) ($contribution['location'] ?? ''));
            if ($location !== 'sidebar' && !str_starts_with($location, 'dashboard')) {
                continue;
            }
            $route = trim((string) ($contribution['route'] ?? ''));
            $capability = trim((string) ($contribution['capability'] ?? $contribution['render_capability'] ?? ''));
            if ($capability === '' && $route !== '') {
                $capability = trim((string) ($routeAuthority['GET ' . $route] ?? ''));
            }
            $declared = akiraShellAuthorityRoleSet($contribution['roles'] ?? []);
            $policy = $capability !== '' ? ($byCapability[$capability] ?? null) : null;
            $effective = is_array($policy) ? (array) $policy['allowed_role_set'] : [];
            $onlyDeclared = array_values(array_diff($declared, $effective));
            $onlyPolicy = array_values(array_diff($effective, $declared));
            if ($capability === '') {
                $status = 'route-undeclared';
            } elseif (!is_array($policy)) {
                $status = 'policy-missing';
            } elseif ($onlyDeclared === [] && $onlyPolicy === []) {
                $status = 'agreement';
            } elseif ($onlyDeclared !== [] && $onlyPolicy !== []) {
                $status = 'different';
            } elseif ($onlyDeclared !== []) {
                $status = 'wider';
            } else {
                $status = 'narrower';
            }
            $deltas[] = [
                'module' => $moduleId,
                'id' => (string) ($contribution['id'] ?? ''),
                'location' => $location,
                'route' => $route,
                'capability' => $capability,
                'declared_roles' => $declared,
                'policy_roles' => $effective,
                'declaration_only' => $onlyDeclared,
                'policy_only' => $onlyPolicy,
                'status' => $status,
            ];
        }

        $modulePath = trim((string) ($manifest['_path'] ?? ''));
        $routesFile = $modulePath !== '' ? $modulePath . '/routes.php' : '';
        if ($routesFile === '' || !is_file($routesFile)) {
            continue;
        }
        try {
            $routes = require $routesFile;
        } catch (Throwable) {
            continue;
        }
        if (!is_array($routes)) {
            continue;
        }
        foreach (is_array($routes['POST'] ?? null) ? $routes['POST'] : [] as $route => $handler) {
            if (!array_key_exists('POST ' . $route, $routeAuthority)) {
                $undeclared[] = ['module' => $moduleId, 'route' => (string) $route, 'handler' => (string) $handler];
            }
        }
    }
    usort($deltas, static fn (array $a, array $b): int => [$a['module'], $a['id']] <=> [$b['module'], $b['id']]);
    usort($undeclared, static fn (array $a, array $b): int => [$a['module'], $a['route']] <=> [$b['module'], $b['route']]);

    return ['policies' => $policies, 'deltas' => $deltas, 'undeclared_posts' => $undeclared];
}

/** @param array{policies:list<array<string,mixed>>,deltas:list<array<string,mixed>>,undeclared_posts:list<array<string,string>>} $snapshot */
function akiraShellAuthorityHtml(array $snapshot): string
{
    $policyHtml = '';
    foreach ($snapshot['policies'] as $row) {
        $grant = strtolower(trim((string) ($row['grant_state'] ?? '')));
        $grantClass = match ($grant) {
            'granted' => 'border-emerald-300 bg-emerald-100 text-emerald-900',
            'suspended' => 'border-amber-400 bg-amber-100 text-amber-950 ring-2 ring-amber-300',
            'revoked' => 'border-red-500 bg-red-100 text-red-950 ring-2 ring-red-400',
            default => 'border-slate-400 bg-slate-100 text-slate-900',
        };
        $policyHtml .= '<tr class="border-b border-slate-100 align-top"><td class="p-3"><code class="font-semibold text-akira-700">' . akiraShellEscape($row['capability_id'] ?? '') . '</code></td>'
            . '<td class="p-3 text-xs">' . akiraShellEscape(implode(', ', (array) ($row['allowed_role_set'] ?? []))) . '</td>'
            . '<td class="p-3 text-xs"><strong>' . akiraShellEscape($row['provider'] ?? '') . '</strong><br>caller: ' . akiraShellEscape(($row['caller_module'] ?? null) ?: 'any') . '</td>'
            . '<td class="p-3 text-xs">' . akiraShellEscape($row['requires_protocol'] ?? '') . '</td>'
            . '<td class="p-3 text-xs">v' . akiraShellEscape($row['policy_version'] ?? '') . '</td>'
            . '<td class="p-3"><strong class="inline-flex rounded-full border px-2.5 py-1 text-xs uppercase tracking-wide ' . $grantClass . '">' . akiraShellEscape($grant !== '' ? $grant : 'unknown') . '</strong></td></tr>';
    }
    if ($policyHtml === '') {
        $policyHtml = '<tr><td class="p-5 text-slate-500" colspan="6">No active Akira policy rows were returned.</td></tr>';
    }

    $deltaHtml = '';
    foreach ($snapshot['deltas'] as $delta) {
        $status = (string) $delta['status'];
        $isMismatch = in_array($status, ['narrower', 'wider', 'different'], true);
        $statusClass = match ($status) {
            'agreement' => 'bg-emerald-100 text-emerald-800',
            'narrower', 'wider', 'different' => 'bg-red-100 text-red-900 ring-2 ring-red-300',
            default => 'bg-amber-100 text-amber-900 ring-2 ring-amber-300',
        };
        $detail = $isMismatch
            ? 'declaration only: ' . (implode(', ', $delta['declaration_only']) ?: '—') . '; policy only: ' . (implode(', ', $delta['policy_only']) ?: '—')
            : ($status === 'agreement' ? 'Role sets are identical.' : ($status === 'route-undeclared' ? 'Route has no GET capability declaration.' : 'No active policy row was returned.'));
        $target = $delta['capability'] !== '' ? $delta['capability'] : $delta['route'];
        $deltaHtml .= '<tr class="border-b border-slate-100 align-top"><td class="p-3"><strong>' . akiraShellEscape($delta['id']) . '</strong><br><span class="text-xs text-slate-500">' . akiraShellEscape($delta['module'] . ' · ' . $delta['location']) . '</span></td>'
            . '<td class="p-3"><code class="text-xs">' . akiraShellEscape($target) . '</code></td><td class="p-3 text-xs">' . akiraShellEscape(implode(', ', $delta['declared_roles'])) . '</td>'
            . '<td class="p-3 text-xs">' . akiraShellEscape(implode(', ', $delta['policy_roles'])) . '</td><td class="p-3"><strong class="inline-flex rounded-full px-2.5 py-1 text-xs uppercase ' . $statusClass . '">' . akiraShellEscape($status) . '</strong><p class="mt-2 max-w-md text-xs text-slate-500">' . akiraShellEscape($detail) . '</p></td></tr>';
    }
    if ($deltaHtml === '') {
        $deltaHtml = '<tr><td class="p-5 text-slate-500" colspan="5">No sidebar or dashboard contributions were found.</td></tr>';
    }

    $undeclaredHtml = '';
    foreach ($snapshot['undeclared_posts'] as $route) {
        $undeclaredHtml .= '<li class="border-b border-amber-200 px-4 py-3 last:border-0"><strong>' . akiraShellEscape($route['module']) . '</strong> <code class="ml-2 text-xs">POST ' . akiraShellEscape($route['route']) . '</code><span class="block text-xs text-amber-800">' . akiraShellEscape($route['handler']) . '</span></li>';
    }
    if ($undeclaredHtml === '') {
        $undeclaredHtml = '<li class="px-4 py-3 text-emerald-800">Every discovered POST route is declared.</li>';
    }

    return '<p class="mb-6 max-w-3xl text-sm text-slate-600">Read-only reconciliation of active policy, module declarations, and POST route authority. Nothing on this page changes policy or grant state.</p>'
        . '<section class="mb-8"><h2 class="mb-3 text-xl font-bold text-slate-950">Effective active policies</h2><div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm"><table class="w-full text-left"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="p-3">Capability</th><th class="p-3">Allowed roles</th><th class="p-3">Provider / caller</th><th class="p-3">Protocol</th><th class="p-3">Policy</th><th class="p-3">Grant state</th></tr></thead><tbody>' . $policyHtml . '</tbody></table></div></section>'
        . '<section class="mb-8"><h2 class="mb-1 text-xl font-bold text-slate-950">Declaration vs policy</h2><p class="mb-3 text-sm text-slate-500">Red rows are role-set deltas; amber rows cannot yet be reconciled to active policy.</p><div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm"><table class="w-full text-left"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="p-3">Contribution</th><th class="p-3">Authority target</th><th class="p-3">Declared roles</th><th class="p-3">Policy roles</th><th class="p-3">Result</th></tr></thead><tbody>' . $deltaHtml . '</tbody></table></div></section>'
        . '<section><h2 class="mb-1 text-xl font-bold text-slate-950">Undeclared POST routes</h2><p class="mb-3 text-sm text-slate-500">Routes present in routes.php but absent from capabilities.routes, grouped by owning module.</p><ul class="rounded-2xl border border-amber-300 bg-amber-50 shadow-sm">' . $undeclaredHtml . '</ul></section>';
}

/** @param list<array<string,mixed>> $rows */
function akiraShellMediaTable(array $rows, bool $manager): string
{
    if ($rows === []) {
        return '<div data-akira-media-empty class="rounded-[26px] border border-slate-200 bg-white p-12 text-center text-sm text-slate-400 shadow-sm">No media uploaded yet.</div>';
    }

    $user = app()->user();
    $actorId = is_array($user) ? (int) ($user['id'] ?? $user['sub'] ?? 0) : 0;
    $body = '';
    foreach ($rows as $row) {
        $key = (string) ($row['key'] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/D', $key) !== 1) {
            continue;
        }
        $mime = (string) ($row['mime_type'] ?? '');
        $filenameRaw = (string) ($row['filename'] ?? '');
        $filename = akiraShellEscape($filenameRaw);
        $alt = trim((string) ($row['alt'] ?? ''));
        $preview = str_starts_with($mime, 'image/')
            ? '<img class="h-14 w-14 rounded-xl border border-slate-200 object-cover" src="' . akiraShellEscape((string) ($row['url'] ?? '')) . '" alt="' . akiraShellEscape($alt) . '">'
            : '<span aria-label="File type" class="flex h-14 w-14 items-center justify-center rounded-xl bg-slate-100 text-xs font-bold text-slate-500">' . akiraShellEscape(strtoupper((string) pathinfo($filenameRaw, PATHINFO_EXTENSION))) . '</span>';
        $width = $row['width'] ?? null;
        $height = $row['height'] ?? null;
        $dimensions = is_numeric($width) && is_numeric($height) ? (int) $width . ' × ' . (int) $height : '—';
        $altLabel = $alt !== '' ? akiraShellEscape($alt) : '<span class="text-slate-300">—</span>';
        $pending = ($row['delete_requested_at'] ?? null) !== null;
        $requesterId = (int) ($row['delete_requested_by'] ?? 0);
        $reason = trim((string) ($row['delete_request_reason'] ?? ''));
        $pendingBadge = $pending
            ? '<span data-akira-media-delete-pending class="mt-1 inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-700" title="' . akiraShellEscape($reason) . '">Pending deletion</span>'
            : '';

        if ($pending && $manager) {
            $actions = '<div class="flex justify-end gap-2">'
                . '<form method="post" action="/cms-akira-shell/media/' . rawurlencode($key) . '/delete" onsubmit="return confirm(\'Approve permanent deletion? This permanently removes the stored file. Published content that references it may stop rendering. This cannot be undone.\')">' . akiraShellCsrfField()
                . '<input type="hidden" name="idempotency_key" value="media-delete-' . bin2hex(random_bytes(10)) . '">'
                . '<button type="submit" class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700">Approve</button></form>'
                . '<form method="post" action="/cms-akira-shell/media/' . rawurlencode($key) . '/delete-cancel">' . akiraShellCsrfField()
                . '<input type="hidden" name="idempotency_key" value="media-cancel-' . bin2hex(random_bytes(10)) . '">'
                . '<button type="submit" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600">Cancel</button></form></div>';
        } elseif ($pending && $requesterId === $actorId) {
            $actions = '<form class="flex justify-end" method="post" action="/cms-akira-shell/media/' . rawurlencode($key) . '/delete-cancel">' . akiraShellCsrfField()
                . '<input type="hidden" name="idempotency_key" value="media-cancel-' . bin2hex(random_bytes(10)) . '">'
                . '<button type="submit" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600">Cancel request</button></form>';
        } elseif ($pending) {
            $actions = '<div class="flex justify-end text-xs font-semibold text-amber-600">Awaiting administrator</div>';
        } elseif ($manager) {
            $actions = '<form method="post" action="/cms-akira-shell/media/' . rawurlencode($key) . '/delete" onsubmit="return confirm(\'Delete this media permanently? This removes the stored file. Published content that references it may stop rendering. This cannot be undone.\')">' . akiraShellCsrfField()
                . '<input type="hidden" name="idempotency_key" value="media-delete-' . bin2hex(random_bytes(10)) . '">'
                . '<button type="submit" class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700">Delete</button></form>';
        } else {
            $actions = '<details class="relative text-right"><summary class="cursor-pointer list-none rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700">Request deletion</summary>'
                . '<form method="post" action="/cms-akira-shell/media/' . rawurlencode($key) . '/delete-request" class="absolute right-0 z-10 mt-2 w-72 rounded-2xl border border-slate-200 bg-white p-4 text-left shadow-xl">' . akiraShellCsrfField()
                . '<label class="block text-xs font-semibold text-slate-600">Reason (optional)<input name="delete_request_reason" maxlength="255" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></label>'
                . '<input type="hidden" name="idempotency_key" value="media-request-' . bin2hex(random_bytes(10)) . '">'
                . '<button type="submit" class="mt-3 w-full rounded-xl bg-amber-600 px-3 py-2 text-xs font-semibold text-white">Submit request</button></form></details>';
        }
        $body .= '<div data-akira-media-row data-media-key="' . $key . '" class="grid grid-cols-[64px_minmax(0,1.4fr)_130px_minmax(0,1fr)_auto] items-center gap-4 border-b border-slate-100 px-6 py-4 last:border-0">'
            . $preview . '<span><strong class="block text-sm text-slate-900">' . $filename . '</strong><code class="text-xs text-slate-400">' . akiraShellEscape($mime) . '</code>' . $pendingBadge . '</span>'
            . '<span class="text-sm text-slate-500">' . $dimensions . '</span><span class="text-sm text-slate-500">' . $altLabel . '</span>'
            . $actions . '</div>';
    }
    return '<div class="overflow-hidden rounded-[26px] border border-slate-200 bg-white shadow-sm"><div class="grid grid-cols-[64px_minmax(0,1.4fr)_130px_minmax(0,1fr)_auto] gap-4 border-b border-slate-100 bg-slate-50/60 px-6 py-3 text-xs font-semibold uppercase tracking-wide text-slate-400"><span>Preview</span><span>File</span><span>Dimensions</span><span>Alt text</span><span>Action</span></div>' . $body . '</div>';
}
