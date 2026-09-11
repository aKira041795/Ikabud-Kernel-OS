<?php

/** Focused persistence and module-propagation gate for capability authorization policies. */

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/_support/tenant_fixture.php';

use Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry;
use Ikabud\Kernel\Capabilities\CapabilityBus;
use Ikabud\Kernel\Capabilities\CapabilityCallException;
use Ikabud\Kernel\Capabilities\CapabilityRegistry;
use Ikabud\Kernel\Database\MigrationRunner;

$passed = 0;
$failed = 0;

function capAuthzPolicyTest(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    $condition ? $passed++ : $failed++;
    echo ($condition ? '  ✓ ' : '  ✗ ') . $label . (!$condition && $detail !== '' ? " — {$detail}" : '') . "\n";
}

/** @param array<string, mixed> $options */
function capAuthzPolicyDenied(CapabilityBus $bus, string $capabilityId, array $options): bool
{
    try {
        $bus->call($capabilityId, [], $options);
    } catch (CapabilityCallException $e) {
        return str_contains($e->getMessage(), 'authorization denied');
    }

    return false;
}

/** @param array<string, mixed> $options */
function capAuthzPolicyDeniedForReason(CapabilityBus $bus, string $capabilityId, array $options, string $reason): bool
{
    try {
        $bus->call($capabilityId, [], $options);
    } catch (CapabilityCallException $e) {
        return str_contains($e->getMessage(), 'authorization denied: ' . $reason);
    }

    return false;
}

/**
 * Call the bus and report the outcome instead of letting a denial kill the
 * process. An unwrapped call buried the reason: the test died here, printed no
 * assertion, and (while uncaught CLI exceptions still exited 0) reported PASS.
 *
 * @param array<string, mixed> $options
 * @return array{allowed: bool, reason: string}
 */
function capAuthzPolicyOutcome(CapabilityBus $bus, string $capabilityId, array $options): array
{
    try {
        $result = $bus->call($capabilityId, [], $options);

        return ['allowed' => is_array($result) && ($result['allowed'] ?? false) === true, 'reason' => ''];
    } catch (CapabilityCallException $e) {
        return ['allowed' => false, 'reason' => $e->getMessage()];
    }
}

echo "=== CAPABILITY AUTHORIZATION POLICY MIGRATION ===\n";

$db = app()->db();
$runner = new MigrationRunner($db);
$runner->migrate('_kernel');

$tableExists = (int)$db->query(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() "
    . "AND table_name = 'capability_authorization_policies'"
)->fetchColumn() === 1;
capAuthzPolicyTest('migration creates the authorization policy table', $tableExists);

$naturalKey = $db->query(
    "SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') "
    . "FROM information_schema.statistics WHERE table_schema = DATABASE() "
    . "AND table_name = 'capability_authorization_policies' "
    . "AND index_name = 'uq_capability_authorization_policy' AND non_unique = 0"
)->fetchColumn();
capAuthzPolicyTest(
    'information_schema confirms the seedPolicy natural-key unique index',
    $naturalKey === 'policy_version,capability_id,capability_version,provider',
    (string)$naturalKey
);
$migrationSource = (string)file_get_contents(__DIR__ . '/../migrations/016_capability_authorization_policies.sql');
capAuthzPolicyTest(
    'migration 016 declares the same seedPolicy natural key',
    str_contains($migrationSource, 'UNIQUE KEY uq_capability_authorization_policy (policy_version, capability_id, capability_version, provider)')
);

$migrationRegistration = $db->prepare(
    "SELECT COUNT(*) FROM _migrations WHERE module = '_kernel' AND migration = ?"
);
$migrationRegistration->execute(['016_capability_authorization_policies.sql']);
capAuthzPolicyTest('migration is registered by kernel convention', (int)$migrationRegistration->fetchColumn() === 1);
$migrationRegistration->closeCursor();

$secondRun = $runner->migrate('_kernel');
$migrationRegistration->execute(['016_capability_authorization_policies.sql']);
capAuthzPolicyTest(
    'migration rerun converges without duplicate registration',
    $secondRun === [] && (int)$migrationRegistration->fetchColumn() === 1,
    json_encode($secondRun, JSON_UNESCAPED_SLASHES)
);
$migrationRegistration->closeCursor();

$policyVersion = 4000000000 + (getmypid() % 1000000);
$suffix = 'x' . bin2hex(random_bytes(4));
$capabilityId = 'test.authz.policy.' . $suffix . '@2';
$providerId = 'kernel';
$legacyProviderId = 'authz-policy-v1-provider-' . $suffix;
$callerModule = 'authz-policy-caller-' . $suffix;
$alternateCallerModule = 'authz-policy-alternate-caller-' . $suffix;
$registry = new CapabilityAuthorizationRegistry($db);
$tenantResolver = app()->tenant();
$previousTenantId = $tenantResolver->current();
$resolverFixtureTenant = null;

try {
    $tenantResolver->setTenantId(null);
    $noScopeCapability = 'test.authz.no-scope.' . $suffix . '@1';
    $noScopeCount = $db->prepare('SELECT COUNT(*) FROM capability_authorization_policies WHERE capability_id = ?');
    $noScopeCount->execute([$noScopeCapability]);
    $beforeNoScopeSeed = (int)$noScopeCount->fetchColumn();
    CapabilityAuthorizationRegistry::seedPolicyForCurrentScope([[
        'policy_version' => $policyVersion,
        'capability_id' => $noScopeCapability,
        'capability_version' => '1',
        'provider' => 'no-scope-provider',
        'caller_module' => 'no-scope-caller',
        'allowed_roles' => 'admin',
    ]]);
    $noScopeCount->execute([$noScopeCapability]);
    capAuthzPolicyTest(
        'policy seeding without an explicit tenant scope writes no authority row',
        (int)$noScopeCount->fetchColumn() === $beforeNoScopeSeed
    );
    $tenantResolver->setTenantId($previousTenantId);

    $policy = [
        'policy_version' => $policyVersion,
        'capability_id' => $capabilityId,
        'capability_version' => '2',
        'provider' => $providerId,
        'caller_module' => $callerModule . ',' . $alternateCallerModule,
        'allowed_roles' => 'admin,editor',
        'provider_activation_required' => false,
        'requires_protocol' => 'v1',
        'is_active' => true,
    ];
    $registry->seedPolicy([$policy]);
    $narrowPolicy = array_merge($policy, [
        'caller_module' => $callerModule,
        'allowed_roles' => 'admin',
        'provider_activation_required' => true,
        'requires_protocol' => 'v2',
    ]);
    $registry->seedPolicy([$narrowPolicy]);
    // Ratified D-Q4 rule: declared permissions may narrow automatically, but
    // adding a caller/role or relaxing another gate requires operator re-grant.
    $registry->seedPolicy([array_merge($narrowPolicy, [
        'caller_module' => $callerModule . ',' . $alternateCallerModule,
        'allowed_roles' => 'admin,editor',
        'provider_activation_required' => false,
        'requires_protocol' => 'v1',
    ])]);
    $registry->seedPolicy([array_merge($policy, [
        'provider' => $legacyProviderId,
        'caller_module' => $callerModule,
        'allowed_roles' => 'admin',
        'requires_protocol' => 'v2',
    ])]);

    $stored = $db->prepare(
        'SELECT COUNT(*) AS row_count, MAX(caller_module) AS caller_module, '
        . 'MAX(allowed_roles) AS allowed_roles, MAX(requires_protocol) AS requires_protocol, '
        . 'MAX(provider_activation_required) AS provider_activation_required '
        . 'FROM capability_authorization_policies '
        . 'WHERE policy_version = ? AND capability_id = ? AND capability_version = ? AND provider = ?'
    );
    $stored->execute([$policyVersion, $capabilityId, '2', $providerId]);
    $storedPolicy = $stored->fetch(PDO::FETCH_ASSOC);
    capAuthzPolicyTest(
        'seedPolicy applies narrowing and refuses widening of a stored grant (ratified D-Q4)',
        is_array($storedPolicy)
            && (int)$storedPolicy['row_count'] === 1
            && $storedPolicy['caller_module'] === $callerModule
            && $storedPolicy['allowed_roles'] === 'admin'
            && $storedPolicy['requires_protocol'] === 'v2'
            && (int)$storedPolicy['provider_activation_required'] === 1,
        json_encode($storedPolicy, JSON_UNESCAPED_SLASHES)
    );

    $manifest = [
        'capabilities' => [
            'exposes' => [[
                'id' => $capabilityId,
                'modes' => ['first'],
                'requires_protocol' => 'v2',
            ]],
            'depends' => [],
        ],
    ];
    $capabilityCheck = validateModuleCapabilities($manifest);
    $expose = $capabilityCheck['exposes'][0] ?? null;
    $meta = is_array($expose)
        ? moduleCapabilityProviderMeta($expose, [], ['type' => 'module_fixture', 'module' => $providerId])
        : [];
    $capabilityRegistry = new CapabilityRegistry();
    $capabilityRegistry->register(
        $capabilityId,
        $providerId,
        static fn (): array => ['allowed' => true],
        10,
        ['first'],
        $meta
    );
    $capabilityRegistry->register(
        $capabilityId,
        $legacyProviderId,
        static fn (): array => ['allowed' => false],
        5,
        ['first'],
        []
    );
    $bus = new CapabilityBus($capabilityRegistry);
    capAuthzPolicyTest(
        'module requires_protocol declaration propagates into capability bus metadata',
        !empty($capabilityCheck['ok']) && ($meta['requires_protocol'] ?? null) === 'v2',
        json_encode($meta, JSON_UNESCAPED_SLASHES)
    );
    $legacyMeta = moduleCapabilityProviderMeta(['id' => 'test.legacy@1'], [], []);
    capAuthzPolicyTest(
        'module capabilities without requires_protocol retain legacy behavior',
        ($legacyMeta['requires_protocol'] ?? null) === ''
    );

    $resolverOptions = [
        'provider' => $providerId,
        'caller_module' => $callerModule,
        'caller_user' => ['role' => 'admin'],
    ];
    // Resolver propagation — the tenant comes from app()->tenant(), not from
    // options and not from request context — is asserted on a synthetic fixture
    // tenant that resolves to this suite's own database. Binding this to a live
    // product tenant made a persistence test seed and then delete rows in a real
    // tenant's policy table, which is not a test's business. The fixture keeps
    // the coverage and drops the live dependency.
    $resolverFixtureTenant = 9411;
    ensureTestTenant($resolverFixtureTenant, 'gui-settings');
    $tenantResolver->setTenantId($resolverFixtureTenant);
    CapabilityAuthorizationRegistry::seedPolicyForCurrentScope([array_merge($policy, [
        'caller_module' => $callerModule,
        'allowed_roles' => 'admin',
        'requires_protocol' => 'v2',
    ])]);
    $tenantPolicyDb = app()->dbForTenant($resolverFixtureTenant);
    $tenantRegistry = $tenantPolicyDb instanceof PDO ? new CapabilityAuthorizationRegistry($tenantPolicyDb) : null;
    if ($tenantRegistry instanceof CapabilityAuthorizationRegistry) {
        $tenantRegistry->seedPolicy([array_merge($policy, [
            'provider' => $legacyProviderId,
            'caller_module' => $callerModule,
            'allowed_roles' => 'admin',
            'requires_protocol' => 'v2',
        ])]);
    }
    $tenantPolicyCount = $tenantPolicyDb instanceof PDO ? $tenantPolicyDb->prepare(
        'SELECT COUNT(*) FROM capability_authorization_policies WHERE policy_version = ? AND capability_id = ? AND provider = ?'
    ) : null;
    if ($tenantPolicyCount instanceof PDOStatement) {
        $tenantPolicyCount->execute([$policyVersion, $capabilityId, $providerId]);
    }
    capAuthzPolicyTest(
        'tenant-scoped seeding resolves and writes the explicit tenant authority store',
        $tenantPolicyCount instanceof PDOStatement && (int)$tenantPolicyCount->fetchColumn() === 1
    );
    CapabilityAuthorizationRegistry::invalidate();
    $resolverResult = capAuthzPolicyOutcome($bus, $capabilityId, $resolverOptions);
    capAuthzPolicyTest(
        'governed dispatch resolves tenant from the app tenant resolver without options or request context',
        $resolverResult['allowed'] === true,
        $resolverResult['reason']
    );
    $tenantResolver->setTenantId(null);

    CapabilityAuthorizationRegistry::invalidate();
    capAuthzPolicyTest(
        'governed dispatch without a tenant anywhere remains fail-closed',
        capAuthzPolicyDeniedForReason($bus, $capabilityId, $resolverOptions, 'missing_tenant_authority_scope')
    );

    $baseOptions = [
        'provider' => $providerId,
        'caller_module' => $callerModule,
        'tenant_id' => $resolverFixtureTenant,
        'authority_entry_point' => 'test',
    ];
    $adminResult = capAuthzPolicyOutcome($bus, $capabilityId, array_merge($baseOptions, [
        'caller_user' => ['role' => 'admin'],
    ]));
    capAuthzPolicyTest(
        'protocol-v2 dispatch is allowed after canonical bus re-authorization',
        $adminResult['allowed'] === true,
        $adminResult['reason']
    );

    // Falsifier: removing seedPolicy's widening guard makes this assertion fail,
    // because the alternate caller would be silently added to the live grant.
    $wideningDeclaration = array_merge($narrowPolicy, [
        'caller_module' => $callerModule . ',' . $alternateCallerModule,
    ]);
    $registry->seedPolicy([$wideningDeclaration]);
    if ($tenantRegistry instanceof CapabilityAuthorizationRegistry) {
        $tenantRegistry->seedPolicy([$wideningDeclaration]);
    }
    CapabilityAuthorizationRegistry::invalidate();

    $alternateCallerResult = capAuthzPolicyOutcome($bus, $capabilityId, array_merge($baseOptions, [
        'caller_module' => $alternateCallerModule,
        'caller_user' => ['role' => 'admin'],
    ]));
    capAuthzPolicyTest(
        'a widening caller declaration is refused until operator re-grant',
        $alternateCallerResult['allowed'] === false && str_contains($alternateCallerResult['reason'], 'disabled_caller'),
        $alternateCallerResult['reason']
    );
    capAuthzPolicyTest(
        'protocol-v2 policy denies a legacy dispatch in the canonical bus',
        capAuthzPolicyDenied($bus, $capabilityId, array_merge($baseOptions, [
            'provider' => $legacyProviderId,
            'caller_user' => ['role' => 'admin'],
        ]))
    );
    capAuthzPolicyTest(
        'real registry path denies a non-admin actor',
        capAuthzPolicyDenied($bus, $capabilityId, array_merge($baseOptions, ['caller_user' => ['role' => 'editor']]))
    );
    capAuthzPolicyTest(
        'real registry path denies the wrong caller module',
        capAuthzPolicyDenied($bus, $capabilityId, array_merge($baseOptions, [
            'caller_module' => 'wrong-caller-' . $suffix,
            'caller_user' => ['role' => 'admin'],
        ]))
    );
    capAuthzPolicyTest(
        'real registry path denies a missing tenant context',
        capAuthzPolicyDenied($bus, $capabilityId, [
            'provider' => $providerId,
            'caller_module' => $callerModule,
            'tenant_id' => '',
            'caller_user' => ['role' => 'admin'],
        ])
    );
} finally {
    $tenantResolver->setTenantId($previousTenantId);
    if (is_int($resolverFixtureTenant)) {
        cleanupTestTenant($resolverFixtureTenant);
    }
    $cleanup = $db->prepare(
        'DELETE FROM capability_authorization_policies '
        . 'WHERE policy_version = ? AND capability_id = ? AND capability_version = ? AND provider IN (?, ?)'
    );
    $cleanup->execute([$policyVersion, $capabilityId, '2', $providerId, $legacyProviderId]);
    CapabilityAuthorizationRegistry::invalidate();
}

echo "\nCapability authorization policy migration tests: {$passed} passed, {$failed} failed\n";

function capAuthzPolicyExitCode(): int
{
    global $failed;
    return $failed > 0 ? 1 : 0;
}

exit(capAuthzPolicyExitCode());
