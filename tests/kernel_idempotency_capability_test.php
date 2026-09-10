<?php

declare(strict_types=1);

/** Guarded-module integration test for the kernel-owned idempotency bridge. */

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/_support/env_guard.php';

requireTenantFixture(953331);

$pass = 0;
$fail = 0;
$assert = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

$app = app();
$db = $app->db();
$originalTenantId = $app->tenant()->current();
$tenantId = 953331;
$app->tenant()->setTenantId($tenantId);
$prefix = 'kernel-capability-test-' . bin2hex(random_bytes(8));
$keys = [
    'complete' => $prefix . '-complete',
    'processing' => $prefix . '-processing',
    'release' => $prefix . '-release',
    'wrong-tenant' => $prefix . '-wrong-tenant',
    'wrong-pdo' => $prefix . '-wrong-pdo',
];

try {
    moduleWithContext('daily-ledger', static function () use ($app, $db, $tenantId, $keys, $assert): void {
        $hash = $app->cap()->call('kernel.idempotency.hash@1', [
            'payload' => ['method' => 'POST', 'body' => ['b' => 2, 'a' => 1]],
        ]);
        $sameHash = $app->cap()->call('kernel.idempotency.hash@1', [
            'payload' => ['body' => ['a' => 1, 'b' => 2], 'method' => 'POST'],
        ]);
        $assert('hash capability uses the canonical kernel hash', $hash === $sameHash && strlen($hash) === 64);

        $db->beginTransaction();
        $claim = $app->cap()->call('kernel.idempotency.claim@1', [
            'key' => $keys['complete'],
            'tenant_id' => $tenantId,
            'payload_hash' => $hash,
            'db' => $db,
            'wait_cap_seconds' => 0,
        ]);
        $assert('guarded module claims on its caller PDO', ($claim['status'] ?? null) === 'new', json_encode($claim));

        $writeStmt = $db->prepare(
            'INSERT INTO audit_logs (module, action, entity_type, entity_id) '
            . 'VALUES (:module, :action, :entity_type, :entity_id)'
        );
        $write = $writeStmt->execute([
            ':module' => 'daily-ledger',
            ':action' => 'idempotency.capability.test',
            ':entity_type' => 'kernel_idempotency_capability_test',
            ':entity_id' => $keys['complete'],
        ]);
        $assert('guarded module writes its co-owned table in the same transaction', $write);

        $outcome = ['ok' => true, 'marker' => $keys['complete']];
        $committed = $app->cap()->call('kernel.idempotency.commit@1', [
            'key' => $keys['complete'],
            'tenant_id' => $tenantId,
            'outcome' => $outcome,
            'db' => $db,
        ]);
        $assert('commit capability persists the supplied outcome', $committed === true);
        $assert('capability commit does not commit or roll back caller transaction', $db->inTransaction());
        $db->commit();

        $duplicate = $app->cap()->call('kernel.idempotency.claim@1', [
            'key' => $keys['complete'],
            'tenant_id' => $tenantId,
            'payload_hash' => $hash,
            'db' => $db,
            'wait_cap_seconds' => 0,
        ]);
        $assert(
            'duplicate returns the stored outcome',
            ($duplicate['status'] ?? null) === 'duplicate' && ($duplicate['outcome'] ?? null) === $outcome,
            json_encode($duplicate),
        );

        $conflict = $app->cap()->call('kernel.idempotency.claim@1', [
            'key' => $keys['complete'],
            'tenant_id' => $tenantId,
            'payload_hash' => hash('sha256', 'different-envelope'),
            'db' => $db,
            'wait_cap_seconds' => 0,
        ]);
        $assert('conflicting payload is rejected', ($conflict['status'] ?? null) === 'conflict', json_encode($conflict));

        $processingHash = hash('sha256', 'processing');
        $first = $app->cap()->call('kernel.idempotency.claim@1', [
            'key' => $keys['processing'],
            'tenant_id' => $tenantId,
            'payload_hash' => $processingHash,
            'db' => $db,
            'wait_cap_seconds' => 0,
        ]);
        $second = $app->cap()->call('kernel.idempotency.claim@1', [
            'key' => $keys['processing'],
            'tenant_id' => $tenantId,
            'payload_hash' => $processingHash,
            'db' => $db,
            'wait_cap_seconds' => 0,
        ]);
        $assert('an unpublished claim is rejected as in_progress', ($first['status'] ?? null) === 'new' && ($second['status'] ?? null) === 'in_progress');
        $app->cap()->call('kernel.idempotency.release@1', [
            'key' => $keys['processing'],
            'tenant_id' => $tenantId,
            'db' => $db,
        ]);

        $db->beginTransaction();
        $releaseClaim = $app->cap()->call('kernel.idempotency.claim@1', [
            'key' => $keys['release'],
            'tenant_id' => $tenantId,
            'payload_hash' => hash('sha256', 'release'),
            'db' => $db,
            'wait_cap_seconds' => 0,
        ]);
        $released = $app->cap()->call('kernel.idempotency.release@1', [
            'key' => $keys['release'],
            'tenant_id' => $tenantId,
            'db' => $db,
        ]);
        $assert('release removes a processing claim', ($releaseClaim['status'] ?? null) === 'new' && $released === true);
        $assert('release capability does not commit or roll back caller transaction', $db->inTransaction());
        $db->rollBack();

        $db->beginTransaction();
        $wrongTenantDenied = false;
        try {
            $app->cap()->call('kernel.idempotency.claim@1', [
                'key' => $keys['wrong-tenant'],
                'tenant_id' => $tenantId + 1,
                'payload_hash' => $hash,
                'db' => $db,
                'wait_cap_seconds' => 0,
            ]);
        } catch (Throwable) {
            $wrongTenantDenied = true;
        }
        $assert('wrong tenant is rejected while caller transaction remains owned', $wrongTenantDenied && $db->inTransaction());
        $db->rollBack();

        global $config;
        $database = $config['database'];
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $database['host'],
            $database['port'],
            $database['database'],
            $database['charset'] ?? 'utf8mb4',
        );
        $otherDb = new PDO($dsn, $database['username'], $database['password'], $database['options']);

        $db->beginTransaction();
        $wrongPdoDenied = false;
        try {
            $app->cap()->call('kernel.idempotency.claim@1', [
                'key' => $keys['wrong-pdo'],
                'tenant_id' => $tenantId,
                'payload_hash' => $hash,
                'db' => $otherDb,
                'wait_cap_seconds' => 0,
            ]);
        } catch (Throwable) {
            $wrongPdoDenied = true;
        }
        $assert('wrong PDO is rejected while caller transaction remains owned', $wrongPdoDenied && $db->inTransaction());
        $db->rollBack();
    });

    foreach (['wrong-tenant', 'wrong-pdo'] as $name) {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM kernel_idempotency_keys WHERE idempotency_key_hash = :hash'
        );
        $stmt->execute([':hash' => hash('sha256', $keys[$name])]);
        $assert("{$name} rejection occurs before a row write", (int)$stmt->fetchColumn() === 0);
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $assert('kernel idempotency capability scenario completes', false, $e::class . ': ' . $e->getMessage());
} finally {
    try {
        $hashes = array_map(static fn (string $key): string => hash('sha256', $key), array_values($keys));
        $quoted = implode(',', array_fill(0, count($hashes), '?'));
        $db->prepare("DELETE FROM kernel_idempotency_keys WHERE idempotency_key_hash IN ({$quoted})")->execute($hashes);
        $db->prepare(
            "DELETE FROM audit_logs WHERE entity_type = 'kernel_idempotency_capability_test' AND entity_id = ?"
        )->execute([$keys['complete']]);
    } catch (Throwable $e) {
        $assert('test idempotency row cleanup succeeds', false, $e->getMessage());
    }
    $app->tenant()->setTenantId($originalTenantId);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
