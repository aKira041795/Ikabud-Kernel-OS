<?php

declare(strict_types=1);

use Ikabud\Kernel\Services\ModuleInstallService;

/** The Kernel service is the sole trusted writer for install-control state. */
function cacAkiraModuleInstaller(): ModuleInstallService
{
    return new ModuleInstallService(app()->controlDb());
}

/** @return array<string,mixed> */
function cac_cap_akira_module_list_1(mixed $payload): array
{
    return [
        'ok' => true,
        'tenant_id' => cacPostTenantId(),
        'rows' => cacAkiraModuleInstaller()->suiteState(cacPostTenantId(), 'cms-akira'),
    ];
}

/** @return array<string,mixed> */
function cac_cap_akira_module_manage_1(mixed $payload): array
{
    if (!is_array($payload)) {
        throw new CacGovernanceException('payload must be an object.');
    }
    $actor = cacGovernanceActor();
    $tenantId = cacPostTenantId();
    $moduleId = trim((string)($payload['module_id'] ?? ''));
    $action = trim((string)($payload['action'] ?? ''));
    if ($moduleId === '' || !in_array($action, ['install', 'enable', 'disable'], true)) {
        throw new CacGovernanceException('A module and valid action are required.');
    }

    $installer = cacAkiraModuleInstaller();
    $beforeRows = $installer->suiteState($tenantId, 'cms-akira');
    $before = null;
    foreach ($beforeRows as $row) {
        if (($row['id'] ?? '') === $moduleId) {
            $before = $row;
            break;
        }
    }
    if (!is_array($before)) {
        throw new CacGovernanceException('Akira module not found.', 404);
    }
    if ($action !== 'install' && ($before['installed'] ?? false) !== true) {
        throw new CacGovernanceException('The module must be installed before its activation can change.', 409);
    }

    if ($action === 'install') {
        $key = trim((string)($payload['idempotency_key'] ?? ''));
        $generation = $key !== ''
            ? 'akira-ui-' . substr(hash('sha256', $tenantId . ':' . $moduleId . ':' . $key), 0, 32)
            : '';
        $result = $installer->install($tenantId, $moduleId, $generation === '' ? [] : ['generation' => $generation]);
    } else {
        $result = $installer->setEnabled($tenantId, $moduleId, $action === 'enable');
    }
    if (($result['ok'] ?? false) !== true) {
        throw new CacGovernanceException((string)($result['error'] ?? 'Module operation failed.'), 422);
    }

    $afterRows = $installer->suiteState($tenantId, 'cms-akira');
    $after = null;
    foreach ($afterRows as $row) {
        if (($row['id'] ?? '') === $moduleId) {
            $after = $row;
            break;
        }
    }
    $audit = app()->cap()->call('kernel.audit.record@1', [
        'module' => 'cms-akira-core',
        'action' => 'akira.module.' . $action,
        'entity_type' => 'module_activation',
        'entity_id' => $moduleId,
        'old_data' => $before,
        'new_data' => ['tenant_id' => $tenantId] + (is_array($after) ? $after : []),
    ], ['caller' => ['module' => 'cms-akira-core', 'user' => $actor], 'mode' => 'first']);
    if (!is_array($audit) || ($audit['ok'] ?? false) !== true) {
        throw new RuntimeException('Durable module-management audit failed.');
    }

    return $result + ['module' => $after];
}
