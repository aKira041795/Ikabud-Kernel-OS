<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Capabilities;

use PDO;
use PDOException;
use Throwable;

final class CapabilityAuthorizationRegistry
{
    /** @var array<int, array<int, array<string, mixed>>> */
    private static array $policyCache = [];
    /** @var array<string, int|null> */
    private static array $activeVersionCache = [];

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    public static function invalidate(): void
    {
        self::$policyCache = [];
        self::$activeVersionCache = [];
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function authorize(array $ctx): array
    {
        $capabilityId = trim((string)($ctx['capability_id'] ?? ''));
        $capabilityVersion = trim((string)($ctx['capability_version'] ?? ''));
        $provider = trim((string)($ctx['provider'] ?? $ctx['explicit_provider'] ?? ''));
        $callerModule = trim((string)($ctx['caller_module'] ?? ''));
        $actorRole = trim((string)($ctx['actor_role'] ?? ''));
        $tenantId = trim((string)($ctx['tenant_id'] ?? ''));
        $providerActivation = (bool)($ctx['provider_activation'] ?? false);
        $dispatchProtocol = strtolower(trim((string)($ctx['dispatch_protocol'] ?? '')));
        $explicitProvider = trim((string)($ctx['explicit_provider'] ?? ''));
        $override = isset($ctx['policy_version']) && $ctx['policy_version'] !== '' ? (int)$ctx['policy_version'] : null;

        $result = [
            'allowed' => false,
            'policy_version' => null,
            'reason' => 'missing_policy_row',
            'capability_id' => $capabilityId,
            'capability_version' => $capabilityVersion,
            'provider' => $provider,
            'caller_module' => $callerModule,
            'actor_role' => $actorRole,
            'tenant_id' => $tenantId,
        ];

        try {
            if ($capabilityId === '') {
                return $this->audit(array_merge($result, ['reason' => 'missing_capability_id']), 'warning');
            }
            if ($capabilityVersion === '') {
                return $this->audit(array_merge($result, ['reason' => 'missing_capability_version']), 'warning');
            }
            if ($provider === '') {
                return $this->audit(array_merge($result, ['reason' => 'missing_provider']), 'warning');
            }
            if ($callerModule === '') {
                return $this->audit(array_merge($result, ['reason' => 'missing_caller_module']), 'warning');
            }
            if ($actorRole === '') {
                return $this->audit(array_merge($result, ['reason' => 'unknown_role']), 'warning');
            }
            if ($tenantId === '') {
                return $this->audit(array_merge($result, ['reason' => 'missing_tenant']), 'warning');
            }
            if ($explicitProvider !== '' && $explicitProvider !== $provider) {
                return $this->audit(array_merge($result, ['reason' => 'disabled_provider']), 'warning');
            }

            $policyVersion = $this->resolvePolicyVersion($override);
            $result['policy_version'] = $policyVersion;
            if ($policyVersion === null) {
                return $this->audit(array_merge($result, ['reason' => $override !== null ? 'inactive_policy_version_override' : 'missing_active_policy_version']), 'warning');
            }

            $rows = $this->rowsForVersion($policyVersion);
            $capabilityRows = [];
            $providerRows = [];
            $exactRow = null;
            foreach ($rows as $row) {
                if ((string)($row['capability_id'] ?? '') !== $capabilityId) {
                    continue;
                }
                $capabilityRows[] = $row;
                if ((string)($row['provider'] ?? '') === $provider) {
                    $providerRows[] = $row;
                    if ((string)($row['capability_version'] ?? '') === $capabilityVersion) {
                        $exactRow = $row;
                        break;
                    }
                }
            }

            if ($capabilityRows === []) {
                return $this->audit(array_merge($result, ['reason' => 'missing_policy_row']), 'warning');
            }
            if ($providerRows === []) {
                return $this->audit(array_merge($result, ['reason' => 'disabled_provider']), 'warning');
            }
            if (!is_array($exactRow)) {
                return $this->audit(array_merge($result, ['reason' => 'version_mismatch']), 'warning');
            }

            $grantState = strtolower(trim((string)($exactRow['grant_state'] ?? 'granted')));
            if ($grantState !== 'granted') {
                $reason = in_array($grantState, ['revoked', 'suspended'], true)
                    ? 'grant_' . $grantState
                    : 'grant_state_invalid';
                return $this->audit(array_merge($result, ['reason' => $reason]), 'warning');
            }

            // Protocol-v2 policy is a dispatch invariant, not informational metadata.
            // The bus supplies this value from trusted provider metadata/configuration.
            // Missing, legacy, and unknown dispatch protocols all fail closed for v2 rows.
            $requiredProtocol = strtolower(trim((string)($exactRow['requires_protocol'] ?? '')));
            if ($requiredProtocol === 'v2' && $dispatchProtocol !== 'v2') {
                return $this->audit(array_merge($result, ['reason' => 'protocol_mismatch']), 'warning');
            }

            $allowedCallers = $this->parseCsv($exactRow['caller_module'] ?? null);
            if ($allowedCallers !== [] && !in_array($callerModule, $allowedCallers, true)) {
                return $this->audit(array_merge($result, ['reason' => 'disabled_caller']), 'warning');
            }

            $allowedRoles = $this->parseAllowedRoles($exactRow['allowed_roles'] ?? null);
            if ($allowedRoles !== [] && !in_array($actorRole, $allowedRoles, true)) {
                return $this->audit(array_merge($result, ['reason' => 'role_not_allowed']), 'warning');
            }

            if ((int)($exactRow['provider_activation_required'] ?? 1) === 1 && !$providerActivation) {
                return $this->audit(array_merge($result, ['reason' => 'disabled_provider']), 'warning');
            }

            return $this->audit(array_merge($result, ['allowed' => true, 'reason' => 'allowed']), 'info');
        } catch (CapabilityAuthorizationRegistryUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new CapabilityAuthorizationRegistryUnavailableException('capability authorization registry unavailable: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Seed declarations only when an explicit tenant authority scope exists.
     * Helper loading without a tenant (CLI/cron/control-plane bootstrap) is a
     * deliberate no-op; it must never fall through to the ambient database.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public static function seedPolicyForCurrentScope(array $rows): void
    {
        if (!function_exists('app')) {
            self::logSeedDecision('skipped', ['reason' => 'application_unavailable']);
            return;
        }

        $application = app();
        $tenant = method_exists($application, 'tenant') ? $application->tenant() : null;
        $tenantId = is_object($tenant) && method_exists($tenant, 'current') ? (int)($tenant->current() ?? 0) : 0;
        if ($tenantId <= 0) {
            self::logSeedDecision('skipped', ['reason' => 'missing_explicit_tenant_scope']);
            return;
        }

        $db = method_exists($application, 'dbForTenant') ? $application->dbForTenant($tenantId) : null;
        if (!$db instanceof PDO) {
            self::logSeedDecision('skipped', ['reason' => 'tenant_authority_store_unavailable', 'tenant_id' => $tenantId]);
            return;
        }

        (new self($db))->seedPolicy($rows);
    }

    /**
     * Reconcile declarations for an explicitly selected authority store.
     * Existing non-granted rows are immutable. Existing granted rows may be
     * narrowed (or left unchanged), but any widening rejects the whole row.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public function seedPolicy(array $rows): void
    {
        try {
            $this->withKernelTableAccess(function () use ($rows): void {
                $db = $this->db();
                $select = $db->prepare(
                    'SELECT caller_module, allowed_roles, provider_activation_required, requires_protocol, grant_state '
                    . 'FROM capability_authorization_policies WHERE policy_version = :policy_version '
                    . 'AND capability_id = :capability_id AND capability_version = :capability_version AND provider = :provider'
                );
                $insert = $db->prepare(
                    'INSERT INTO capability_authorization_policies '
                    . '(policy_version, capability_id, capability_version, provider, caller_module, allowed_roles, provider_activation_required, requires_protocol, is_active, grant_state, updated_at) '
                    . "VALUES (:policy_version, :capability_id, :capability_version, :provider, :caller_module, :allowed_roles, :provider_activation_required, :requires_protocol, :is_active, 'granted', NOW())"
                );
                $update = $db->prepare(
                    'UPDATE capability_authorization_policies SET caller_module = :caller_module, allowed_roles = :allowed_roles, '
                    . 'provider_activation_required = :provider_activation_required, requires_protocol = :requires_protocol, updated_at = NOW() '
                    . 'WHERE policy_version = :policy_version AND capability_id = :capability_id '
                    . "AND capability_version = :capability_version AND provider = :provider AND grant_state = 'granted'"
                );

                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $declared = $this->normalizeSeedRow($row);
                    $key = array_intersect_key($declared, array_flip(['policy_version', 'capability_id', 'capability_version', 'provider']));
                    $select->execute($this->prefixParams($key));
                    $stored = $select->fetch(PDO::FETCH_ASSOC);
                    if (!is_array($stored)) {
                        $insert->execute($this->prefixParams($declared));
                        continue;
                    }
                    if ((string)$stored['grant_state'] !== 'granted') {
                        continue;
                    }

                    $widenedFields = $this->widenedFields($stored, $declared);
                    if ($widenedFields !== []) {
                        self::logSeedDecision('widening_refused', $key + [
                            'fields' => $widenedFields,
                            'stored' => $stored,
                            'declared' => $declared,
                            'actor' => 'system',
                            'reason' => 'operator_regrant_required',
                        ]);
                        continue;
                    }

                    $changed = $this->governedFieldsChanged($stored, $declared);
                    if (!$changed) {
                        continue;
                    }
                    $update->execute($this->prefixParams(array_diff_key($declared, ['is_active' => true])));
                    self::logSeedDecision('narrowing_applied', $key + [
                        'stored' => $stored,
                        'declared' => $declared,
                        'actor' => 'system',
                        'reason' => 'declaration_permission_narrowing',
                    ]);
                }
            });
            self::invalidate();
        } catch (Throwable $e) {
            throw new CapabilityAuthorizationRegistryUnavailableException('capability authorization registry seed failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Perform an explicit, audited grant-state transition. The audit capability
     * derives "who" from the authenticated kernel actor and records "when";
     * reason is mandatory and is persisted in the audit payload.
     */
    public function transitionGrantState(
        int $policyVersion,
        string $capabilityId,
        string $capabilityVersion,
        string $provider,
        string $state,
        string $reason
    ): void {
        $state = strtolower(trim($state));
        $reason = trim($reason);
        if (!in_array($state, ['granted', 'suspended', 'revoked'], true)) {
            throw new \InvalidArgumentException('Grant state must be granted, suspended, or revoked.');
        }
        if ($reason === '') {
            throw new \InvalidArgumentException('A grant-state transition requires a reason.');
        }

        $actor = function_exists('app') ? app()->user() : null;
        if (!is_array($actor) || (int)($actor['id'] ?? $actor['sub'] ?? 0) <= 0) {
            throw new \LogicException('An authenticated actor is required for a grant-state transition.');
        }

        $db = $this->db();
        $ownsTransaction = !$db->inTransaction();
        $savepoint = 'cap_policy_grant_state';
        try {
            if ($ownsTransaction) {
                $db->beginTransaction();
            } else {
                $db->exec('SAVEPOINT ' . $savepoint);
            }

            $oldState = $this->withKernelTableAccess(function () use ($db, $policyVersion, $capabilityId, $capabilityVersion, $provider): string {
                $stmt = $db->prepare(
                    'SELECT grant_state FROM capability_authorization_policies '
                    . 'WHERE policy_version = :policy_version AND capability_id = :capability_id '
                    . 'AND capability_version = :capability_version AND provider = :provider FOR UPDATE'
                );
                $stmt->execute([
                    ':policy_version' => $policyVersion,
                    ':capability_id' => $capabilityId,
                    ':capability_version' => $capabilityVersion,
                    ':provider' => $provider,
                ]);
                $found = $stmt->fetchColumn();
                if ($found === false) {
                    throw new \InvalidArgumentException('The selected policy grant does not exist.');
                }
                return (string)$found;
            });

            $this->withKernelTableAccess(function () use ($db, $policyVersion, $capabilityId, $capabilityVersion, $provider, $state): void {
                $stmt = $db->prepare(
                    'UPDATE capability_authorization_policies SET grant_state = :state, updated_at = NOW() '
                    . 'WHERE policy_version = :policy_version AND capability_id = :capability_id '
                    . 'AND capability_version = :capability_version AND provider = :provider'
                );
                $stmt->execute([
                    ':state' => $state,
                    ':policy_version' => $policyVersion,
                    ':capability_id' => $capabilityId,
                    ':capability_version' => $capabilityVersion,
                    ':provider' => $provider,
                ]);
            });

            $policyKey = implode('|', [$policyVersion, $capabilityId, $capabilityVersion, $provider]);
            $audit = app()->cap()->call('kernel.audit.record@1', [
                'module' => '_kernel',
                'action' => 'capability.policy.grant_' . $state,
                'entity_type' => 'capability_authorization_policy',
                'entity_id' => md5($policyKey),
                'old_data' => ['policy_key' => $policyKey, 'grant_state' => $oldState],
                'new_data' => ['policy_key' => $policyKey, 'grant_state' => $state, 'reason' => $reason],
                'reason' => $reason,
            ]);
            if (!is_array($audit) || ($audit['ok'] ?? false) !== true) {
                throw new \RuntimeException('The grant-state audit record could not be written.');
            }

            if ($ownsTransaction) {
                $db->commit();
            } else {
                $db->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            self::invalidate();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                if ($ownsTransaction) {
                    $db->rollBack();
                } else {
                    $db->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                }
            }
            throw $e;
        }
    }

    /** @return list<array<string, mixed>> */
    public function activePolicyRows(): array
    {
        $version = $this->resolvePolicyVersion();
        return $version === null ? [] : $this->rowsForVersion($version);
    }

    /**
     * Clone the complete active policy set into N+1 and change exactly one row.
     * The caller owns the surrounding transaction.
     * @param list<string> $allowedRoles
     */
    public function replaceActiveRowRoles(string $capabilityId, string $capabilityVersion, string $provider, string $callerModule, array $allowedRoles): int
    {
        $db = $this->db();
        if (!$db->inTransaction()) {
            throw new \LogicException('Replacing active policy roles requires an existing transaction.');
        }

        $rows = $this->activePolicyRows();
        if ($rows === []) {
            throw new CapabilityAuthorizationRegistryUnavailableException('active capability policy is unavailable');
        }
        $oldVersion = (int)$rows[0]['policy_version'];
        $nextVersion = $oldVersion + 1;
        $matched = false;
        foreach ($rows as &$row) {
            $row['policy_version'] = $nextVersion;
            $rowState = (string)($row['grant_state'] ?? 'granted');
            if ((string)$row['capability_id'] === $capabilityId
                && (string)$row['capability_version'] === $capabilityVersion
                && (string)$row['provider'] === $provider
                && (string)($row['caller_module'] ?? '') === $callerModule) {
                if ($rowState !== 'granted') {
                    throw new \InvalidArgumentException('A suspended or revoked policy declaration cannot be edited.');
                }
                $row['allowed_roles'] = implode(',', $allowedRoles);
                $matched = true;
            }
        }
        unset($row);
        if (!$matched) {
            throw new \InvalidArgumentException('The selected active policy row no longer exists.');
        }
        // Insert each clone exactly once with its final state. In particular,
        // suspended/revoked rows must never exist in N+1 as granted, even inside
        // the caller's transaction or during an audit-capability dispatch.
        $this->insertPolicyRowsWithFinalState($rows);
        $this->withKernelTableAccess(function () use ($oldVersion): void {
            $stmt = $this->db()->prepare('UPDATE capability_authorization_policies SET is_active = 0, updated_at = NOW() WHERE policy_version = :version');
            $stmt->execute([':version' => $oldVersion]);
        });
        self::invalidate();
        return $nextVersion;
    }

    public function hasPolicyFor(string $capabilityId, ?string $capabilityVersion = null, ?string $provider = null, ?int $policyVersion = null): bool
    {
        $version = $this->resolvePolicyVersion($policyVersion);
        if ($version === null) {
            return false;
        }

        foreach ($this->rowsForVersion($version) as $row) {
            if ((string)($row['capability_id'] ?? '') !== $capabilityId) {
                continue;
            }
            if ($capabilityVersion !== null && (string)($row['capability_version'] ?? '') !== $capabilityVersion) {
                continue;
            }
            if ($provider !== null && (string)($row['provider'] ?? '') !== $provider) {
                continue;
            }
            return true;
        }

        return false;
    }

    public function requiresProtocol(string $capabilityId, string $capabilityVersion, string $provider, ?int $policyVersion = null): ?string
    {
        $version = $this->resolvePolicyVersion($policyVersion);
        if ($version === null) {
            return null;
        }

        foreach ($this->rowsForVersion($version) as $row) {
            if ((string)($row['capability_id'] ?? '') === $capabilityId
                && (string)($row['capability_version'] ?? '') === $capabilityVersion
                && (string)($row['provider'] ?? '') === $provider) {
                $requiresProtocol = trim((string)($row['requires_protocol'] ?? ''));
                return $requiresProtocol !== '' ? $requiresProtocol : null;
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $rows */
    private function insertPolicyRowsWithFinalState(array $rows): void
    {
        $sql = 'INSERT INTO capability_authorization_policies '
            . '(policy_version, capability_id, capability_version, provider, caller_module, allowed_roles, provider_activation_required, requires_protocol, is_active, grant_state, updated_at) '
            . 'VALUES (:policy_version, :capability_id, :capability_version, :provider, :caller_module, :allowed_roles, :provider_activation_required, :requires_protocol, :is_active, :grant_state, NOW())';

        $this->withKernelTableAccess(function () use ($rows, $sql): void {
            $stmt = $this->db()->prepare($sql);
            foreach ($rows as $row) {
                $normalized = $this->normalizeSeedRow($row);
                $normalized['grant_state'] = in_array(($row['grant_state'] ?? ''), ['granted', 'suspended', 'revoked'], true)
                    ? (string)$row['grant_state']
                    : 'granted';
                $stmt->execute($this->prefixParams($normalized));
            }
        });
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private function normalizeSeedRow(array $row): array
    {
        return [
            'policy_version' => (int)($row['policy_version'] ?? 0),
            'capability_id' => trim((string)($row['capability_id'] ?? '')),
            'capability_version' => trim((string)($row['capability_version'] ?? '')),
            'provider' => trim((string)($row['provider'] ?? '')),
            'caller_module' => $this->nullableString($row['caller_module'] ?? null),
            'allowed_roles' => $this->nullableString($row['allowed_roles'] ?? null),
            'provider_activation_required' => !array_key_exists('provider_activation_required', $row) || (bool)$row['provider_activation_required'] ? 1 : 0,
            'requires_protocol' => strtolower(trim((string)($row['requires_protocol'] ?? 'v1'))),
            'is_active' => !array_key_exists('is_active', $row) || (bool)$row['is_active'] ? 1 : 0,
        ];
    }

    /** @param array<string, mixed> $values
     *  @return array<string, mixed>
     */
    private function prefixParams(array $values): array
    {
        $params = [];
        foreach ($values as $key => $value) {
            $params[':' . $key] = $value;
        }
        return $params;
    }

    /** @param array<string, mixed> $stored
     *  @param array<string, mixed> $declared
     *  @return list<string>
     */
    private function widenedFields(array $stored, array $declared): array
    {
        $widened = [];
        foreach (['allowed_roles', 'caller_module'] as $field) {
            if (!$this->declaredSetIsSubset($declared[$field] ?? null, $stored[$field] ?? null)) {
                $widened[] = $field;
            }
        }

        $storedProtocol = strtolower(trim((string)($stored['requires_protocol'] ?? 'v1')));
        $declaredProtocol = strtolower(trim((string)($declared['requires_protocol'] ?? 'v1')));
        $protocolRank = ['v1' => 0, 'v2' => 1];
        if ($storedProtocol !== $declaredProtocol
            && (!isset($protocolRank[$storedProtocol], $protocolRank[$declaredProtocol])
                || $protocolRank[$declaredProtocol] < $protocolRank[$storedProtocol])) {
            $widened[] = 'requires_protocol';
        }

        if ((int)($stored['provider_activation_required'] ?? 1) === 1
            && (int)($declared['provider_activation_required'] ?? 1) === 0) {
            $widened[] = 'provider_activation_required';
        }
        return $widened;
    }

    private function declaredSetIsSubset(mixed $declared, mixed $stored): bool
    {
        $declaredSet = $this->parseCsv($declared);
        $storedSet = $this->parseCsv($stored);
        // An empty allowlist means unrestricted. It is the widest possible set.
        if ($storedSet === []) {
            return true;
        }
        if ($declaredSet === []) {
            return false;
        }
        return array_diff($declaredSet, $storedSet) === [];
    }

    /** @param array<string, mixed> $stored
     *  @param array<string, mixed> $declared
     */
    private function governedFieldsChanged(array $stored, array $declared): bool
    {
        foreach (['allowed_roles', 'caller_module'] as $field) {
            $old = $this->parseCsv($stored[$field] ?? null);
            $new = $this->parseCsv($declared[$field] ?? null);
            sort($old);
            sort($new);
            if ($old !== $new) {
                return true;
            }
        }
        return strtolower(trim((string)($stored['requires_protocol'] ?? 'v1'))) !== (string)$declared['requires_protocol']
            || (int)($stored['provider_activation_required'] ?? 1) !== (int)$declared['provider_activation_required'];
    }

    /** @param array<string, mixed> $context */
    private static function logSeedDecision(string $decision, array $context): void
    {
        if (function_exists('write_log')) {
            write_log('capability.policy.seed.' . $decision, $decision === 'widening_refused' ? 'warning' : 'info', $context);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function rowsForVersion(int $policyVersion): array
    {
        if (isset(self::$policyCache[$policyVersion])) {
            return self::$policyCache[$policyVersion];
        }

        try {
            $rows = $this->withKernelTableAccess(function () use ($policyVersion): array {
                $stmt = $this->db()->prepare('SELECT policy_version, capability_id, capability_version, provider, caller_module, allowed_roles, provider_activation_required, requires_protocol, is_active, grant_state FROM capability_authorization_policies WHERE is_active = 1 AND policy_version = :policy_version ORDER BY capability_id ASC, capability_version ASC, provider ASC');
                $stmt->execute([':policy_version' => $policyVersion]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                return is_array($rows) ? $rows : [];
            });
            self::$policyCache[$policyVersion] = $rows;
            return self::$policyCache[$policyVersion];
        } catch (Throwable $e) {
            if ($this->isMissingTableError($e)) {
                self::$policyCache[$policyVersion] = [];
                return self::$policyCache[$policyVersion];
            }
            throw new CapabilityAuthorizationRegistryUnavailableException('capability authorization registry query failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function resolvePolicyVersion(?int $override = null): ?int
    {
        $cacheKey = $override === null ? 'active' : 'override:' . $override;
        if (array_key_exists($cacheKey, self::$activeVersionCache)) {
            return self::$activeVersionCache[$cacheKey];
        }

        try {
            $resolved = $this->withKernelTableAccess(function () use ($override): ?int {
                $db = $this->db();
                if ($override !== null) {
                    $stmt = $db->prepare('SELECT policy_version FROM capability_authorization_policies WHERE policy_version = :policy_version AND is_active = 1 ORDER BY id DESC LIMIT 1');
                    $stmt->execute([':policy_version' => $override]);
                    $found = $stmt->fetchColumn();
                    return $found === false ? null : (int)$found;
                }

                $stmt = $db->query('SELECT MAX(policy_version) FROM capability_authorization_policies WHERE is_active = 1');
                $value = $stmt === false ? false : $stmt->fetchColumn();
                return $value === false || $value === null ? null : (int)$value;
            });
            self::$activeVersionCache[$cacheKey] = $resolved;
            return self::$activeVersionCache[$cacheKey];
        } catch (Throwable $e) {
            if ($this->isMissingTableError($e)) {
                self::$activeVersionCache[$cacheKey] = null;
                return self::$activeVersionCache[$cacheKey];
            }
            throw new CapabilityAuthorizationRegistryUnavailableException('capability authorization registry version lookup failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Kernel services that read KERNEL-OWNED tables from within a module request MUST route those
     * reads through kernel escalation (KernelPDO::kernelEscalationEnter/Leave); never query a
     * kernel-owned table via app()->db() in an active module context, or the ModuleDB ownership gate
     * will deny it. This is the same contract moduleCatalogWithKernelDbEscalation() implements for
     * module-manager catalog reads.
     */
    private function withKernelTableAccess(callable $fn): mixed
    {
        $kernelPdoClass = '\\Ikabud\\Kernel\\Database\\KernelPDO';
        $canEscalate = class_exists($kernelPdoClass)
            && method_exists($kernelPdoClass, 'kernelEscalationEnter')
            && method_exists($kernelPdoClass, 'kernelEscalationLeave');

        if (!$canEscalate) {
            if (function_exists('write_log')) {
                write_log('CapabilityAuthorizationRegistry: kernel DB escalation unavailable; continuing without escalation', 'warning');
            }
            return $fn();
        }

        $kernelPdoClass::kernelEscalationEnter();
        try {
            return $fn();
        } finally {
            $kernelPdoClass::kernelEscalationLeave();
        }
    }

    private function isMissingTableError(Throwable $e): bool
    {
        if ($e instanceof PDOException) {
            if ((string)$e->getCode() === '42S02') {
                return true;
            }

            $errorInfo = $e->errorInfo;
            if (is_array($errorInfo) && isset($errorInfo[1]) && (int)$errorInfo[1] === 1146) {
                return true;
            }
        }

        $previous = $e->getPrevious();
        return $previous instanceof Throwable ? $this->isMissingTableError($previous) : false;
    }

    private function db(): PDO
    {
        if ($this->db instanceof PDO) {
            return $this->db;
        }

        if (function_exists('app')) {
            $db = app()->db();
            if ($db instanceof PDO) {
                return $db;
            }
        }

        throw new CapabilityAuthorizationRegistryUnavailableException('capability authorization registry database unavailable');
    }

    /** @return array<int, string> */
    private function parseAllowedRoles(mixed $value): array
    {
        return $this->parseCsv($value);
    }

    /** @return list<string> */
    private function parseCsv(mixed $value): array
    {
        $roles = array_filter(array_map(
            static fn (string $role): string => trim($role),
            explode(',', trim((string)$value))
        ), static fn (string $role): bool => $role !== '');

        return array_values(array_unique($roles));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $result
     *  @return array<string, mixed>
     */
    private function audit(array $result, string $level): array
    {
        try {
            if (function_exists('write_log')) {
                write_log('capability.authz.decision', $level, $result);
            }
        } catch (Throwable $e) {
        }

        return $result;
    }
}

final class CapabilityAuthorizationRegistryUnavailableException extends \RuntimeException
{
}
