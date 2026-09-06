<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';

require __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\Database\MigrationRunner;
use Ikabud\Kernel\Http\Idempotency;

$pass = 0;
$fail = 0;
/** @var list<string> $errors */
$errors = [];

function edt(string $label, bool $ok, string $detail = ''): void
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

function eventOutboxConnection(): PDO
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

/**
 * @param array<string, mixed> $payload
 * @return array{pid: int, socket: resource, file: string}
 */
function eventOutboxFork(string $event, string $key, int $tenantId, array $payload, string $file): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($pair === false) {
        throw new RuntimeException('Could not create event outbox test socket');
    }
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Could not fork event outbox test worker');
    }
    if ($pid === 0) {
        fclose($pair[0]);
        app()->reconnectDb();
        app()->tenant()->setTenantId($tenantId);
        fwrite($pair[1], 'R');
        fflush($pair[1]);
        if (fread($pair[1], 1) !== 'G') {
            exit(2);
        }
        try {
            $id = app()->events()->fireDurable($event, $payload, app()->db(), [
                'tenant_id' => $tenantId,
                'idempotency_key' => $key,
                'source' => '_test',
                'event_id' => 'concurrent-event',
            ]);
            file_put_contents($file, json_encode(['id' => $id]), LOCK_EX);
        } catch (Throwable $e) {
            file_put_contents($file, json_encode(['error' => $e->getMessage()]), LOCK_EX);
        }
        fclose($pair[1]);
        exit(0);
    }
    fclose($pair[1]);
    return ['pid' => $pid, 'socket' => $pair[0], 'file' => $file];
}

/**
 * @param array{pid: int, socket: resource, file: string} $worker
 * @return array<string, mixed>
 */
function eventOutboxCollect(array $worker): array
{
    fclose($worker['socket']);
    pcntl_waitpid($worker['pid'], $status);
    $raw = @file_get_contents($worker['file']);
    @unlink($worker['file']);
    $result = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($result) ? $result : [];
}

$logDir = __DIR__ . '/../storage/logs';
file_put_contents($logDir . '/app.log', '');
file_put_contents($logDir . '/error.log', '');

echo "=== EVENTBUS DURABLE OUTBOX ===\n";

$db = app()->db();
$runner = new MigrationRunner($db);
$runner->migrate('_kernel');
$prefix = 'test.eventbus.' . getmypid();
$tenantA = 62001;
$tenantB = 62002;
$keys = [];

try {
    $tableExists = (int)$db->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() "
        . "AND table_name = 'kernel_durable_event_outbox'"
    )->fetchColumn() === 1;
    edt('outbox migration creates the sanctioned table', $tableExists);
    $migration = $db->prepare("SELECT COUNT(*) FROM _migrations WHERE module = '_kernel' AND migration = ?");
    $migration->execute(['015_kernel_durable_event_outbox.sql']);
    edt('outbox migration is registered by kernel convention', (int)$migration->fetchColumn() === 1);
    $secondMigrationRun = $runner->migrate('_kernel');
    $migration->execute(['015_kernel_durable_event_outbox.sql']);
    edt('outbox migration registration is idempotent', $secondMigrationRun === [] && (int)$migration->fetchColumn() === 1);

    app()->tenant()->setTenantId($tenantA);
    $key = $prefix . '.sequential';
    $keys[] = $key;
    $first = app()->events()->fireDurable($prefix . '.created', ['nested' => ['b' => 2, 'a' => 1]], $db, [
        'tenant_id' => (string)$tenantA,
        'idempotency_key' => $key,
        'source' => '_test',
        'actor_id' => 77,
        'actor_role' => 'admin',
        'request_id' => 'req-1',
        'event_id' => 'evt-1',
    ]);
    $duplicate = app()->events()->fireDurable($prefix . '.created', ['nested' => ['a' => 1, 'b' => 2]], $db, [
        'tenant_id' => $tenantA,
        'idempotency_key' => $key,
        'source' => '_test',
        'actor_id' => 77,
        'actor_role' => 'admin',
        'request_id' => 'req-1',
        'event_id' => 'evt-1',
    ]);
    edt('keyed duplicate replays the same integer outbox id', is_int($first) && $first > 0 && $duplicate === $first, json_encode([$first, $duplicate]));
    $count = $db->prepare('SELECT COUNT(*) FROM kernel_durable_event_outbox WHERE idempotency_key = ? AND tenant_id = ?');
    $count->execute([$key, $tenantA]);
    edt('reordered associative keyed duplicate writes exactly one row', (int)$count->fetchColumn() === 1);
    $stored = $db->prepare('SELECT event_name, payload_json, status, attempts FROM kernel_durable_event_outbox WHERE id = ?');
    $stored->execute([$first]);
    $storedRow = $stored->fetch(PDO::FETCH_ASSOC);
    edt(
        'outbox row persists envelope and delivery metadata',
        is_array($storedRow)
            && $storedRow['event_name'] === $prefix . '.created'
            && json_decode((string)$storedRow['payload_json'], true) === ['nested' => ['b' => 2, 'a' => 1]]
            && $storedRow['status'] === 'pending'
            && (int)$storedRow['attempts'] === 0,
        json_encode($storedRow),
    );

    $conflict = false;
    try {
        app()->events()->fireDurable($prefix . '.created', ['nested' => ['a' => 99, 'b' => 2]], $db, [
            'tenant_id' => $tenantA,
            'idempotency_key' => $key,
            'source' => '_test',
            'actor_id' => 77,
            'actor_role' => 'admin',
            'request_id' => 'req-1',
            'event_id' => 'evt-1',
        ]);
    } catch (RuntimeException $e) {
        $conflict = str_contains($e->getMessage(), 'conflict');
    }
    $count->execute([$key, $tenantA]);
    edt('changed keyed payload fails explicitly without another row', $conflict && (int)$count->fetchColumn() === 1);

    $keylessEvent = $prefix . '.keyless';
    $tenantClaimCount = $db->prepare('SELECT COUNT(*) FROM kernel_idempotency_keys WHERE tenant_id = ?');
    $tenantClaimCount->execute([$tenantA]);
    $claimsBeforeKeyless = (int)$tenantClaimCount->fetchColumn();
    $keylessId = app()->events()->fireDurable($keylessEvent, ['value' => 1], $db, ['tenant_id' => $tenantA]);
    edt('keyless durable event writes one row without a claim', is_int($keylessId) && $keylessId > 0);
    $tenantClaimCount->execute([$tenantA]);
    edt('keyless durable event creates no idempotency row', (int)$tenantClaimCount->fetchColumn() === $claimsBeforeKeyless);
    $claimCount = $db->prepare('SELECT COUNT(*) FROM kernel_idempotency_keys WHERE tenant_id = ? AND idempotency_key_hash = ?');

    $db->beginTransaction();
    $transactionId = app()->events()->fireDurable($prefix . '.transactional-keyless', [], $db, ['tenant_id' => $tenantA]);
    $stillOpen = $db->inTransaction();
    $insideCount = (int)$db->query('SELECT COUNT(*) FROM kernel_durable_event_outbox WHERE id = ' . (int)$transactionId)->fetchColumn();
    $db->rollBack();
    $outsideCount = (int)$db->query('SELECT COUNT(*) FROM kernel_durable_event_outbox WHERE id = ' . (int)$transactionId)->fetchColumn();
    edt('keyless call leaves caller transaction open and writes once inside it', $stillOpen && $insideCount === 1 && $outsideCount === 0);

    $openKey = $prefix . '.open-transaction';
    $keys[] = $openKey;
    $beforeOpen = (int)$db->query("SELECT COUNT(*) FROM kernel_durable_event_outbox WHERE event_name = " . $db->quote($prefix . '.open'))->fetchColumn();
    $db->beginTransaction();
    $openRejected = false;
    try {
        app()->events()->fireDurable($prefix . '.open', [], $db, ['tenant_id' => $tenantA, 'idempotency_key' => $openKey]);
    } catch (RuntimeException $e) {
        $openRejected = str_contains($e->getMessage(), 'autocommit');
    }
    $openStillOpen = $db->inTransaction();
    $db->rollBack();
    $afterOpen = (int)$db->query("SELECT COUNT(*) FROM kernel_durable_event_outbox WHERE event_name = " . $db->quote($prefix . '.open'))->fetchColumn();
    $claimCount->execute([$tenantA, hash('sha256', $openKey)]);
    edt('keyed call rejects an open transaction before claim or write', $openRejected && $openStillOpen && $beforeOpen === $afterOpen && (int)$claimCount->fetchColumn() === 0);

    $ownerKey = $prefix . '.owner';
    $keys[] = $ownerKey;
    $ownerHash = Idempotency::canonicalPayloadHash(['owner' => true]);
    $ownerClaim = Idempotency::claim($ownerKey, $tenantA, $ownerHash, $db);
    $wrongDb = eventOutboxConnection();
    $wrongRelease = Idempotency::release($ownerKey, $tenantA, $wrongDb);
    $wrongCommit = Idempotency::commit($ownerKey, $tenantA, 1, $wrongDb);
    $ownerRelease = Idempotency::release($ownerKey, $tenantA, $db);
    edt('same-PDO lock ownership rejects wrong-PDO commit/release', $ownerClaim['status'] === 'new' && !$wrongRelease && !$wrongCommit && $ownerRelease);

    $legacyKey = $prefix . '.legacy';
    $keys[] = $legacyKey;
    app()->tenant()->setTenantId($tenantA);
    $legacyCheck = Idempotency::check($legacyKey, $tenantA);
    $legacyProcessing = Idempotency::claim($legacyKey, $tenantA, hash('sha256', 'unknown'), $db);
    edt('legacy envelope-less processing row observes in_progress, never conflict', $legacyCheck === null && $legacyProcessing['status'] === 'in_progress', json_encode($legacyProcessing));
    Idempotency::store($legacyKey, $tenantA, ['ok' => true, 'run_id' => 987654, 'result' => ['legacy' => true]]);
    $legacyCompleted = Idempotency::claim($legacyKey, $tenantA, hash('sha256', 'different-unknown'), $db);
    edt('legacy envelope-less completed row replays plain JSON, never conflict', $legacyCompleted['status'] === 'duplicate' && ($legacyCompleted['outcome']['run_id'] ?? 0) === 987654, json_encode($legacyCompleted));
    $workflowLegacy = app()->workflowEngine()->start($prefix . '.legacy-workflow', '_test', ['different' => 'payload'], null, null, $legacyKey);
    edt('WorkflowEngine accepts legacy completed rows as duplicates', ($workflowLegacy['ok'] ?? false) === true && ($workflowLegacy['run_id'] ?? 0) === 987654 && ($workflowLegacy['deduplicated'] ?? false) === true, json_encode($workflowLegacy));

    $isolationKey = $prefix . '.tenant-isolation';
    $keys[] = $isolationKey;
    app()->tenant()->setTenantId($tenantA);
    $tenantAId = app()->events()->fireDurable($prefix . '.isolated', ['tenant' => 'same'], $db, ['tenant_id' => $tenantA, 'idempotency_key' => $isolationKey]);
    app()->tenant()->setTenantId($tenantB);
    $tenantBId = app()->events()->fireDurable($prefix . '.isolated', ['tenant' => 'same'], $db, ['tenant_id' => $tenantB, 'idempotency_key' => $isolationKey]);
    edt('same caller key is isolated across tenants', is_int($tenantAId) && is_int($tenantBId) && $tenantAId !== $tenantBId);
    $count->execute([$isolationKey, $tenantA]);
    $tenantARows = (int)$count->fetchColumn();
    $count->execute([$isolationKey, $tenantB]);
    edt('outbox rows are tenant scoped', $tenantARows === 1 && (int)$count->fetchColumn() === 1);

    app()->tenant()->setTenantId($tenantA);
    $rejectionEvent = $prefix . '.tenant-rejection';
    $beforeReject = (int)$db->query('SELECT COUNT(*) FROM kernel_durable_event_outbox WHERE event_name = ' . $db->quote($rejectionEvent))->fetchColumn();
    $rejections = 0;
    foreach ([[], ['tenant_id' => 0], ['tenant_id' => -1], ['tenant_id' => $tenantB]] as $invalidOpts) {
        try {
            app()->events()->fireDurable($rejectionEvent, [], $db, $invalidOpts);
        } catch (InvalidArgumentException $e) {
            $rejections++;
        }
    }
    app()->tenant()->reset();
    try {
        app()->events()->fireDurable($rejectionEvent, [], $db, ['tenant_id' => $tenantA]);
    } catch (InvalidArgumentException $e) {
        $rejections++;
    }
    app()->tenant()->setTenantId($tenantA);
    $afterReject = (int)$db->query('SELECT COUNT(*) FROM kernel_durable_event_outbox WHERE event_name = ' . $db->quote($rejectionEvent))->fetchColumn();
    edt('missing, unresolved, mismatched, and non-positive tenants reject before writes', $rejections === 5 && $beforeReject === $afterReject);

    if (!function_exists('pcntl_fork') || !function_exists('stream_socket_pair')) {
        edt('concurrent duplicate test requires pcntl and Unix sockets', false);
    } else {
        $concurrentKey = $prefix . '.concurrent';
        $keys[] = $concurrentKey;
        $concurrentEvent = $prefix . '.concurrent-event';
        $file1 = sys_get_temp_dir() . '/ikabud-event-outbox-' . getmypid() . '-1';
        $file2 = sys_get_temp_dir() . '/ikabud-event-outbox-' . getmypid() . '-2';
        @unlink($file1);
        @unlink($file2);
        $worker1 = eventOutboxFork($concurrentEvent, $concurrentKey, $tenantA, ['b' => 2, 'a' => 1], $file1);
        $worker2 = eventOutboxFork($concurrentEvent, $concurrentKey, $tenantA, ['a' => 1, 'b' => 2], $file2);
        $ready = [fread($worker1['socket'], 1), fread($worker2['socket'], 1)];
        foreach ([$worker1, $worker2] as $worker) {
            fwrite($worker['socket'], 'G');
            fflush($worker['socket']);
        }
        $result1 = eventOutboxCollect($worker1);
        $result2 = eventOutboxCollect($worker2);
        app()->reconnectDb();
        $db = app()->db();
        app()->tenant()->setTenantId($tenantA);
        $ids = [(int)($result1['id'] ?? 0), (int)($result2['id'] ?? 0)];
        edt('concurrent callers use independent workers and replay one row id', $ready === ['R', 'R'] && $ids[0] > 0 && $ids[0] === $ids[1], json_encode([$result1, $result2]));
        $count = $db->prepare('SELECT COUNT(*) FROM kernel_durable_event_outbox WHERE idempotency_key = ? AND tenant_id = ?');
        $count->execute([$concurrentKey, $tenantA]);
        edt('concurrent keyed duplicate creates exactly one outbox row', (int)$count->fetchColumn() === 1);
    }
} finally {
    app()->reconnectDb();
    $db = app()->db();
    $db->prepare('DELETE FROM kernel_durable_event_outbox WHERE event_name LIKE ?')->execute([$prefix . '%']);
    foreach (array_unique($keys) as $cleanupKey) {
        $db->prepare('DELETE FROM kernel_idempotency_keys WHERE idempotency_key_hash = ?')->execute([hash('sha256', $cleanupKey)]);
    }
    app()->tenant()->reset();
}

$appLog = @file_get_contents($logDir . '/app.log') ?: '';
$errorLog = @file_get_contents($logDir . '/error.log') ?: '';
edt('app.log has no warning/error/critical findings', preg_match('/\[(warning|error|critical)\]/i', $appLog) !== 1, $appLog);
edt('error.log is clean', trim($errorLog) === '', trim($errorLog));

echo "\n  PASS: {$pass}  FAIL: {$fail}\n";
if ($errors !== []) {
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}
// PHPStan cannot infer mutations made through the procedural assertion helper.
// @phpstan-ignore-next-line
exit($fail === 0 ? 0 : 1);
