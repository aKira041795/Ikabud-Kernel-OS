<?php

declare(strict_types=1);

/** Prove: activating the module for a synthetic test tenant satisfies the gate. */

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';
require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/src/helpers/module-manager.php';

$tenant = 994701;
app()->tenant()->setTenantId($tenant);
kernel_request_context_set('tenant_id', $tenant);

echo 'before: isActive=' . var_export(moduleIsActive('cms-akira-builder'), true) . PHP_EOL;

$written = saveTenantModuleSettingsForTenant('cms-akira-builder', $tenant, ['_module_enabled' => true]);
echo 'write ok=' . var_export($written, true) . PHP_EOL;
echo 'settings now=' . json_encode(readTenantModuleSettingsForTenant('cms-akira-builder', $tenant)) . PHP_EOL;
echo 'after: isActive=' . var_export(moduleIsActive('cms-akira-builder'), true) . PHP_EOL;

// Clean up so the probe is idempotent.
saveTenantModuleSettingsForTenant('cms-akira-builder', $tenant, ['_module_enabled' => false]);
echo 'cleanup: isActive=' . var_export(moduleIsActive('cms-akira-builder'), true) . PHP_EOL;
