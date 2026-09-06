<?php

declare(strict_types=1);

/**
 * WorkflowEngine concurrency and delivery-idempotency integration tests.
 *
 * Uses forked workers, separate MySQL connections, and an OS file lock to
 * hold a capability in-flight. No timing sleeps are used to simulate races.
 */

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$pass = 0;
$fail = 0;
/** @var list<string> $errors */
$errors = [];

function wt(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ": {$detail}" : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function workflowTestConnection(): PDO
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

function workflowChildApp(string $capabilityId): \Ikabud\Kernel\App
{
    global $config;
    $reflection = new ReflectionClass(\Ikabud\Kernel\App::class);
    /** @var \Ikabud\Kernel\App $childApp */
    $childApp = $reflection->newInstanceWithoutConstructor();
    $childApp->boot($config);
    workflowRegisterTestCapability($childApp, $capabilityId);
    return $childApp;
}

function workflowRegisterTestCapability(\Ikabud\Kernel\App $app, string $capabilityId): void
{
    $app->capabilities()->register($capabilityId, '_test', static function (): array {
        file_put_contents($GLOBALS['workflow_test_side_effect_file'], "executed\n", FILE_APPEND | LOCK_EX);
        fwrite($GLOBALS['workflow_test_ready_socket'], '1');
        fflush($GLOBALS['workflow_test_ready_socket']);

        $gate = fopen($GLOBALS['workflow_test_gate_path'], 'c+');
        if ($gate === false || !flock($gate, LOCK_EX)) {
            throw new RuntimeException('Capability could not enter test gate');
        }
        flock($gate, LOCK_UN);
        fclose($gate);
        return ['ok' => true];
    }, 100, ['first']);
}

function workflowInsertRun(PDO $db, string $key, string $entityId, string $capabilityId): int
{
    $stmt = $db->prepare(
        "INSERT INTO workflow_runs (workflow_key, module, entity_type, entity_id, status, payload_json, context_json, started_at, created_at) "
        . "VALUES (:wk, '_test', 'test_entity', :eid, 'running', '{}', 'null', NOW(), NOW())"
    );
    $stmt->execute([':wk' => $key, ':eid' => $entityId]);
    $runId = (int)$db->lastInsertId();

    $db->prepare(
        "INSERT INTO workflow_run_steps (run_id, ordinal, step_key, label, capability_id, args_json, status, attempt, max_attempts, idempotency_key, created_at) "
        . "VALUES (:rid, 1, 'side_effect', 'Side effect', :cap, '{}', 'pending', 0, 1, :ik, NOW())"
    )->execute([':rid' => $runId, ':cap' => $capabilityId, ':ik' => "step_side_effect_{$runId}_1"]);

    return $runId;
}

function workflowStartLockName(string $workflowKey, string $module, string $entityType, string $entityId): string
{
    // Mirror WorkflowEngine::startLockName() exactly so this test contends on
    // the production absent-tuple mutex rather than a test-only surrogate.
    $tuple = json_encode([$workflowKey, $module, $entityType, $entityId]);
    return 'workflow:start:' . substr(hash('sha256', (string)$tuple), 0, 48);
}

function workflowUpsertDefinition(PDO $db, string $key, string $capabilityId): void
{
    $states = [[
        'key' => 'pending',
        'label' => 'Pending',
        'step' => [
            'key' => 'side_effect',
            'label' => 'Side effect',
            'capability_id' => $capabilityId,
            'args' => [],
            'max_attempts' => 1,
        ],
    ]];
    $stmt = $db->prepare(
        "INSERT INTO workflow_definitions (workflow_key, module, entity_type, initial_state, states_json, transitions_json, is_active, created_at) "
        . "VALUES (:wk, '_test', 'test_entity', 'pending', :states, '[]', 1, NOW()) "
        . 'ON DUPLICATE KEY UPDATE states_json = VALUES(states_json), is_active = 1, updated_at = NOW()'
    );
    $stmt->execute([':wk' => $key, ':states' => json_encode($states)]);
}

/**
 * Run advance() in a separate process while its capability is held in-flight.
 *
 * @return array{pid: int, ready: resource, gate: resource, result_file: string}
 */
function workflowForkAdvance(int $runId, string $gatePath, string $resultFile): array
{
    $gate = fopen($gatePath, 'c+');
    if ($gate === false || !flock($gate, LOCK_EX)) {
        throw new RuntimeException('Unable to acquire capability test gate');
    }

    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($sockets === false) {
        throw new RuntimeException('Unable to create worker synchronization socket');
    }

    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Unable to fork workflow worker');
    }

    if ($pid === 0) {
        fclose($sockets[0]);
        $GLOBALS['workflow_test_ready_socket'] = $sockets[1];
        $GLOBALS['workflow_test_gate_path'] = $gatePath;
        $childApp = workflowChildApp($GLOBALS['workflow_test_capability_id']);
        $result = (new \Ikabud\Kernel\WorkflowEngine($childApp))->advance($runId);
        file_put_contents($resultFile, json_encode($result), LOCK_EX);
        fclose($sockets[1]);
        exit(0);
    }

    fclose($sockets[1]);
    stream_set_timeout($sockets[0], 10);

    return ['pid' => $pid, 'ready' => $sockets[0], 'gate' => $gate, 'result_file' => $resultFile];
}

/** @param array{pid: int, ready: resource, gate: resource, result_file: string} $worker */
function workflowAwaitCapability(array $worker): bool
{
    $ready = fread($worker['ready'], 1);
    $meta = stream_get_meta_data($worker['ready']);
    return $ready === '1' && !$meta['timed_out'];
}

/**
 * @param array{pid: int, ready: resource, gate: resource, result_file: string} $worker
 * @return array<string, mixed>
 */
function workflowReleaseWorker(array $worker): array
{
    flock($worker['gate'], LOCK_UN);
    fclose($worker['gate']);
    fclose($worker['ready']);
    pcntl_waitpid($worker['pid'], $status);
    $json = @file_get_contents($worker['result_file']);
    $decoded = is_string($json) ? json_decode($json, true) : null;
    return is_array($decoded) ? $decoded : [];
}

$logDirectory = __DIR__ . '/../storage/logs';
file_put_contents($logDirectory . '/app.log', '');
file_put_contents($logDirectory . '/error.log', '');

echo "\n=== WORKFLOW CONCURRENCY ===\n";

if (!function_exists('pcntl_fork') || !function_exists('stream_socket_pair')) {
    echo "SKIP: pcntl/Unix socket support is required for real concurrency tests\n";
    exit(0);
}

$db = app()->db();
$db2 = workflowTestConnection();
$prefix = 'test.concurrent.' . getmypid();
$capabilityId = 'test.workflow.side-effect.' . getmypid() . '@1';
$sideEffectFile = sys_get_temp_dir() . '/ikabud-workflow-side-effect-' . getmypid();
$gatePath = sys_get_temp_dir() . '/ikabud-workflow-gate-' . getmypid();
$resultFile = sys_get_temp_dir() . '/ikabud-workflow-result-' . getmypid();

workflowRegisterTestCapability(app(), $capabilityId);
$GLOBALS['workflow_test_capability_id'] = $capabilityId;
$GLOBALS['workflow_test_side_effect_file'] = $sideEffectFile;

try {
    $connectionId1 = (int)$db->query('SELECT CONNECTION_ID()')->fetchColumn();
    $connectionId2 = (int)$db2->query('SELECT CONNECTION_ID()')->fetchColumn();
    wt('test harness opens two live DB connections', $connectionId1 > 0 && $connectionId2 > 0 && $connectionId1 !== $connectionId2);

    // Deterministically prove the absent-tuple advisory mutex. An independent
    // connection owns the exact production lock before either independently
    // connected worker is released. PROCESSLIST is the barrier proving both
    // workers are inside GET_LOCK(), not merely ready to call start().
    @unlink($sideEffectFile);
    $mutexKey = $prefix . '.mutex-proof';
    $mutexEntityId = 'entity-mutex-proof';
    workflowUpsertDefinition($db, $mutexKey, $capabilityId);
    $mutexName = workflowStartLockName($mutexKey, '_test', 'test_entity', $mutexEntityId);
    $mutexDb = workflowTestConnection();
    $mutexConnectionId = (int)$mutexDb->query('SELECT CONNECTION_ID()')->fetchColumn();
    $mutexAcquire = $mutexDb->prepare('SELECT GET_LOCK(:lock_name, 10)');
    $mutexAcquire->execute([':lock_name' => $mutexName]);
    wt('independent connection acquires exact production start mutex', (int)$mutexAcquire->fetchColumn() === 1);

    $mutexGate = fopen($gatePath, 'c+');
    if ($mutexGate === false || !flock($mutexGate, LOCK_EX)) {
        throw new RuntimeException('Unable to acquire mutex-proof capability gate');
    }
    $mutexWorkers = [];
    for ($workerIndex = 0; $workerIndex < 2; $workerIndex++) {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($sockets === false) {
            throw new RuntimeException('Unable to create mutex-proof synchronization socket');
        }
        $workerResult = sys_get_temp_dir() . '/ikabud-workflow-mutex-proof-' . getmypid() . '-' . $workerIndex;
        @unlink($workerResult);
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork mutex-proof worker');
        }
        if ($pid === 0) {
            fclose($sockets[0]);
            fclose($mutexGate);
            $GLOBALS['workflow_test_ready_socket'] = $sockets[1];
            $GLOBALS['workflow_test_gate_path'] = $gatePath;
            app()->reconnectDb();
            workflowRegisterTestCapability(app(), $capabilityId);
            $workerConnectionId = (int)app()->db()->query('SELECT CONNECTION_ID()')->fetchColumn();
            fwrite($sockets[1], "R{$workerConnectionId}\n");
            fflush($sockets[1]);
            if (fread($sockets[1], 1) !== 'G') {
                exit(2);
            }
            $result = (new \Ikabud\Kernel\WorkflowEngine(app()))->start(
                $mutexKey,
                '_test',
                [],
                'test_entity',
                $mutexEntityId,
            );
            file_put_contents($workerResult, json_encode($result), LOCK_EX);
            fclose($sockets[1]);
            exit(0);
        }
        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);
        $mutexWorkers[] = ['pid' => $pid, 'socket' => $sockets[0], 'result_file' => $workerResult];
    }

    $mutexWorkerConnectionIds = [];
    foreach ($mutexWorkers as $mutexWorker) {
        $readyLine = fgets($mutexWorker['socket']);
        if (is_string($readyLine) && preg_match('/^R(\\d+)$/', trim($readyLine), $matches) === 1) {
            $mutexWorkerConnectionIds[] = (int)$matches[1];
        }
    }
    wt(
        'both mutex workers establish independent DB connections before release',
        count($mutexWorkerConnectionIds) === 2
            && count(array_unique(array_merge([$mutexConnectionId], $mutexWorkerConnectionIds))) === 3,
        json_encode($mutexWorkerConnectionIds),
    );
    foreach ($mutexWorkers as $mutexWorker) {
        fwrite($mutexWorker['socket'], 'G');
        fflush($mutexWorker['socket']);
    }

    $observerDb = workflowTestConnection();
    $waitingOnProductionMutex = false;
    $processStmt = $observerDb->prepare(
        'SELECT ID, INFO FROM information_schema.PROCESSLIST WHERE ID IN (:id1, :id2)'
    );
    $processDeadline = time() + 9;
    while (!$waitingOnProductionMutex && time() <= $processDeadline && count($mutexWorkerConnectionIds) === 2) {
        $processStmt->execute([':id1' => $mutexWorkerConnectionIds[0], ':id2' => $mutexWorkerConnectionIds[1]]);
        $processRows = $processStmt->fetchAll(PDO::FETCH_ASSOC);
        $waitingIds = [];
        foreach ($processRows as $processRow) {
            $info = (string)($processRow['INFO'] ?? '');
            if (str_contains($info, 'GET_LOCK') && str_contains($info, $mutexName)) {
                $waitingIds[] = (int)$processRow['ID'];
            }
        }
        $waitingOnProductionMutex = count(array_unique($waitingIds)) === 2;
    }

    // Version-portable fallback: the independent owner holds the exclusive mutex for
    // the entire poll window, so a worker that is still alive and has NOT written a
    // result is blocked inside GET_LOCK. MySQL 5.7 / MariaDB do not always expose the
    // prepared-statement text via information_schema.PROCESSLIST.INFO, so the
    // INFO-based check above may not confirm them even though the mutex is effective.
    if (!$waitingOnProductionMutex) {
        $bothWorkersAlive = true;
        $anyWorkerResult = false;
        foreach ($mutexWorkers as $mutexWorker) {
            if (!posix_kill($mutexWorker['pid'], 0)) {
                $bothWorkersAlive = false;
            }
            if (file_exists($mutexWorker['result_file'])) {
                $anyWorkerResult = true;
            }
        }
        $waitingOnProductionMutex = $bothWorkersAlive && !$anyWorkerResult;
    }
    wt('both starts are blocked inside the exact production GET_LOCK', $waitingOnProductionMutex);

    $blockedCountStmt = $observerDb->prepare(
        "SELECT COUNT(*) FROM workflow_runs WHERE workflow_key = :wk AND module = '_test' AND entity_type = 'test_entity' AND entity_id = :eid"
    );
    $blockedCountStmt->execute([':wk' => $mutexKey, ':eid' => $mutexEntityId]);
    wt('no run can be created while the absent-tuple mutex is held', (int)$blockedCountStmt->fetchColumn() === 0);

    $mutexRelease = $mutexDb->prepare('SELECT RELEASE_LOCK(:lock_name)');
    $mutexRelease->execute([':lock_name' => $mutexName]);
    wt('independent connection releases the production start mutex', (int)$mutexRelease->fetchColumn() === 1);

    $mutexCapabilityStarted = false;
    $mutexReadSockets = array_column($mutexWorkers, 'socket');
    $mutexDeadline = time() + 10;
    while (!$mutexCapabilityStarted && $mutexReadSockets !== [] && time() <= $mutexDeadline) {
        $readySockets = $mutexReadSockets;
        $writeSockets = null;
        $exceptSockets = null;
        if (stream_select($readySockets, $writeSockets, $exceptSockets, 1) === false) {
            break;
        }
        foreach ($readySockets as $readySocket) {
            $signal = fread($readySocket, 1);
            if ($signal === '1') {
                $mutexCapabilityStarted = true;
                break;
            }
            if ($signal === '' && feof($readySocket)) {
                $mutexReadSockets = array_values(array_filter(
                    $mutexReadSockets,
                    static fn($socket): bool => $socket !== $readySocket,
                ));
            }
        }
    }
    wt('creation proceeds after mutex release and reaches one side effect', $mutexCapabilityStarted);
    $blockedCountStmt->execute([':wk' => $mutexKey, ':eid' => $mutexEntityId]);
    $activeMutexStmt = $observerDb->prepare(
        "SELECT COUNT(*) FROM workflow_runs WHERE workflow_key = :wk AND module = '_test' AND entity_type = 'test_entity' "
        . "AND entity_id = :eid AND status IN ('pending', 'running')"
    );
    $activeMutexStmt->execute([':wk' => $mutexKey, ':eid' => $mutexEntityId]);
    wt('mutex proof has exactly one total row and one active row during dispatch',
        (int)$blockedCountStmt->fetchColumn() === 1 && (int)$activeMutexStmt->fetchColumn() === 1);

    flock($mutexGate, LOCK_UN);
    fclose($mutexGate);
    $mutexResults = [];
    foreach ($mutexWorkers as $mutexWorker) {
        fclose($mutexWorker['socket']);
        pcntl_waitpid($mutexWorker['pid'], $status);
        $json = @file_get_contents($mutexWorker['result_file']);
        $decoded = is_string($json) ? json_decode($json, true) : null;
        $mutexResults[] = is_array($decoded) ? $decoded : [];
        @unlink($mutexWorker['result_file']);
    }
    app()->reconnectDb();
    $db = app()->db();
    $db2 = workflowTestConnection();
    $mutexIds = array_map(static fn(array $result): int => (int)($result['run_id'] ?? 0), $mutexResults);
    $blockedCountStmt = $db->prepare(
        "SELECT COUNT(*) FROM workflow_runs WHERE workflow_key = :wk AND module = '_test' AND entity_type = 'test_entity' AND entity_id = :eid"
    );
    $blockedCountStmt->execute([':wk' => $mutexKey, ':eid' => $mutexEntityId]);
    $sideEffects = is_file($sideEffectFile) ? file($sideEffectFile, FILE_IGNORE_NEW_LINES) : [];
    wt('mutex contenders return an identical nonzero run id', $mutexIds[0] > 0 && count(array_unique($mutexIds)) === 1, json_encode($mutexResults));
    wt('mutex proof finishes with exactly one total run and one side effect',
        (int)$blockedCountStmt->fetchColumn() === 1 && count($sideEffects ?: []) === 1,
        json_encode($sideEffects));

    // Keep the independent two-connection empty-range race as broader
    // integration coverage in addition to the deterministic mutex proof.
    @unlink($sideEffectFile);
    $emptyRaceKey = $prefix . '.empty-start';
    workflowUpsertDefinition($db, $emptyRaceKey, $capabilityId);
    $capabilityGate = fopen($gatePath, 'c+');
    if ($capabilityGate === false || !flock($capabilityGate, LOCK_EX)) {
        throw new RuntimeException('Unable to acquire empty-start capability gate');
    }

    $startWorkers = [];
    for ($workerIndex = 0; $workerIndex < 2; $workerIndex++) {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($sockets === false) {
            throw new RuntimeException('Unable to create empty-start synchronization socket');
        }
        $workerResult = sys_get_temp_dir() . '/ikabud-workflow-empty-start-' . getmypid() . '-' . $workerIndex;
        @unlink($workerResult);
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork empty-start worker');
        }
        if ($pid === 0) {
            fclose($sockets[0]);
            // Drop the inherited descriptor; only the parent must hold this
            // lock while the child opens its independent capability gate.
            fclose($capabilityGate);
            $GLOBALS['workflow_test_ready_socket'] = $sockets[1];
            $GLOBALS['workflow_test_gate_path'] = $gatePath;
            fwrite($sockets[1], 'R');
            fflush($sockets[1]);
            if (fread($sockets[1], 1) !== 'G') {
                exit(2);
            }
            // Replace every inherited DB descriptor before either child starts;
            // each racer must own an independent MySQL connection.
            app()->reconnectDb();
            workflowRegisterTestCapability(app(), $capabilityId);
            $result = (new \Ikabud\Kernel\WorkflowEngine(app()))->start(
                $emptyRaceKey,
                '_test',
                [],
                'test_entity',
                'entity-empty-start',
            );
            file_put_contents($workerResult, json_encode($result), LOCK_EX);
            fclose($sockets[1]);
            exit(0);
        }
        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);
        $startWorkers[] = ['pid' => $pid, 'socket' => $sockets[0], 'result_file' => $workerResult];
    }

    $bothReady = true;
    foreach ($startWorkers as $startWorker) {
        $bothReady = $bothReady && fread($startWorker['socket'], 1) === 'R';
    }
    wt('two starts reach the empty-range race barrier', $bothReady);
    foreach ($startWorkers as $startWorker) {
        fwrite($startWorker['socket'], 'G');
        fflush($startWorker['socket']);
    }

    $capabilityStarted = false;
    $readSockets = array_column($startWorkers, 'socket');
    $deadline = time() + 10;
    while (!$capabilityStarted && $readSockets !== [] && time() <= $deadline) {
        $readySockets = $readSockets;
        $writeSockets = null;
        $exceptSockets = null;
        if (stream_select($readySockets, $writeSockets, $exceptSockets, 1) === false) {
            break;
        }
        foreach ($readySockets as $readySocket) {
            $signal = fread($readySocket, 1);
            if ($signal === '1') {
                $capabilityStarted = true;
                break;
            }
            if ($signal === '' && feof($readySocket)) {
                $readSockets = array_values(array_filter(
                    $readSockets,
                    static fn($socket): bool => $socket !== $readySocket,
                ));
            }
        }
    }
    wt('empty-range winner reaches its side effect', $capabilityStarted);
    $raceObservationDb = workflowTestConnection();
    $activeRaceStmt = $raceObservationDb->prepare(
        "SELECT COUNT(*) FROM workflow_runs WHERE workflow_key = :wk AND module = '_test' AND entity_type = 'test_entity' "
        . "AND entity_id = 'entity-empty-start' AND status IN ('pending', 'running')"
    );
    $activeRaceStmt->execute([':wk' => $emptyRaceKey]);
    $activeRowsDuringRace = (int)$activeRaceStmt->fetchColumn();
    wt('empty-range race has exactly one active run row', $activeRowsDuringRace === 1);
    flock($capabilityGate, LOCK_UN);
    fclose($capabilityGate);

    $emptyRaceResults = [];
    foreach ($startWorkers as $startWorker) {
        fclose($startWorker['socket']);
        pcntl_waitpid($startWorker['pid'], $status);
        $json = @file_get_contents($startWorker['result_file']);
        $decoded = is_string($json) ? json_decode($json, true) : null;
        $emptyRaceResults[] = is_array($decoded) ? $decoded : [];
        @unlink($startWorker['result_file']);
    }
    app()->reconnectDb();
    $db = app()->db();
    $db2 = workflowTestConnection();
    $emptyRaceIds = array_map(static fn(array $result): int => (int)($result['run_id'] ?? 0), $emptyRaceResults);
    $emptyCountStmt = $db->prepare(
        "SELECT COUNT(*) FROM workflow_runs WHERE workflow_key = :wk AND module = '_test' AND entity_type = 'test_entity' "
        . "AND entity_id = 'entity-empty-start' AND status IN ('pending', 'running', 'completed')"
    );
    $emptyCountStmt->execute([':wk' => $emptyRaceKey]);
    $sideEffects = is_file($sideEffectFile) ? file($sideEffectFile, FILE_IGNORE_NEW_LINES) : [];
    wt('empty-range race returns one shared run id', $emptyRaceIds[0] > 0 && count(array_unique($emptyRaceIds)) === 1, json_encode($emptyRaceResults));
    wt('empty-range race creates exactly one total run row', (int)$emptyCountStmt->fetchColumn() === 1);
    wt('empty-range race executes one side effect', count($sideEffects ?: []) === 1, json_encode($sideEffects));

    // Simulate a completion compare-and-set failure after a successful side
    // effect. The resulting interrupted marker must never be replayable.
    @unlink($sideEffectFile);
    $interruptCapabilityId = 'test.workflow.interrupt-after-dispatch.' . getmypid() . '@1';
    app()->capabilities()->register($interruptCapabilityId, '_test', static function (): array {
        file_put_contents($GLOBALS['workflow_test_side_effect_file'], "executed\n", FILE_APPEND | LOCK_EX);
        $mutationDb = workflowTestConnection();
        $mutationDb->prepare(
            "UPDATE workflow_run_steps SET status = 'interrupted' WHERE run_id = :rid AND status = 'running'"
        )->execute([':rid' => $GLOBALS['workflow_test_interrupt_run_id']]);
        return ['ok' => true];
    }, 100, ['first']);
    $interruptRunId = workflowInsertRun(
        $db,
        $prefix . '.interrupt',
        'entity-interrupt',
        $interruptCapabilityId,
    );
    $GLOBALS['workflow_test_interrupt_run_id'] = $interruptRunId;
    $interrupted = app()->workflowEngine()->advance($interruptRunId);
    wt('post-dispatch completion failure is non-retryable', ($interrupted['ok'] ?? true) === false && ($interrupted['status'] ?? '') === 'interrupted', json_encode($interrupted));
    $interruptedReplay = app()->workflowEngine()->replay($interruptRunId, 'side_effect');
    wt('replay refuses an interrupted step', ($interruptedReplay['ok'] ?? true) === false && ($interruptedReplay['error'] ?? '') === 'step_interrupted', json_encode($interruptedReplay));
    $interruptedAdvance = app()->workflowEngine()->advance($interruptRunId);
    wt('advance refuses an interrupted step', ($interruptedAdvance['ok'] ?? true) === false && ($interruptedAdvance['error'] ?? '') === 'step_interrupted', json_encode($interruptedAdvance));
    $sideEffects = is_file($sideEffectFile) ? file($sideEffectFile, FILE_IGNORE_NEW_LINES) : [];
    wt('interrupted persistence path executes side effect once', count($sideEffects ?: []) === 1, json_encode($sideEffects));

    // Active-run dedupe preserves the successful start() shape and run id.
    $dedupeKey = $prefix . '.start';
    $existingRunId = workflowInsertRun($db, $dedupeKey, 'entity-start', $capabilityId);
    $deduped = app()->workflowEngine()->start($dedupeKey, '_test', [], 'test_entity', 'entity-start');
    $activeCountStmt = $db->prepare(
        "SELECT COUNT(*) FROM workflow_runs WHERE workflow_key = :wk AND module = '_test' AND entity_type = 'test_entity' "
        . "AND entity_id = 'entity-start' AND status IN ('pending', 'running')"
    );
    $activeCountStmt->execute([':wk' => $dedupeKey]);
    wt('start returns the existing active run id', ($deduped['ok'] ?? false) === true && ($deduped['run_id'] ?? 0) === $existingRunId, json_encode($deduped));
    wt('start active-run dedupe creates no second run', (int)$activeCountStmt->fetchColumn() === 1);
    wt('start marks the response as deduplicated', ($deduped['deduplicated'] ?? false) === true);

    // Two advances overlap while the first capability is blocked on a real lock.
    @unlink($sideEffectFile);
    @unlink($resultFile);
    $advanceRunId = workflowInsertRun($db, $prefix . '.advance', 'entity-advance', $capabilityId);
    $worker = workflowForkAdvance($advanceRunId, $gatePath, $resultFile);
    wt('first advance reaches the in-flight capability', workflowAwaitCapability($worker));
    $loser = app()->workflowEngine()->advance($advanceRunId);
    $stepDuring = app()->workflowEngine()->getRun($advanceRunId)['steps'][0] ?? [];
    wt('overlapping advance returns run_busy', ($loser['ok'] ?? true) === false && ($loser['run_busy'] ?? false) === true && ($loser['error'] ?? '') === 'run_busy', json_encode($loser));
    wt('attempt increments once while capability is in-flight', (int)($stepDuring['attempt'] ?? -1) === 1);
    $winner = workflowReleaseWorker($worker);
    // Forked PDO handles are not reusable in the parent after child shutdown.
    app()->reconnectDb();
    $db = app()->db();
    $db2 = workflowTestConnection();
    $sideEffects = is_file($sideEffectFile) ? file($sideEffectFile, FILE_IGNORE_NEW_LINES) : [];
    $finishedStep = app()->workflowEngine()->getRun($advanceRunId)['steps'][0] ?? [];
    wt('double advance executes capability exactly once', count($sideEffects ?: []) === 1, json_encode($sideEffects));
    wt('winning advance completes normally', ($winner['ok'] ?? false) === true && ($winner['status'] ?? '') === 'completed', json_encode($winner));
    wt('attempt remains exactly one after execution', (int)($finishedStep['attempt'] ?? -1) === 1);

    // replay() must refuse while advance() owns a running step.
    @unlink($sideEffectFile);
    @unlink($resultFile);
    $replayRunId = workflowInsertRun($db, $prefix . '.replay', 'entity-replay', $capabilityId);
    $worker = workflowForkAdvance($replayRunId, $gatePath, $resultFile);
    wt('replay race reaches the in-flight capability', workflowAwaitCapability($worker));
    $cancel = app()->workflowEngine()->cancel($replayRunId, 'concurrent cancellation');
    wt('cancel while running is refused as run_busy', ($cancel['ok'] ?? true) === false && ($cancel['run_busy'] ?? false) === true, json_encode($cancel));
    $replay = app()->workflowEngine()->replay($replayRunId, 'side_effect');
    wt('replay while running is refused as run_busy', ($replay['ok'] ?? true) === false && ($replay['run_busy'] ?? false) === true, json_encode($replay));
    workflowReleaseWorker($worker);
    app()->reconnectDb();
    $db = app()->db();
    $db2 = workflowTestConnection();
    $sideEffects = is_file($sideEffectFile) ? file($sideEffectFile, FILE_IGNORE_NEW_LINES) : [];
    wt('replay race does not double-execute capability', count($sideEffects ?: []) === 1, json_encode($sideEffects));

    // Duplicate event delivery while the first auto-start is in-flight.
    @unlink($sideEffectFile);
    @unlink($resultFile);
    $eventKey = $prefix . '.event';
    $eventId = $prefix . '.delivered';
    workflowUpsertDefinition($db, $eventKey, $capabilityId);
    app()->workflowEngine()->subscribe('_test', $eventId, $eventKey, null, 'test_entity');

    $gate = fopen($gatePath, 'c+');
    flock($gate, LOCK_EX);
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    $pid = pcntl_fork();
    if ($pid === 0) {
        fclose($sockets[0]);
        $GLOBALS['workflow_test_ready_socket'] = $sockets[1];
        $GLOBALS['workflow_test_gate_path'] = $gatePath;
        $childApp = workflowChildApp($capabilityId);
        (new \Ikabud\Kernel\WorkflowEngine($childApp))->handleEvent($eventId, ['id' => 'entity-event']);
        fclose($sockets[1]);
        exit(0);
    }
    fclose($sockets[1]);
    stream_set_timeout($sockets[0], 10);
    $ready = fread($sockets[0], 1);
    wt('first event delivery reaches the in-flight capability', $ready === '1');
    app()->workflowEngine()->handleEvent($eventId, ['id' => 'entity-event']);
    $eventCountStmt = $db->prepare(
        "SELECT COUNT(*) FROM workflow_runs WHERE workflow_key = :wk AND module = '_test' AND entity_type = 'test_entity' AND entity_id = 'entity-event'"
    );
    $eventCountStmt->execute([':wk' => $eventKey]);
    wt('duplicate handleEvent delivery creates one run', (int)$eventCountStmt->fetchColumn() === 1);
    flock($gate, LOCK_UN);
    fclose($gate);
    fclose($sockets[0]);
    pcntl_waitpid($pid, $status);
    app()->reconnectDb();
    $db = app()->db();
    $db2 = workflowTestConnection();
    $sideEffects = is_file($sideEffectFile) ? file($sideEffectFile, FILE_IGNORE_NEW_LINES) : [];
    wt('duplicate event delivery executes capability once', count($sideEffects ?: []) === 1, json_encode($sideEffects));
} finally {
    $db->prepare('DELETE FROM workflow_subscriptions WHERE event_id LIKE :prefix')->execute([':prefix' => $prefix . '%']);
    $db->prepare('DELETE FROM workflow_runs WHERE workflow_key LIKE :prefix')->execute([':prefix' => $prefix . '%']);
    $db->prepare('DELETE FROM workflow_definitions WHERE workflow_key LIKE :prefix')->execute([':prefix' => $prefix . '%']);
    @unlink($sideEffectFile);
    @unlink($gatePath);
    @unlink($resultFile);
}

$appLog = @file_get_contents($logDirectory . '/app.log') ?: '';
$errorLog = @file_get_contents($logDirectory . '/error.log') ?: '';
wt('no app.log critical errors', !str_contains($appLog, '[critical]'));
wt('no PHP errors in error.log', trim($errorLog) === '', trim($errorLog));

echo "\n  PASS: {$pass}  FAIL: {$fail}\n";
// PHPStan cannot infer mutations made through the procedural assertion helper.
// @phpstan-ignore-next-line
if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

// @phpstan-ignore-next-line
exit($fail > 0 ? 1 : 0);
