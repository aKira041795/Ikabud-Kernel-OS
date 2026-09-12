<?php

declare(strict_types=1);

function testEnvironmentSkip(string $reason): never
{
    echo 'SKIP: ' . $reason . "\n";
    exit(0);
}

function requireWritableCompiledCache(): void
{
    $cacheDir = dirname(__DIR__, 2) . '/storage/cache/compiled';
    if (!is_dir($cacheDir)) {
        testEnvironmentSkip('compiled template cache directory is missing');
    }

    $probe = tempnam($cacheDir, 'write-probe-');
    if ($probe === false || dirname($probe) !== $cacheDir) {
        if ($probe !== false) {
            @unlink($probe);
        }
        testEnvironmentSkip('compiled template cache is not writable by the current process');
    }

    @unlink($probe);
}

function requireWritableCacheDirectory(string $cacheDir, string $label): void
{
    if (is_dir($cacheDir)) {
        $probe = tempnam($cacheDir, 'write-probe-');
        if ($probe === false || dirname($probe) !== $cacheDir) {
            if ($probe !== false) {
                @unlink($probe);
            }
            testEnvironmentSkip("{$label} is not writable by the current process");
        }
        @unlink($probe);
        return;
    }

    $parent = dirname($cacheDir);
    if (!is_dir($parent) || !is_writable($parent)) {
        testEnvironmentSkip("{$label} cannot be created by the current process");
    }
}

/**
 * Require the baseline seed and a currently active row for every governed capability used by a test.
 *
 * @param list<array{capability_id: string, provider: string}> $requirements
 */
function requireCapabilityAuthorizationPolicies(PDO $db, array $requirements): void
{
    $baseline = $db->prepare(
        'SELECT COUNT(*) FROM capability_authorization_policies '
        . 'WHERE capability_id = ? AND capability_version = ? AND provider = ? AND policy_version = 1'
    );
    $active = $db->prepare(
        'SELECT COUNT(*) FROM capability_authorization_policies '
        . 'WHERE capability_id = ? AND capability_version = ? AND provider = ? AND is_active = 1'
    );

    foreach ($requirements as $requirement) {
        $capabilityId = $requirement['capability_id'];
        $provider = $requirement['provider'];
        $parameters = [$capabilityId, '1', $provider];

        $baseline->execute($parameters);
        if ((int) $baseline->fetchColumn() === 0) {
            testEnvironmentSkip("required capability authorization policy is absent: {$capabilityId} for {$provider} (policy version 1)");
        }

        $active->execute($parameters);
        if ((int) $active->fetchColumn() === 0) {
            testEnvironmentSkip("required active capability authorization policy is absent: {$capabilityId} for {$provider}");
        }
    }
}

/** @param list<string> $moduleIds */
function requireTenantModulesActive(int $tenantId, array $moduleIds): void
{
    foreach ($moduleIds as $moduleId) {
        if (!moduleIsActive($moduleId, $tenantId)) {
            testEnvironmentSkip("tenant {$tenantId} does not activate required CLI module {$moduleId}");
        }
    }
}

function requireTwoDistinctDedicatedTenantDatabases(): void
{
    $tenantIds = app()->controlDb()
        ->query('SELECT tenant_id FROM kernel_tenant_db_connections ORDER BY tenant_id ASC LIMIT 2')
        ->fetchAll(PDO::FETCH_COLUMN);
    if (count($tenantIds) < 2) {
        testEnvironmentSkip('fewer than two tenant databases resolve in CLI');
    }

    $baseName = (string) app()->db()->query('SELECT DATABASE()')->fetchColumn();
    $names = [];
    foreach ($tenantIds as $tenantId) {
        try {
            $database = app()->dbForTenant((int) $tenantId);
            $names[] = $database instanceof PDO
                ? (string) $database->query('SELECT DATABASE()')->fetchColumn()
                : '';
        } catch (Throwable) {
            $names[] = '';
        }
    }

    if ($names[0] === '' || $names[1] === '' || $names[0] === $baseName || $names[1] === $baseName || $names[0] === $names[1]) {
        testEnvironmentSkip('two distinct dedicated tenant databases do not resolve in CLI');
    }
}

function requireTenantFixture(int $tenantId): void
{
    if (!(bool) app()->config('app.multi_tenant.enabled', false)) {
        return;
    }

    try {
        $database = app()->dbForTenant($tenantId);
    } catch (Throwable) {
        $database = null;
    }

    if ($database === null) {
        testEnvironmentSkip("tenant {$tenantId} has no resolvable database configuration");
    }
}
