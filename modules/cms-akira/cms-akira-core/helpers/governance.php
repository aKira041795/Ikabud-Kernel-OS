<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/src/helpers/kernel-users-admin.php';

const CAC_AKIRA_ROLES = ['contributor', 'author', 'editor', 'admin', 'administrator', 'superadmin', 'manager', 'viewer'];
const CAC_AKIRA_ADMIN_ROLES = ['admin', 'administrator', 'superadmin'];

/**
 * The canonical administrative tier as a policy CSV.
 *
 * Every admin-tier policy row must use this. Seeding the single `admin` role instead
 * silently excludes `administrator` and `superadmin` -- including a kernel superadmin
 * -- from capabilities they are meant to own, and leaves a surface refusing a role it
 * rendered itself. That inconsistency was confirmed live: an `administrator` was
 * refused by every builder capability with "Administrator role required."
 */
function cacAkiraAdminRoleCsv(): string
{
    return implode(',', CAC_AKIRA_ADMIN_ROLES);
}

final class CacGovernanceException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}

/** @return array{id:int,role:string} */
function cacGovernanceActor(): array
{
    $actor = app()->user();
    if (!is_array($actor) || (int)($actor['id'] ?? $actor['sub'] ?? 0) <= 0) {
        throw new CacGovernanceException('Authentication required.', 401);
    }
    return ['id' => (int)($actor['id'] ?? $actor['sub']), 'role' => (string)($actor['role'] ?? '')];
}

/** @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function cacGovernanceMutate(string $operation, array $payload, callable $write): array
{
    $actor = cacGovernanceActor();
    $tenantId = cacPostTenantId();
    if (array_key_exists('tenant_id', $payload)) {
        throw new CacGovernanceException('tenant_id is supplied by kernel context.');
    }
    $key = trim((string)($payload['idempotency_key'] ?? ''));
    if ($key === '' || strlen($key) > 255) {
        throw new CacGovernanceException('A valid idempotency_key is required.');
    }
    $input = $payload;
    unset($input['idempotency_key']);
    $hash = app()->cap()->call('kernel.idempotency.hash@1', ['payload' => ['operation' => $operation, 'input' => $input]], [
        'caller' => ['module' => 'cms-akira-core', 'user' => $actor], 'mode' => 'first',
    ]);
    if (!is_string($hash)) {
        throw new CacGovernanceException('Idempotency hashing unavailable.', 503);
    }
    $db = app()->db();
    $claimed = false;
    try {
        $db->beginTransaction();
        $claim = app()->cap()->call('kernel.idempotency.claim@1', ['key' => $key, 'tenant_id' => $tenantId, 'payload_hash' => $hash, 'db' => $db], [
            'caller' => ['module' => 'cms-akira-core', 'user' => $actor], 'mode' => 'first',
        ]);
        $status = is_array($claim) ? (string)($claim['status'] ?? '') : '';
        if ($status === 'duplicate') {
            $db->rollBack();
            return is_array($claim['outcome'] ?? null) ? $claim['outcome'] : ['ok' => true, 'replayed' => true];
        }
        if ($status !== 'new') {
            $db->rollBack();
            throw new CacGovernanceException($status === 'conflict' ? 'Idempotency key payload conflict.' : 'Mutation is already processing.', $status === 'conflict' ? 409 : 425);
        }
        $claimed = true;
        $change = $write($db, $actor, $tenantId);
        $audit = app()->cap()->call('kernel.audit.record@1', [
            'module' => 'cms-akira-core', 'action' => $operation, 'entity_type' => (string)$change['entity_type'],
            'entity_id' => (string)$change['entity_id'], 'old_data' => $change['old_data'] ?? null,
            'new_data' => ['tenant_id' => $tenantId] + (array)($change['new_data'] ?? []),
        ], ['caller' => ['module' => 'cms-akira-core', 'user' => $actor], 'mode' => 'first']);
        if (!is_array($audit) || ($audit['ok'] ?? false) !== true) {
            throw new RuntimeException('Durable governance audit failed.');
        }
        $outcome = ['ok' => true, 'operation' => $operation] + (array)($change['outcome'] ?? []);
        $commit = app()->cap()->call('kernel.idempotency.commit@1', ['key' => $key, 'tenant_id' => $tenantId, 'outcome' => $outcome, 'db' => $db], [
            'caller' => ['module' => 'cms-akira-core', 'user' => $actor], 'mode' => 'first',
        ]);
        if ($commit !== true) {
            throw new RuntimeException('Idempotency outcome commit failed.');
        }
        $db->commit();
        return $outcome;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($claimed) {
            try {
                app()->cap()->call('kernel.idempotency.release@1', ['key' => $key, 'tenant_id' => $tenantId, 'db' => $db], [
                    'caller' => ['module' => 'cms-akira-core', 'user' => $actor], 'mode' => 'first',
                ]);
            } catch (Throwable) {
            }
        }
        throw $error;
    }
}

/** @return array<string,mixed> */
function cac_cap_akira_policy_list_1(mixed $payload): array
{
    $resolver = \Ikabud\Kernel\Capabilities\AuthorityScopeResolver::forApplication();
    $scope = $resolver->resolve(\Ikabud\Kernel\Capabilities\AuthorityScopeResolver::WEB, [
        'actor' => app()->user(),
    ]);
    $registry = new \Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry(
        null,
        $scope,
        $resolver,
        $resolver->failureReason() ?? 'missing_tenant_authority_scope'
    );
    $rows = $registry->activePolicyRows();
    $rows = array_values(array_filter(
        $rows,
        static fn (array $row): bool => str_starts_with((string)$row['capability_id'], 'akira.')
    ));
    return ['ok' => true, 'rows' => $rows];
}

/** @return array<string,mixed> */
function cac_cap_akira_policy_set_roles_1(mixed $payload): array
{
    if (!is_array($payload)) {
        throw new CacGovernanceException('payload must be an object.');
    }
    $roles = array_values(array_unique(array_filter($payload['allowed_roles'] ?? [], static fn (mixed $role): bool => is_string($role) && in_array($role, CAC_AKIRA_ROLES, true))));
    if ($roles === [] || count($roles) !== count((array)($payload['allowed_roles'] ?? [])) || array_intersect($roles, CAC_AKIRA_ADMIN_ROLES) === []) {
        throw new CacGovernanceException('Roles must be a non-empty known set containing an administrator role.');
    }
    foreach (['capability_id', 'capability_version', 'provider'] as $field) {
        if (trim((string)($payload[$field] ?? '')) === '') {
            throw new CacGovernanceException("{$field} is required.");
        }
    }
    if (!array_key_exists('caller_module', $payload) || !is_string($payload['caller_module'])) {
        throw new CacGovernanceException('caller_module must be supplied as a string.');
    }
    $callerModule = trim($payload['caller_module']); // Empty is the form representation of SQL NULL.
    return cacGovernanceMutate('akira.policy.set_roles', $payload, static function (PDO $db, array $actor, int $tenantId) use ($payload, $roles, $callerModule): array {
        $registry = new \Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry($db);
        $version = $registry->replaceActiveRowRoles((string)$payload['capability_id'], (string)$payload['capability_version'], (string)$payload['provider'], $callerModule, $roles);
        return ['entity_type' => 'capability_policy', 'entity_id' => (string)$payload['capability_id'], 'old_data' => null,
            'new_data' => ['policy_version' => $version, 'allowed_roles' => $roles], 'outcome' => ['policy_version' => $version]];
    });
}

/** @return array<string,mixed> */
function cac_cap_akira_user_list_1(mixed $payload): array
{
    return ['ok' => true, 'rows' => kernelUsersList(cacPostTenantId())];
}

/** @return array<string,mixed> */
function cacUserMutate(string $operation, mixed $payload): array
{
    if (!is_array($payload)) {
        throw new CacGovernanceException('payload must be an object.');
    }
    $userId = (int)($payload['user_id'] ?? 0);
    if ($userId <= 0) {
        throw new CacGovernanceException('A user id is required.');
    }
    return cacGovernanceMutate('akira.user.' . $operation, $payload, static function (PDO $db, array $actor, int $tenantId) use ($payload, $userId, $operation): array {
        $old = kernelUserForGovernanceUpdate($tenantId, $userId);
        if (!is_array($old)) {
            throw new CacGovernanceException('User not found.', 404);
        }
        $role = (string)$old['role'];
        $active = (int)$old['is_active'];
        if ($operation === 'update_role') {
            $role = trim((string)($payload['role'] ?? ''));
            if (!in_array($role, CAC_AKIRA_ROLES, true)) {
                throw new CacGovernanceException('role must be a known Akira role.');
            }
            if ($userId === $actor['id'] && in_array((string)$old['role'], CAC_AKIRA_ADMIN_ROLES, true) && !in_array($role, CAC_AKIRA_ADMIN_ROLES, true)) {
                throw new CacGovernanceException('You cannot demote yourself.');
            }
        } else {
            $active = filter_var($payload['is_active'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($active === null) {
                throw new CacGovernanceException('is_active must be boolean.');
            }
            $active = $active ? 1 : 0;
            if ($userId === $actor['id'] && $active === 0) {
                throw new CacGovernanceException('You cannot deactivate yourself.');
            }
        }
        $removesPrivileged = in_array((string)$old['role'], ['administrator', 'superadmin'], true) && (int)$old['is_active'] === 1
            && ($active === 0 || !in_array($role, ['administrator', 'superadmin'], true));
        if ($removesPrivileged) {
            $count = kernelActivePrivilegedUserCountForUpdate($tenantId);
            if ($count <= 1) {
                throw new CacGovernanceException('The last active administrator or superadmin cannot be changed.');
            }
        }
        if ($operation === 'update_role') {
            kernelUserSetRole($tenantId, $userId, $role);
        } else {
            kernelUserSetActive($tenantId, $userId, $active === 1);
        }
        return ['entity_type' => 'user', 'entity_id' => (string)$userId, 'old_data' => $old,
            'new_data' => ['role' => $role, 'is_active' => $active, 'token_version' => (int)$old['token_version'] + 1], 'outcome' => ['user_id' => $userId]];
    });
}

/** @return array<string,mixed> */
function cac_cap_akira_user_update_role_1(mixed $payload): array
{
    return cacUserMutate('update_role', $payload);
}
/** @return array<string,mixed> */
function cac_cap_akira_user_set_active_1(mixed $payload): array
{
    return cacUserMutate('set_active', $payload);
}
