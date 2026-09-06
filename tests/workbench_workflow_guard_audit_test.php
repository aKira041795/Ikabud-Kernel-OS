<?php
/** Adversarial regression-net tests for the heuristic Workbench workflow guard tripwire. */

declare(strict_types=1);

require_once __DIR__ . '/../kernel/Workbench/Audit/WorkflowGuardAuditor.php';
require_once __DIR__ . '/../kernel/Workbench/Issues/IssueLedger.php';

use Ikabud\Kernel\Workbench\Audit\WorkflowGuardAuditor;
use Ikabud\Kernel\Workbench\Issues\IssueLedger;

$pass = 0;
$fail = 0;

function wga_test(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
}

function wga_replace(string $source, string $search, string $replacement): string
{
    if (substr_count($source, $search) !== 1) {
        throw new RuntimeException('Fixture anchor is not unique: ' . $search);
    }
    return str_replace($search, $replacement, $source);
}

function wga_replace_nth(string $source, string $search, string $replacement, int $occurrence): string
{
    $offset = 0;
    for ($i = 1; $i <= $occurrence; $i++) {
        $found = strpos($source, $search, $offset);
        if ($found === false) {
            throw new RuntimeException("Fixture anchor occurrence {$occurrence} not found: {$search}");
        }
        $offset = $found + strlen($search);
    }
    return substr_replace($source, $replacement, $found, strlen($search));
}

function wga_line(string $source, string $needle): int
{
    $offset = strpos($source, $needle);
    if ($offset === false) {
        throw new RuntimeException('Line anchor not found: ' . $needle);
    }
    return 1 + substr_count(substr($source, 0, $offset), "\n");
}

function wga_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        $child = $path . '/' . $entry;
        is_dir($child) ? wga_remove_tree($child) : @unlink($child);
    }
    @rmdir($path);
}

$auditor = new WorkflowGuardAuditor();
$realFile = __DIR__ . '/../kernel/WorkflowEngine.php';
$realFindings = $auditor->audit($realFile);

echo "=== Workbench Workflow Guard Auditor ===\n";
wga_test('real hardened WorkflowEngine has zero findings', $realFindings === [], json_encode($realFindings));

$fixture = <<<'PHP'
<?php
final class WorkflowEngine
{
    public function start(): void
    {
        $lockName = 'workflow:start:same';
        $lockStmt = $db->prepare('SELECT GET_LOCK(:lock_name, 10)');
        $lockStmt->execute([':lock_name' => $lockName]);
        try {
            $this->findActiveRun();
            $insertStmt = $db->prepare('INSERT INTO workflow_runs (id) VALUES (1)');
            $insertStmt->execute();
        } finally {
            $releaseStmt = $db->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $releaseStmt->execute([':lock_name' => $lockName]);
        }
    }

    public function advance(): array
    {
        $db->beginTransaction();
        try {
            $runStmt = $db->prepare('SELECT * FROM workflow_runs WHERE id = :id FOR UPDATE');
            $runStmt->execute([':id' => 1]);
            $claimStmt = $db->prepare("UPDATE workflow_run_steps SET status = 'running', attempt = attempt + 1 WHERE id = :id AND run_id = :rid AND status IN ('pending', 'failed')");
            $claimStmt->execute();
            if ($claimStmt->rowCount() !== 1) {
                $db->rollBack();
                return $this->runBusyResult(1);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        $this->app->cap()->call('fixture', []);
        try {
            $completeStmt = $db->prepare("UPDATE workflow_run_steps SET status = 'completed' WHERE id = :id AND status = 'running'");
            $completeStmt->execute();
            if ($completeStmt->rowCount() !== 1) {
                throw new RuntimeException('changed');
            }
        } catch (Throwable $e) {
            $status = $this->interruptStepAfterDispatch($db, 1, $e);
            return ['status' => $status];
        }
        return ['status' => 'completed'];
    }

    public function cancel(): array
    {
        $runStmt = $db->prepare('SELECT * FROM workflow_runs WHERE id = :id FOR UPDATE');
        $runStmt->execute();
        $blockedStep = $this->findBlockedStep($db, 1);
        if (is_array($blockedStep)) {
            $db->commit();
            if ($blockedStep['status'] === 'running') {
                return $this->runBusyResult(1);
            }
            return $this->interruptedStepResult(1, 2);
        }
        $db->prepare("UPDATE workflow_run_steps SET status = 'cancelled' WHERE run_id = :rid AND status IN ('pending', 'failed')")->execute();
        return ['ok' => true];
    }

    public function replay(): array
    {
        $runStmt = $db->prepare('SELECT * FROM workflow_runs WHERE id = :id FOR UPDATE');
        $runStmt->execute();
        $blockedStep = $this->findBlockedStep($db, 1);
        if (is_array($blockedStep)) {
            $db->commit();
            if ($blockedStep['status'] === 'running') {
                return $this->runBusyResult(1);
            }
            return $this->interruptedStepResult(1, 2);
        }
        $db->prepare("UPDATE workflow_run_steps SET status = 'pending' WHERE run_id = :rid AND status IN ('failed', 'cancelled')")->execute();
        return ['ok' => true];
    }

    private function interruptStepAfterDispatch(): string
    {
        $stmt = $db->prepare("UPDATE workflow_run_steps SET status = 'interrupted' WHERE id = :id AND status = 'running'");
        $stmt->execute();
        if ($stmt->rowCount() === 1) {
            return 'interrupted';
        }
        return 'running';
    }
}
PHP;

$tempDir = sys_get_temp_dir() . '/ikabud-workflow-audit-' . bin2hex(random_bytes(6));
if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Unable to create fixture directory');
}

try {
    $clean = $tempDir . '/clean.php';
    file_put_contents($clean, $fixture);
    $cleanFindings = $auditor->audit($clean);
    wga_test('canonical executed-statement fixture is clean', $cleanFindings === [], json_encode($cleanFindings));

    $cases = [
        ['unexecuted GET_LOCK', 'WORKFLOW_START_LOCK_SYMMETRY', 'critical',
            wga_replace($fixture, "        \$lockStmt->execute([':lock_name' => \$lockName]);\n", ''), 'SELECT GET_LOCK'],
        ['unexecuted RELEASE_LOCK', 'WORKFLOW_START_LOCK_SYMMETRY', 'critical',
            wga_replace($fixture, "            \$releaseStmt->execute([':lock_name' => \$lockName]);\n", ''), 'SELECT GET_LOCK'],
        ['bound lock-name mismatch', 'WORKFLOW_START_LOCK_SYMMETRY', 'critical',
            wga_replace($fixture, "[':lock_name' => \$lockName]);\n        }\n    }", "[':lock_name' => \$otherLock]);\n        }\n    }"), 'SELECT GET_LOCK'],
        ['short-circuit release in finally', 'WORKFLOW_START_LOCK_SYMMETRY', 'critical',
            wga_replace($fixture, "            \$releaseStmt->execute([':lock_name' => \$lockName]);", "            \$shouldRelease && \$releaseStmt->execute([':lock_name' => \$lockName]);"), 'SELECT GET_LOCK'],
        ['ternary release in finally', 'WORKFLOW_START_LOCK_SYMMETRY', 'critical',
            wga_replace($fixture, "            \$releaseStmt->execute([':lock_name' => \$lockName]);", "            \$shouldRelease ? \$releaseStmt->execute([':lock_name' => \$lockName]) : null;"), 'SELECT GET_LOCK'],
        ['release nested in conditional finally', 'WORKFLOW_START_LOCK_SYMMETRY', 'critical',
            wga_replace($fixture, "            \$releaseStmt->execute([':lock_name' => \$lockName]);", "            if (\$shouldRelease) {\n                \$releaseStmt->execute([':lock_name' => \$lockName]);\n            }"), 'SELECT GET_LOCK'],
        ['missing start dedupe', 'WORKFLOW_START_DEDUPE', 'critical',
            wga_replace($fixture, "            \$this->findActiveRun();\n", ''), 'INSERT INTO workflow_runs'],
        ['unexecuted start INSERT', 'WORKFLOW_START_DEDUPE', 'critical',
            wga_replace($fixture, "            \$insertStmt->execute();\n", ''), 'INSERT INTO workflow_runs'],
        ['unexecuted claim', 'WORKFLOW_STEP_CLAIM_GUARD', 'critical',
            wga_replace_nth($fixture, "            \$claimStmt->execute();\n", '', 1), 'UPDATE workflow_run_steps SET status'],
        ['claim predicate is not pending and failed', 'WORKFLOW_STEP_CLAIM_GUARD', 'critical',
            wga_replace_nth($fixture, "status IN ('pending', 'failed')", "status IN ('pending')", 1), 'UPDATE workflow_run_steps SET status'],
        ['attempt increment absent from claim', 'WORKFLOW_STEP_ATTEMPT_ATOMIC', 'critical',
            wga_replace($fixture, ', attempt = attempt + 1', ''), 'UPDATE workflow_run_steps SET status'],
        ['unreachable nested claim rejection', 'WORKFLOW_STEP_CLAIM_ROWCOUNT', 'critical',
            wga_replace($fixture, "                \$db->rollBack();\n                return \$this->runBusyResult(1);", "                if (false) {\n                    \$db->rollBack();\n                    return \$this->runBusyResult(1);\n                }"), 'UPDATE workflow_run_steps SET status'],
        ['unexecuted FOR UPDATE', 'WORKFLOW_DISPATCH_OUTSIDE_LOCK', 'critical',
            wga_replace_nth($fixture, "            \$runStmt->execute([':id' => 1]);\n", '', 1), "->cap()->call('fixture'"],
        ['commit only in constant-false branch', 'WORKFLOW_DISPATCH_OUTSIDE_LOCK', 'critical',
            wga_replace($fixture, "            \$db->commit();\n        } catch", "            if (false) {\n                \$db->commit();\n            }\n        } catch"), "->cap()->call('fixture'"],
        ['dispatch before commit', 'WORKFLOW_DISPATCH_OUTSIDE_LOCK', 'critical',
            wga_replace($fixture, "            \$db->commit();\n        } catch", "            \$this->app->cap()->call('early', []);\n            \$db->commit();\n        } catch"), "->cap()->call('early'"],
        ['cancel unexecuted FOR UPDATE', 'WORKFLOW_CANCEL_RUN_GUARD', 'critical',
            wga_replace_nth($fixture, "        \$runStmt->execute();\n", '', 1), "UPDATE workflow_run_steps SET status = 'cancelled'"],
        ['cancel blocked lookup decoy', 'WORKFLOW_CANCEL_RUN_GUARD', 'critical',
            wga_replace_nth($fixture, "        \$blockedStep = \$this->findBlockedStep(\$db, 1);", "        \$this->findBlockedStep(\$db, 1);\n        \$blockedStep = false;", 1), "UPDATE workflow_run_steps SET status = 'cancelled'"],
        ['cancel unreachable running rejection', 'WORKFLOW_CANCEL_RUN_GUARD', 'critical',
            wga_replace_nth($fixture, "            if (\$blockedStep['status'] === 'running') {", "            if (false) {", 1), "UPDATE workflow_run_steps SET status = 'cancelled'"],
        ['cancel unsafe parameterized mutation beside safe update', 'WORKFLOW_CANCEL_RUNNING_RECLAIM', 'critical',
            wga_replace($fixture, "        \$db->prepare(\"UPDATE workflow_run_steps SET status = 'cancelled'", "        \$unsafe = \$db->prepare(\"UPDATE workflow_run_steps SET status = :new_status WHERE run_id = :rid AND status IN ('failed', :selected)\");\n        \$unsafe->execute([':new_status' => 'cancelled', ':selected' => 'running']);\n        \$db->prepare(\"UPDATE workflow_run_steps SET status = 'cancelled'"), 'UPDATE workflow_run_steps SET status = :new_status'],
        ['cancel broad mutation beside safe decoy', 'WORKFLOW_CANCEL_RUNNING_RECLAIM', 'critical',
            wga_replace($fixture, "        \$db->prepare(\"UPDATE workflow_run_steps SET status = 'cancelled'", "        \$db->prepare(\"UPDATE workflow_run_steps SET status = 'cancelled' WHERE run_id = :rid\")->execute();\n        \$db->prepare(\"UPDATE workflow_run_steps SET status = 'cancelled'"), "UPDATE workflow_run_steps SET status = 'cancelled' WHERE run_id = :rid\""],
        ['replay unexecuted FOR UPDATE', 'WORKFLOW_REPLAY_RUN_GUARD', 'critical',
            wga_replace_nth($fixture, "        \$runStmt->execute();\n", '', 2), "UPDATE workflow_run_steps SET status = 'pending'"],
        ['replay unqualified reset', 'WORKFLOW_REPLAY_RUNNING_RECLAIM', 'critical',
            wga_replace($fixture, "WHERE run_id = :rid AND status IN ('failed', 'cancelled')", 'WHERE run_id = :rid'), "UPDATE workflow_run_steps SET status = 'pending'"],
        ['replay bound predicate includes running', 'WORKFLOW_REPLAY_RUNNING_RECLAIM', 'critical',
            wga_replace($fixture, "        \$db->prepare(\"UPDATE workflow_run_steps SET status = 'pending' WHERE run_id = :rid AND status IN ('failed', 'cancelled')\")->execute();", "        \$reset = \$db->prepare(\"UPDATE workflow_run_steps SET status = :new_status WHERE run_id = :rid AND status IN ('failed', :selected)\");\n        \$reset->execute([':new_status' => 'pending', ':selected' => 'running']);"), 'UPDATE workflow_run_steps SET status = :new_status'],
        ['unexecuted completion update', 'WORKFLOW_POST_DISPATCH_FAIL_CLOSED', 'critical',
            wga_replace($fixture, "            \$completeStmt->execute();\n", ''), '$completeStmt = $db->prepare'],
        ['unreachable fail-closed throw', 'WORKFLOW_POST_DISPATCH_FAIL_CLOSED', 'critical',
            wga_replace($fixture, "                throw new RuntimeException('changed');", "                if (false) {\n                    throw new RuntimeException('changed');\n                }"), '$completeStmt = $db->prepare'],
        ['unexecuted interrupted update', 'WORKFLOW_POST_DISPATCH_FAIL_CLOSED', 'critical',
            wga_replace_nth($fixture, "        \$stmt->execute();\n", '', 1), '$completeStmt = $db->prepare'],
        ['interrupt success return unreachable', 'WORKFLOW_POST_DISPATCH_FAIL_CLOSED', 'critical',
            wga_replace($fixture, "            return 'interrupted';", "            if (false) {\n                return 'interrupted';\n            }"), '$completeStmt = $db->prepare'],
        ['window SQL', 'MYSQL8_WINDOW_FUNCTION', 'major', $fixture . "\n\$sql = 'SELECT COUNT(*) OVER (PARTITION BY id) FROM workflow_runs';\n", 'OVER ('],
        ['CTE SQL', 'MYSQL8_CTE', 'major', $fixture . "\n\$sql = 'WITH recent AS (SELECT id FROM workflow_runs) SELECT * FROM recent';\n", 'WITH recent AS ('],
        ['JSON_TABLE SQL', 'MYSQL8_JSON_TABLE', 'major', $fixture . "\n\$sql = 'SELECT * FROM JSON_TABLE(payload, \'$[*]\' COLUMNS (id INT PATH \'$.id\')) jt';\n", 'JSON_TABLE('],
        ['SKIP LOCKED SQL', 'MYSQL8_SKIP_LOCKED', 'major', $fixture . "\n\$sql = 'SELECT id FROM workflow_runs FOR UPDATE SKIP LOCKED';\n", 'SKIP LOCKED'],
    ];

    $roundTripFinding = null;
    foreach ($cases as $index => [$label, $code, $severity, $source, $lineNeedle]) {
        $path = $tempDir . "/regression-{$index}.php";
        file_put_contents($path, $source);
        $findings = $auditor->audit($path);
        $matching = array_values(array_filter($findings, static fn (array $finding): bool => $finding['code'] === $code));
        $expectedLine = wga_line($source, $lineNeedle);
        $actual = $matching[0] ?? null;
        wga_test(
            "{$label} emits exact {$code}/{$severity}/line {$expectedLine}",
            count($matching) === 1 && $actual['severity'] === $severity
                && $actual['line'] === $expectedLine && $actual['file'] === $path,
            json_encode($findings, JSON_UNESCAPED_SLASHES)
        );
        if ($label === 'unexecuted claim') {
            $roundTripFinding = $actual;
        }
    }

    $ledgerPath = $tempDir . '/ledger';
    $ledger = new IssueLedger($ledgerPath);
    if (!is_array($roundTripFinding)) {
        throw new RuntimeException('Real auditor finding unavailable for ledger proof');
    }
    $location = basename((string)$roundTripFinding['file']) . ':' . $roundTripFinding['line'];
    $stored = $ledger->ingest([
        'module_id' => 'kernel',
        'action_id' => 'workbench:audit',
        'failing_node' => $location,
        'category' => 'workflow_guard.' . strtolower((string)$roundTripFinding['code']),
        'severity' => $roundTripFinding['severity'],
        'summary' => $roundTripFinding['code'] . ' at ' . $location . ': ' . $roundTripFinding['message'],
        'source_fingerprint' => hash('sha256', $roundTripFinding['code'] . '|' . $location),
    ], [
        'source' => 'workbench:audit',
        'file' => basename((string)$roundTripFinding['file']),
        'line' => $roundTripFinding['line'],
        'invariant_code' => $roundTripFinding['code'],
    ]);
    $readBack = $ledger->get((string)$stored['id']);
    $occurrence = $readBack['occurrences'][0] ?? [];
    wga_test(
        'real auditor finding round-trips through temporary IssueLedger with invariant and location',
        ($occurrence['invariant_code'] ?? null) === $roundTripFinding['code']
            && ($occurrence['file'] ?? null) === basename((string)$roundTripFinding['file'])
            && ($occurrence['line'] ?? null) === $roundTripFinding['line']
            && ($readBack['failing_node'] ?? null) === $location,
        json_encode($readBack, JSON_UNESCAPED_SLASHES)
    );
    echo '    ledger_read_back=' . json_encode([
        'issue_id' => $readBack['id'] ?? null,
        'invariant_code' => $occurrence['invariant_code'] ?? null,
        'file' => $occurrence['file'] ?? null,
        'line' => $occurrence['line'] ?? null,
        'failing_node' => $readBack['failing_node'] ?? null,
    ], JSON_UNESCAPED_SLASHES) . "\n";

    $auditorSource = (string)file_get_contents(__DIR__ . '/../kernel/Workbench/Audit/WorkflowGuardAuditor.php');
    $boundaryDoc = (string)file_get_contents(__DIR__ . '/../docs/kernel/workbench-workflow-guard-auditor.md');
    wga_test(
        'documented guarantee is explicitly heuristic and names runtime authority',
        str_contains($auditorSource, 'HEURISTIC GUARANTEE')
            && str_contains($auditorSource, 'not a correctness prover')
            && str_contains($boundaryDoc, 'Narrow heuristic guarantee')
            && str_contains($boundaryDoc, 'not a structural proof or PHP correctness prover')
            && str_contains($boundaryDoc, 'Reaching-definitions analysis')
            && str_contains($boundaryDoc, 'runtime dispatch invariant')
    );
} finally {
    wga_remove_tree($tempDir);
}

echo "\n════════════════════════════════════════════\n";
echo "  Workflow guard audit tests: {$pass} passed, {$fail} failed\n";
echo "════════════════════════════════════════════\n";

function wga_exit_code(): int
{
    global $fail;
    return $fail > 0 ? 1 : 0;
}

exit(wga_exit_code());
