<?php

/**
 * GUI Settings route authority — the two write routes are declared, the policy
 * that backs them exists, and the declaration is load-bearing at dispatch.
 *
 * The claim under test: POST /api/v1/admin/gui-settings and its /reset sibling
 * declare gui_settings.apply@1 and are checked before the handler body runs,
 * while the module's GET read stays undeclared (reads are a separate campaign).
 *
 * The falsifier is the negative control at the end. If the declared route and
 * the undeclared route behaved the same for the same actor, the declaration
 * would be decoration and this test would prove nothing. They must differ.
 *
 * Seeded-policy proof: an `admin` actor is ALLOWED (so declaring the route does
 * not 403 the operator the handler admits), while `editor` and an absent actor
 * are refused. The role set is `admin` only because that is exactly what
 * handlers.php gates (`$user['role'] === 'admin'`); a wider set would grant
 * access nobody has, a narrower one would break the page.
 */

declare(strict_types=1);

require __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../src/helpers/module-manager.php';
require_once __DIR__ . '/../../../src/helpers/module-routes.php';
require_once __DIR__ . '/../../../tests/_support/env_guard.php';
require_once __DIR__ . '/../../../tests/_support/tenant_fixture.php';
require_once __DIR__ . '/../helpers.php';

use Ikabud\Kernel\Capabilities\AuthorityScopeResolver;
use Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry;

// The registry is shared runtime state; restore storage/modules.json no matter
// how this test exits (the fuller suites delete and rewrite it).
$registryPath = STORAGE_PATH . '/modules.json';
$registryBackup = is_file($registryPath) ? (string) file_get_contents($registryPath) : null;
register_shutdown_function(static function () use ($registryPath, $registryBackup): void {
    if ($registryBackup === null) {
        @unlink($registryPath);
        return;
    }
    @file_put_contents($registryPath, $registryBackup);
});

requireNotLiveTenantDatabase();

$passed = 0;
$failed = 0;

function ok(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ✓ {$label}\n";
        return;
    }
    $failed++;
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "\n=== 1. THE MANIFEST DECLARES BOTH WRITE ROUTES ===\n";

$declared = moduleRouteAuthorityDeclarations('gui-settings');

ok(
    'POST /api/v1/admin/gui-settings declares gui_settings.apply@1',
    ($declared['POST /api/v1/admin/gui-settings'] ?? null) === 'gui_settings.apply@1',
    json_encode($declared)
);
ok(
    'POST /api/v1/admin/gui-settings/reset declares gui_settings.apply@1',
    ($declared['POST /api/v1/admin/gui-settings/reset'] ?? null) === 'gui_settings.apply@1',
    json_encode($declared)
);
ok(
    'the GET read route is NOT declared (reads are a separate campaign)',
    !isset($declared['GET /admin/gui-settings']),
    json_encode($declared)
);
ok(
    'the declared capability is one the module itself exposes',
    str_contains((string) file_get_contents(__DIR__ . '/../module.json'), '"id": "gui_settings.apply@1"')
);

echo "\n=== 2. THE POLICY BACKS THE DECLARATION ===\n";

$control = app()->controlDb();
$fixtureTenantId = 0;
for ($attempt = 0; $attempt < 20; $attempt++) {
    $candidate = random_int(7000000, 7999999);
    $stmt = $control->prepare('SELECT 1 FROM kernel_tenants WHERE id = ?');
    $stmt->execute([$candidate]);
    if ($stmt->fetchColumn() === false) {
        $fixtureTenantId = $candidate;
        break;
    }
}
if ($fixtureTenantId === 0) {
    testEnvironmentSkip('could not allocate an unused tenant fixture id');
}

ensureTestTenant($fixtureTenantId, 'gui-settings');
register_shutdown_function(static function () use ($fixtureTenantId): void {
    try {
        cleanupTestTenant($fixtureTenantId);
    } catch (\Throwable) {
        // Cleanup is best effort; the authoritative assertions ran above.
    }
});

// Register the module's capability provider so `authorize` can evaluate the
// real policy rather than reporting a missing capability.
if (!isModuleEnabled('gui-settings')) {
    enableModule('gui-settings');
    unset($GLOBALS['_kernel_discovered_modules']);
}
loadModuleRoutes([]);

$policyRow = null;
AuthorityScopeResolver::withScope($fixtureTenantId, AuthorityScopeResolver::CLI, function () use ($fixtureTenantId, &$policyRow): void {
    // Idempotent: seeds the capability policy into this scope's active version.
    guiSettingsSeedApplyPolicy();

    $resolver = AuthorityScopeResolver::forApplication();
    $scope = $resolver->resolve(AuthorityScopeResolver::CLI, ['tenant_id' => $fixtureTenantId]);
    $registry = new CapabilityAuthorizationRegistry(null, $scope, $resolver, 'missing_tenant_authority_scope');

    foreach ($registry->activePolicyRows() as $row) {
        if (($row['capability_id'] ?? '') === 'gui_settings.apply@1') {
            $policyRow = $row;
            break;
        }
    }

    ok(
        'hasPolicyFor(gui_settings.apply@1) is true for the active policy version',
        $registry->hasPolicyFor('gui_settings.apply@1', '1', 'gui-settings')
    );
});

ok(
    'the active policy row restricts allowed_roles to exactly admin',
    is_array($policyRow) && ($policyRow['allowed_roles'] ?? null) === 'admin',
    json_encode($policyRow)
);
ok(
    'the policy row permits the route dispatcher as a caller',
    is_array($policyRow) && in_array('gui-settings', explode(',', (string) ($policyRow['caller_module'] ?? '')), true),
    json_encode($policyRow)
);
ok(
    'the policy row is active and granted',
    is_array($policyRow)
        && (int) ($policyRow['is_active'] ?? 0) === 1
        && ($policyRow['grant_state'] ?? '') === 'granted',
    json_encode($policyRow)
);

echo "\n=== 3. DISPATCH ENFORCES THE DECLARATION (and admits the admin) ===\n";

$admin = ['id' => 7, 'role' => 'admin', 'source' => 'kernel'];
$editor = ['id' => 8, 'role' => 'editor', 'source' => 'kernel'];

AuthorityScopeResolver::withScope($fixtureTenantId, AuthorityScopeResolver::CLI, function () use ($admin, $editor): void {
    $adminDecision = moduleRouteAuthorityDecision(
        'gui-settings',
        'POST',
        '/api/v1/admin/gui-settings',
        '/api/v1/admin/gui-settings',
        $admin
    );
    ok(
        'an admin actor is ALLOWED on the declared route',
        ($adminDecision['allowed'] ?? false) === true,
        json_encode($adminDecision)
    );

    $editorDecision = moduleRouteAuthorityDecision(
        'gui-settings',
        'POST',
        '/api/v1/admin/gui-settings',
        '/api/v1/admin/gui-settings',
        $editor
    );
    ok(
        'an editor actor is refused with role_not_allowed (no widening)',
        ($editorDecision['allowed'] ?? true) === false && ($editorDecision['reason'] ?? '') === 'role_not_allowed',
        json_encode($editorDecision)
    );

    $resetDecision = moduleRouteAuthorityDecision(
        'gui-settings',
        'POST',
        '/api/v1/admin/gui-settings/reset',
        '/api/v1/admin/gui-settings/reset',
        $admin
    );
    ok(
        'the /reset route is governed by the same active policy',
        ($resetDecision['allowed'] ?? false) === true,
        json_encode($resetDecision)
    );
});

echo "\n=== 4. NEGATIVE CONTROL — IS THE DECLARATION LOAD-BEARING? ===\n";

$declaredAllowed = null;
$undeclaredAllowed = null;

AuthorityScopeResolver::withScope($fixtureTenantId, AuthorityScopeResolver::CLI, function () use (&$declaredAllowed, &$undeclaredAllowed): void {
    $declared = moduleRouteAuthorityDecision(
        'gui-settings',
        'POST',
        '/api/v1/admin/gui-settings',
        '/api/v1/admin/gui-settings',
        null
    );
    $declaredAllowed = $declared['allowed'] ?? null;

    $undeclared = moduleRouteAuthorityDecision(
        'gui-settings',
        'GET',
        '/admin/gui-settings',
        '/admin/gui-settings',
        null
    );
    $undeclaredAllowed = $undeclared['allowed'] ?? null;

    ok(
        'the declared route refuses a null actor',
        $declaredAllowed === false,
        json_encode($declared)
    );
    ok(
        'the undeclared GET route proceeds for the same null actor',
        $undeclaredAllowed === true && ($undeclared['state'] ?? '') === 'undeclared',
        json_encode($undeclared)
    );
    ok(
        'the declaration alone decides the outcome (load-bearing)',
        $declaredAllowed === false && $undeclaredAllowed === true
    );
});

echo "\n=== RESULT ===\n";
echo "  checks ok: {$passed}\n";
echo "  checks bad: {$failed}\n\n";

exit($failed === 0 ? 0 : 1);
