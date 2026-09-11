<?php

/** Explicit authority-scope parity and fail-closed falsification test. */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

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

$log = __DIR__ . '/../storage/logs/app.log';
@file_put_contents($log, '');

$kernelDatabase = $dbName(app()->db());
$previousTenant = app()->tenant()->current();
app()->tenant()->setTenantId(54);

$resolver = AuthorityScopeResolver::forApplication();
$actor = ['id' => 1, 'role' => 'administrator', 'source' => 'kernel'];
$web = $resolver->resolve(AuthorityScopeResolver::WEB, ['actor' => $actor]);
$cli = $resolver->resolve(AuthorityScopeResolver::CLI, ['tenant_id' => 54, 'actor' => $actor]);
$cron = $resolver->resolve(AuthorityScopeResolver::CRON, ['tenant_id' => 54, 'actor' => $actor]);

$scopes = ['web' => $web, 'cli' => $cli, 'cron' => $cron];
$names = [];
foreach ($scopes as $entryPoint => $scope) {
    $check("{$entryPoint} resolves tenant 54 authority scope", $scope instanceof AuthorityScope && $scope->tenantId === 54);
    $db = $scope instanceof AuthorityScope ? $resolver->database($scope) : null;
    $names[$entryPoint] = $db instanceof PDO ? $dbName($db) : '';
}

$check('web, CLI, and cron resolve the same authority database', count(array_unique(array_values($names))) === 1, json_encode($names));
$check('tenant 54 authority database is akira', $names['web'] === 'akira', json_encode($names));
$check('tenant authority database is never the kernel database', !in_array($kernelDatabase, $names, true), json_encode(['kernel' => $kernelDatabase, 'scopes' => $names]));

$tenantDb = $resolver->database($web);
$row = $tenantDb instanceof PDO ? $tenantDb->query(
    "SELECT policy_version, capability_id, capability_version, provider, caller_module, allowed_roles, "
    . "provider_activation_required, requires_protocol FROM capability_authorization_policies "
    . "WHERE is_active = 1 AND grant_state = 'granted' ORDER BY id ASC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC) : false;
$check('tenant fixture has a granted policy for the ambient-fallback falsifier', is_array($row), json_encode($row));

if (is_array($row)) {
    $roles = array_values(array_filter(array_map('trim', explode(',', (string)($row['allowed_roles'] ?? '')))));
    $callers = array_values(array_filter(array_map('trim', explode(',', (string)($row['caller_module'] ?? '')))));
    $decision = (new CapabilityAuthorizationRegistry())->authorize([
        'capability_id' => (string)$row['capability_id'],
        'capability_version' => (string)$row['capability_version'],
        'provider' => (string)$row['provider'],
        'caller_module' => $callers[0] ?? 'falsifier-caller',
        'actor_role' => $roles[0] ?? 'administrator',
        'tenant_id' => '54',
        'provider_activation' => true,
        'dispatch_protocol' => (string)($row['requires_protocol'] ?? 'v1'),
        'policy_version' => (int)$row['policy_version'],
    ]);
    $check(
        'authorization with no authority scope denies with the recorded reason',
        ($decision['allowed'] ?? null) === false && ($decision['reason'] ?? null) === 'missing_tenant_authority_scope',
        json_encode($decision)
    );
}

// Falsifier: this private selector must throw without a scope. Restoring the old
// app()->db() fallback makes this assertion fail because tenant 54 is current.
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

app()->tenant()->setTenantId($previousTenant);

echo "\nAuthority scope tests: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
