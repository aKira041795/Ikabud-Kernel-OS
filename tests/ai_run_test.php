<?php

declare(strict_types=1);

require_once __DIR__ . '/harness/TestHarness.php';

/**
 * tests/ai_run_test.php — pure (MODE_PURE) suite for tools/ai-run.php.
 *
 * This suite is the non-vacuity anchor for run classification. It replays the two observed real cases
 * from the run-ledger contract:
 *
 *   - `scope-path-semantics`: exit 0 with a 0-byte log  -> `silent`   (a real, unreported success)
 *   - a 7 KB report:          exit 0 with content       -> `completed`
 *
 * A classifier that derives the outcome from the exit code alone marks the first case `completed`.
 * The assertions below fail against that version: that is the point of the suite.
 *
 * No bootstrap, no DB, no network, no HARPP. Subprocesses get no special environment.
 */

/** Instantiate the shared harness without requiring PHPStan to scan it. */
function aiRunHarness(string $className): mixed
{
    return new $className('ai-run', 'pure');
}

$h = aiRunHarness('TestHarness');
$h->fingerprint('tools/ai-run.php');

/**
 * Run the ledger tool with an explicit argv and no shell interpolation.
 *
 * @param list<string> $arguments
 * @return array{code:int,output:string,command:string}
 */
function aiRunLedger(string $tool, array $arguments): array
{
    if (!function_exists('proc_open')) {
        return ['code' => 127, 'output' => 'proc_open failed', 'command' => 'proc_open unavailable'];
    }
    $command = array_merge([PHP_BINARY, $tool], $arguments);
    $rendered = implode(' ', array_map('escapeshellarg', $command));
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        return ['code' => 127, 'output' => 'proc_open failed', 'command' => $rendered];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [
        'code' => proc_close($process),
        'output' => trim(($stdout === false ? '' : $stdout) . ($stderr === false ? '' : $stderr)),
        'command' => $rendered,
    ];
}

/** @param array{code:int,output:string,command:string} $run */
function aiRunDetail(array $run): string
{
    return "command: {$run['command']}\nexit: {$run['code']}\noutput:\n{$run['output']}";
}

/** Read the persisted status field of a run record. */
function aiRunStatus(string $runsDir, string $id): string
{
    $raw = @file_get_contents($runsDir . '/' . $id . '.json');
    $record = $raw === false ? null : json_decode($raw, true);
    return is_array($record) ? (string) ($record['status'] ?? '') : '';
}

/** @return array<string,mixed>|null */
function aiRunRecord(string $runsDir, string $id): ?array
{
    $raw = @file_get_contents($runsDir . '/' . $id . '.json');
    $record = $raw === false ? null : json_decode($raw, true);
    return is_array($record) ? $record : null;
}

/** Spawn a process, reap it, and return the now-dead pid. */
function aiRunDeadPid(): int
{
    if (!function_exists('proc_open')) {
        return 0;
    }
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-r', 'exit(0);'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        return 0;
    }
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_get_status($process);
    $pid = (int) $status['pid'];
    proc_close($process);
    return $pid;
}

/** Delete a fixture tree without shelling out. */
function aiRunRemoveFixture(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $child = $path . '/' . $name;
        is_dir($child) ? aiRunRemoveFixture($child) : unlink($child);
    }
    rmdir($path);
}

if (!function_exists('proc_open')) {
    $h->skip('ai-run ledger cases', 'proc_open is unavailable; subprocess behaviour cannot be verified');
    $h->done();
}

$fixture = sys_get_temp_dir() . '/ikabud-ai-run-' . bin2hex(random_bytes(6));
$runs = $fixture . '/runs';
mkdir($runs, 0777, true);
$tool = $h->basePath() . '/tools/ai-run.php';

$contract = $fixture . '/contract.md';
$contractText = <<<'MD'
# CONTRACT — ledger fixture
## Objective
Exercise the run ledger without touching a real slice.
## Architectural constraints
- none
## Files likely affected
- `tools/ai-run.php` — the tool under test
- `tests/` — the suite
## Acceptance criteria
- classification is recorded, not inferred
## Required tests
- `php tests/ai_run_test.php`
## Risks
- an exit-code-only classifier would mark a silent run as complete
## Forbidden changes
- `kernel/`
MD;
file_put_contents($contract, $contractText);

/** @param list<string> $arguments @return array{code:int,output:string,command:string} */
$run = static function (array $arguments) use ($tool, $runs): array {
    return aiRunLedger($tool, array_merge($arguments, ["--runs-dir={$runs}"]));
};
$startArgs = static function (string $name) use ($contract): array {
    return ['start', "--contract={$contract}", '--lane=deepseek-v4-flash', "--name={$name}"];
};

// ── R1: start records the run, status reads it ─────────────────────────────────────────────────
$h->section('start writes the authoritative record');
$started = $run($startArgs('case-1'));
$h->test('1. start exits 0 and writes a record', $started['code'] === 0 && is_file($runs . '/case-1.json'), aiRunDetail($started));
$record = aiRunRecord($runs, 'case-1');
$h->test(
    '2. record carries a 16-hex contract revision',
    is_array($record) && preg_match('/^[0-9a-f]{16}$/', (string) ($record['contract_revision'] ?? '')) === 1,
    'record: ' . json_encode($record, JSON_UNESCAPED_SLASHES)
);
$h->test(
    '3. record carries the pid, lane and scope counts',
    is_array($record)
        && (int) ($record['pid'] ?? 0) > 0
        && ($record['lane'] ?? '') === 'deepseek-v4-flash'
        && (int) ($record['allowed_count'] ?? 0) === 2
        && (int) ($record['forbidden_count'] ?? 0) === 1,
    'record: ' . json_encode($record, JSON_UNESCAPED_SLASHES)
);
$statusRun = $run(['status', '--json']);
$statusData = json_decode($statusRun['output'], true);
$case1 = null;
foreach (is_array($statusData) ? ($statusData['runs'] ?? []) : [] as $item) {
    if (($item['id'] ?? null) === 'case-1') {
        $case1 = $item;
    }
}
$h->test(
    '4. status reports the started run as running (live dispatcher pid)',
    $statusRun['code'] === 0 && is_array($case1) && ($case1['status'] ?? null) === 'running',
    aiRunDetail($statusRun)
);

// ── R2: the classification matrix, measured from real exit codes ───────────────────────────────
$h->section('finish classification matrix');
$run(array_merge($startArgs('failed-case'), []));
$failedFinish = $run(['finish', '--id=failed-case', '--exit=1']);
$h->test(
    '5. exit 1 classifies failed',
    $failedFinish['code'] === 0 && aiRunStatus($runs, 'failed-case') === 'failed',
    aiRunDetail($failedFinish)
);

file_put_contents($fixture . '/empty.log', '');
$run(array_merge($startArgs('silent-case'), ["--log={$fixture}/empty.log"]));
$silentFinish = $run(['finish', '--id=silent-case', '--exit=0']);
$h->test(
    '6. exit 0 with a 0-byte log classifies silent',
    $silentFinish['code'] === 0 && aiRunStatus($runs, 'silent-case') === 'silent',
    aiRunDetail($silentFinish)
);

file_put_contents($fixture . '/content.log', "47/47 passed\n");
$run(array_merge($startArgs('completed-case'), ["--log={$fixture}/content.log"]));
$completedFinish = $run(['finish', '--id=completed-case', '--exit=0']);
$h->test(
    '7. exit 0 with content classifies completed',
    $completedFinish['code'] === 0 && aiRunStatus($runs, 'completed-case') === 'completed',
    aiRunDetail($completedFinish)
);

// ── R3: replay the two observed real cases ─────────────────────────────────────────────────────
$h->section('replay of the observed real cases (non-vacuity anchor)');
file_put_contents($fixture . '/scope-path-semantics.flash-run.log', '');
$run(array_merge($startArgs('scope-path-semantics'), ["--log={$fixture}/scope-path-semantics.flash-run.log"]));
$scopeFinish = $run(['finish', '--id=scope-path-semantics', '--exit=0']);
$h->test(
    '8. scope-path-semantics (exit 0, 0-byte log) -> silent',
    $scopeFinish['code'] === 0 && aiRunStatus($runs, 'scope-path-semantics') === 'silent',
    aiRunDetail($scopeFinish)
);

$sevenKbReport = str_repeat("## Completion report\n\n 46/46 passed\n\n", 220);
file_put_contents($fixture . '/mechanise.report.md', $sevenKbReport);
$run(array_merge($startArgs('mechanise-doctrine'), ["--report={$fixture}/mechanise.report.md"]));
$mechaniseFinish = $run(['finish', '--id=mechanise-doctrine', '--exit=0']);
$mechanise = aiRunRecord($runs, 'mechanise-doctrine');
$h->test(
    '9. a ~7 KB report (exit 0) -> completed',
    $mechaniseFinish['code'] === 0
        && aiRunStatus($runs, 'mechanise-doctrine') === 'completed'
        && (int) ($mechanise['report_bytes'] ?? 0) >= 7000,
    aiRunDetail($mechaniseFinish)
);
$h->test(
    '10. classification is content-sensitive, not exit-code-only (same exit 0, two outcomes)',
    aiRunStatus($runs, 'scope-path-semantics') === 'silent'
        && aiRunStatus($runs, 'mechanise-doctrine') === 'completed',
    'exit 0 classified both silent and completed; an exit-only classifier cannot do that'
);

// ── R4: pid reconciliation ─────────────────────────────────────────────────────────────────────
$h->section('pid reconciliation: from the pid, never from log size');
$livePid = getmypid() ?: 0;
$run(array_merge($startArgs('live-pid'), ["--pid={$livePid}"]));
$run(['status']);
$h->test(
    '11. a live pid stays running',
    aiRunStatus($runs, 'live-pid') === 'running',
    'recorded pid ' . $livePid . ' is the live test process'
);

$deadPid = aiRunDeadPid();
$run(array_merge($startArgs('dead-pid'), ["--pid={$deadPid}"]));
$reconcile = $run(['status']);
$deadRecord = aiRunRecord($runs, 'dead-pid');
$h->test(
    '12. a dead pid reconciles running -> abandoned',
    $deadPid > 0 && aiRunStatus($runs, 'dead-pid') === 'abandoned',
    'dead pid ' . $deadPid . '; ' . aiRunDetail($reconcile)
);
$h->test(
    '13. reconciliation is persisted, not re-inferred per call',
    is_array($deadRecord) && ($deadRecord['status'] ?? null) === 'abandoned' && isset($deadRecord['reconciled_at']),
    'record: ' . json_encode($deadRecord, JSON_UNESCAPED_SLASHES)
);

// ── R5: the gate is the policy layer ───────────────────────────────────────────────────────────
$h->section('--gate refuses to advance on an unreported run');
$gateBlocked = $run(['status', '--gate']);
$h->test(
    '14. --gate exits 3 while silent/failed/abandoned runs exist',
    $gateBlocked['code'] === 3 && str_contains($gateBlocked['output'], 'GATE: BLOCKED'),
    aiRunDetail($gateBlocked)
);
$cleanRuns = $fixture . '/clean-runs';
mkdir($cleanRuns, 0777, true);
aiRunLedger($tool, array_merge($startArgs('clean-case'), ["--runs-dir={$cleanRuns}"]));
file_put_contents($fixture . '/clean.log', "1/1 passed\n");
aiRunLedger($tool, ['finish', '--id=clean-case', '--exit=0', "--log={$fixture}/clean.log", "--runs-dir={$cleanRuns}"]);
$gateClean = aiRunLedger($tool, ['status', '--gate', "--runs-dir={$cleanRuns}"]);
$h->test(
    '15. --gate exits 0 when every run is clean',
    $gateClean['code'] === 0 && str_contains($gateClean['output'], 'GATE: OK'),
    aiRunDetail($gateClean)
);

// ── R6: claims are extracted, never verified ───────────────────────────────────────────────────
$h->section('claims: extract, mark unverified, never assert');
$claimsReport = $fixture . '/claims-report.md';
file_put_contents($claimsReport, <<<'MD'
# Completion report
RESULTS
  46/46 passed
  passed=46 failed=0
  exit=0
  PASS auth
  FAIL widgets
  SKIP browser
  tests/ai_run_test.php ok
MD);
$run(array_merge($startArgs('claims-case'), ["--report={$claimsReport}"]));
$run(['finish', '--id=claims-case', '--exit=0']);
$claimsRun = $run(['claims', '--id=claims-case', '--json']);
$claimsData = json_decode($claimsRun['output'], true);
if (!is_array($claimsData)) {
    $claimsData = [];
}
$claims = is_array($claimsData['claims'] ?? null) ? $claimsData['claims'] : [];
$kinds = [];
$allUnverified = $claims !== [];
foreach ($claims as $claim) {
    $kinds[(string) ($claim['kind'] ?? '')] = true;
    if (($claim['status'] ?? null) !== 'unverified') {
        $allUnverified = false;
    }
}
$h->test(
    '16. claims extracts at least three distinct claim shapes',
    $claimsRun['code'] === 0 && count($kinds) >= 3,
    'kinds: ' . implode(', ', array_keys($kinds)) . '; ' . aiRunDetail($claimsRun)
);
$h->test(
    '17. every extracted claim is marked unverified',
    $allUnverified && ($claimsData['verified'] ?? true) === false,
    aiRunDetail($claimsRun)
);
$h->test(
    '18. claims state that re-derivation is a later slice',
    str_contains((string) ($claimsData['note'] ?? ''), 'later slice'),
    'note: ' . (string) ($claimsData['note'] ?? '')
);
file_put_contents($runs . '/broken.json', '{not valid json');
$malformed = $run(['claims', '--id=broken']);
$unknown = $run(['claims', '--id=does-not-exist']);
$h->test(
    '19. malformed/absent input exits 2',
    $malformed['code'] === 2 && $unknown['code'] === 2,
    aiRunDetail($malformed) . "\n---\n" . aiRunDetail($unknown)
);

aiRunRemoveFixture($fixture);
$h->done();
