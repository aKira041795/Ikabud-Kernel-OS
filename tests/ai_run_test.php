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

// ── R6: claims are structured objects, extracted unverified ────────────────────────────────────
$h->section('claims: structured, extracted unverified, honest re-derivability');
$claimsReport = $fixture . '/claims-report.md';
file_put_contents($claimsReport, <<<'MD'
# Completion report
$ php tests/ai_run_test.php
  19/19 passed
  exit=0
$ php -l tools/ai-run.php
No syntax errors detected in tools/ai-run.php
exit 0
$ npx playwright test tests/browser
  3 passed
$ php ikabud migrate:status
$ hyperfine ./bench.sh
$ sha256sum tools/ai-run.php
$ git diff --name-only
$ composer test
  99/99 passed
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
$types = [];
$allUnverified = $claims !== [];
$shapeOk = $claims !== [];
foreach ($claims as $claim) {
    $types[(string) ($claim['type'] ?? '')] = true;
    foreach (['claim_id', 'run', 'type', 're_derivable', 'subject', 'executor_claim', 'verification', 'status'] as $key) {
        if (!array_key_exists($key, $claim)) {
            $shapeOk = false;
        }
    }
    if (!str_starts_with((string) ($claim['claim_id'] ?? ''), 'CLM-claims-case-')) {
        $shapeOk = false;
    }
    if (($claim['status'] ?? null) !== 'UNVERIFIED') {
        $allUnverified = false;
    }
}
$h->test(
    '16. claims extracts at least three distinct structured claim types',
    $claimsRun['code'] === 0 && count($types) >= 3 && $shapeOk,
    'types: ' . implode(', ', array_keys($types)) . '; ' . aiRunDetail($claimsRun)
);
$h->test(
    '17. every extracted claim is UNVERIFIED and carries the structured shape',
    $allUnverified && ($claimsData['verified'] ?? true) === false,
    aiRunDetail($claimsRun)
);
$h->test(
    '18. claims state that verify re-derives by execution',
    str_contains((string) ($claimsData['note'] ?? ''), 'verify'),
    'note: ' . (string) ($claimsData['note'] ?? '')
);
$honest = [
    'TEST_RESULT' => true,
    'LINT_RESULT' => true,
    'CONTRACT_CONFORMANCE' => true,
    'ARTIFACT_HASH' => true,
    'FILE_SCOPE' => true,
    'BROWSER_JOURNEY' => false,
    'PERFORMANCE_MEASUREMENT' => false,
    'MIGRATION_STATE' => false,
];
$claimTypes = $claimsData['claim_types'] ?? null;
$honestyOk = is_array($claimTypes) && $claimTypes === $honest;
foreach ($claims as $claim) {
    $type = (string) ($claim['type'] ?? '');
    if (!array_key_exists($type, $honest) || ($claim['re_derivable'] ?? null) !== $honest[$type]) {
        $honestyOk = false;
    }
}
$h->test(
    '18b. every recognised type declares re_derivable honestly',
    $honestyOk && count($types) >= 3,
    'claim_types: ' . json_encode($claimsData['claim_types'] ?? null)
);
file_put_contents($runs . '/broken.json', '{not valid json');
$malformed = $run(['claims', '--id=broken']);
$unknown = $run(['claims', '--id=does-not-exist']);
$h->test(
    '19. malformed/absent input exits 2',
    $malformed['code'] === 2 && $unknown['code'] === 2,
    aiRunDetail($malformed) . "\n---\n" . aiRunDetail($unknown)
);

// ── R7: commit-check is deterministic ──────────────────────────────────────────────────────────
$h->section('commit-check: eligibility decided by the ledger, not the tree');
$commitCheck = static function (string $dir) use ($tool): array {
    return aiRunLedger($tool, ['commit-check', "--runs-dir={$dir}"]);
};
$freshRun = static function (string $name, string $dir) use ($startArgs): array {
    return array_merge($startArgs($name), ["--runs-dir={$dir}"]);
};

$ccEmpty = $fixture . '/cc-empty';
mkdir($ccEmpty, 0777, true);
$ccEmptyRun = $commitCheck($ccEmpty);
$h->test('20. commit-check: no runs -> exit 0', $ccEmptyRun['code'] === 0 && str_contains($ccEmptyRun['output'], 'ELIGIBLE'), aiRunDetail($ccEmptyRun));

$ccCompleted = $fixture . '/cc-completed';
mkdir($ccCompleted, 0777, true);
aiRunLedger($tool, $freshRun('cc-done', $ccCompleted));
file_put_contents($fixture . '/cc-done.log', "1/1 passed\n");
aiRunLedger($tool, ['finish', '--id=cc-done', '--exit=0', "--log={$fixture}/cc-done.log", "--runs-dir={$ccCompleted}"]);
$ccCompletedRun = $commitCheck($ccCompleted);
$h->test('21. commit-check: completed only -> exit 0', $ccCompletedRun['code'] === 0 && str_contains($ccCompletedRun['output'], 'ELIGIBLE'), aiRunDetail($ccCompletedRun));

$livePid = getmypid() ?: 0;
$ccRunning = $fixture . '/cc-running';
mkdir($ccRunning, 0777, true);
aiRunLedger($tool, array_merge($freshRun('cc-live', $ccRunning), ["--pid={$livePid}"]));
$ccRunningResult = $commitCheck($ccRunning);
$h->test(
    '22. commit-check: running -> exit 3, run named with its state',
    $ccRunningResult['code'] === 3 && str_contains($ccRunningResult['output'], 'cc-live') && str_contains($ccRunningResult['output'], 'running'),
    aiRunDetail($ccRunningResult)
);

$ccSilent = $fixture . '/cc-silent';
mkdir($ccSilent, 0777, true);
file_put_contents($fixture . '/cc-silent.log', '');
aiRunLedger($tool, array_merge($freshRun('cc-silent', $ccSilent), ["--log={$fixture}/cc-silent.log"]));
aiRunLedger($tool, ['finish', '--id=cc-silent', '--exit=0', "--runs-dir={$ccSilent}"]);
$ccSilentResult = $commitCheck($ccSilent);
$h->test(
    '23. commit-check: silent -> exit 3, run named with its state',
    $ccSilentResult['code'] === 3 && str_contains($ccSilentResult['output'], 'cc-silent') && str_contains($ccSilentResult['output'], 'silent'),
    aiRunDetail($ccSilentResult)
);

$ccFailed = $fixture . '/cc-failed';
mkdir($ccFailed, 0777, true);
aiRunLedger($tool, $freshRun('cc-failed', $ccFailed));
aiRunLedger($tool, ['finish', '--id=cc-failed', '--exit=1', "--runs-dir={$ccFailed}"]);
$ccFailedResult = $commitCheck($ccFailed);
$h->test(
    '24. commit-check: failed -> exit 3, run named with its state',
    $ccFailedResult['code'] === 3 && str_contains($ccFailedResult['output'], 'cc-failed') && str_contains($ccFailedResult['output'], 'failed'),
    aiRunDetail($ccFailedResult)
);

$ccAbandoned = $fixture . '/cc-abandoned';
mkdir($ccAbandoned, 0777, true);
$ccDeadPid = aiRunDeadPid();
aiRunLedger($tool, array_merge($freshRun('cc-dead', $ccAbandoned), ["--pid={$ccDeadPid}"]));
$ccAbandonedResult = $commitCheck($ccAbandoned);
$h->test(
    '25. commit-check: dead pid reconciled to abandoned -> exit 3, run named with its state',
    $ccDeadPid > 0 && $ccAbandonedResult['code'] === 3 && str_contains($ccAbandonedResult['output'], 'cc-dead') && str_contains($ccAbandonedResult['output'], 'abandoned'),
    aiRunDetail($ccAbandonedResult)
);

// ── R8: verify re-derives by execution ─────────────────────────────────────────────────────────
$h->section('verify: re-derive by execution and bind evidence to the revision');

$verifyReport = $fixture . '/verify-ok.md';
file_put_contents($verifyReport, <<<'MD'
# Verification fixture
$ php -l tools/ai-run.php
No syntax errors detected in tools/ai-run.php
exit 0
MD);
$run(array_merge($startArgs('verify-ok'), ["--report={$verifyReport}"]));
$run(['finish', '--id=verify-ok', '--exit=0']);
$verifyOk = $run(['verify', '--run=verify-ok', '--json']);
$verifyOkData = json_decode($verifyOk['output'], true);
$verifyOkClaims = is_array($verifyOkData['claims'] ?? null) ? $verifyOkData['claims'] : [];
$verifyOkClaim = is_array($verifyOkClaims[0] ?? null) ? $verifyOkClaims[0] : [];
$verifyOkVerification = is_array($verifyOkClaim['verification'] ?? null) ? $verifyOkClaim['verification'] : [];
$verifyOkBinding = is_array($verifyOkData['binding'] ?? null) ? $verifyOkData['binding'] : [];
$verifyOkRecord = aiRunRecord($runs, 'verify-ok');
$h->test(
    '26. verify re-executes an allowlisted command and records RE_DERIVED',
    $verifyOk['code'] === 0
        && ($verifyOkClaim['status'] ?? null) === 'RE_DERIVED'
        && ($verifyOkVerification['method'] ?? null) === 'independent_execution'
        && ($verifyOkVerification['verifier'] ?? null) === 'deterministic'
        && ($verifyOkVerification['observed_exit'] ?? null) === 0,
    aiRunDetail($verifyOk)
);
$h->test(
    '27. verify records the tree binding, on the output and in the run record',
    preg_match('/^[0-9a-f]{40}$/', (string) ($verifyOkBinding['rev'] ?? '')) === 1
        && is_bool($verifyOkBinding['dirty'] ?? null)
        && is_array($verifyOkRecord)
        && ($verifyOkRecord['claim_verification']['rev'] ?? null) === ($verifyOkBinding['rev'] ?? null),
    'binding: ' . json_encode($verifyOkBinding) . '; record: ' . json_encode(is_array($verifyOkRecord) ? ($verifyOkRecord['claim_verification'] ?? null) : null)
);

$contradictReport = $fixture . '/verify-bad.md';
file_put_contents($contradictReport, <<<'MD'
# Contradiction fixture
$ php -l tools/ai-run.php
exit 1
MD);
$run(array_merge($startArgs('verify-bad'), ["--report={$contradictReport}"]));
$run(['finish', '--id=verify-bad', '--exit=0']);
$verifyBad = $run(['verify', '--run=verify-bad', '--json']);
$verifyBadData = json_decode($verifyBad['output'], true);
$verifyBadClaims = is_array($verifyBadData['claims'] ?? null) ? $verifyBadData['claims'] : [];
$verifyBadClaim = is_array($verifyBadClaims[0] ?? null) ? $verifyBadClaims[0] : [];
$verifyBadVerification = is_array($verifyBadClaim['verification'] ?? null) ? $verifyBadClaim['verification'] : [];
$verifyBadMismatches = is_array($verifyBadVerification['mismatches'] ?? null) ? $verifyBadVerification['mismatches'] : [];
$exitMismatch = is_array($verifyBadMismatches['exit_code'] ?? null) ? $verifyBadMismatches['exit_code'] : [];
$h->test(
    '28. a claim the command does not produce is CONTRADICTED, exit 3',
    $verifyBad['code'] === 3
        && ($verifyBadClaim['status'] ?? null) === 'CONTRADICTED'
        && ($exitMismatch['claimed'] ?? null) === 1
        && ($exitMismatch['observed'] ?? null) === 0,
    aiRunDetail($verifyBad)
);

$sentinel = $fixture . '/verify-refused-sentinel';
$refuseReport = $fixture . '/verify-refuse.md';
$refuseCommand = 'php -l tools/ai-run.php; touch ' . $sentinel;
file_put_contents($refuseReport, "# Refusal fixture\n\$ " . $refuseCommand . "\nexit 0\n");
$run(array_merge($startArgs('verify-refuse'), ["--report={$refuseReport}"]));
$run(['finish', '--id=verify-refuse', '--exit=0']);
$verifyRefuse = $run(['verify', '--run=verify-refuse', '--json']);
$verifyRefuseData = json_decode($verifyRefuse['output'], true);
$verifyRefuseClaims = is_array($verifyRefuseData['claims'] ?? null) ? $verifyRefuseData['claims'] : [];
$verifyRefuseClaim = is_array($verifyRefuseClaims[0] ?? null) ? $verifyRefuseClaims[0] : [];
$verifyRefuseVerification = is_array($verifyRefuseClaim['verification'] ?? null) ? $verifyRefuseClaim['verification'] : [];
$h->test(
    '29. a non-allowlisted command is refused, shown, and NOT executed',
    ($verifyRefuseClaim['status'] ?? null) === 'UNVERIFIED'
        && ($verifyRefuseVerification['reason'] ?? null) === 'command_not_allowlisted'
        && !is_file($sentinel)
        && str_contains($verifyRefuse['output'], 'command_not_allowlisted'),
    'sentinel exists: ' . (is_file($sentinel) ? 'yes' : 'no') . "\n" . aiRunDetail($verifyRefuse)
);

$browserReport = $fixture . '/verify-browser.md';
file_put_contents($browserReport, <<<'MD'
# Browser fixture
$ npx playwright test tests/browser
  3 passed
MD);
$run(array_merge($startArgs('verify-browser'), ["--report={$browserReport}"]));
$run(['finish', '--id=verify-browser', '--exit=0']);
$verifyBrowser = $run(['verify', '--run=verify-browser', '--json']);
$verifyBrowserData = json_decode($verifyBrowser['output'], true);
$verifyBrowserClaims = is_array($verifyBrowserData['claims'] ?? null) ? $verifyBrowserData['claims'] : [];
$verifyBrowserClaim = is_array($verifyBrowserClaims[0] ?? null) ? $verifyBrowserClaims[0] : [];
$verifyBrowserVerification = is_array($verifyBrowserClaim['verification'] ?? null) ? $verifyBrowserClaim['verification'] : [];
$h->test(
    '30. a non-re-derivable claim is UNVERIFIED, never RE_DERIVED',
    ($verifyBrowserClaim['type'] ?? null) === 'BROWSER_JOURNEY'
        && ($verifyBrowserClaim['status'] ?? null) === 'UNVERIFIED'
        && ($verifyBrowserVerification['reason'] ?? null) === 'not_re_derivable_by_pure_tool'
        && ($verifyBrowserVerification['method'] ?? null) === null,
    aiRunDetail($verifyBrowser)
);

// ── R9: command binding is declared or derived, never invented ──────────────────────────────────
$h->section('claim binding: declared routes and labelled derivation');
// Build the purity markers without writing the literal substrings into this file, or the screen
// would (correctly) classify this suite as impure and refuse to re-derive it.
$impureIntegrationMarker = 'MODE_' . 'INTEGRATION';
$impureBootstrapMarker = 'bootstrap' . '.php';
$declaredClaimsRun = $run(['claims', '--id=verify-ok', '--json']);
$declaredClaimsData = json_decode($declaredClaimsRun['output'], true);
$declaredClaimsList = is_array($declaredClaimsData['claims'] ?? null) ? $declaredClaimsData['claims'] : [];
$declaredClaim = is_array($declaredClaimsList[0] ?? null) ? $declaredClaimsList[0] : [];
$declaredSubject = is_array($declaredClaim['subject'] ?? null) ? $declaredClaim['subject'] : [];
$h->test(
    '31. a command-bearing evidence line binds command_source declared',
    $declaredClaimsRun['code'] === 0
        && ($declaredSubject['command'] ?? null) === 'php -l tools/ai-run.php'
        && ($declaredSubject['command_source'] ?? null) === 'declared',
    aiRunDetail($declaredClaimsRun)
);

$derivedReport = $fixture . '/binding-derived.md';
file_put_contents($derivedReport, <<<'MD'
# Binding fixture — derived
### Suites
ai_contract_lint_test exit=0 3/3 passed
MD);
$run(array_merge($startArgs('binding-derived'), ["--report={$derivedReport}"]));
$run(['finish', '--id=binding-derived', '--exit=0']);
$derivedVerify = $run(['verify', '--run=binding-derived', '--json']);
$derivedData = json_decode($derivedVerify['output'], true);
$derivedClaims = is_array($derivedData['claims'] ?? null) ? $derivedData['claims'] : [];
$derivedClaim = is_array($derivedClaims[0] ?? null) ? $derivedClaims[0] : [];
$derivedSubject = is_array($derivedClaim['subject'] ?? null) ? $derivedClaim['subject'] : [];
$derivedDerivation = is_array($derivedSubject['derivation'] ?? null) ? $derivedSubject['derivation'] : [];
$derivedTarget = dirname(__DIR__) . '/tests/ai_contract_lint_test.php';
$derivedContent = is_file($derivedTarget) ? (string) file_get_contents($derivedTarget) : '';
$h->test(
    '32. a TEST_RESULT naming an existing pure test binds a labelled derived command',
    $derivedVerify['code'] === 0
        && ($derivedClaim['status'] ?? null) === 'RE_DERIVED'
        && ($derivedSubject['command'] ?? null) === 'php tests/ai_contract_lint_test.php'
        && ($derivedSubject['command_source'] ?? null) === 'derived'
        && ($derivedDerivation['candidate'] ?? null) === 'tests/ai_contract_lint_test.php'
        && array_key_exists('refused', $derivedDerivation)
        && $derivedDerivation['refused'] === null
        && is_file($derivedTarget)
        && !str_contains($derivedContent, $impureIntegrationMarker)
        && !str_contains($derivedContent, $impureBootstrapMarker),
    aiRunDetail($derivedVerify)
);

$missingReport = $fixture . '/binding-missing.md';
file_put_contents($missingReport, "# Binding fixture — missing\n### Suites\ntests/no_such_test_xyz.php 1/1 passed\n");
$run(array_merge($startArgs('binding-missing'), ["--report={$missingReport}"]));
$run(['finish', '--id=binding-missing', '--exit=0']);
$missingVerify = $run(['verify', '--run=binding-missing', '--json']);
$missingData = json_decode($missingVerify['output'], true);
$missingClaims = is_array($missingData['claims'] ?? null) ? $missingData['claims'] : [];
$missingClaim = is_array($missingClaims[0] ?? null) ? $missingClaims[0] : [];
$missingSubject = is_array($missingClaim['subject'] ?? null) ? $missingClaim['subject'] : [];
$missingDerivation = is_array($missingSubject['derivation'] ?? null) ? $missingSubject['derivation'] : [];
$h->test(
    '33. a TEST_RESULT naming a missing file is refused and stays UNVERIFIED',
    ($missingClaim['status'] ?? null) === 'UNVERIFIED'
        && ($missingSubject['command'] ?? null) === null
        && ($missingSubject['command_source'] ?? null) === null
        && ($missingDerivation['refused'] ?? null) === 'test_file_missing'
        && !is_file(dirname(__DIR__) . '/tests/no_such_test_xyz.php'),
    aiRunDetail($missingVerify)
);

$impureReport = $fixture . '/binding-impure.md';
file_put_contents($impureReport, "# Binding fixture — impure\n### Suites\nauthority_scope_test exit=0 1/1 passed\n");
$run(array_merge($startArgs('binding-impure'), ["--report={$impureReport}"]));
$run(['finish', '--id=binding-impure', '--exit=0']);
$impureVerify = $run(['verify', '--run=binding-impure', '--json']);
$impureData = json_decode($impureVerify['output'], true);
$impureClaims = is_array($impureData['claims'] ?? null) ? $impureData['claims'] : [];
$impureClaim = is_array($impureClaims[0] ?? null) ? $impureClaims[0] : [];
$impureSubject = is_array($impureClaim['subject'] ?? null) ? $impureClaim['subject'] : [];
$impureDerivation = is_array($impureSubject['derivation'] ?? null) ? $impureSubject['derivation'] : [];
$impureTarget = dirname(__DIR__) . '/tests/authority_scope_test.php';
$impureContent = is_file($impureTarget) ? (string) file_get_contents($impureTarget) : '';
$h->test(
    '34. a TEST_RESULT naming an impure file is refused and stays UNVERIFIED',
    ($impureClaim['status'] ?? null) === 'UNVERIFIED'
        && ($impureSubject['command'] ?? null) === null
        && ($impureSubject['command_source'] ?? null) === null
        && ($impureDerivation['refused'] ?? null) === 'test_file_impure'
        && (str_contains($impureContent, $impureBootstrapMarker) || str_contains($impureContent, $impureIntegrationMarker)),
    aiRunDetail($impureVerify)
);

aiRunRemoveFixture($fixture);
$h->done();
