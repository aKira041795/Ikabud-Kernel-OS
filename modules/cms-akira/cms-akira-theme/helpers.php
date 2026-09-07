<?php

declare(strict_types=1);

const CAT_THEME_MODULE_ID = 'cms-akira-theme';
const CAT_THEME_FALLBACK = 'cms-akira-posts';
const CAT_THEME_SETTING_ACTIVE = 'active_theme_slug';
const CAT_THEME_INVALIDATION = 'theme.active';

/** @return array<string, string> */
function cms_akira_theme_capability_handlers(): array
{
    return [
        'akira.theme.resolve@1' => 'cat_cap_akira_theme_resolve_1',
        'akira.theme.registry@1' => 'cat_cap_akira_theme_registry_1',
        'akira.theme.validate@1' => 'cat_cap_akira_theme_validate_1',
        'akira.theme.activate@1' => 'cat_cap_akira_theme_activate_1',
    ];
}

/** Seed only the activation mutation policy; reads remain policy-free. */
function catSeedThemeMutationPolicies(): void
{
    if (!function_exists('app')) {
        return;
    }
    $rows = [];
    foreach ([
        'akira.theme.activate@1',
    ] as $capabilityId) {
        $rows[] = [
            'policy_version' => 1,
            'capability_id' => $capabilityId,
            'capability_version' => '1',
            'provider' => 'cms-akira-theme',
            'caller_module' => null,
            'allowed_roles' => 'admin',
            'provider_activation_required' => true,
            'requires_protocol' => 'v2',
            'is_active' => true,
        ];
    }
    (new \Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry(app()->db()))->seedPolicy($rows);
}

catSeedThemeMutationPolicies();

final class CatThemeException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}

function catThemesPath(): string
{
    if (defined('CMS_THEMES_PATH')) {
        return (string) CMS_THEMES_PATH;
    }
    $storage = defined('STORAGE_PATH') ? (string) STORAGE_PATH : dirname(__DIR__, 3) . '/storage';
    return $storage . '/cms-themes';
}

function catThemeSlugAccepts(string $slug): bool
{
    return $slug !== '' && strlen($slug) <= 190 && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $slug) === 1;
}

function catThemeSlug(mixed $value, string $field = 'theme_slug'): string
{
    $slug = is_string($value) ? trim($value) : '';
    if (!catThemeSlugAccepts($slug)) {
        throw new CatThemeException("{$field} must be a canonical Akira theme slug.");
    }
    return $slug;
}

/** Real theme directory inside the themes root, or null on traversal/absence. */
function catThemeDir(string $slug): ?string
{
    $themesPath = rtrim(catThemesPath(), '/');
    $realThemes = realpath($themesPath);
    $realDir = realpath($themesPath . '/' . $slug);
    if ($realThemes === false || $realDir === false || !is_dir($realDir)) {
        return null;
    }
    $prefix = rtrim($realThemes, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!str_starts_with($realDir . DIRECTORY_SEPARATOR, $prefix)) {
        return null;
    }
    return $realDir;
}

/** @return array<string, mixed>|null */
function catThemeReadJson(string $path): ?array
{
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $contents = @file_get_contents($path);
    if (!is_string($contents) || $contents === '') {
        return null;
    }
    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }
    return is_array($decoded) ? $decoded : null;
}

/** @return array<string, mixed>|null */
function catThemeManifest(string $slug): ?array
{
    $dir = catThemeDir($slug);
    if ($dir === null) {
        return null;
    }
    return catThemeReadJson($dir . '/theme.manifest.json');
}

/** @return array<string, mixed>|null */
function catThemeRegistry(string $themeDir): ?array
{
    if ($themeDir === '') {
        return null;
    }
    return catThemeReadJson($themeDir . '/renderer-registry.json');
}

/**
 * A theme is ARK-visible when its manifest and renderer registry exist and
 * ArkRendererResolver resolves both canonical post views (exact match only,
 * with its own traversal protection).
 */
function catThemeIsArkVisible(string $slug): bool
{
    if (!catThemeSlugAccepts($slug) || catThemeManifest($slug) === null) {
        return false;
    }
    $dir = catThemeDir($slug);
    if ($dir === null) {
        return false;
    }
    $registry = catThemeRegistry($dir);
    if (!is_array($registry) || !is_array($registry['renderers'] ?? null) || $registry['renderers'] === []) {
        return false;
    }
    return app()->arkRenderers()->resolve('entity.list.post', $slug) !== null
        && app()->arkRenderers()->resolve('entity.detail.post', $slug) !== null;
}

/**
 * Lint every .disyl file in a theme (balanced blocks + v4 parser).
 *
 * @return list<string>
 */
function catThemeLintDisyl(string $themeDir): array
{
    $errors = [];
    if (!is_dir($themeDir)) {
        return ['Theme directory is missing.'];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($themeDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'disyl') {
            $files[] = $fileInfo->getPathname();
        }
    }
    sort($files);

    if ($files === []) {
        return ['Theme contains no .disyl template files.'];
    }

    $parser = new \Ikabud\Kernel\DiSyL\v4\Parser();
    foreach ($files as $file) {
        $relative = str_starts_with($file, $themeDir . '/') ? substr($file, strlen($themeDir) + 1) : $file;
        $source = @file_get_contents($file);
        if (!is_string($source) || $source === '') {
            $errors[] = "{$relative}: unreadable or empty";
            continue;
        }
        $stripped = preg_replace('/\{#.*?#\}/s', '', $source) ?? $source;

        foreach ([
            'block' => ['/\{block\s/', '{/block}'],
            'if' => ['/\{if\s/', '{/if}'],
            'for' => ['/\{for\s/', '{/for}'],
            'foreach' => ['/\{foreach\s/', '{/foreach}'],
            'while' => ['/\{while\s/', '{/while}'],
        ] as $label => [$openPattern, $close]) {
            $open = preg_match_all($openPattern, $stripped);
            $closeCount = substr_count($stripped, $close);
            if ($open !== $closeCount) {
                $errors[] = "{$relative}: mismatched {{$label}}/{{$close}";
            }
        }

        try {
            $parser->parse($source, $relative);
        } catch (Throwable $error) {
            $errors[] = "{$relative}: {$error->getMessage()}";
        }
    }

    return $errors;
}

/**
 * Full native validation for one Akira theme.
 *
 * @return array{slug: string, valid: bool, errors: list<string>, warnings: list<string>, checks: array<string, mixed>, fallback_available: bool}
 */
function catThemeValidate(string $slug): array
{
    $result = [
        'slug' => $slug,
        'valid' => false,
        'errors' => [],
        'warnings' => [],
        'checks' => [],
        'fallback_available' => false,
    ];

    try {
        $slug = catThemeSlug($slug);
        $result['slug'] = $slug;
    } catch (Throwable $error) {
        $result['errors'][] = $error->getMessage();
        return $result;
    }

    $dir = catThemeDir($slug);
    if ($dir === null) {
        $result['errors'][] = 'Theme directory not found or escapes the themes root.';
        return $result;
    }
    $result['checks']['traversal'] = true;

    $manifest = catThemeManifest($slug);
    if (!is_array($manifest)) {
        $result['errors'][] = 'theme.manifest.json is missing or invalid.';
        return $result;
    }
    $result['checks']['manifest_present'] = true;

    if (class_exists(\Ikabud\Kernel\Services\ThemeManifestValidator::class)) {
        $validation = \Ikabud\Kernel\Services\ThemeManifestValidator::validate($slug, $manifest, $dir);
        foreach ($validation['errors'] as $error) {
            $result['errors'][] = $error;
        }
        foreach ($validation['warnings'] as $warning) {
            $result['warnings'][] = $warning;
        }
        $result['checks']['manifest_shape'] = (bool) $validation['valid'];
    } else {
        foreach (['name', 'version', 'label', 'supported_surfaces'] as $key) {
            if (!array_key_exists($key, $manifest) || $manifest[$key] === '') {
                $result['errors'][] = "Missing required manifest key: {$key}";
            }
        }
        $result['checks']['manifest_shape'] = $result['errors'] === [];
    }

    $registry = catThemeRegistry($dir);
    if (!is_array($registry) || !is_array($registry['renderers'] ?? null) || $registry['renderers'] === []) {
        $result['errors'][] = 'renderer-registry.json must declare a non-empty renderers map.';
        $result['checks']['registry'] = false;
    } else {
        $renderers = $registry['renderers'];
        $result['checks']['registry'] = true;
        $denyContextKeys = ['tenant_id', 'id', 'provider', 'module', 'user', 'actor', 'kernel', 'db', 'app', '*'];
        foreach ($renderers as $viewId => $definition) {
            $viewId = (string) $viewId;
            if (preg_match('/^entity\.(?:list|detail)\.[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $viewId) !== 1) {
                $result['errors'][] = "renderer key '{$viewId}' is not a known entity view id.";
            }
            if (!is_array($definition)) {
                $result['errors'][] = "renderer '{$viewId}' must be an object.";
                continue;
            }
            $template = trim((string) ($definition['template'] ?? ''));
            $component = trim((string) ($definition['renders_as_component'] ?? ''));
            if (($template === '' && $component === '') || ($template !== '' && $component !== '')) {
                $result['errors'][] = "renderer '{$viewId}' must declare exactly one of template or renders_as_component.";
            }
            if ($template !== '' && (str_contains($template, '..') || str_contains($template, '\\') || str_starts_with($template, '/'))) {
                $result['errors'][] = "renderer '{$viewId}' template escapes the theme directory.";
            }
            $contextKeys = $definition['context_keys'] ?? null;
            if (!is_array($contextKeys) || $contextKeys === []) {
                $result['errors'][] = "renderer '{$viewId}' must declare non-empty projected context_keys.";
            } else {
                foreach ($contextKeys as $contextKey) {
                    $contextKey = trim((string) $contextKey);
                    if ($contextKey === '' || preg_match('/^[a-z][a-z0-9_]*$/', $contextKey) !== 1 || in_array($contextKey, $denyContextKeys, true) || str_contains($contextKey, '*')) {
                        $result['errors'][] = "renderer '{$viewId}' context_keys contains a non-projected key '{$contextKey}'.";
                    }
                }
            }
            if (app()->arkRenderers()->resolve($viewId, $slug) === null) {
                $result['errors'][] = "renderer '{$viewId}' does not resolve through ARK.";
            }
        }
    }

    $lintErrors = catThemeLintDisyl($dir);
    foreach ($lintErrors as $error) {
        $result['errors'][] = $error;
    }
    $result['checks']['disyl_lint'] = $lintErrors === [];

    $result['fallback_available'] = catThemeIsArkVisible(CAT_THEME_FALLBACK);
    if (!$result['fallback_available']) {
        $result['warnings'][] = 'Canonical fallback theme is unavailable.';
    }

    $result['valid'] = $result['errors'] === [];
    return $result;
}

/**
 * Read the tenant-scoped active-theme setting directly from the tenant-aware
 * PDO (no multi-tenant-mode gate, no per-request cache) so activation and
 * resolution behave identically in shared and dedicated topologies.
 */
function catThemeActiveSetting(): ?string
{
    $tenantId = (int) app()->tenant()->current();
    if ($tenantId <= 0 || !function_exists('_readTenantModuleSettingsSingle')) {
        return null;
    }
    try {
        $settings = _readTenantModuleSettingsSingle(CAT_THEME_MODULE_ID, $tenantId, app()->db());
        $value = $settings[CAT_THEME_SETTING_ACTIVE] ?? null;
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    } catch (Throwable) {
        return null;
    }
}

/**
 * Canonical tenant-scoped module-setting write for the active Akira theme.
 * Delegates to the Kernel-owned `tenantWriteModuleSetting()` helper (which
 * performs the KernelPDO escalation from `src/helpers`, never from this module
 * file) so module-table access enforcement is bypassed legally.
 */
function catThemeWriteActiveSetting(int $tenantId, string $slug): bool
{
    if ($tenantId <= 0 || !function_exists('tenantWriteModuleSetting')) {
        return false;
    }
    return tenantWriteModuleSetting(app()->db(), $tenantId, CAT_THEME_MODULE_ID, CAT_THEME_SETTING_ACTIVE, $slug);
}

/**
 * Deterministic active-theme resolution: module setting → request context →
 * canonical fallback. Every candidate must be a valid, ARK-visible theme;
 * an unvalidated slug is never returned.
 *
 * @return array{ok: bool, theme_slug: ?string, resolved_from: ?string, validated: bool}
 */
function catThemeResolveActive(): array
{
    $candidates = [];
    $setting = catThemeActiveSetting();
    if ($setting !== null) {
        $candidates[] = ['slug' => $setting, 'from' => 'setting'];
    }
    $context = function_exists('kernel_request_context_get')
        ? trim((string) kernel_request_context_get('active_theme_slug', ''))
        : '';
    if ($context !== '') {
        $candidates[] = ['slug' => $context, 'from' => 'request'];
    }
    $candidates[] = ['slug' => CAT_THEME_FALLBACK, 'from' => 'fallback'];

    $seen = [];
    foreach ($candidates as $candidate) {
        $slug = $candidate['slug'];
        if (isset($seen[$slug])) {
            continue;
        }
        $seen[$slug] = true;
        if (catThemeSlugAccepts($slug) && catThemeIsArkVisible($slug)) {
            return [
                'ok' => true,
                'theme_slug' => $slug,
                'resolved_from' => $candidate['from'],
                'validated' => true,
            ];
        }
    }

    return ['ok' => false, 'theme_slug' => null, 'resolved_from' => null, 'validated' => false];
}

/**
 * Request-context seam: when the tenant has an explicit, validated activation
 * setting, publish it as `active_theme_slug` so core's `cacPostThemeSlug()`
 * resolves it. When no setting exists, core's own `cms-akira-posts` fallback
 * remains authoritative.
 */
function catSeedActiveThemeRequestContext(): void
{
    if (!function_exists('kernel_request_context_set') || !function_exists('app')) {
        return;
    }
    try {
        $setting = catThemeActiveSetting();
        if ($setting === null || !catThemeSlugAccepts($setting) || !catThemeIsArkVisible($setting)) {
            return;
        }
        kernel_request_context_set('active_theme_slug', $setting);
    } catch (Throwable) {
        // Seeding is best-effort and must never break module load.
    }
}

/**
 * @param array<string, mixed> $manifest
 * @param array<string, mixed> $registry
 * @return array<string, mixed>
 */
function catThemeProject(array $manifest, array $registry, string $slug, bool $validated, bool $active): array
{
    return [
        'slug' => $slug,
        'name' => (string) ($manifest['name'] ?? $slug),
        'label' => (string) ($manifest['label'] ?? $manifest['name'] ?? $slug),
        'version' => (string) ($manifest['version'] ?? ''),
        'description' => (string) ($manifest['description'] ?? ''),
        'supported_surfaces' => array_values(array_map('strval', is_array($manifest['supported_surfaces'] ?? null) ? $manifest['supported_surfaces'] : [])),
        'renderers' => array_keys($registry['renderers'] ?? []),
        'validated' => $validated,
        'active' => $active,
    ];
}

/** @return list<array<string, mixed>> */
function catThemeRegistryRows(): array
{
    $themesPath = catThemesPath();
    $slugs = [];
    if (is_dir($themesPath)) {
        foreach (scandir($themesPath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($themesPath . '/' . $entry)) {
                continue;
            }
            if (catThemeSlugAccepts($entry)) {
                $slugs[] = $entry;
            }
        }
    }
    sort($slugs, SORT_STRING);

    $active = catThemeActiveSetting();
    $rows = [];
    foreach ($slugs as $slug) {
        $dir = catThemeDir($slug);
        $manifest = catThemeManifest($slug);
        $registry = $dir !== null ? catThemeRegistry($dir) : null;
        if (!is_array($manifest) || !is_array($registry) || !is_array($registry['renderers'] ?? null) || $registry['renderers'] === []) {
            continue;
        }
        $validation = catThemeValidate($slug);
        $rows[] = catThemeProject($manifest, $registry, $slug, (bool) $validation['valid'], $slug === $active);
    }
    return $rows;
}

/** @return array{id: int, role: string, source?: string} */
function catThemeActor(): array
{
    $actor = app()->user();
    if (!is_array($actor) || (int) ($actor['id'] ?? $actor['sub'] ?? 0) <= 0) {
        throw new CatThemeException('Authentication required.', 401);
    }
    if ((string) ($actor['role'] ?? '') !== 'admin') {
        throw new CatThemeException('Administrator role required.', 403);
    }
    return $actor;
}

function catThemeTenantId(): int
{
    $tenantId = (int) app()->tenant()->current();
    if ($tenantId <= 0) {
        throw new CatThemeException('A trusted tenant context is required.', 500);
    }
    return $tenantId;
}

function catThemeCorrelationId(): string
{
    $context = function_exists('kernel_request_context_get') ? trim((string) kernel_request_context_get('correlation_id', '')) : '';
    return $context !== '' ? $context : bin2hex(random_bytes(16));
}

/**
 * Governed v2 activation mutation: validate, persist as a tenant-scoped module
 * setting, audit durably on the same PDO, and invalidate `theme.active`.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function catThemeMutateActivate(array $payload): array
{
    $actor = catThemeActor();
    $tenantId = catThemeTenantId();
    if (array_key_exists('tenant_id', $payload)) {
        throw new CatThemeException('tenant_id is supplied by kernel context.', 422);
    }
    $idempotencyKey = is_string($payload['idempotency_key'] ?? null) ? trim($payload['idempotency_key']) : '';
    if ($idempotencyKey === '' || strlen($idempotencyKey) > 255) {
        throw new CatThemeException('A valid idempotency_key is required.', 422);
    }
    $slug = catThemeSlug($payload['theme_slug'] ?? null);
    $validation = catThemeValidate($slug);
    if (!$validation['valid']) {
        throw new CatThemeException('Theme failed validation: ' . implode('; ', array_slice($validation['errors'], 0, 3)), 422);
    }

    $input = ['theme_slug' => $slug];
    $envelope = ['operation' => 'theme.activate', 'theme' => $input];
    $hash = app()->cap()->call('kernel.idempotency.hash@1', ['payload' => $envelope], [
        'caller' => ['module' => CAT_THEME_MODULE_ID, 'user' => $actor],
        'mode' => 'first',
    ]);
    if (!is_string($hash)) {
        throw new CatThemeException('Idempotency hashing unavailable.', 503);
    }

    $pdo = app()->db();
    $claimed = false;
    $publicationUncertain = false;
    try {
        $pdo->beginTransaction();
        $claim = app()->cap()->call('kernel.idempotency.claim@1', [
            'key' => $idempotencyKey,
            'tenant_id' => $tenantId,
            'payload_hash' => $hash,
            'db' => $pdo,
        ], ['caller' => ['module' => CAT_THEME_MODULE_ID, 'user' => $actor], 'mode' => 'first']);
        $status = is_array($claim) ? (string) ($claim['status'] ?? '') : '';
        if ($status === 'duplicate') {
            $pdo->rollBack();
            return is_array($claim['outcome'] ?? null) ? $claim['outcome'] : ['ok' => true, 'replayed' => true];
        }
        if ($status === 'conflict') {
            $pdo->rollBack();
            throw new CatThemeException('Idempotency key payload conflict.', 409);
        }
        if ($status === 'in_progress') {
            $pdo->rollBack();
            throw new CatThemeException('Idempotent mutation is still processing.', 425, 2);
        }
        if ($status !== 'new') {
            throw new RuntimeException('Unexpected idempotency claim result.');
        }
        $claimed = true;

        $old = catThemeActiveSetting();
        if (!function_exists('moduleTenantSettingsEnsureTable')) {
            throw new CatThemeException('Tenant module settings are unavailable.', 503);
        }
        if (catThemeWriteActiveSetting($tenantId, $slug) !== true) {
            throw new CatThemeException('Theme activation setting write failed.', 503);
        }

        $correlationId = catThemeCorrelationId();
        $audit = app()->cap()->call('kernel.audit.record@1', [
            'module' => CAT_THEME_MODULE_ID,
            'action' => 'akira.theme.activate',
            'entity_type' => 'theme',
            'entity_id' => $slug,
            'old_data' => ['active_theme_slug' => $old],
            'new_data' => ['correlation_id' => $correlationId, 'tenant_id' => $tenantId, 'active_theme_slug' => $slug],
        ], ['caller' => ['module' => CAT_THEME_MODULE_ID, 'user' => $actor], 'mode' => 'first']);
        if (!is_array($audit) || ($audit['ok'] ?? false) !== true) {
            throw new RuntimeException('Durable theme audit failed.');
        }

        $outcome = [
            'ok' => true,
            'operation' => 'theme.activate',
            'theme' => ['slug' => $slug],
            'active_theme_slug' => $slug,
            'correlation_id' => $correlationId,
        ];
        $committed = app()->cap()->call('kernel.idempotency.commit@1', [
            'key' => $idempotencyKey,
            'tenant_id' => $tenantId,
            'outcome' => $outcome,
            'db' => $pdo,
        ], ['caller' => ['module' => CAT_THEME_MODULE_ID, 'user' => $actor], 'mode' => 'first']);
        if ($committed !== true) {
            throw new RuntimeException('Idempotency outcome commit failed.');
        }
        $publicationUncertain = true;
        $pdo->commit();
        $publicationUncertain = false;

        // `theme.active` is the canonical non-entity invalidation tag; the
        // kernel only auto-applies entity.* cache effects, so apply it here.
        try {
            app()->templates()->fragmentStore()->invalidate([CAT_THEME_INVALIDATION], (string) $tenantId);
        } catch (Throwable) {
            // Fail-open: cache infrastructure must not break a committed write.
        }

        return $outcome;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($claimed && !$publicationUncertain) {
            try {
                app()->cap()->call('kernel.idempotency.release@1', [
                    'key' => $idempotencyKey,
                    'tenant_id' => $tenantId,
                    'db' => $pdo,
                ], ['caller' => ['module' => CAT_THEME_MODULE_ID, 'user' => $actor], 'mode' => 'first']);
            } catch (Throwable) {
                // A failed release stays processing and therefore fails closed.
            }
        }
        throw $error;
    }
}

/** @return array<string, mixed> */
function cat_cap_akira_theme_resolve_1(mixed $payload, string $capabilityId = 'akira.theme.resolve@1', string $caller = 'unknown'): array
{
    if ($payload !== null && !is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }
    if (is_array($payload) && array_key_exists('tenant_id', $payload)) {
        return ['ok' => false, 'error' => 'tenant_id is supplied by kernel context'];
    }
    return catThemeResolveActive();
}

/** @return array<string, mixed> */
function cat_cap_akira_theme_registry_1(mixed $payload, string $capabilityId = 'akira.theme.registry@1', string $caller = 'unknown'): array
{
    if ($payload !== null && !is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }
    if (is_array($payload) && array_key_exists('tenant_id', $payload)) {
        return ['ok' => false, 'error' => 'tenant_id is supplied by kernel context'];
    }
    try {
        $themes = catThemeRegistryRows();
    } catch (Throwable $error) {
        return ['ok' => false, 'themes' => [], 'total' => 0, 'error' => 'Theme registry unavailable'];
    }
    return ['ok' => true, 'themes' => $themes, 'total' => count($themes)];
}

/** @return array<string, mixed> */
function cat_cap_akira_theme_validate_1(mixed $payload, string $capabilityId = 'akira.theme.validate@1', string $caller = 'unknown'): array
{
    if (!is_array($payload) || array_key_exists('tenant_id', $payload)) {
        return ['ok' => false, 'error' => 'A tenant-context theme slug is required'];
    }
    try {
        $slug = catThemeSlug($payload['theme_slug'] ?? $payload['slug'] ?? null);
    } catch (Throwable $error) {
        return ['ok' => false, 'error' => $error->getMessage()];
    }
    return ['ok' => true, 'data' => catThemeValidate($slug)];
}

/** @return array<string, mixed> */
function cat_cap_akira_theme_activate_1(mixed $payload, string $capabilityId = 'akira.theme.activate@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CatThemeException('payload must be an object.');
    }
    return catThemeMutateActivate($payload);
}

// ── Minimal shell-guarded admin/JSON surface helpers ─────────────────────

function catThemeEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @return array<string, mixed>|null */
function catThemeAdmin(): ?array
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

/** @param array<string, mixed> $data */
function catThemePage(string $title, string $body, array $data = []): string
{
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . catThemeEscape($title) . '</title></head><body>'
        . '<nav aria-label="Akira theme administration"><a href="/cms-akira-shell">Akira Shell</a> '
        . '<a href="/cms-akira-theme">Themes</a> <a href="/auth/logout">Sign out</a></nav>'
        . '<main><h1>' . catThemeEscape($title) . '</h1>' . $body . '</main></body></html>';
}

function catThemeCsrfField(): string
{
    $token = '';
    if (method_exists(app(), 'csrfToken')) {
        $token = (string) app()->csrfToken();
    } elseif (isset($_SESSION['_csrf_token'])) {
        $token = (string) $_SESSION['_csrf_token'];
    }
    return '<input type="hidden" name="_csrf_token" value="' . catThemeEscape($token) . '">';
}

/** @return array<string, mixed> */
function catThemeInput(): array
{
    $ctx = function_exists('module') ? module(CAT_THEME_MODULE_ID) : null;
    if ($ctx !== null) {
        $input = $ctx->input();
        if (is_array($input) && $input !== []) {
            return $input;
        }
    }
    $raw = file_get_contents('php://input');
    $json = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
    if (is_array($json)) {
        return $json;
    }
    return $_POST;
}

function catThemeEnforceMutationCsrf(): void
{
    $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    $cookieNames = [(string) config('app.cookie_name', 'guidance_token')];
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
    if ($hasAuthCookie || preg_match('/^Bearer\s+\S+$/i', $authorization) !== 1) {
        app()->csrfEnforce();
    }
}

catSeedActiveThemeRequestContext();
