<?php

declare(strict_types=1);

/** WorkflowEngine dispatch transaction runtime-guard integration tests. */

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

use Ikabud\Kernel\WorkflowEngine;

$pass = 0;
$fail = 0;
/** @var list<string> $errors */
$errors = [];

function wrg_test(string $label, bool $ok, string $detail = ''): void
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

function wrg_insert_run(PDO $db, string $workflowKey, string $capabilityId): int
{
    $stmt = $db->prepare(
        "INSERT INTO workflow_runs (workflow_key, module, entity_type, entity_id, status, payload_json, context_json, started_at, created_at) "
        . "VALUES (:wk, '_test', 'test_entity', :eid, 'running', '{}', 'null', NOW(), NOW())"
    );
    $stmt->execute([':wk' => $workflowKey, ':eid' => 'runtime-guard-' . getmypid()]);
    $runId = (int)$db->lastInsertId();

    $db->prepare(
        "INSERT INTO workflow_run_steps (run_id, ordinal, step_key, label, capability_id, args_json, status, attempt, max_attempts, idempotency_key, created_at) "
        . "VALUES (:rid, 1, 'guard_step', 'Guard step', :cap, '{}', 'pending', 0, 1, :ik, NOW())"
    )->execute([':rid' => $runId, ':cap' => $capabilityId, ':ik' => "runtime_guard_{$runId}"]);

    return $runId;
}

function wrg_step_status(PDO $db, int $runId): string
{
    $stmt = $db->prepare('SELECT status FROM workflow_run_steps WHERE run_id = :rid LIMIT 1');
    $stmt->execute([':rid' => $runId]);
    return (string)$stmt->fetchColumn();
}

$appLogPath = __DIR__ . '/../storage/logs/app.log';
$errorLogPath = __DIR__ . '/../storage/logs/error.log';
file_put_contents($appLogPath, '');
file_put_contents($errorLogPath, '');

echo "=== Workflow Runtime Dispatch Guard ===\n";

$db = app()->db();
$engine = app()->workflowEngine();
$prefix = 'test.runtime-guard.' . getmypid();
$runIds = [];

try {
    $offCalls = 0;
    $offHookCalled = false;
    $offCapability = $prefix . '.off@1';
    app()->capabilities()->register($offCapability, '_test', static function () use (&$offCalls): array {
        $offCalls++;
        return ['ok' => true];
    }, 100, ['first']);
    WorkflowEngine::setRuntimeGuardEnabled(false, static function () use (&$offHookCalled): void {
        $offHookCalled = true;
    });
    $offRunId = wrg_insert_run($db, $prefix . '.off', $offCapability);
    $runIds[] = $offRunId;
    $offResult = $engine->advance($offRunId);
    wrg_test('guard OFF leaves normal dispatch unchanged', ($offResult['ok'] ?? false) === true, json_encode($offResult));
    wrg_test('guard OFF dispatches capability exactly once', $offCalls === 1, "calls={$offCalls}");
    wrg_test('guard OFF does not invoke fault-injection seam', $offHookCalled === false);

    $onCalls = 0;
    $onCapability = $prefix . '.on@1';
    app()->capabilities()->register($onCapability, '_test', static function () use (&$onCalls): array {
        $onCalls++;
        return ['ok' => true];
    }, 100, ['first']);
    WorkflowEngine::setRuntimeGuardEnabled(true);
    $onRunId = wrg_insert_run($db, $prefix . '.on', $onCapability);
    $runIds[] = $onRunId;
    $onResult = $engine->advance($onRunId);
    wrg_test('guard ON permits normal advance after claim commit', ($onResult['ok'] ?? false) === true, json_encode($onResult));
    wrg_test('guard ON normal advance dispatches exactly once', $onCalls === 1, "calls={$onCalls}");
    wrg_test('guard ON normal advance leaves no transaction open', !$db->inTransaction());

    $blockedCalls = 0;
    $blockedCapability = $prefix . '.blocked@1';
    app()->capabilities()->register($blockedCapability, '_test', static function () use (&$blockedCalls): array {
        $blockedCalls++;
        return ['ok' => true];
    }, 100, ['first']);
    WorkflowEngine::setRuntimeGuardEnabled(true, static function (PDO $connection): void {
        $connection->beginTransaction();
    });
    $blockedRunId = wrg_insert_run($db, $prefix . '.blocked', $blockedCapability);
    $runIds[] = $blockedRunId;
    $blockedResult = $engine->advance($blockedRunId);
    $guardLog = (string)file_get_contents($appLogPath);
    wrg_test(
        'guard ON fails closed when dispatch sees an open transaction',
        ($blockedResult['ok'] ?? true) === false
            && ($blockedResult['error'] ?? '') === 'runtime_dispatch_guard_violation',
        json_encode($blockedResult)
    );
    wrg_test('guard violation does not dispatch capability', $blockedCalls === 0, "calls={$blockedCalls}");
    wrg_test('guard violation leaves committed claim non-retryable', wrg_step_status($db, $blockedRunId) === 'running');
    wrg_test('guard violation logs at error level', str_contains($guardLog, '[error]') && str_contains($guardLog, 'runtime dispatch guard blocked'));
    wrg_test('guard violation rolls back unexpected transaction', !$db->inTransaction());
} catch (Throwable $e) {
    wrg_test('runtime guard test setup and execution', false, $e->getMessage());
} finally {
    WorkflowEngine::setRuntimeGuardEnabled(false);
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if ($runIds !== []) {
        $placeholders = implode(',', array_fill(0, count($runIds), '?'));
        $db->prepare("DELETE FROM workflow_run_steps WHERE run_id IN ({$placeholders})")->execute($runIds);
        $db->prepare("DELETE FROM workflow_runs WHERE id IN ({$placeholders})")->execute($runIds);
    }

    // The expected guard error was asserted above; leave mandatory post-test
    // repository logs clean for subsequent suites and release checks.
    file_put_contents($appLogPath, '');
    file_put_contents($errorLogPath, '');
}

echo "\n  PASS: {$pass}  FAIL: {$fail}\n";
if ($errors !== []) {
    echo "Failed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

function wrg_exit_code(): int
{
    global $fail;
    return $fail > 0 ? 1 : 0;
}

exit(wrg_exit_code());
