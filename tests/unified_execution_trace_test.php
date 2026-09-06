<?php

declare(strict_types=1);

/**
 * WorkflowEngine-to-CapabilityBus correlation evidence integration test.
 */

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$pass = 0;
$fail = 0;
/** @var list<string> $errors */
$errors = [];

function uet(string $label, bool $ok, string $detail = ''): void
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

$logDirectory = __DIR__ . '/../storage/logs';
file_put_contents($logDirectory . '/app.log', '');
file_put_contents($logDirectory . '/error.log', '');

// The repository test environment is production; enable capability traces only
// for this process so the evidence contract is exercised rather than skipped.
$previousTraceSetting = $_ENV['APP_CAPABILITY_TRACE_LOGS'] ?? null;
$_ENV['APP_CAPABILITY_TRACE_LOGS'] = 'true';

$db = app()->db();
$suffix = (string)getmypid();
$workflowKey = 'test.unified-trace.' . $suffix;
$capabilityId = 'test.unified-trace.' . $suffix . '@1';
$runId = 0;

app()->capabilities()->register($capabilityId, '_test', static function (array $args): array {
    return ['ok' => true, 'observed' => $args['value'] ?? null];
}, 100, ['first']);

$states = [[
    'key' => 'pending',
    'label' => 'Pending',
    'step' => [
        'key' => 'prove_trace',
        'label' => 'Prove Trace',
        'capability_id' => $capabilityId,
        'args' => ['value' => '{payload.value}'],
        'max_attempts' => 1,
    ],
]];

try {
    $definition = $db->prepare(
        "INSERT INTO workflow_definitions "
        . "(workflow_key, module, entity_type, initial_state, states_json, transitions_json, is_active, created_at) "
        . "VALUES (:key, '_test', 'trace_subject', 'pending', :states, '[]', 1, NOW()) "
        . 'ON DUPLICATE KEY UPDATE states_json = VALUES(states_json), is_active = 1, updated_at = NOW()'
    );
    $definition->execute([':key' => $workflowKey, ':states' => json_encode($states)]);

    echo "\n=== UNIFIED EXECUTION TRACE ===\n";

    $result = app()->workflowEngine()->start(
        $workflowKey,
        '_test',
        ['value' => 'linked'],
        'trace_subject',
        $suffix,
    );
    $runId = (int)($result['run_id'] ?? 0);
    uet('one-step workflow starts successfully', ($result['ok'] ?? false) === true && $runId > 0, json_encode($result));

    $stepStatement = $db->prepare('SELECT id, status FROM workflow_run_steps WHERE run_id = :run_id LIMIT 1');
    $stepStatement->execute([':run_id' => $runId]);
    $step = $stepStatement->fetch(PDO::FETCH_ASSOC);
    $stepId = is_array($step) ? (int)($step['id'] ?? 0) : 0;
    $correlationId = "wf:run:{$runId}:step:{$stepId}";
    uet('workflow capability step completes', is_array($step) && ($step['status'] ?? '') === 'completed');

    $appLog = @file_get_contents($logDirectory . '/app.log') ?: '';
    $lines = preg_split('/\R/', $appLog) ?: [];
    $stepLine = '';
    $capabilityLine = '';
    foreach ($lines as $line) {
        if (str_contains($line, "WorkflowEngine: run {$runId} step {$stepId}") && str_contains($line, 'completed')) {
            $stepLine = $line;
        }
        if (str_contains($line, 'capability.call') && str_contains($line, $capabilityId)) {
            $capabilityLine = $line;
        }
    }

    uet('step-completed log carries workflow correlation id', $stepLine !== '' && str_contains($stepLine, $correlationId), $stepLine);
    uet('capability.call log carries the same correlation id', $capabilityLine !== '' && str_contains($capabilityLine, $correlationId), $capabilityLine);

    echo "\nCORRELATION PROOF {$correlationId}\n";
    echo $capabilityLine . "\n";
    echo $stepLine . "\n";
} finally {
    if ($runId > 0) {
        $db->prepare('DELETE FROM workflow_runs WHERE id = :id')->execute([':id' => $runId]);
    }
    $db->prepare('DELETE FROM workflow_definitions WHERE workflow_key = :key')->execute([':key' => $workflowKey]);

    if ($previousTraceSetting === null) {
        unset($_ENV['APP_CAPABILITY_TRACE_LOGS']);
    } else {
        $_ENV['APP_CAPABILITY_TRACE_LOGS'] = $previousTraceSetting;
    }
}

$errorLog = @file_get_contents($logDirectory . '/error.log') ?: '';
uet('no PHP errors in error.log', trim($errorLog) === '', trim($errorLog));

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
