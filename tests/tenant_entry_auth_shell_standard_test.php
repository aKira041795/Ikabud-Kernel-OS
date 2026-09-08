<?php

declare(strict_types=1);

$configuredMultiTenant = $_ENV['APP_MULTI_TENANT_ENABLED'] ?? null;
unset($_ENV['APP_MULTI_TENANT_ENABLED']);
$defaultConfig = require __DIR__ . '/../config/app.php';
if ($configuredMultiTenant !== null) {
    $_ENV['APP_MULTI_TENANT_ENABLED'] = $configuredMultiTenant;
}

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/http/core-routes.php';
require_once __DIR__ . '/../src/http/page-handlers.php';

$failures = [];
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . "\n";
    if (!$ok) {
        $failures[] = $label;
    }
};
$urls = static fn (array $items): array => array_column($items, 'url');

$check('multi-tenant mode defaults to enabled', ($defaultConfig['multi_tenant']['enabled'] ?? false) === true);

$previousTenant = app()->tenant()->current();
$previousUser = app()->user();
$previousTenantHost = $_SERVER['IK_TENANT_HOST'] ?? null;
$previousEntry = $_SERVER['IK_ENTRY_MODULE_ID'] ?? null;
$admin = ['id' => 54, 'role' => 'admin', 'source' => 'kernel', 'username' => 'tenant-admin'];

app()->tenant()->setTenantId(54);
app()->setUser($admin);
$_SERVER['IK_TENANT_HOST'] = '1';
$_SERVER['IK_ENTRY_MODULE_ID'] = 'cms-akira-shell';

$tenantHome = kernelResolveAuthenticatedHomeRedirect($admin, true);
$check('tenant admin home is the entry module shell', $tenantHome === '/cms-akira-shell');
$check('tenant home does not resolve back to root', $tenantHome !== '/');
$check('shell accepts the Kernel-authenticated admin on its tenant host', moduleIsCurrentTenantEntrySurface('cms-akira-shell'));
$tenantRoutes = loadModuleRoutes(kernelCoreRoutes());
$check('tenant shell dashboard route is dispatchable', ($tenantRoutes['GET']['/cms-akira-shell'] ?? null) === 'cms-akira-shell:akiraShellDashboard');
$shellPage = akiraShellPage('CMS Akira Dashboard', '');
$check('tenant shell renders its administration nav', str_contains($shellPage, '/cms-akira-shell/posts') && str_contains($shellPage, '/cms-akira-shell/compositions') && str_contains($shellPage, '/cms-akira-shell/health'));
$tenantNav = $urls(getModuleNavItems(null, $admin));
$check('tenant admin nav includes Akira dashboard', in_array('/cms-akira-shell', $tenantNav, true));
$check('tenant admin nav excludes kernel platform', !in_array('/admin/platform', $tenantNav, true));
$check('tenant admin nav excludes kernel users and tenants', !in_array('/admin/users', $tenantNav, true) && !in_array('/admin/tenants', $tenantNav, true));
$loginContext = kernelResolveEntryModuleLoginContext();
$check('tenant login resolves the entry module template', ($loginContext['login_template'] ?? '') === 'modules/cms-akira-shell/pages/login.disyl');
$check('tenant login uses CMS Akira branding', ($loginContext['app_name'] ?? '') === 'CMS Akira');

unset($_SERVER['IK_TENANT_HOST'], $_SERVER['IK_ENTRY_MODULE_ID']);
app()->tenant()->setTenantId(null);
$check('control-plane admin home remains platform', kernelResolveAuthenticatedHomeRedirect($admin, true) === '/admin/platform');
$check('control-plane host does not treat the shell as its entry surface', !moduleIsCurrentTenantEntrySurface('cms-akira-shell'));
$controlNav = $urls(getModuleNavItems(null, $admin));
$check('control-plane admin nav remains kernel-owned', in_array('/admin/platform', $controlNav, true) && in_array('/admin/tenants', $controlNav, true));
$check('control-plane login remains the kernel template', !isset(kernelResolveEntryModuleLoginContext()['login_template']));

app()->tenant()->setTenantId($previousTenant);
app()->setUser(is_array($previousUser) ? $previousUser : []);
if ($previousTenantHost !== null) {
    $_SERVER['IK_TENANT_HOST'] = $previousTenantHost;
}
if ($previousEntry !== null) {
    $_SERVER['IK_ENTRY_MODULE_ID'] = $previousEntry;
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
