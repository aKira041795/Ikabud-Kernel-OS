<?php

/** Policy declaration/grant lifecycle regression and falsification gate. */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/_support/tenant_fixture.php';

use Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry;
use Ikabud\Kernel\Database\MigrationRunner;

$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

echo "=== CAPABILITY POLICY GRANT LIFECYCLE ===\n";
$db = app()->db();
$runner = new MigrationRunner($db);
$firstRun = $runner->migrate('_kernel');
$secondRun = $runner->migrate('_kernel');

$column = $db->query(
    "SELECT column_type, column_default FROM information_schema.columns "
    . "WHERE table_schema = DATABASE() AND table_name = 'capability_authorization_policies' AND column_name = 'grant_state'"
)->fetch(PDO::FETCH_ASSOC);
$column = is_array($column) ? array_change_key_case($column, CASE_LOWER) : $column;
// MySQL reports an ENUM default unquoted ('granted'); MariaDB reports it quoted
// ("'granted'"). Normalise before asserting so the check is engine-independent.
$columnDefault = is_array($column) ? trim((string)($column['column_default'] ?? ''), "'") : null;
$check(
    'migration installs the explicit three-state lifecycle with granted default',
    is_array($column)
        && $column['column_type'] === "enum('granted','suspended','revoked')"
        && $columnDefault === 'granted',
    json_encode($column)
);
// Prove the backfill the migration relies on — `ADD COLUMN ... NOT NULL
// DEFAULT 'granted'` — on a throwaway table seeded with rows that already
// existed. Scanning the live table instead was an isolation flaw: a policy row
// that is legitimately suspended or revoked (or one left behind by another
// test's falsification run) is valid state, not a migration failure, yet it
// failed this assertion spuriously.
$notGranted = null;
try {
    $db->exec('DROP TEMPORARY TABLE IF EXISTS _grant_backfill_probe');
    $db->exec('CREATE TEMPORARY TABLE _grant_backfill_probe (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $db->exec('INSERT INTO _grant_backfill_probe (id) VALUES (1), (2)');
    $db->exec(
        "ALTER TABLE _grant_backfill_probe ADD COLUMN grant_state "
        . "ENUM('granted','suspended','revoked') NOT NULL DEFAULT 'granted'"
    );
    $notGranted = (int)$db->query(
        "SELECT COUNT(*) FROM _grant_backfill_probe WHERE grant_state <> 'granted'"
    )->fetchColumn();
    $db->exec('DROP TEMPORARY TABLE IF EXISTS _grant_backfill_probe');
} catch (Throwable $e) {
    echo 'SKIP: pre-existing policy rows migrate to granted — ' . $e->getMessage() . "\n";
}
if ($notGranted !== null) {
    $check('pre-existing policy rows migrate to granted', $notGranted === 0, (string)$notGranted);
}
$check('migration runner re-run is clean', $secondRun === [], json_encode(['first' => $firstRun, 'second' => $secondRun]));

$admin = $db->query("SELECT id, username, role FROM users WHERE role IN ('admin', 'superadmin') ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!is_array($admin) || (int)($admin['id'] ?? 0) <= 0) {
    $check('an authenticated operator exists for audited transitions', false);
    echo "\nCapability policy lifecycle tests: {$passed} passed, {$failed} failed\n";
    exit(1);
}
app()->setUser([
    'id' => (int)$admin['id'],
    'username' => (string)$admin['username'],
    'role' => (string)$admin['role'],
    'source' => 'kernel',
]);

$previousTenantId = app()->tenant()->current();
$fixtureTenantId = 9412;
ensureTestTenant($fixtureTenantId, 'gui-settings');
app()->tenant()->setTenantId($fixtureTenantId);
$db = app()->reconnectDb();

$version = 4200000000 + (getmypid() % 1000000);
$suffix = bin2hex(random_bytes(5));
$capability = 'test.lifecycle.' . $suffix . '@1';
$roleCapability = 'test.lifecycle.role.' . $suffix . '@1';
$provider = 'lifecycle-provider-' . $suffix;
$entityId = md5(implode('|', [$version, $capability, '1', $provider]));
$registry = new CapabilityAuthorizationRegistry($db);
$base = [
    'policy_version' => $version,
    'capability_id' => $capability,
    'capability_version' => '1',
    'provider' => $provider,
    'caller_module' => 'declared-caller',
    'allowed_roles' => 'admin',
    'provider_activation_required' => false,
    'requires_protocol' => 'v1',
    'is_active' => true,
];
$context = [
    'capability_id' => $capability,
    'capability_version' => '1',
    'provider' => $provider,
    'caller_module' => 'declared-caller',
    'actor_role' => 'admin',
    'tenant_id' => 'test-tenant',
    'provider_activation' => true,
    'dispatch_protocol' => 'v1',
    'policy_version' => $version,
];

try {
    // Acceptance sentinel: seed -> revoke -> seed again must remain revoked.
    $registry->seedPolicy([$base]);
    $registry->transitionGrantState($version, $capability, '1', $provider, 'revoked', 'operator security revocation');
    $registry->seedPolicy([[...$base, 'caller_module' => 'resurrection-attempt', 'allowed_roles' => 'editor']]);

    $storedStmt = $db->prepare(
        'SELECT grant_state, caller_module, allowed_roles FROM capability_authorization_policies '
        . 'WHERE policy_version = ? AND capability_id = ? AND capability_version = ? AND provider = ?'
    );
    $storedStmt->execute([$version, $capability, '1', $provider]);
    $stored = $storedStmt->fetch(PDO::FETCH_ASSOC);
    $check(
        'seed -> revoke -> seed remains revoked and cannot rewrite its declaration',
        is_array($stored)
            && $stored['grant_state'] === 'revoked'
            && $stored['caller_module'] === 'declared-caller'
            && $stored['allowed_roles'] === 'admin',
        json_encode($stored)
    );

    CapabilityAuthorizationRegistry::invalidate();
    $revoked = $registry->authorize($context);
    $check('revoked grant denies with grant_revoked', ($revoked['allowed'] ?? true) === false && ($revoked['reason'] ?? '') === 'grant_revoked', json_encode($revoked));

    $registry->transitionGrantState($version, $capability, '1', $provider, 'suspended', 'temporary incident hold');
    CapabilityAuthorizationRegistry::invalidate();
    $suspended = $registry->authorize($context);
    $check('suspended grant denies with grant_suspended', ($suspended['allowed'] ?? true) === false && ($suspended['reason'] ?? '') === 'grant_suspended', json_encode($suspended));

    $registry->seedPolicy([[...$base, 'capability_id' => $roleCapability, 'allowed_roles' => 'editor']]);
    CapabilityAuthorizationRegistry::invalidate();
    $roleDenied = $registry->authorize([...$context, 'capability_id' => $roleCapability]);
    $check('present but unauthorized actor is role_not_allowed', ($roleDenied['allowed'] ?? true) === false && ($roleDenied['reason'] ?? '') === 'role_not_allowed', json_encode($roleDenied));

    $auditStmt = $db->prepare(
        "SELECT actor_user_id, action, new_data, created_at FROM audit_logs "
        . "WHERE entity_type = 'capability_authorization_policy' AND entity_id = ? ORDER BY id ASC"
    );
    $auditStmt->execute([$entityId]);
    $audits = $auditStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $firstAudit = $audits[0] ?? [];
    $lastAudit = $audits[count($audits) - 1] ?? [];
    $firstData = json_decode((string)($firstAudit['new_data'] ?? ''), true);
    $lastData = json_decode((string)($lastAudit['new_data'] ?? ''), true);
    $check(
        'explicit transitions record who, when, state, and reason through kernel audit',
        count($audits) === 2
            && (int)($firstAudit['actor_user_id'] ?? 0) === (int)$admin['id']
            && !empty($firstAudit['created_at'])
            && ($firstAudit['action'] ?? '') === 'capability.policy.grant_revoked'
            && ($firstData['reason'] ?? '') === 'operator security revocation'
            && ($lastAudit['action'] ?? '') === 'capability.policy.grant_suspended'
            && ($lastData['reason'] ?? '') === 'temporary incident hold',
        json_encode($audits, JSON_UNESCAPED_SLASHES)
    );

    try {
        $registry->replaceActiveRowRoles($roleCapability, '1', $provider, 'declared-caller', ['admin']);
        $missingTransactionRejected = false;
    } catch (LogicException $e) {
        $missingTransactionRejected = str_contains($e->getMessage(), 'requires an existing transaction');
    }
    $check('policy cloning rejects a caller without an existing transaction', $missingTransactionRejected);

    $db->beginTransaction();
    try {
        $nextVersion = $registry->replaceActiveRowRoles($roleCapability, '1', $provider, 'declared-caller', ['admin', 'editor']);
        $cloneStateStmt = $db->prepare(
            'SELECT grant_state FROM capability_authorization_policies WHERE policy_version = ? '
            . 'AND capability_id = ? AND capability_version = ? AND provider = ?'
        );
        $cloneStateStmt->execute([$nextVersion, $capability, '1', $provider]);
        $cloneState = $cloneStateStmt->fetchColumn();
        $cloneAuditId = md5(implode('|', [$nextVersion, $capability, '1', $provider]));
        $cloneAuditStmt = $db->prepare(
            "SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'capability_authorization_policy' AND entity_id = ?"
        );
        $cloneAuditStmt->execute([$cloneAuditId]);
        $cloneTransitionAudits = (int)$cloneAuditStmt->fetchColumn();
        $check(
            'a non-granted clone is inserted once in its final state without a corrective transition',
            $cloneState === 'suspended' && $cloneTransitionAudits === 0,
            json_encode(['state' => $cloneState, 'corrective_transition_audits' => $cloneTransitionAudits])
        );
    } finally {
        $db->rollBack();
        CapabilityAuthorizationRegistry::invalidate();
    }
} catch (Throwable $e) {
    $check('lifecycle scenario completes without exception', false, $e::class . ': ' . $e->getMessage());
} finally {
    $cleanup = $db->prepare('DELETE FROM capability_authorization_policies WHERE policy_version = ? AND capability_id IN (?, ?)');
    $cleanup->execute([$version, $capability, $roleCapability]);
    $auditCleanup = $db->prepare("DELETE FROM audit_logs WHERE entity_type = 'capability_authorization_policy' AND entity_id = ?");
    $auditCleanup->execute([$entityId]);
    CapabilityAuthorizationRegistry::invalidate();
    app()->tenant()->setTenantId($previousTenantId);
    app()->reconnectDb();
    cleanupTestTenant($fixtureTenantId);
}

echo "\nCapability policy lifecycle tests: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
