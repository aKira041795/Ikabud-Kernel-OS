<?php

/** Explicit authority-scope parity and fail-closed falsification test. */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/_support/env_guard.php';
require_once __DIR__ . '/_support/tenant_fixture.php';

use Ikabud\Kernel\Capabilities\AuthorityScope;
use Ikabud\Kernel\Capabilities\AuthorityScopeResolver;
use Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry;
use Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistryUnavailableException;

$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

$dbName = static function (PDO $db): string {
    return (string)$db->query('SELECT DATABASE()')->fetchColumn();
};
$quoteIdentifier = static function (string $identifier): string {
    return '`' . str_replace('`', '``', $identifier) . '`';
};

$log = __DIR__ . '/../storage/logs/app.log';
@file_put_contents($log, '');

$kernelDb = app()->db();
$kernelDatabase = $dbName($kernelDb);
$controlDb = app()->controlDb();
$previousTenant = app()->tenant()->current();
$fixtureTenantId = 0;
$fixtureDatabase = '';
$originalFixtureDatabase = '';
$isolatedDatabase = null;
$fixtureCreated = false;

// Allocate a tenant id that this process owns, rather than binding the test to
// any pre-existing product tenant.
for ($attempt = 0; $attempt < 20; $attempt++) {
    $candidate = random_int(7000000, 7999999);
    $stmt = $controlDb->prepare('SELECT 1 FROM kernel_tenants WHERE id = ?');
    $stmt->execute([$candidate]);
    if ($stmt->fetchColumn() === false) {
        $fixtureTenantId = $candidate;
        break;
    }
}
if ($fixtureTenantId === 0) {
    testEnvironmentSkip('could not allocate an unused tenant fixture id');
}

try {
    ensureTestTenant($fixtureTenantId, 'gui-settings');
    $fixtureCreated = true;

    $connection = $controlDb->prepare('SELECT db_name FROM kernel_tenant_db_connections WHERE tenant_id = ?');
    $connection->execute([$fixtureTenantId]);
    $originalFixtureDatabase = (string)$connection->fetchColumn();
    $fixtureDb = app()->dbForTenant($fixtureTenantId);
    if (!$fixtureDb instanceof PDO || $originalFixtureDatabase === '') {
        throw new RuntimeException('tenant fixture did not provide a database connection');
    }

    // The shared test fixture may initially point at the kernel database. Give
    // this authority-scope test an isolated schema so database separation is a
    // real assertion in every environment, including the one-database CI job.
    if ($dbName($fixtureDb) === $kernelDatabase) {
        $isolatedDatabase = 'authority_scope_' . $fixtureTenantId . '_' . bin2hex(random_bytes(3));
        $quotedIsolated = $quoteIdentifier($isolatedDatabase);
        $quotedKernel = $quoteIdentifier($kernelDatabase);
        $kernelDb->exec("CREATE DATABASE {$quotedIsolated} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $kernelDb->exec("CREATE TABLE {$quotedIsolated}.capability_authorization_policies LIKE {$quotedKernel}.capability_authorization_policies");

        $update = $controlDb->prepare('UPDATE kernel_tenant_db_connections SET db_name = ? WHERE tenant_id = ?');
        $update->execute([$isolatedDatabase, $fixtureTenantId]);
        $fixtureDb = app()->reconnectDbForTenant($fixtureTenantId);
        if (!$fixtureDb instanceof PDO) {
            throw new RuntimeException('isolated tenant authority database is not resolvable');
        }
    }

    $fixtureDatabase = $dbName($fixtureDb);
} catch (Throwable $e) {
    if ($isolatedDatabase !== null && $originalFixtureDatabase !== '') {
        $restore = $controlDb->prepare('UPDATE kernel_tenant_db_connections SET db_name = ? WHERE tenant_id = ?');
        $restore->execute([$originalFixtureDatabase, $fixtureTenantId]);
        app()->reconnectDbForTenant($fixtureTenantId);
    }
    if ($fixtureCreated) {
        cleanupTestTenant($fixtureTenantId);
    }
    if ($isolatedDatabase !== null) {
        $kernelDb->exec('DROP DATABASE IF EXISTS ' . $quoteIdentifier($isolatedDatabase));
    }
    testEnvironmentSkip('authority-scope fixture unavailable: ' . $e->getMessage());
}

$policyVersion = 1700000000 + random_int(1, 999999);
$suffix = bin2hex(random_bytes(6));
$capabilityId = 'test.authority.scope.' . $suffix . '@1';
$provider = 'authority-scope-provider-' . $suffix;
$caller = 'authority-scope-caller';
$actor = ['id' => 1, 'role' => 'administrator', 'source' => 'kernel'];

try {
    app()->tenant()->setTenantId($fixtureTenantId);

    $resolver = AuthorityScopeResolver::forApplication();
    $web = $resolver->resolve(AuthorityScopeResolver::WEB, ['actor' => $actor]);
    $cli = $resolver->resolve(AuthorityScopeResolver::CLI, ['tenant_id' => $fixtureTenantId, 'actor' => $actor]);
    $cron = $resolver->resolve(AuthorityScopeResolver::CRON, ['tenant_id' => $fixtureTenantId, 'actor' => $actor]);

    $scopes = ['web' => $web, 'cli' => $cli, 'cron' => $cron];
    $names = [];
    foreach ($scopes as $entryPoint => $scope) {
        $check(
            "{$entryPoint} resolves the fixture tenant authority scope",
            $scope instanceof AuthorityScope && $scope->tenantId === $fixtureTenantId
        );
        $resolvedDb = $scope instanceof AuthorityScope ? $resolver->database($scope) : null;
        $names[$entryPoint] = $resolvedDb instanceof PDO ? $dbName($resolvedDb) : '';
    }

    $check('web, CLI, and cron resolve the same authority database', count(array_unique(array_values($names))) === 1, json_encode($names));
    $check('entry points resolve the fixture authority database', $names['web'] === $fixtureDatabase, json_encode($names));
    $check('tenant authority database is never the kernel database', !in_array($kernelDatabase, $names, true), json_encode(['kernel' => $kernelDatabase, 'scopes' => $names]));

    $registry = new CapabilityAuthorizationRegistry($fixtureDb);
    $registry->seedPolicy([[
        'policy_version' => $policyVersion,
        'capability_id' => $capabilityId,
        'capability_version' => '1',
        'provider' => $provider,
        'caller_module' => $caller,
        'allowed_roles' => 'administrator',
        'provider_activation_required' => true,
        'requires_protocol' => 'v2',
        'is_active' => true,
    ]]);

    $context = [
        'capability_id' => $capabilityId,
        'capability_version' => '1',
        'provider' => $provider,
        'caller_module' => $caller,
        'actor_role' => 'administrator',
        'tenant_id' => (string)$fixtureTenantId,
        'provider_activation' => true,
        'dispatch_protocol' => 'v2',
        'policy_version' => $policyVersion,
    ];
    CapabilityAuthorizationRegistry::invalidate();
    $scopedDecision = (new CapabilityAuthorizationRegistry(null, $web, $resolver))->authorize($context);
    $check('fixture-seeded policy authorizes through the resolved scope', ($scopedDecision['allowed'] ?? false) === true, json_encode($scopedDecision));

    $decision = (new CapabilityAuthorizationRegistry())->authorize($context);
    $check(
        'authorization with no authority scope denies with the recorded reason',
        ($decision['allowed'] ?? null) === false && ($decision['reason'] ?? null) === 'missing_tenant_authority_scope',
        json_encode($decision)
    );

    // Falsifier: this private selector must throw without a scope. Restoring an
    // ambient app database fallback makes this assertion fail while a tenant is current.
    $unscopedDbRejected = false;
    try {
        $method = new ReflectionMethod(CapabilityAuthorizationRegistry::class, 'db');
        $method->invoke(new CapabilityAuthorizationRegistry());
    } catch (CapabilityAuthorizationRegistryUnavailableException) {
        $unscopedDbRejected = true;
    }
    $check('unscoped registry cannot reach the ambient database (fallback falsifier)', $unscopedDbRejected);

    $logText = is_file($log) ? (string)file_get_contents($log) : '';
    $check('no-scope denial records why', str_contains($logText, 'missing_tenant_authority_scope'), $logText);
    $source = (string)file_get_contents(__DIR__ . '/../kernel/Capabilities/CapabilityAuthorizationRegistry.php');
    $check('registry source contains no ambient app database selection', !preg_match('/app\(\)\s*->\s*db\s*\(/', $source));
} finally {
    app()->tenant()->setTenantId($previousTenant);

    if ($isolatedDatabase === null) {
        $delete = $fixtureDb->prepare(
            'DELETE FROM capability_authorization_policies '
            . 'WHERE policy_version = ? AND capability_id = ? AND capability_version = ? AND provider = ?'
        );
        $delete->execute([$policyVersion, $capabilityId, '1', $provider]);
    } else {
        $restore = $controlDb->prepare('UPDATE kernel_tenant_db_connections SET db_name = ? WHERE tenant_id = ?');
        $restore->execute([$originalFixtureDatabase, $fixtureTenantId]);
        app()->reconnectDbForTenant($fixtureTenantId);
    }

    cleanupTestTenant($fixtureTenantId);
    if ($isolatedDatabase !== null) {
        $kernelDb->exec('DROP DATABASE IF EXISTS ' . $quoteIdentifier($isolatedDatabase));
    }
    CapabilityAuthorizationRegistry::invalidate();
}

echo "\nAuthority scope tests: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
