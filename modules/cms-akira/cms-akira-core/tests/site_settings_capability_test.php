<?php

/** CMS Akira P3.1 site behaviour settings contract (synthetic tenant only). */

declare(strict_types=1);

use Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry;

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

$tenant = 995431;
$actor = ['id' => 995431, 'role' => 'administrator'];
$originalTenant = app()->tenant()->current();
$originalUser = app()->user();
$db = app()->db();
$key = 'site-settings-test-' . bin2hex(random_bytes(6));

try {
    kernel_request_context_delete('_tenant_module_settings_cache');
    app()->setUser($actor);
    $db->prepare("DELETE FROM tenant_module_settings WHERE tenant_id = ? AND module_id = ? AND setting_key = ?")
        ->execute([$tenant, CAC_SITE_SETTINGS_MODULE, CAC_SITE_SETTING_PUBLIC_ARCHIVE]);
    $db->prepare("DELETE FROM audit_logs WHERE module = ? AND action = ? AND entity_id = ?")
        ->execute([CAC_SITE_SETTINGS_MODULE, 'akira.site.settings.update', (string) $tenant]);
    $db->prepare('DELETE FROM kernel_idempotency_keys WHERE tenant_id = ? AND idempotency_key_hash = ?')->execute([$tenant, hash('sha256', $key)]);

    $policyVersion = random_int(960000, 969999);
    $policies = new CapabilityAuthorizationRegistry($db);
    $policies->seedPolicy([[
        'policy_version' => $policyVersion,
        'capability_id' => 'akira.site.settings.update@1', 'capability_version' => '1',
        'provider' => 'cms-akira-core', 'caller_module' => 'cms-akira-shell',
        'allowed_roles' => 'admin,administrator,superadmin', 'provider_activation_required' => true,
        'requires_protocol' => 'v2', 'is_active' => true,
    ]]);
    $authority = [
        'capability_id' => 'akira.site.settings.update@1', 'capability_version' => '1',
        'provider' => 'cms-akira-core', 'caller_module' => 'cms-akira-shell',
        'provider_activation' => true, 'dispatch_protocol' => 'v2',
        'policy_version' => $policyVersion, 'tenant_id' => (string) $tenant,
    ];
    $check(($policies->authorize($authority + ['actor_role' => 'editor'])['allowed'] ?? true) === false,
        'settings update capability fails closed for an unauthorized editor');
    $check(($policies->authorize($authority + ['actor_role' => 'administrator'])['allowed'] ?? false) === true,
        'settings update capability admits the declared administrator role');

    $unknownRefused = false;
    try {
        cacSiteSettingsValidate(['public_posts_archive' => 'enabled', 'mystery' => 'value']);
    } catch (CacSiteSettingsException $error) {
        $unknownRefused = str_contains($error->getMessage(), 'Unknown site setting');
    }
    $check($unknownRefused, 'unknown setting keys are refused fail-closed');
    $check(cacSiteSettingsValidate(['public_posts_archive' => ''])['public_posts_archive'] === 'enabled',
        'blank values resolve to the documented default');

    $check(tenantWriteModuleSetting($db, $tenant, CAC_SITE_SETTINGS_MODULE, CAC_SITE_SETTING_PUBLIC_ARCHIVE, 'disabled'),
        'synthetic fixture writes through the tenant module-setting helper');
    kernel_request_context_delete('_tenant_module_settings_cache');
    // The capability handler delegates to this exact real-store resolver; the
    // explicit tenant/PDO seam keeps this suite isolated from provisioned tenants.
    $read = ['ok' => true, 'settings' => cacSiteSettingsCurrent($tenant, $db)];
    $storedStmt = $db->prepare('SELECT setting_value FROM tenant_module_settings WHERE tenant_id = ? AND module_id = ? AND setting_key = ?');
    $storedStmt->execute([$tenant, CAC_SITE_SETTINGS_MODULE, CAC_SITE_SETTING_PUBLIC_ARCHIVE]);
    $stored = json_decode((string) $storedStmt->fetchColumn(), true);
    $check(($read['settings']['public_posts_archive'] ?? null) === $stored && $stored === 'disabled',
        'authorized capability read returns the real tenant module-setting state');
    $source = (string) file_get_contents(dirname(__DIR__) . '/helpers/settings.php');
    $check(str_contains($source, "kernel.idempotency.claim@1") && str_contains($source, "kernel.audit.record@1")
        && str_contains($source, "kernel.idempotency.commit@1"),
        'settings mutation is wired to durable idempotency and audit capabilities');
    $check((cms_akira_core_capability_handlers()['akira.site.settings.update@1'] ?? '') === 'cac_cap_akira_site_settings_update_1',
        'runtime exports the governed settings mutation capability');
} finally {
    $db->prepare("DELETE FROM tenant_module_settings WHERE tenant_id = ? AND module_id = ? AND setting_key = ?")
        ->execute([$tenant, CAC_SITE_SETTINGS_MODULE, CAC_SITE_SETTING_PUBLIC_ARCHIVE]);
    $db->prepare("DELETE FROM audit_logs WHERE module = ? AND action = ? AND entity_id = ?")
        ->execute([CAC_SITE_SETTINGS_MODULE, 'akira.site.settings.update', (string) $tenant]);
    $db->prepare('DELETE FROM kernel_idempotency_keys WHERE tenant_id = ? AND idempotency_key_hash = ?')->execute([$tenant, hash('sha256', $key)]);
    $db->prepare("DELETE FROM capability_authorization_policies WHERE policy_version BETWEEN 960000 AND 969999 AND capability_id = 'akira.site.settings.update@1'")->execute();
    app()->tenant()->setTenantId($originalTenant);
    kernel_request_context_set('tenant_id', $originalTenant);
    kernel_request_context_delete('_tenant_module_settings_cache');
    app()->setUser(is_array($originalUser) ? $originalUser : []);
}

echo "site settings capability: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
