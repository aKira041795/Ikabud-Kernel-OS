<?php

/**
 * The capability seeder's refusal SEVERITY: which widening refusals deserve a warning.
 *
 * CHAIR-OWNED ACCEPTANCE, written BEFORE the change, so a red baseline means the behaviour is absent
 * rather than that the test is missing.
 *
 * The defect this fixes, measured 2026-09-20 on tenant 54:
 *
 *   `akira.post.publish@1` has 30 stored policy versions. v1-v21 carry `allowed_roles = admin`;
 *   v22-v30 carry `admin,administrator,superadmin`; **v30 is active**. The code still declares its seed
 *   against **v1**, so on every bootstrap it compares its wide declaration against v1's narrow row,
 *   correctly refuses to widen it, and logs `capability.policy.seed.widening_refused` at WARNING.
 *
 * Every bootstrap. For ever. Which is why `storage/logs/app.log` is 2212 bytes of permanent noise after
 * any bootstrap, why every "logs are clean" criterion in this repository has to carve out an exception
 * (one of the chair's own contract criteria did, earlier the same day), and -- the actual damage -- why
 * readers learn to ignore warnings.
 *
 * The distinction that matters, and it is the whole fix:
 *
 *   * refusing to widen the **ACTIVE** version means the code wants authority the system does not have:
 *     a human must look. WARNING.
 *   * refusing to widen a **SUPERSEDED** version means the store has already moved past it and the seed
 *     is inert bookkeeping: it cannot and must not rewrite history. INFO.
 *
 * Pure-logic on purpose: the severity is a function of two version numbers, so this suite needs no
 * database, no tenant and no bootstrap, has no skip path, and cannot pass vacuously.
 *
 * Interface fixed here, and the lane implements it:
 *
 *   Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry::seedRefusalSeverity(
 *       int $storedPolicyVersion,
 *       int $activePolicyVersion
 *   ): string    // 'warning' | 'info'
 */

declare(strict_types=1);

$class = 'Ikabud\\Kernel\\Capabilities\\CapabilityAuthorizationRegistry';
$file = dirname(__DIR__) . '/kernel/Capabilities/CapabilityAuthorizationRegistry.php';
require_once $file;

$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

echo "=== the severity decision exists, purely ===\n";

if (!is_callable([$class, 'seedRefusalSeverity'])) {
    $check('seedRefusalSeverity() exists', false, 'not implemented yet — this is the red baseline');
    echo "\n=== summary ===\n";
    printf("  %d passed, %d failed\n", $passed, $failed);
    exit(1);
}

$check('seedRefusalSeverity() is callable', true);

echo "\n=== the dangerous case stays loud ===\n";
// The store's own active version, refused: the code is asking for authority the system does not grant.
$check(
    'refusing to widen the ACTIVE version is a WARNING',
    $class::seedRefusalSeverity(30, 30) === 'warning',
    (string) $class::seedRefusalSeverity(30, 30)
);
$check(
    'and it is a warning at any version number, not just at one that was tested',
    $class::seedRefusalSeverity(1, 1) === 'warning' && $class::seedRefusalSeverity(7, 7) === 'warning'
);

echo "\n=== the bookkeeping case stops shouting ===\n";
// The measured defect: seed declares v1, the store has advanced to v30. Rewriting v1 is not the seeder's
// job, and doing so would be editing history.
$check(
    'refusing to widen a SUPERSEDED version is INFO',
    $class::seedRefusalSeverity(1, 30) === 'info',
    (string) $class::seedRefusalSeverity(1, 30)
);
$check(
    'and every superseded version is quiet, not only the oldest',
    $class::seedRefusalSeverity(21, 30) === 'info'
    && $class::seedRefusalSeverity(29, 30) === 'info'
    && $class::seedRefusalSeverity(2, 3) === 'info'
);

echo "\n=== it is a decision about versions, and nothing else ===\n";
$check(
    'one refusal, one severity — deterministic',
    $class::seedRefusalSeverity(1, 30) === $class::seedRefusalSeverity(1, 30)
);
$check(
    'only the two defined severities are ever returned',
    in_array($class::seedRefusalSeverity(5, 5), ['warning', 'info'], true)
    && in_array($class::seedRefusalSeverity(5, 9), ['warning', 'info'], true)
);
$check(
    'a version ABOVE the active one is not treated as active (a store ahead of us is not our refusal)',
    $class::seedRefusalSeverity(31, 30) === 'info',
    (string) $class::seedRefusalSeverity(31, 30)
);

echo "\n=== summary ===\n";
printf("  %d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
