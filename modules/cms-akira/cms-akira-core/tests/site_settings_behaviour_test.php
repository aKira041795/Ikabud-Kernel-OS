<?php

/**
 * CMS Akira P3.1b additional site behaviour settings (synthetic tenant only).
 *
 * Every key asserted here must have a real render-time consumer in
 * cms-akira-shell/handlers.php; a settings-page-only key is a defect.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
require_once $root . '/tests/_support/env_guard.php';
require_once dirname(__DIR__) . '/helpers.php';
requireNotLiveTenantDatabase();

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    $ok ? ++$pass : ++$fail;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . "\n";
};

$defaults = cacSiteSettingsDefaults();
$newKeys = [
    CAC_SITE_SETTING_PUBLIC_SINGLE => 'enabled',
    CAC_SITE_SETTING_ARCHIVE_PAGE_SIZE => '12',
    CAC_SITE_SETTING_ARCHIVE_SORT => 'newest',
];
$check(isset($defaults[CAC_SITE_SETTING_PUBLIC_ARCHIVE]), 'public_posts_archive keeps its documented default');
foreach ($newKeys as $key => $default) {
    $check(($defaults[$key] ?? null) === $default, "{$key} has a documented default ({$default})");
}

// ── Fail-closed validation ───────────────────────────────────────────────
$settings = cacSiteSettingsValidate(cacSiteSettingsDefaults());
$check($settings === cacSiteSettingsDefaults(), 'complete default submission validates unchanged');

$unknownRefused = false;
try {
    cacSiteSettingsValidate(cacSiteSettingsDefaults() + ['mystery' => 'value']);
} catch (CacSiteSettingsException $error) {
    $unknownRefused = str_contains($error->getMessage(), 'Unknown site setting');
}
$check($unknownRefused, 'unknown setting keys are refused fail-closed');

$invalidRefused = static function (array $overrides): bool {
    try {
        cacSiteSettingsValidate($overrides + cacSiteSettingsDefaults());
        return false;
    } catch (CacSiteSettingsException) {
        return true;
    }
};
$check($invalidRefused([CAC_SITE_SETTING_PUBLIC_SINGLE => 'maybe']), 'public_post_single rejects an unknown value');
$check($invalidRefused([CAC_SITE_SETTING_ARCHIVE_SORT => 'sideways']), 'public_archive_sort rejects an unknown value');
foreach (['0', '-1', '51', '999', 'twelve', '1.5'] as $bad) {
    $check($invalidRefused([CAC_SITE_SETTING_ARCHIVE_PAGE_SIZE => $bad]), "public_archive_page_size rejects '{$bad}'");
}

// Blank means the documented default and is never persisted as an empty value.
$blank = cacSiteSettingsValidate([
    CAC_SITE_SETTING_PUBLIC_ARCHIVE => '',
    CAC_SITE_SETTING_PUBLIC_SINGLE => '',
    CAC_SITE_SETTING_ARCHIVE_PAGE_SIZE => '',
    CAC_SITE_SETTING_ARCHIVE_SORT => '',
]);
$check($blank === cacSiteSettingsDefaults(), 'blank values all resolve to the documented defaults');
$check(cacSiteSettingNormalize(CAC_SITE_SETTING_ARCHIVE_PAGE_SIZE, '1') === '1'
    && cacSiteSettingNormalize(CAC_SITE_SETTING_ARCHIVE_PAGE_SIZE, '50') === '50'
    && cacSiteSettingNormalize(CAC_SITE_SETTING_ARCHIVE_PAGE_SIZE, '0') === null
    && cacSiteSettingNormalize(CAC_SITE_SETTING_ARCHIVE_PAGE_SIZE, '51') === null,
    'page-size normalization keeps the 1..50 bounds');

// ── Read-time fail-closed fallback against the real store seam ───────────
$tenant = 995432;
$db = app()->db();
$originalTenant = app()->tenant()->current();
$writtenKeys = array_keys(cacSiteSettingsDefaults());

try {
    kernel_request_context_delete('_tenant_module_settings_cache');
    foreach ($writtenKeys as $key) {
        $db->prepare('DELETE FROM tenant_module_settings WHERE tenant_id = ? AND module_id = ? AND setting_key = ?')
            ->execute([$tenant, CAC_SITE_SETTINGS_MODULE, $key]);
    }

    foreach (cacSiteSettingsDefaults() as $key => $value) {
        $check(tenantWriteModuleSetting($db, $tenant, CAC_SITE_SETTINGS_MODULE, $key, $value), "fixture writes {$key}");
    }
    kernel_request_context_delete('_tenant_module_settings_cache');
    $current = cacSiteSettingsCurrent($tenant, $db);
    $check($current === cacSiteSettingsDefaults(), 'current read returns the stored set');

    // Corrupt stored values must fall back to the documented default, never leak.
    tenantWriteModuleSetting($db, $tenant, CAC_SITE_SETTINGS_MODULE, CAC_SITE_SETTING_PUBLIC_SINGLE, 'maybe');
    tenantWriteModuleSetting($db, $tenant, CAC_SITE_SETTINGS_MODULE, CAC_SITE_SETTING_ARCHIVE_PAGE_SIZE, '999');
    tenantWriteModuleSetting($db, $tenant, CAC_SITE_SETTINGS_MODULE, CAC_SITE_SETTING_ARCHIVE_SORT, 'sideways');
    kernel_request_context_delete('_tenant_module_settings_cache');
    $sanitized = cacSiteSettingsCurrent($tenant, $db);
    $check($sanitized[CAC_SITE_SETTING_PUBLIC_SINGLE] === 'enabled'
        && $sanitized[CAC_SITE_SETTING_ARCHIVE_PAGE_SIZE] === '12'
        && $sanitized[CAC_SITE_SETTING_ARCHIVE_SORT] === 'newest',
        'invalid legacy storage fails closed to the documented defaults');
} finally {
    foreach ($writtenKeys as $key) {
        $db->prepare('DELETE FROM tenant_module_settings WHERE tenant_id = ? AND module_id = ? AND setting_key = ?')
            ->execute([$tenant, CAC_SITE_SETTINGS_MODULE, $key]);
    }
    kernel_request_context_delete('_tenant_module_settings_cache');
    app()->tenant()->setTenantId($originalTenant);
    kernel_request_context_set('tenant_id', $originalTenant);
}

echo "site settings behaviour: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
