<?php

/**
 * P1.3 / B1 — tenant module activation must govern capability dispatch.
 *
 * Asserts the three-valued contract of the activation gate:
 *   1. activated module            → allowed;
 *   2. unactivated (resolved) module → refused with the distinct
 *      `module_not_activated` reason (not a policy reason);
 *   3. unresolvable activation state → ALLOWED (fail-safe).
 *
 * Enforcement is proven on a SYNTHETIC tenant. Tenant 54 is never touched.
 *
 * Run: php tests/activation_gate_dispatch_test.php
 */

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'akiracms.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/_support/env_guard.php';
require_once __DIR__ . '/_support/tenant_fixture.php';

use Ikabud\Kernel\Capabilities\CapabilityBus;
use Ikabud\Kernel\Capabilities\CapabilityCallException;
use Ikabud\Kernel\Capabilities\CapabilityRegistry;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$pass, &$fail): void {
    $ok ? ++$pass : ++$fail;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

echo "=== P1.3 activation gate dispatch ===\n";

// Allocate an unused synthetic tenant id.
$tenantId = 0;
for ($attempt = 0; $attempt < 40; $attempt++) {
    $candidate = random_int(8200000, 8899999);
    $exists = app()->controlDb()->prepare('SELECT 1 FROM kernel_tenants WHERE id = ?');
    $exists->execute([$candidate]);
    if ($exists->fetchColumn() === false) {
        $tenantId = $candidate;
        break;
    }
}
if ($tenantId === 0) {
    testEnvironmentSkip('could not allocate an unused synthetic tenant id');
}

// Allocate an id that is guaranteed to have no database connection row, so
// activation cannot be resolved for it.
$unresolvableTenantId = 0;
for ($attempt = 0; $attempt < 40; $attempt++) {
    $candidate = random_int(8900000, 8999999);
    $exists = app()->controlDb()->prepare('SELECT 1 FROM kernel_tenants WHERE id = ? OR id IN (SELECT tenant_id FROM kernel_tenant_db_connections WHERE tenant_id = ?)');
    $exists->execute([$candidate, $candidate]);
    if ($exists->fetchColumn() === false) {
        $unresolvableTenantId = $candidate;
        break;
    }
}
if ($unresolvableTenantId === 0) {
    testEnvironmentSkip('could not allocate an unresolvable synthetic tenant id');
}

try {
    // Active module: cms-akira-core. cms-akira-ai stays unactivated.
    ensureTestTenant($tenantId, 'cms-akira-core');
} catch (Throwable $error) {
    cleanupTestTenant($tenantId);
    testEnvironmentSkip('activation gate tenant fixture is unavailable: ' . $error->getMessage());
}

$logPath = STORAGE_PATH . '/logs/app.log';
$logOffsetBefore = is_file($logPath) ? (int) filesize($logPath) : 0;

try {
    $registry = new CapabilityRegistry();
    // Provider owned by the activated module.
    $registry->register('test.p13.active@1', 'cms-akira-core', static fn (): array => ['provider' => 'cms-akira-core'], 10, ['first']);
    // Provider owned by the unactivated module.
    $registry->register('test.p13.inactive@1', 'cms-akira-ai', static fn (): array => ['provider' => 'cms-akira-ai'], 10, ['first']);
    // Mixed: an inactive provider with HIGHER priority must be skipped so the
    // active provider serves.
    $registry->register('test.p13.mixed@1', 'cms-akira-ai', static fn (): array => ['provider' => 'cms-akira-ai'], 20, ['first']);
    $registry->register('test.p13.mixed@1', 'cms-akira-core', static fn (): array => ['provider' => 'cms-akira-core'], 10, ['first']);

    $bus = new CapabilityBus($registry);
    $options = static fn (int $tenant): array => [
        'caller' => ['module' => 'cms-akira-shell', 'user' => ['id' => 1, 'role' => 'administrator']],
        'tenant_id' => $tenant,
        'mode' => 'first',
    ];

    // ── 1. activated module → allowed ────────────────────────────────
    $result = $bus->call('test.p13.active@1', [], $options($tenantId));
    $check(($result['provider'] ?? '') === 'cms-akira-core', 'activated module is allowed to serve');

    // ── 2. unactivated module → refused with module_not_activated ────
    $refused = false;
    $message = '';
    try {
        $bus->call('test.p13.inactive@1', [], $options($tenantId));
    } catch (CapabilityCallException $e) {
        $message = $e->getMessage();
        $refused = str_contains($message, 'module_not_activated');
    } catch (Throwable $e) {
        $message = get_class($e) . ': ' . $e->getMessage();
    }
    $check($refused, 'unactivated module is refused with module_not_activated', $message);

    // The probe (route authority) must report the same distinct reason.
    $decision = $bus->authorize('test.p13.inactive@1', $options($tenantId));
    $check(
        ($decision['reason'] ?? '') === 'module_not_activated',
        'authorize() reports module_not_activated (not a policy reason)',
        (string) json_encode($decision)
    );

    // ── 3. inactive provider skipped; active provider serves ─────────
    $result = $bus->call('test.p13.mixed@1', [], $options($tenantId));
    $check(($result['provider'] ?? '') === 'cms-akira-core', 'inactive provider is skipped, active provider serves');

    // ── 4. unresolvable activation state → allowed (fail-safe) ───────
    $result = $bus->call('test.p13.inactive@1', [], $options($unresolvableTenantId));
    $check(
        ($result['provider'] ?? '') === 'cms-akira-ai',
        'unresolvable activation state allows the call (fail-safe)',
        (string) json_encode($result)
    );

    // ── 5. reason is observable in app.log ───────────────────────────
    $logDelta = '';
    if (is_file($logPath)) {
        $handle = fopen($logPath, 'rb');
        if ($handle !== false) {
            fseek($handle, $logOffsetBefore);
            $logDelta = (string) stream_get_contents($handle);
            fclose($handle);
        }
    }
    $check(
        str_contains($logDelta, 'module_not_activated'),
        'refusal is observable in app.log as module_not_activated',
        substr($logDelta, 0, 400)
    );
} finally {
    cleanupTestTenant($tenantId);
}

echo "activation gate dispatch: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
