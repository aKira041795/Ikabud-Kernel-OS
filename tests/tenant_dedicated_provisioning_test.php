<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$handlers = (string)file_get_contents($root . '/src/http/admin-handlers.php');
$routes = (string)file_get_contents($root . '/src/http/core-routes.php');
$ui = (string)file_get_contents($root . '/templates/pages/admin-tenants.disyl');
$provisioner = (string)file_get_contents($root . '/kernel/Services/TenantProvisioner.php');

$failures = 0;
$check = static function (bool $condition, string $label) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
};

$createStart = strpos($handlers, "function kernelHandleApiTenantCreate(): void");
$createEnd = strpos($handlers, "function kernelHandleApiTenantEntryModuleSet(): void");
$create = substr($handlers, (int)$createStart, (int)$createEnd - (int)$createStart);
$check(!str_contains($create, "\$_ENV['DB_DATABASE']"), 'tenant create never defaults to the kernel database');
$check(!str_contains($create, 'kernelTenantScopedMigrationSync'), 'tenant create runs no migrations');
$check(str_contains($create, 'tenantRejectBaseDbConnection'), 'optional create DB is isolation guarded');

$dbStart = strpos($handlers, "function kernelHandleApiTenantDbUpsert(): void");
$dbEnd = strpos($handlers, "function kernelTenantProvisionSelection");
$dbUpsert = substr($handlers, (int)$dbStart, (int)$dbEnd - (int)$dbStart);
$check(str_contains($dbUpsert, 'tenantRejectBaseDbConnection'), 'DB upsert rejects the kernel database');
$check(!str_contains($dbUpsert, 'kernelTenantScopedMigrationSync'), 'DB upsert defers migrations to Provision');

$check(str_contains($routes, "'/api/v1/admin/tenants/provision' => 'apiTenantProvision'"), 'provision API is routed');
$check(str_contains($handlers, 'new \\Ikabud\\Kernel\\Services\\ModuleInstallService'), 'provision delegates module installation');
$check(str_contains($handlers, 'seedInstalledAdmin'), 'provision delegates idempotent admin seeding');
$check(str_contains($provisioner, 'public function seedInstalledAdmin'), 'provisioner exposes its existing manifest-aware seed path');
$check(str_contains($ui, '>Provision</button>') && str_contains($ui, "'/api/v1/admin/tenants/provision'"), 'tenant UI offers Provision action');

exit($failures === 0 ? 0 : 1);
