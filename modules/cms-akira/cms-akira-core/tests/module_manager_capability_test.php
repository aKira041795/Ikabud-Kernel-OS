<?php

/** CMS Akira governed module-manager contract (isolated stores only). */

declare(strict_types=1);

use Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry;
use Ikabud\Kernel\Services\ModuleInstallService;

$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = 'localhost';
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

$policyDb = app()->db();
$policyVersion = random_int(900000, 999999);
try {
    (new CapabilityAuthorizationRegistry($policyDb))->seedPolicy([[
        'policy_version' => $policyVersion, 'capability_id' => 'akira.module.list@1', 'capability_version' => '1',
        'provider' => 'cms-akira-core', 'caller_module' => 'cms-akira-shell',
        'allowed_roles' => 'admin,administrator,superadmin', 'provider_activation_required' => true,
        'requires_protocol' => 'v1', 'is_active' => true,
    ]]);
    $policies = new CapabilityAuthorizationRegistry($policyDb);
    $base = [
        'capability_id' => 'akira.module.list@1', 'capability_version' => '1',
        'provider' => 'cms-akira-core', 'caller_module' => 'cms-akira-shell',
        'provider_activation' => true, 'dispatch_protocol' => 'v1', 'policy_version' => $policyVersion, 'tenant_id' => '985001',
    ];
    $denied = $policies->authorize($base + ['actor_role' => 'editor']);
    $allowed = $policies->authorize($base + ['actor_role' => 'admin']);
    $check(($denied['allowed'] ?? true) === false, 'module manager capability fails closed for an unauthorized editor');
    $check(($allowed['allowed'] ?? false) === true, 'module manager capability authorizes the declared administrator role');

    $control = new PDO('sqlite::memory:');
    $tenant = new PDO('sqlite::memory:');
    foreach ([$control, $tenant] as $db) {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    $control->exec('CREATE TABLE kernel_tenants (id INTEGER PRIMARY KEY, status TEXT, entry_module_id TEXT)');
    $control->exec('CREATE TABLE kernel_module_install_generations (id INTEGER PRIMARY KEY, tenant_id INTEGER, selection_id TEXT, status TEXT)');
    $control->exec('CREATE TABLE kernel_module_install_steps (install_generation_id INTEGER, module_id TEXT)');
    $control->exec("INSERT INTO kernel_tenants VALUES (985001, 'active', 'cms-akira-shell')");
    $tenant->exec('CREATE TABLE tenant_module_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, module_id TEXT NOT NULL, setting_key TEXT NOT NULL, setting_value TEXT, created_at TEXT, updated_at TEXT, UNIQUE(tenant_id,module_id,setting_key))');
    tenantSetModuleActivationState($tenant, 985001, ['cms-akira-core', 'cms-akira-shell'], true);
    $modules = [
        'cms-akira-core' => ['id' => 'cms-akira-core', 'name' => 'Core', 'suite' => 'cms-akira', 'kind' => 'product-core', 'depends' => []],
        'cms-akira-shell' => ['id' => 'cms-akira-shell', 'name' => 'Shell', 'suite' => 'cms-akira', 'kind' => 'standalone-application', 'depends' => ['cms-akira-core']],
    ];
    $service = new ModuleInstallService($control, static fn (int $id): ?PDO => $id === 985001 ? $tenant : null, static fn (): array => $modules);
    $rows = array_column($service->suiteState(985001, 'cms-akira'), null, 'id');
    $stored = $tenant->query("SELECT setting_value FROM tenant_module_settings WHERE tenant_id = 985001 AND module_id = 'cms-akira-core' AND setting_key = '_module_enabled'")->fetchColumn();
    $check(($rows['cms-akira-core']['enabled'] ?? null) === json_decode((string)$stored, true), 'authorized read state equals the real tenant_module_settings value');
    $check((cms_akira_core_capability_handlers()['akira.module.list@1'] ?? '') === 'cac_cap_akira_module_list_1', 'real-state reader is exported as akira.module.list@1');

    $source = (string)file_get_contents($root . '/kernel/Services/ModuleInstallService.php');
    $migrationAt = strpos($source, '($this->migrationRunner)');
    $activationAt = strpos($source, '$this->activationWrite', $migrationAt === false ? 0 : $migrationAt);
    $check($migrationAt !== false && $activationAt !== false && $migrationAt < $activationAt
        && str_contains($source, 'migration callbacks execute outside this scope.'),
        'migration callbacks remain outside the narrow Kernel escalation scope');
} finally {
    $delete = $policyDb->prepare("DELETE FROM capability_authorization_policies WHERE policy_version = :version AND capability_id = 'akira.module.list@1' AND provider = 'cms-akira-core'");
    $delete->execute([':version' => $policyVersion]);
}

echo "module manager capability: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
