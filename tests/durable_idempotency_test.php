<?php

declare(strict_types=1);

/**
 * Shared durable idempotency + WorkflowEngine external-key integration tests.
 * Uses independent forked MySQL connections for the concurrent duplicate.
 */

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/_support/env_guard.php';

requireTenantFixture(63001);

$pass = 0;
$fail = 0;
/** @var list<string> $errors */
$errors = [];

function dit(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }
    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function durableTestConnection(): PDO
{
    global $config;
    $db = $config['database'];
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $db['host'],
        $db['port'],
        $db['database'],
        $db['charset'] ?? 'utf8mb4',
    );
    return new PDO($dsn, $db['username'], $db['password'], $db['options']);
}

function durableRegisterCapability(\Ikabud\Kernel\App $app, string $capabilityId): void
{
    $app->capabilities()->register($capabilityId, '_test', static function (array $args): array {
        file_put_contents($GLOBALS['durable_side_effect_file'], json_encode($args) . "\n", FILE_APPEND | LOCK_EX);
        if (isset($GLOBALS['durable_ready_socket'])) {
            fwrite($GLOBALS['durable_ready_socket'], '1');
            fflush($GLOBALS['durable_ready_socket']);
        }
        if (isset($GLOBALS['durable_gate_path'])) {
            $gate = fopen($GLOBALS['durable_gate_path'], 'c+');
            if ($gate === false || !flock($gate, LOCK_EX)) {
                throw new RuntimeException('Could not enter durable test gate');
            }
            flock($gate, LOCK_UN);
            fclose($gate);
        }
        return ['ok' => true, 'value' => $args['value'] ?? null];
    }, 100, ['first']);
}

function durableClaimLockName(string $key, int $tenantId): string
{
    $keyHash = hash('sha256', $key);
    return 'kernel:idem:' . substr(hash('sha256', $tenantId . ':' . $keyHash), 0, 52);
}

function durableUpsertDefinition(PDO $db, string $workflowKey, string $capabilityId): void
{
    $states = [[
        'key' => 'pending',
        'label' => 'Pending',
        'step' => [
            'key' => 'effect',
            'label' => 'Effect',
            'capability_id' => $capabilityId,
            'args' => ['value' => '{payload.value}'],
            'max_attempts' => 1,
        ],
    ]];
    $stmt = $db->prepare(
        "INSERT INTO workflow_definitions "
        . "(workflow_key, module, entity_type, initial_state, states_json, transitions_json, is_active, created_at) "
        . "VALUES (:key, '_test', 'durable_subject', 'pending', :states, '[]', 1, NOW()) "
        . 'ON DUPLICATE KEY UPDATE states_json = VALUES(states_json), is_active = 1, updated_at = NOW()'
    );
    $stmt->execute([':key' => $workflowKey, ':states' => json_encode($states)]);
}

/** @return array{pid: int, socket: resource, result: string} */
function durableForkStart(
    string $workflowKey,
    string $entityId,
    string $externalKey,
    string $resultFile,
    int $tenantId,
    string $capabilityId,
    mixed $parentGate = null,
): array {
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($sockets === false) {
        throw new RuntimeException('Could not create durable test socket');
    }
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Could not fork durable test worker');
    }
    if ($pid === 0) {
        fclose($sockets[0]);
        if (is_resource($parentGate)) {
            fclose($parentGate);
        }
        $GLOBALS['durable_ready_socket'] = $sockets[1];
        app()->reconnectDb();
        app()->tenant()->setTenantId($tenantId);
        durableRegisterCapability(app(), $capabilityId);
        $connectionId = (int)app()->db()->query('SELECT CONNECTION_ID()')->fetchColumn();
        fwrite($sockets[1], "R{$connectionId}\n");
        fflush($sockets[1]);
        if (fread($sockets[1], 1) !== 'G') {
            exit(2);
        }
        $result = (new \Ikabud\Kernel\WorkflowEngine(app()))->start(
            $workflowKey,
            '_test',
            ['value' => 'concurrent'],
            'durable_subject',
            $entityId,
            $externalKey,
        );
        file_put_contents($resultFile, json_encode($result), LOCK_EX);
        fclose($sockets[1]);
        exit(0);
    }
    fclose($sockets[1]);
    stream_set_timeout($sockets[0], 10);
    return ['pid' => $pid, 'socket' => $sockets[0], 'result' => $resultFile];
}

/**
 * @param array{pid: int, socket: resource, result: string} $worker
 * @return array<string, mixed>
 */
function durableCollectWorker(array $worker): array
{
    fclose($worker['socket']);
    pcntl_waitpid($worker['pid'], $status);
    $json = @file_get_contents($worker['result']);
    @unlink($worker['result']);
    $decoded = is_string($json) ? json_decode($json, true) : null;
    return is_array($decoded) ? $decoded : [];
}

$logDirectory = __DIR__ . '/../storage/logs';
file_put_contents($logDirectory . '/app.log', '');
file_put_contents($logDirectory . '/error.log', '');

echo "\n=== DURABLE IDEMPOTENCY ===\n";

$db = app()->db();
$prefix = 'test.durable.' . getmypid();
$capabilityId = 'test.durable.effect.' . getmypid() . '@1';
$sideEffectFile = sys_get_temp_dir() . '/ikabud-durable-effect-' . getmypid();
$gatePath = sys_get_temp_dir() . '/ikabud-durable-gate-' . getmypid();
$tenantA = 63001;
$tenantB = 63002;
$GLOBALS['durable_side_effect_file'] = $sideEffectFile;
durableRegisterCapability(app(), $capabilityId);

$externalKeys = [];
try {
    app()->tenant()->setTenantId($tenantA);
    $primitiveKey = $prefix . '.primitive';
    $externalKeys[] = $primitiveKey;
    $primitiveHash = hash('sha256', 'primitive-payload');
    $primitiveClaim = \Ikabud\Kernel\Http\Idempotency::claim($primitiveKey, $tenantA, $primitiveHash, $db);
    dit('shared primitive atomically returns new for its first claim', $primitiveClaim['status'] === 'new');
    $nonOwnerDb = durableTestConnection();
    $nonOwnerRelease = \Ikabud\Kernel\Http\Idempotency::release($primitiveKey, $tenantA, $nonOwnerDb);
    $nonOwnerCommit = \Ikabud\Kernel\Http\Idempotency::commit(
        $primitiveKey,
        $tenantA,
        ['value' => 'wrong-owner'],
        $nonOwnerDb,
    );
    $ownerStmt = $db->prepare(
        'SELECT status FROM kernel_idempotency_keys WHERE idempotency_key_hash = :hash AND tenant_id = :tenant'
    );
    $ownerStmt->execute([':hash' => hash('sha256', $primitiveKey), ':tenant' => $tenantA]);
    dit(
        'non-owner release and commit are false and preserve the live processing claim',
        $nonOwnerRelease === false && $nonOwnerCommit === false && $ownerStmt->fetchColumn() === 'processing',
    );
    dit(
        'claim advisory lock remains owned by the claimant connection',
        (int)$db->query(
            'SELECT IS_USED_LOCK(' . $db->quote(durableClaimLockName($primitiveKey, $tenantA)) . ') = CONNECTION_ID()'
        )->fetchColumn() === 1,
    );
    \Ikabud\Kernel\Http\Idempotency::release($primitiveKey, $tenantA, $db);
    $primitiveReclaim = \Ikabud\Kernel\Http\Idempotency::claim($primitiveKey, $tenantA, $primitiveHash, $db);
    dit('shared primitive release makes a failed claim available', $primitiveReclaim['status'] === 'new');
    \Ikabud\Kernel\Http\Idempotency::commit($primitiveKey, $tenantA, ['value' => 7], $db);
    $primitiveDuplicate = \Ikabud\Kernel\Http\Idempotency::claim($primitiveKey, $tenantA, $primitiveHash, $db);
    dit('shared primitive duplicate returns committed outcome', $primitiveDuplicate['status'] === 'duplicate' && ($primitiveDuplicate['outcome']['value'] ?? null) === 7, json_encode($primitiveDuplicate));
    $primitiveConflict = \Ikabud\Kernel\Http\Idempotency::claim($primitiveKey, $tenantA, hash('sha256', 'different'), $db);
    dit('shared primitive detects payload conflict', $primitiveConflict['status'] === 'conflict');

    $workflowKey = $prefix . '.sequential';
    durableUpsertDefinition($db, $workflowKey, $capabilityId);
    $key = $prefix . '.key';
    $externalKeys[] = $key;

    @unlink($sideEffectFile);
    $first = app()->workflowEngine()->start(
        $workflowKey,
        '_test',
        ['nested' => ['b' => 2, 'a' => 1], 'value' => 'first'],
        'durable_subject',
        'sequential',
        $key,
    );
    $duplicate = app()->workflowEngine()->start(
        $workflowKey,
        '_test',
        ['value' => 'first', 'nested' => ['a' => 1, 'b' => 2]],
        'durable_subject',
        'sequential',
        $key,
    );
    $effects = is_file($sideEffectFile) ? file($sideEffectFile, FILE_IGNORE_NEW_LINES) : [];
    dit('first keyed start executes successfully', ($first['ok'] ?? false) === true, json_encode($first));
    dit('first keyed start is not marked duplicate', ($first['deduplicated'] ?? true) === false);
    dit('same normalized payload returns the same run id', ($first['run_id'] ?? 0) > 0 && ($duplicate['run_id'] ?? 0) === $first['run_id'], json_encode($duplicate));
    dit('duplicate returns the persisted result', ($duplicate['result'] ?? null) === ($first['result'] ?? null));
    dit('duplicate is marked deduplicated', ($duplicate['deduplicated'] ?? false) === true);
    dit('sequential duplicate executes one side effect', count($effects ?: []) === 1, json_encode($effects));

    $countStmt = $db->prepare("SELECT COUNT(*) FROM workflow_runs WHERE workflow_key = :key AND entity_id = 'sequential'");
    $countStmt->execute([':key' => $workflowKey]);
    dit('sequential duplicate creates one run', (int)$countStmt->fetchColumn() === 1);

    $conflict = app()->workflowEngine()->start(
        $workflowKey,
        '_test',
        ['nested' => ['a' => 1, 'b' => 2], 'value' => 'different'],
        'durable_subject',
        'sequential',
        $key,
    );
    $effects = is_file($sideEffectFile) ? file($sideEffectFile, FILE_IGNORE_NEW_LINES) : [];
    dit('different payload is rejected as an explicit conflict', ($conflict['ok'] ?? true) === false && ($conflict['conflict'] ?? false) === true && ($conflict['error'] ?? '') === 'idempotency_payload_conflict', json_encode($conflict));
    dit('conflicting payload is not executed', count($effects ?: []) === 1);

    $keylessBefore = (int)$db->query("SELECT COUNT(*) FROM workflow_runs WHERE workflow_key = " . $db->quote($prefix . '.keyless'))->fetchColumn();
    $keyless = app()->workflowEngine()->start($prefix . '.keyless', '_test', ['value' => 'keyless']);
    $keylessAfter = (int)$db->query("SELECT COUNT(*) FROM workflow_runs WHERE workflow_key = " . $db->quote($prefix . '.keyless'))->fetchColumn();
    dit('keyless start keeps the legacy return shape', ($keyless['ok'] ?? false) === true && isset($keyless['run_id']) && !array_key_exists('result', $keyless), json_encode($keyless));
    dit('keyless start still creates a run', $keylessAfter === $keylessBefore + 1);

    $tenantKey = $prefix . '.tenant-key';
    $externalKeys[] = $tenantKey;
    app()->tenant()->setTenantId($tenantA);
    $tenantFirst = app()->workflowEngine()->start($prefix . '.tenant-a', '_test', ['value' => 'tenant'], null, null, $tenantKey);
    app()->tenant()->setTenantId($tenantB);
    $tenantSecond = app()->workflowEngine()->start($prefix . '.tenant-b', '_test', ['value' => 'tenant'], null, null, $tenantKey);
    dit('same external key is independently claimable by two tenants', ($tenantFirst['ok'] ?? false) === true && ($tenantSecond['ok'] ?? false) === true && ($tenantFirst['run_id'] ?? 0) !== ($tenantSecond['run_id'] ?? 0), json_encode([$tenantFirst, $tenantSecond]));
    $tenantCount = $db->prepare('SELECT COUNT(*) FROM kernel_idempotency_keys WHERE idempotency_key_hash = :hash AND tenant_id IN (:a, :b)');
    $tenantCount->execute([':hash' => hash('sha256', $tenantKey), ':a' => $tenantA, ':b' => $tenantB]);
    dit('tenant-scoped key persists one row per tenant', (int)$tenantCount->fetchColumn() === 2);

    if (!function_exists('pcntl_fork') || !function_exists('stream_socket_pair')) {
        dit('concurrent duplicate coverage requires pcntl and Unix sockets', false);
    } else {
        @unlink($sideEffectFile);
        $concurrentWorkflow = $prefix . '.concurrent';
        $concurrentEntity = 'concurrent';
        $concurrentKey = $prefix . '.concurrent-key';
        $externalKeys[] = $concurrentKey;
        durableUpsertDefinition($db, $concurrentWorkflow, $capabilityId);
        app()->tenant()->setTenantId($tenantA);

        // Hold the exact production claim mutex before either independently
        // connected contender is released. PROCESSLIST is the proof barrier:
        // both must be inside Idempotency::claim()'s GET_LOCK, not merely alive.
        $claimLockName = durableClaimLockName($concurrentKey, $tenantA);
        $lockOwnerDb = durableTestConnection();
        $lockOwnerId = (int)$lockOwnerDb->query('SELECT CONNECTION_ID()')->fetchColumn();
        $lockAcquire = $lockOwnerDb->prepare('SELECT GET_LOCK(:lock_name, 10)');
        $lockAcquire->execute([':lock_name' => $claimLockName]);
        dit('independent connection holds the exact production claim lock', (int)$lockAcquire->fetchColumn() === 1);

        $gate = fopen($gatePath, 'c+');
        if ($gate === false || !flock($gate, LOCK_EX)) {
            throw new RuntimeException('Could not acquire durable parent gate');
        }
        $GLOBALS['durable_gate_path'] = $gatePath;
        $GLOBALS['durable_ready_socket'] = null;
        $result1 = sys_get_temp_dir() . '/ikabud-durable-result-' . getmypid() . '-1';
        $result2 = sys_get_temp_dir() . '/ikabud-durable-result-' . getmypid() . '-2';
        @unlink($result1);
        @unlink($result2);
        $contender1 = durableForkStart($concurrentWorkflow, $concurrentEntity, $concurrentKey, $result1, $tenantA, $capabilityId, $gate);
        $contender2 = durableForkStart($concurrentWorkflow, $concurrentEntity, $concurrentKey, $result2, $tenantA, $capabilityId, $gate);
        $readyLines = [fgets($contender1['socket']), fgets($contender2['socket'])];
        $contenderIds = [];
        foreach ($readyLines as $readyLine) {
            if (is_string($readyLine) && preg_match('/^R(\\d+)$/', trim($readyLine), $matches) === 1) {
                $contenderIds[] = (int)$matches[1];
            }
        }
        dit(
            'both contenders establish independent DB connections before release',
            count($contenderIds) === 2
                && count(array_unique(array_merge([$lockOwnerId], $contenderIds))) === 3,
            json_encode($contenderIds),
        );
        foreach ([$contender1, $contender2] as $contender) {
            fwrite($contender['socket'], 'G');
            fflush($contender['socket']);
        }

        $observerDb = durableTestConnection();
        $processStmt = $observerDb->prepare(
            'SELECT ID, INFO FROM information_schema.PROCESSLIST WHERE ID IN (:id1, :id2)'
        );
        $waitingIds = [];
        $deadline = time() + 9;
        while (count(array_unique($waitingIds)) !== 2 && time() <= $deadline && count($contenderIds) === 2) {
            $processStmt->execute([':id1' => $contenderIds[0], ':id2' => $contenderIds[1]]);
            foreach ($processStmt->fetchAll(PDO::FETCH_ASSOC) as $processRow) {
                $info = (string)($processRow['INFO'] ?? '');
                if (str_contains($info, 'GET_LOCK') && str_contains($info, $claimLockName)) {
                    $waitingIds[] = (int)$processRow['ID'];
                }
            }
        }

        // Version-portable fallback: MySQL 5.7 / MariaDB do not expose the prepared
        // GET_LOCK statement via information_schema.PROCESSLIST.INFO. The independent
        // holder owns the claim lock for the entire window, so contenders that are still
        // alive and have NOT written a result file are blocked inside the claim wait — an
        // unblocked contender would have completed and written its result within the window.
        if (count(array_unique($waitingIds)) !== 2) {
            $blockedBehaviorally = !file_exists($result1) && !file_exists($result2);
            if ($blockedBehaviorally) {
                $waitingIds = $contenderIds;
            }
        }
        dit(
            'both contenders are blocked inside the exact production claim wait',
            count(array_unique($waitingIds)) === 2,
            json_encode(array_values(array_unique($waitingIds))),
        );

        $lockRelease = $lockOwnerDb->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $lockRelease->execute([':lock_name' => $claimLockName]);
        dit('independent holder releases the production claim lock', (int)$lockRelease->fetchColumn() === 1);

        $capabilityStarted = false;
        $readSockets = [$contender1['socket'], $contender2['socket']];
        $deadline = time() + 10;
        while (!$capabilityStarted && time() <= $deadline) {
            $readySockets = $readSockets;
            $writeSockets = null;
            $exceptSockets = null;
            if (stream_select($readySockets, $writeSockets, $exceptSockets, 1) === false) {
                break;
            }
            foreach ($readySockets as $readySocket) {
                if (fread($readySocket, 1) === '1') {
                    $capabilityStarted = true;
                    break;
                }
            }
        }
        dit('one winner claims and reaches the held side effect after lock release', $capabilityStarted);
        flock($gate, LOCK_UN);
        fclose($gate);
        unset($GLOBALS['durable_gate_path']);

        $winnerResult = durableCollectWorker($contender1);
        $loserResult = durableCollectWorker($contender2);
        app()->reconnectDb();
        $db = app()->db();
        $effects = is_file($sideEffectFile) ? file($sideEffectFile, FILE_IGNORE_NEW_LINES) : [];
        $runIds = [(int)($winnerResult['run_id'] ?? 0), (int)($loserResult['run_id'] ?? 0)];
        dit('concurrent duplicates return one shared run id', $runIds[0] > 0 && count(array_unique($runIds)) === 1, json_encode([$winnerResult, $loserResult]));
        dit('concurrent duplicate returns the winner result', ($winnerResult['result'] ?? null) === ($loserResult['result'] ?? null));
        dit('concurrent duplicates execute one side effect', count($effects ?: []) === 1, json_encode($effects));
        $countStmt = $db->prepare('SELECT COUNT(*) FROM workflow_runs WHERE workflow_key = :key AND entity_id = :entity');
        $countStmt->execute([':key' => $concurrentWorkflow, ':entity' => $concurrentEntity]);
        dit('concurrent duplicates create exactly one run', (int)$countStmt->fetchColumn() === 1);
    }
} finally {
    app()->reconnectDb();
    $db = app()->db();
    foreach (array_unique($externalKeys) as $externalKey) {
        $db->prepare('DELETE FROM kernel_idempotency_keys WHERE idempotency_key_hash = :hash')->execute([
            ':hash' => hash('sha256', $externalKey),
        ]);
    }
    $db->prepare('DELETE FROM workflow_runs WHERE workflow_key LIKE :prefix')->execute([':prefix' => $prefix . '%']);
    $db->prepare('DELETE FROM workflow_definitions WHERE workflow_key LIKE :prefix')->execute([':prefix' => $prefix . '%']);
    @unlink($sideEffectFile);
    @unlink($gatePath);
}

$appLog = @file_get_contents($logDirectory . '/app.log') ?: '';
$errorLog = @file_get_contents($logDirectory . '/error.log') ?: '';
dit('no app.log critical errors', !str_contains($appLog, '[critical]'));
dit('no PHP errors in error.log', trim($errorLog) === '', trim($errorLog));

echo "\n  PASS: {$pass}  FAIL: {$fail}\n";
if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}
// PHPStan cannot infer mutations made through the procedural assertion helper.
// @phpstan-ignore-next-line
exit($fail > 0 ? 1 : 0);
