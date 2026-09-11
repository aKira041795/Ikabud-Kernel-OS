<?php

declare(strict_types=1);

const CAT_THEME_MODULE_ID = 'cms-akira-theme';
const CAT_THEME_FALLBACK = 'cms-akira-posts';
const CAT_THEME_SETTING_ACTIVE = 'active_theme_slug';
const CAT_THEME_SETTING_PREVIOUS = 'previous_theme_slug';
const CAT_THEME_SETTING_CUSTOMIZER = 'customizer_values';
const CAT_THEME_INVALIDATION = 'theme.active';

/** @return array<string, string> */
function cms_akira_theme_capability_handlers(): array
{
    return [
        'akira.theme.resolve@1' => 'cat_cap_akira_theme_resolve_1',
        'akira.theme.registry@1' => 'cat_cap_akira_theme_registry_1',
        'akira.theme.validate@1' => 'cat_cap_akira_theme_validate_1',
        'akira.theme.blocks@1' => 'cat_cap_akira_theme_blocks_1',
        'akira.theme.customizer.schema@1' => 'cat_cap_akira_theme_customizer_schema_1',
        'akira.theme.customizer.values@1' => 'cat_cap_akira_theme_customizer_values_1',
        'akira.theme.activate@1' => 'cat_cap_akira_theme_activate_1',
        'akira.theme.customize@1' => 'cat_cap_akira_theme_customize_1',
    ];
}

/** Seed mutation policies; customizer reads remain policy-free. */
function catSeedThemeMutationPolicies(): void
{
    if (!function_exists('app')) {
        return;
    }
    $rows = [];
    foreach ([
        'akira.theme.activate@1' => ['cms-akira-theme', 'admin'],
        'akira.theme.customize@1' => ['cms-akira-theme,cms-akira-shell', 'admin,editor,administrator,superadmin'],
    ] as $capabilityId => [$callers, $roles]) {
        $rows[] = [
            'policy_version' => 1,
            'capability_id' => $capabilityId,
            'capability_version' => '1',
            'provider' => 'cms-akira-theme',
            'caller_module' => $callers,
            'allowed_roles' => $roles,
            'provider_activation_required' => true,
            'requires_protocol' => 'v2',
            'is_active' => true,
        ];
    }
    \Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry::seedPolicyForCurrentScope($rows);
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

/** @return list<string> */
function catThemeTreeContentErrors(string $themeDir): array
{
    // Declarative themes must never ship executable/source code. Use a deny-list of
    // web-executable / scripting file types plus a PHP content sniff (catches disguised
    // executables with a benign extension or no extension), so legitimate meta files
    // (e.g. .gitkeep) and asset files (images, fonts, JSON, templates) always pass.
    $deniedExtensions = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar',
        'sql', 'sh', 'bash', 'cgi', 'pl', 'py', 'rb', 'shtml', 'shtm',
    ];
    $deniedBasenames = ['.htaccess', '.htpasswd', '.user.ini'];
    $errors = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($themeDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        $path = $fileInfo->getPathname();
        $relative = str_starts_with($path, $themeDir . DIRECTORY_SEPARATOR)
            ? substr($path, strlen($themeDir) + 1)
            : $path;
        $extension = strtolower((string) $fileInfo->getExtension());
        if (in_array($extension, $deniedExtensions, true)) {
            $errors[] = "{$relative}: file type .{$extension} is not allowed in a declarative theme.";
            continue;
        }
        $basename = strtolower((string) $fileInfo->getBasename());
        if (in_array($basename, $deniedBasenames, true)) {
            $errors[] = "{$relative}: server-config file is not allowed in a declarative theme.";
            continue;
        }
        $head = (string) @file_get_contents($path, false, null, 0, 8192);
        if (preg_match('/<\?php|<\?=/i', $head) === 1) {
            $errors[] = "{$relative}: file contains PHP code, which is not allowed in a declarative theme.";
        }
    }
    sort($errors, SORT_STRING);
    return $errors;
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
 * Read the canonical, versioned theme block catalogue.
 *
 * @return list<array<string, mixed>>
 */
function catThemeBlocks(string $themeDir): array
{
    $catalogue = catThemeReadJson($themeDir . '/block-definitions.json');
    return is_array($catalogue) && is_array($catalogue['blocks'] ?? null) ? array_values($catalogue['blocks']) : [];
}

/**
 * Validate the one canonical block/section contract used by themes and future compositions.
 *
 * @return list<string>
 */
function catThemeBlockErrors(string $themeDir): array
{
    $file = $themeDir . '/block-definitions.json';
    if (!is_file($file)) {
        return []; // Block catalogues are optional for legacy themes.
    }
    $catalogue = catThemeReadJson($file);
    if (!is_array($catalogue)) {
        return ['block-definitions.json: invalid JSON object.'];
    }
    $version = $catalogue['contract_version'] ?? null;
    if (!is_string($version) || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
        return ['block-definitions.json: contract_version must be a semantic version.'];
    }
    if (!is_array($catalogue['blocks'] ?? null)) {
        return ['block-definitions.json: blocks must be an array.'];
    }
    $errors = [];
    $ids = [];
    $allowedTypes = ['string', 'url', 'boolean', 'integer', 'array'];
    $denyContext = ['tenant_id','id','provider','module','user','actor','kernel','db','app','*'];
    foreach (array_values($catalogue['blocks']) as $index => $block) {
        $at = "block-definitions.json blocks[{$index}]";
        if (!is_array($block)) {
            $errors[] = "{$at}: must be an object.";
            continue;
        }
        $id = is_string($block['id'] ?? null) ? $block['id'] : '';
        if (preg_match('/^[a-z][a-z0-9-]*$/', $id) !== 1) {
            $errors[] = "{$at}: invalid id '{$id}'.";
        }
        if (isset($ids[$id])) {
            $errors[] = "{$at}: duplicate id '{$id}'.";
        }
        $ids[$id] = true;
        if (!is_string($block['label'] ?? null) || trim($block['label']) === '') {
            $errors[] = "{$at} ({$id}): label is required.";
        }
        if (!in_array($block['category'] ?? null, ['layout','content','media'], true)) {
            $errors[] = "{$at} ({$id}): invalid category.";
        }
        if (($block['contract_version'] ?? null) !== $version) {
            $errors[] = "{$at} ({$id}): contract_version must equal catalogue version {$version}.";
        }
        $props = $block['schema']['props'] ?? null;
        if (!is_array($props)) {
            $errors[] = "{$at} ({$id}): schema.props must be an object.";
            $props = [];
        }
        foreach ($props as $name => $prop) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1 || !is_array($prop)) {
                $errors[] = "{$at} ({$id}): invalid prop '{$name}'.";
                continue;
            }
            if (!in_array($prop['type'] ?? null, $allowedTypes, true)) {
                $errors[] = "{$at} ({$id}) prop '{$name}': type is not allowed.";
            }
            if (($prop['type'] ?? null) === 'array' && !is_array($prop['items'] ?? null)) {
                $errors[] = "{$at} ({$id}) prop '{$name}': array items schema is required.";
            }
        }
        $defaults = $block['defaults'] ?? null;
        if (!is_array($defaults)) {
            $errors[] = "{$at} ({$id}): defaults must be an object.";
        } else {
            foreach ($defaults as $name => $value) {
                if (!isset($props[$name])) {
                    $errors[] = "{$at} ({$id}): default references unknown prop '{$name}'.";
                }
                if (is_string($value) && preg_match('/<[^>]*>/', $value)) {
                    $errors[] = "{$at} ({$id}) default '{$name}': HTML is prohibited.";
                }
            }
        }
        if (!is_array($block['slots'] ?? null)) {
            $errors[] = "{$at} ({$id}): slots must be an array.";
        }
        $renderer = $block['renderer'] ?? null;
        $template = is_array($renderer) ? ($renderer['template'] ?? '') : '';
        $templateLabel = is_scalar($template) ? (string) $template : get_debug_type($template);
        if (!is_string($template) || preg_match('#^blocks/[a-zA-Z0-9_-]+\.disyl$#', $template) !== 1) {
            $errors[] = "{$at} ({$id}): renderer template '{$templateLabel}' must be a theme blocks/*.disyl path.";
        } else {
            $real = realpath($themeDir . '/' . $template);
            if ($real === false || !is_file($real) || !str_starts_with($real, $themeDir . DIRECTORY_SEPARATOR)) {
                $errors[] = "{$at} ({$id}): renderer template '{$template}' is missing or escapes the theme directory.";
            }
        }
        $keys = is_array($renderer) ? ($renderer['context_keys'] ?? null) : null;
        if ($keys !== ['props']) {
            $errors[] = "{$at} ({$id}): renderer context_keys must be exactly ['props'].";
        }
        if (is_array($keys)) {
            foreach ($keys as $key) {
                if (in_array($key, $denyContext, true)) {
                    $errors[] = "{$at} ({$id}): unsafe renderer context key '{$key}'.";
                }
            }
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

    $blockErrors = catThemeBlockErrors($dir);
    foreach ($blockErrors as $error) {
        $result['errors'][] = $error;
    }
    $result['checks']['blocks'] = $blockErrors === [];

    $contentErrors = catThemeTreeContentErrors($dir);
    foreach ($contentErrors as $error) {
        $result['errors'][] = $error;
    }
    $result['checks']['declarative_content'] = $contentErrors === [];

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

function catThemePreviousSetting(): ?string
{
    $tenantId = (int) app()->tenant()->current();
    if ($tenantId <= 0 || !function_exists('_readTenantModuleSettingsSingle')) {
        return null;
    }
    try {
        $settings = _readTenantModuleSettingsSingle(CAT_THEME_MODULE_ID, $tenantId, app()->db());
        $value = $settings[CAT_THEME_SETTING_PREVIOUS] ?? null;
        return is_string($value) && catThemeSlugAccepts(trim($value)) ? trim($value) : null;
    } catch (Throwable) {
        return null;
    }
}

function catThemeWritePreviousSetting(int $tenantId, string $slug): bool
{
    return $tenantId > 0
        && function_exists('tenantWriteModuleSetting')
        && tenantWriteModuleSetting(app()->db(), $tenantId, CAT_THEME_MODULE_ID, CAT_THEME_SETTING_PREVIOUS, $slug);
}

/** @return array{theme_slug:string,values:array<string,array<string,mixed>>} */
function catThemeCustomizerStored(): array
{
    $tenantId = catThemeTenantId();
    if (!function_exists('_readTenantModuleSettingsSingle')) {
        return ['theme_slug' => '', 'values' => []];
    }
    try {
        $settings = _readTenantModuleSettingsSingle(CAT_THEME_MODULE_ID, $tenantId, app()->db());
        $stored = $settings[CAT_THEME_SETTING_CUSTOMIZER] ?? [];
        if (!is_array($stored)) {
            return ['theme_slug' => '', 'values' => []];
        }
        return [
            'theme_slug' => is_string($stored['theme_slug'] ?? null) ? $stored['theme_slug'] : '',
            'values' => is_array($stored['values'] ?? null) ? $stored['values'] : [],
        ];
    } catch (Throwable) {
        return ['theme_slug' => '', 'values' => []];
    }
}

function catThemeCustomizerProvider(string $slug): \Ikabud\Kernel\Services\DeclarativeThemeCustomizerProvider
{
    $path = catThemeDir($slug);
    if ($path === null) {
        throw new CatThemeException('Theme customizer is unavailable.', 422);
    }
    $provider = new \Ikabud\Kernel\Services\DeclarativeThemeCustomizerProvider($slug, $path);
    if (!\Ikabud\Kernel\Services\ThemeCustomizerOrchestrator::validateProvider($provider, $slug, $path)) {
        throw new CatThemeException('Theme customizer definition is invalid.', 422);
    }
    return $provider;
}

/** @return array<string,mixed> */
function catThemeCustomizerSchema(string $slug): array
{
    $definition = catThemeCustomizerProvider($slug)->definition();
    $sections = [];
    foreach ($definition->sectionNames() as $sectionId) {
        $section = $definition->section($sectionId);
        if ($section === null) {
            continue;
        }
        $controls = [];
        foreach ($section->controls as $control) {
            $controls[$control->id] = [
                'label' => $control->label,
                'type' => $control->type,
                'default' => $control->default,
                'options' => $control->options,
                'constraints' => $control->constraints,
                'description' => $control->description,
            ];
        }
        $sections[$sectionId] = ['label' => $section->label, 'controls' => $controls];
    }
    return ['theme_slug' => $slug, 'sections' => $sections];
}

/**
 * @param array<string,mixed> $values
 * @return array<string,array<string,mixed>>
 */
function catThemeValidateCustomizerValues(string $slug, array $values): array
{
    $provider = catThemeCustomizerProvider($slug);
    $definition = $provider->definition();
    if (array_diff(array_keys($values), $definition->sectionNames()) !== []) {
        throw new CatThemeException('Customizer values contain an unknown section.', 422);
    }
    $validated = [];
    foreach ($definition->sectionNames() as $sectionId) {
        $submitted = $values[$sectionId] ?? [];
        if (!is_array($submitted)) {
            throw new CatThemeException("Customizer section {$sectionId} must be an object.", 422);
        }
        $section = $definition->section($sectionId);
        if ($section === null || array_diff(array_keys($submitted), array_keys($section->controls)) !== []) {
            throw new CatThemeException("Customizer section {$sectionId} contains an unknown field.", 422);
        }
        foreach ($submitted as $field => $value) {
            if (!is_scalar($value) && $value !== null) {
                throw new CatThemeException("Customizer field {$sectionId}.{$field} must be a scalar value.", 422);
            }
        }
        $result = $provider->validate(new \Ikabud\Kernel\Contracts\ThemeCustomizationSubmission(
            $sectionId,
            $submitted,
            \Ikabud\Kernel\Contracts\ThemeCustomizationScope::fromString('native_' . $slug),
        ));
        if (!$result->valid || $result->messages !== []) {
            $message = (string)($result->messages[0]['message'] ?? 'Invalid customizer value.');
            throw new CatThemeException("Customizer section {$sectionId}: {$message}", 422);
        }
        $validated[$sectionId] = $result->correctedValues;
    }
    return $validated;
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

    $rollback = filter_var($payload['rollback'] ?? false, FILTER_VALIDATE_BOOL);
    $input = ['theme_slug' => $slug, 'rollback' => $rollback];
    $envelope = ['operation' => $rollback ? 'theme.rollback' : 'theme.activate', 'theme' => $input];
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
        if ($rollback && ($old === $slug || catThemePreviousSetting() !== $slug)) {
            throw new CatThemeException('Rollback target is not the previous active theme.', 409);
        }
        if (!function_exists('moduleTenantSettingsEnsureTable')) {
            throw new CatThemeException('Tenant module settings are unavailable.', 503);
        }
        if ($old !== $slug && $old !== null && catThemeWritePreviousSetting($tenantId, $old) !== true) {
            throw new CatThemeException('Previous theme setting write failed.', 503);
        }
        if (catThemeWriteActiveSetting($tenantId, $slug) !== true) {
            throw new CatThemeException('Theme activation setting write failed.', 503);
        }

        $operation = $rollback ? 'theme.rollback' : 'theme.activate';
        $auditAction = $rollback ? 'akira.theme.rollback' : 'akira.theme.activate';
        $correlationId = catThemeCorrelationId();
        $audit = app()->cap()->call('kernel.audit.record@1', [
            'module' => CAT_THEME_MODULE_ID,
            'action' => $auditAction,
            'entity_type' => 'theme',
            'entity_id' => $slug,
            'old_data' => ['active_theme_slug' => $old],
            'new_data' => ['correlation_id' => $correlationId, 'tenant_id' => $tenantId, 'active_theme_slug' => $slug, 'rollback' => $rollback],
        ], ['caller' => ['module' => CAT_THEME_MODULE_ID, 'user' => $actor], 'mode' => 'first']);
        if (!is_array($audit) || ($audit['ok'] ?? false) !== true) {
            throw new RuntimeException('Durable theme audit failed.');
        }

        $outcome = [
            'ok' => true,
            'operation' => $operation,
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
            if (function_exists('pageCacheInvalidateModule')) {
                pageCacheInvalidateModule('cms-akira-shell');
            }
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

/**
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function catThemeMutateCustomize(array $payload): array
{
    $actor = catThemeActor();
    $tenantId = catThemeTenantId();
    if (array_key_exists('tenant_id', $payload)) {
        throw new CatThemeException('tenant_id is supplied by kernel context.', 422);
    }
    $key = is_string($payload['idempotency_key'] ?? null) ? trim($payload['idempotency_key']) : '';
    if ($key === '' || strlen($key) > 255) {
        throw new CatThemeException('A valid idempotency_key is required.', 422);
    }
    $slug = catThemeSlug($payload['theme_slug'] ?? null);
    $active = catThemeResolveActive();
    $allowed = $slug === $active['theme_slug'] || catThemeValidate($slug)['valid'];
    if (!$allowed) {
        throw new CatThemeException('Theme is not active or eligible for activation.', 422);
    }
    if (!is_array($payload['values'] ?? null)) {
        throw new CatThemeException('values must be an object of sections and fields.', 422);
    }
    $submittedValues = $payload['values'];
    $hash = app()->cap()->call('kernel.idempotency.hash@1', ['payload' => ['operation' => 'theme.customize', 'theme' => ['theme_slug' => $slug, 'values' => $submittedValues]]], [
        'caller' => ['module' => CAT_THEME_MODULE_ID, 'user' => $actor], 'mode' => 'first',
    ]);
    if (!is_string($hash)) {
        throw new CatThemeException('Idempotency hashing unavailable.', 503);
    }
    $pdo = app()->db();
    $claimed = false;
    $publicationUncertain = false;
    try {
        $pdo->beginTransaction();
        $claim = app()->cap()->call('kernel.idempotency.claim@1', ['key' => $key, 'tenant_id' => $tenantId, 'payload_hash' => $hash, 'db' => $pdo], [
            'caller' => ['module' => CAT_THEME_MODULE_ID, 'user' => $actor], 'mode' => 'first',
        ]);
        $status = is_array($claim) ? (string)($claim['status'] ?? '') : '';
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
        // Validation is part of the same tenant-PDO transaction as the
        // idempotency claim, setting upsert, audit, and outcome commit.
        $values = catThemeValidateCustomizerValues($slug, $submittedValues);
        $old = catThemeCustomizerStored();
        $stored = ['theme_slug' => $slug, 'values' => $values];
        if (!tenantWriteModuleSetting($pdo, $tenantId, CAT_THEME_MODULE_ID, CAT_THEME_SETTING_CUSTOMIZER, $stored)) {
            throw new CatThemeException('Customizer setting write failed.', 503);
        }
        $correlationId = catThemeCorrelationId();
        $audit = app()->cap()->call('kernel.audit.record@1', [
            'module' => CAT_THEME_MODULE_ID, 'action' => 'akira.theme.customize', 'entity_type' => 'theme', 'entity_id' => $slug,
            'old_data' => $old, 'new_data' => $stored + ['correlation_id' => $correlationId, 'tenant_id' => $tenantId],
        ], ['caller' => ['module' => CAT_THEME_MODULE_ID, 'user' => $actor], 'mode' => 'first']);
        if (!is_array($audit) || ($audit['ok'] ?? false) !== true) {
            throw new RuntimeException('Durable theme customizer audit failed.');
        }
        $outcome = ['ok' => true, 'operation' => 'theme.customize', 'theme_slug' => $slug, 'values' => $values, 'correlation_id' => $correlationId];
        $committed = app()->cap()->call('kernel.idempotency.commit@1', ['key' => $key, 'tenant_id' => $tenantId, 'outcome' => $outcome, 'db' => $pdo], [
            'caller' => ['module' => CAT_THEME_MODULE_ID, 'user' => $actor], 'mode' => 'first',
        ]);
        if ($committed !== true) {
            throw new RuntimeException('Idempotency outcome commit failed.');
        }
        $publicationUncertain = true;
        $pdo->commit();
        $publicationUncertain = false;
        try {
            app()->templates()->fragmentStore()->invalidate([CAT_THEME_INVALIDATION], (string)$tenantId);
            if (function_exists('pageCacheInvalidateModule')) {
                pageCacheInvalidateModule('cms-akira-shell');
            }
        } catch (Throwable) {
        }
        return $outcome;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($claimed && !$publicationUncertain) {
            try {
                app()->cap()->call('kernel.idempotency.release@1', ['key' => $key, 'tenant_id' => $tenantId, 'db' => $pdo], [
                    'caller' => ['module' => CAT_THEME_MODULE_ID, 'user' => $actor], 'mode' => 'first',
                ]);
            } catch (Throwable) {
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

/** @return array<string,mixed> */
function cat_cap_akira_theme_blocks_1(mixed $payload, string $capabilityId = 'akira.theme.blocks@1', string $caller = 'unknown'): array
{
    if ($payload !== null && !is_array($payload) || is_array($payload) && array_key_exists('tenant_id', $payload)) {
        return ['ok' => false, 'error' => 'tenant_id is supplied by kernel context'];
    }
    $resolved = catThemeResolveActive();
    $slug = (string) ($resolved['theme_slug'] ?? '');
    $dir = catThemeDir($slug);
    if (!$resolved['ok'] || $dir === null || !catThemeValidate($slug)['valid']) {
        return ['ok' => false, 'error' => 'Active theme block catalogue unavailable'];
    }
    return ['ok' => true, 'theme_slug' => $slug, 'blocks' => catThemeBlocks($dir)];
}

/** @return array<string, mixed> */
function cat_cap_akira_theme_activate_1(mixed $payload, string $capabilityId = 'akira.theme.activate@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CatThemeException('payload must be an object.');
    }
    return catThemeMutateActivate($payload);
}

/** @return array<string,mixed> */
function cat_cap_akira_theme_customizer_schema_1(mixed $payload, string $capabilityId = 'akira.theme.customizer.schema@1', string $caller = 'unknown'): array
{
    if ($payload !== null && !is_array($payload) || is_array($payload) && array_key_exists('tenant_id', $payload)) {
        return ['ok' => false, 'error' => 'tenant_id is supplied by kernel context'];
    }
    $resolved = catThemeResolveActive();
    if ($resolved['ok'] !== true) {
        return ['ok' => false, 'error' => 'Active theme unavailable'];
    }
    return ['ok' => true, 'data' => catThemeCustomizerSchema((string)$resolved['theme_slug'])];
}

/** @return array<string,mixed> */
function cat_cap_akira_theme_customizer_values_1(mixed $payload, string $capabilityId = 'akira.theme.customizer.values@1', string $caller = 'unknown'): array
{
    if ($payload !== null && !is_array($payload) || is_array($payload) && array_key_exists('tenant_id', $payload)) {
        return ['ok' => false, 'error' => 'tenant_id is supplied by kernel context'];
    }
    $resolved = catThemeResolveActive();
    $slug = (string)($resolved['theme_slug'] ?? '');
    $stored = catThemeCustomizerStored();
    return ['ok' => true, 'theme_slug' => $slug, 'values' => $stored['theme_slug'] === $slug ? $stored['values'] : []];
}

/** @return array<string,mixed> */
function cat_cap_akira_theme_customize_1(mixed $payload, string $capabilityId = 'akira.theme.customize@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CatThemeException('payload must be an object.');
    }
    return catThemeMutateCustomize($payload);
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
    if (!in_array((string)($user['role'] ?? ''), ['admin', 'editor', 'administrator', 'superadmin'], true)) {
        return [];
    }
    return $user;
}

/** @param array<string, mixed> $data */
function catThemePage(string $title, string $body, array $data = []): string
{
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . catThemeEscape($title) . '</title><script src="https://cdn.tailwindcss.com"></script><script defer src="https://unpkg.com/alpinejs@3.14.3/dist/cdn.min.js"></script>'
        . '<script>tailwind.config={theme:{extend:{colors:{akira:{500:"#8b5cf6",600:"#7c3aed",700:"#6d28d9"}}}}}</script></head><body class="bg-slate-50 p-6 text-slate-800">'
        . '<nav class="mb-6 flex gap-4" aria-label="Akira theme administration"><a href="/cms-akira-shell">Akira Shell</a> '
        . '<a href="/cms-akira-theme">Themes</a> <a href="/auth/logout">Sign out</a></nav>'
        . '<main><h1>' . catThemeEscape($title) . '</h1>' . $body . '</main></body></html>';
}

function catThemeCsrfField(): string
{
    return app()->csrfField();
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
