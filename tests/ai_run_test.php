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

/** Capture `git status --porcelain` from the repository root, verbatim, without a shell. */
function aiRunGitPorcelain(string $root): string
{
    if (!function_exists('proc_open')) {
        return '';
    }
    $pipes = [];
    $process = proc_open(
        ['git', 'status', '--porcelain=v1'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        return '';
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    return (string) $stdout;
}

/**
 * Sorted in-repo bytecode paths under tests/ and tools/. `git status` never shows these (`.gitignore`
 * hides `__pycache__/`), so this scan is what makes a stray `.pyc` visible to the assertion.
 *
 * @return list<string>
 */
function aiRunPycFiles(string $root): array
{
    $found = [];
    foreach (['tests', 'tools'] as $sub) {
        $base = $root . '/' . $sub;
        if (!is_dir($base)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === 'pyc') {
                $found[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
    }
    sort($found, SORT_STRING);
    return $found;
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
- `docs/run-ledger.md` — benign ledger scope
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
$h->test(
    '3a. start records the trust-surface anchor (aggregate + seven files)',
    is_array($record)
        && preg_match('/^[0-9a-f]{64}$/', (string) ($record['trust_surface_hash'] ?? '')) === 1
        && is_array($record['trust_surface_files'] ?? null)
        && count($record['trust_surface_files']) === 7
        && in_array('tools/ai-run.php', array_keys($record['trust_surface_files']), true),
    'record: ' . json_encode($record, JSON_UNESCAPED_SLASHES)
);
$h->test(
    '3b. start captures the dispatch-time changed-path baseline',
    is_array($record) && is_array($record['scope_baseline_paths'] ?? null) && is_array($record['scope_ignored_paths'] ?? null),
    'record: ' . json_encode($record, JSON_UNESCAPED_SLASHES)
);

$directorContract = $fixture . '/director-contract.md';
file_put_contents($directorContract, str_replace('`docs/run-ledger.md` — benign ledger scope', '`tools/ai-run.php` — director-authorised verifier work', $contractText));
$directorMissing = $run(['start', "--contract={$directorContract}", '--lane=fixture', '--name=director-missing']);
$directorStarted = $run(['start', "--contract={$directorContract}", '--lane=fixture', '--name=director-ok', '--director-decision=CD-28']);
$directorRecord = aiRunRecord($runs, 'director-ok');
$h->test(
    '3c. verifier work requires a real director decision and records its reference',
    $directorMissing['code'] === 3 && $directorStarted['code'] === 0
        && ($directorRecord['director_authorisation']['decision_ref'] ?? null) === 'CD-28'
        && ($directorRecord['director_authorisation']['required_for_trust_surface'] ?? null) === true,
    aiRunDetail($directorMissing) . "\n---\n" . aiRunDetail($directorStarted) . "\nrecord=" . json_encode($directorRecord, JSON_UNESCAPED_SLASHES)
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

$ccBroken = $fixture . '/cc-broken';
mkdir($ccBroken, 0777, true);
file_put_contents($ccBroken . '/broken.json', '{not valid json');
$ccBrokenResult = $commitCheck($ccBroken);
$h->test(
    '25a. commit-check: a malformed record is NOT ELIGIBLE and names the file (D3)',
    $ccBrokenResult['code'] === 3
        && str_contains($ccBrokenResult['output'], 'NOT ELIGIBLE')
        && str_contains($ccBrokenResult['output'], 'broken.json')
        && str_contains($ccBrokenResult['output'], 'unreadable'),
    aiRunDetail($ccBrokenResult)
);

$ccTrust = $fixture . '/cc-trust';
mkdir($ccTrust, 0777, true);
aiRunLedger($tool, $freshRun('cc-trust', $ccTrust));
file_put_contents($fixture . '/cc-trust.log', "1/1 passed\n");
aiRunLedger($tool, ['finish', '--id=cc-trust', '--exit=0', "--log={$fixture}/cc-trust.log", "--runs-dir={$ccTrust}"]);
$trustRecordPath = $ccTrust . '/cc-trust.json';
$trustRecord = json_decode((string) file_get_contents($trustRecordPath), true);
$trustRecord['trust_surface_files']['tools/ai-run.php'] = str_repeat('0', 64);
$trustRecord['trust_surface_hash'] = str_repeat('1', 64);
file_put_contents($trustRecordPath, json_encode($trustRecord, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
$ccTrustResult = $commitCheck($ccTrust);
$h->test(
    '25b. commit-check: a trust-surface hash mismatch blocks and names the file (D2)',
    $ccTrustResult['code'] === 3
        && str_contains($ccTrustResult['output'], 'trust_surface_mismatch')
        && str_contains($ccTrustResult['output'], 'tools/ai-run.php'),
    aiRunDetail($ccTrustResult)
);

$ccLegacy = $fixture . '/cc-legacy';
mkdir($ccLegacy, 0777, true);
aiRunLedger($tool, $freshRun('cc-legacy', $ccLegacy));
file_put_contents($fixture . '/cc-legacy.log', "1/1 passed\n");
aiRunLedger($tool, ['finish', '--id=cc-legacy', '--exit=0', "--log={$fixture}/cc-legacy.log", "--runs-dir={$ccLegacy}"]);
$legacyPath = $ccLegacy . '/cc-legacy.json';
$legacyRecord = json_decode((string) file_get_contents($legacyPath), true);
unset($legacyRecord['trust_surface_hash'], $legacyRecord['trust_surface_files']);
file_put_contents($legacyPath, json_encode($legacyRecord, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
$ccLegacyResult = $commitCheck($ccLegacy);
$h->test(
    '25c. commit-check: a legacy record without a trust-surface hash is not blocked',
    $ccLegacyResult['code'] === 0 && str_contains($ccLegacyResult['output'], 'ELIGIBLE'),
    aiRunDetail($ccLegacyResult)
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

// ── R8b: the verifier executes the subject's native Python evidence (slice C) ────────────────────
// The Python shapes are data in COMMAND_ALLOWLIST. These cases prove the shape is not merely
// permitted but executable, and that execution leaves the tree exactly as it found it.

$pythonRealReport = $fixture . '/verify-python-real.md';
// tests/poc_polyglot_wire_test.py is a real repository file with no pre-existing .pyc, so a stray
// py_compile bytecode artefact is visible to the scan below rather than hidden behind a same path.
file_put_contents($pythonRealReport, <<<'MD'
# Python native-evidence fixture
$ python3 -m py_compile tests/poc_polyglot_wire_test.py
exit 0
MD);
$pythonGitBefore = aiRunGitPorcelain($h->basePath());
$pythonPycBefore = aiRunPycFiles($h->basePath());
$run(array_merge($startArgs('verify-python-real'), ["--report={$pythonRealReport}"]));
$run(['finish', '--id=verify-python-real', '--exit=0']);
$verifyPython = $run(['verify', '--run=verify-python-real', '--json']);
$pythonGitAfter = aiRunGitPorcelain($h->basePath());
$pythonPycAfter = aiRunPycFiles($h->basePath());
$verifyPythonData = json_decode($verifyPython['output'], true);
$verifyPythonClaims = is_array($verifyPythonData['claims'] ?? null) ? $verifyPythonData['claims'] : [];
$verifyPythonClaim = is_array($verifyPythonClaims[0] ?? null) ? $verifyPythonClaims[0] : [];
$verifyPythonVerification = is_array($verifyPythonClaim['verification'] ?? null) ? $verifyPythonClaim['verification'] : [];
$h->test(
    '26a. a real python3 -m py_compile command is executed and re-derives to RE_DERIVED',
    $verifyPython['code'] === 0
        && ($verifyPythonClaim['status'] ?? null) === 'RE_DERIVED'
        && ($verifyPythonClaim['type'] ?? null) === 'LINT_RESULT'
        && ($verifyPythonVerification['method'] ?? null) === 'independent_execution'
        && ($verifyPythonVerification['verifier'] ?? null) === 'deterministic'
        && ($verifyPythonVerification['observed_exit'] ?? null) === 0,
    aiRunDetail($verifyPython)
);
$h->test(
    '26b. a python verification leaves the working tree unchanged (git status and bytecode)',
    $pythonGitBefore === $pythonGitAfter && $pythonPycBefore === $pythonPycAfter,
    'git unchanged: ' . ($pythonGitBefore === $pythonGitAfter ? 'yes' : 'no')
        . '; bytecode unchanged: ' . ($pythonPycBefore === $pythonPycAfter ? 'yes' : 'no')
        . '; pyc before=' . json_encode($pythonPycBefore) . ' after=' . json_encode($pythonPycAfter)
);

// The module shape is bounded to this repository's own `tests.test_*` package. Running it against a
// deliberately non-existent module proves the rule executes (a removed rule would refuse before
// execution) while causing no side effects; the empty claim means it is never recorded as verified.
$pythonModuleReport = $fixture . '/verify-python-module.md';
file_put_contents($pythonModuleReport, "# Python module-shape fixture\n\$ python3 -m unittest tests.test_no_such_module_xyz\n");
$run(array_merge($startArgs('verify-python-module'), ["--report={$pythonModuleReport}"]));
$run(['finish', '--id=verify-python-module', '--exit=0']);
$verifyPythonModule = $run(['verify', '--run=verify-python-module', '--json']);
$verifyPythonModuleData = json_decode($verifyPythonModule['output'], true);
$verifyPythonModuleClaims = is_array($verifyPythonModuleData['claims'] ?? null) ? $verifyPythonModuleData['claims'] : [];
$verifyPythonModuleClaim = is_array($verifyPythonModuleClaims[0] ?? null) ? $verifyPythonModuleClaims[0] : [];
$verifyPythonModuleVerification = is_array($verifyPythonModuleClaim['verification'] ?? null) ? $verifyPythonModuleClaim['verification'] : [];
$h->test(
    '26c. the bounded unittest shape is allowlisted and executed, not refused',
    ($verifyPythonModuleClaim['type'] ?? null) === 'TEST_RESULT'
        && ($verifyPythonModuleVerification['method'] ?? null) === 'independent_execution'
        && ($verifyPythonModuleVerification['observed_exit'] ?? null) === 1,
    aiRunDetail($verifyPythonModule)
);

// The file-test shape carries an existence screen. A name that does not exist must be refused before
// execution; a screen-less rule would have attempted to run the missing file instead.
$pythonMissingReport = $fixture . '/verify-python-missing-file.md';
file_put_contents($pythonMissingReport, "# Python file-test fixture\n\$ python3 tools/harpp-bridge/tests/no_such_test_xyz.py\nexit 0\n");
$run(array_merge($startArgs('verify-python-missing'), ["--report={$pythonMissingReport}"]));
$run(['finish', '--id=verify-python-missing', '--exit=0']);
$verifyPythonMissing = $run(['verify', '--run=verify-python-missing', '--json']);
$verifyPythonMissingData = json_decode($verifyPythonMissing['output'], true);
$verifyPythonMissingClaims = is_array($verifyPythonMissingData['claims'] ?? null) ? $verifyPythonMissingData['claims'] : [];
$verifyPythonMissingClaim = is_array($verifyPythonMissingClaims[0] ?? null) ? $verifyPythonMissingClaims[0] : [];
$verifyPythonMissingVerification = is_array($verifyPythonMissingClaim['verification'] ?? null) ? $verifyPythonMissingClaim['verification'] : [];
$h->test(
    '26d. the file-test shape refuses a missing file through its existence screen',
    ($verifyPythonMissingClaim['status'] ?? null) === 'UNVERIFIED'
        && ($verifyPythonMissingVerification['reason'] ?? null) === 'command_not_allowlisted'
        && ($verifyPythonMissingVerification['method'] ?? null) === null
        && !is_file($h->basePath() . '/tools/harpp-bridge/tests/no_such_test_xyz.py'),
    aiRunDetail($verifyPythonMissing)
);

// The file-test rule itself is data in the table: prove it is declared (with the screen) so removing
// the rule is a test failure, even though the shape is not executed here against the live suite.
$aiRunToolSource = (string) file_get_contents($tool);
$h->test(
    '26e. the file-test shape is declared in the allowlist with its existence screen',
    str_contains($aiRunToolSource, "'screen' => 'bridge_test'")
        && str_contains($aiRunToolSource, 'harpp-bridge')
        && str_contains($aiRunToolSource, 'python3'),
    'tool declares bridge_test screen: ' . (str_contains($aiRunToolSource, "'screen' => 'bridge_test'") ? 'yes' : 'no')
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

// ── R8c: the refusal path, extended to the native shapes ────────────────────────────────────────
// A refusal that still executes is the worst outcome, so every case below shares one sentinel file:
// the command names it, and after every refusal it must still not exist. `python3 -c` is the point
// of AC3: inline code is arbitrary execution, i.e. the Python form of the B-F1 vacuity hole (where a
// report could advance a job with `verify="true"`). It is not a shape this verifier may run.
$pythonSentinel = $fixture . '/verify-python-refused-sentinel';
$pythonRefusals = [
    'chained-and' => 'python3 -m py_compile tools/harpp-bridge/harpp_wake.py && touch ' . $pythonSentinel,
    'traversal' => 'python3 -m py_compile ../../etc/passwd',
    'inline-code' => 'python3 -c "import os; os.system(\'touch ' . $pythonSentinel . '\')"',
    'module-os' => 'python3 -m unittest os',
    'bare-file' => 'python3 evil.py',
    'chained-pipe' => 'python3 -m py_compile tools/harpp-bridge/harpp_wake.py | tee ' . $pythonSentinel,
];
foreach ($pythonRefusals as $pythonLabel => $pythonCommand) {
    $pythonRunId = 'refuse-python-' . $pythonLabel;
    $pythonReport = $fixture . '/verify-' . $pythonRunId . '.md';
    file_put_contents($pythonReport, "# Python refusal fixture — {$pythonLabel}\n\$ " . $pythonCommand . "\nexit 0\n");
    $run(array_merge($startArgs($pythonRunId), ["--report={$pythonReport}"]));
    $run(['finish', '--id=' . $pythonRunId, '--exit=0']);
    $pythonResult = $run(['verify', '--run=' . $pythonRunId, '--json']);
    $pythonData = json_decode($pythonResult['output'], true);
    $pythonClaims = is_array($pythonData['claims'] ?? null) ? $pythonData['claims'] : [];
    $pythonClaim = is_array($pythonClaims[0] ?? null) ? $pythonClaims[0] : [];
    $pythonVerification = is_array($pythonClaim['verification'] ?? null) ? $pythonClaim['verification'] : [];
    $h->test(
        '29-' . $pythonLabel . '. python refusal (' . $pythonLabel . '): refused and not executed',
        ($pythonClaim['status'] ?? null) === 'UNVERIFIED'
            && ($pythonVerification['reason'] ?? null) === 'command_not_allowlisted'
            && ($pythonVerification['method'] ?? null) === null
            && ($pythonVerification['observed_exit'] ?? null) === null
            && !is_file($pythonSentinel),
        'sentinel exists: ' . (is_file($pythonSentinel) ? 'yes' : 'no') . "\n" . aiRunDetail($pythonResult)
    );
}

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

// ── R10: runner artefact usage and one-run acknowledged blocks ─────────────────────────────────
$h->section('runner usage is artefact-derived; blocked history is acknowledged, never rewritten');
$usageRuns = $fixture . '/usage-runs';
mkdir($usageRuns, 0777, true);
$session = $fixture . '/runner-session.jsonl';
$sessionId = 'fixture-session';
file_put_contents($session, json_encode(['type' => 'session', 'version' => 3, 'id' => $sessionId, 'timestamp' => date(DATE_ATOM)]) . "\n");
$oldEnv = [];
foreach (['PI_SESSION_ID', 'PI_SESSION_FILE', 'PI_PROVIDER', 'PI_MODEL'] as $name) { $oldEnv[$name] = getenv($name); }
putenv('PI_SESSION_ID=' . $sessionId); putenv('PI_SESSION_FILE=' . $session); putenv('PI_PROVIDER=fixture'); putenv('PI_MODEL=model');
$usageStart = aiRunLedger($tool, ['start', "--contract={$contract}", '--lane=fixture/model', '--name=usage-bound', "--runs-dir={$usageRuns}"]);
file_put_contents($session, json_encode(['type' => 'message', 'timestamp' => date(DATE_ATOM), 'message' => [
    'role' => 'assistant', 'usage' => ['input' => 11, 'output' => 7, 'cacheRead' => 3, 'cacheWrite' => 0,
        'reasoning' => 2, 'totalTokens' => 21, 'cost' => ['input' => 0.01, 'output' => 0.02, 'cacheRead' => 0.003, 'cacheWrite' => 0, 'total' => 0.033]],
]]) . "\n", FILE_APPEND);
file_put_contents($fixture . '/usage.log', "1/1 passed\n");
$usageFinish = aiRunLedger($tool, ['finish', '--id=usage-bound', '--exit=0', "--log={$fixture}/usage.log", "--runs-dir={$usageRuns}"]);
foreach ($oldEnv as $name => $value) { putenv($value === false ? $name : $name . '=' . $value); }
$usageRecord = aiRunRecord($usageRuns, 'usage-bound');
$h->test('35. run binds the exposed runner session and derives exact tokens/cost from its JSONL', $usageStart['code'] === 0 && $usageFinish['code'] === 0
    && ($usageRecord['runner_session']['session_id'] ?? null) === $sessionId
    && ($usageRecord['usage']['total_tokens'] ?? null) === 21
    && ($usageRecord['usage']['cost_usd'] ?? null) === 0.033
    && ($usageRecord['usage']['source'] ?? null) === 'bound_pi_session_jsonl'
    && array_key_exists('usage_unavailable_reason', (array) $usageRecord) && $usageRecord['usage_unavailable_reason'] === null,
    aiRunDetail($usageStart) . "\n" . aiRunDetail($usageFinish) . "\nrecord=" . json_encode($usageRecord, JSON_UNESCAPED_SLASHES));

$unboundRuns = $fixture . '/unbound-runs'; mkdir($unboundRuns, 0777, true);
$unboundStart = aiRunLedger($tool, ['start', "--contract={$contract}", '--lane=no-such-lane', '--name=usage-null', "--runs-dir={$unboundRuns}"]);
$unboundRecord = aiRunRecord($unboundRuns, 'usage-null');
$h->test('36. absent runner usage is explicit null with a reason, never a figure', $unboundStart['code'] === 0
    && array_key_exists('usage', (array) $unboundRecord) && $unboundRecord['usage'] === null
    && is_string($unboundRecord['usage_unavailable_reason'] ?? null), json_encode($unboundRecord, JSON_UNESCAPED_SLASHES));

$ackRuns = $fixture . '/ack-runs'; mkdir($ackRuns, 0777, true);
$blockedRecord = ['id' => 'slice-a-block', 'status' => 'blocked', 'started_at' => date(DATE_ATOM), 'finished_at' => date(DATE_ATOM),
    'pid' => 0, 'scope_conformance' => ['ok' => false, 'offending' => [['path' => 'tools/ai-run.php', 'reasons' => ['baseline unavailable']]]]];
file_put_contents($ackRuns . '/slice-a-block.json', json_encode($blockedRecord, JSON_PRETTY_PRINT) . "\n");
$blockReason = 'baseline unavailable — acknowledged verbatim';
$ackMissing = aiRunLedger($tool, ['commit-check', '--acknowledge-block=slice-a-block', '--reason=' . $blockReason, "--runs-dir={$ackRuns}"]);
$beforeAck = (string) file_get_contents($ackRuns . '/slice-a-block.json');
$ackOk = aiRunLedger($tool, ['commit-check', '--acknowledge-block=slice-a-block', '--reason=' . $blockReason,
    '--director-decision=CD-29', "--runs-dir={$ackRuns}"]);
$afterAck = (string) file_get_contents($ackRuns . '/slice-a-block.json');
$ackDocument = json_decode((string) file_get_contents($ackRuns . '/.acknowledged-blocks.v1'), true);
$ack = is_array($ackDocument['acknowledgements'][0] ?? null) ? $ackDocument['acknowledgements'][0] : [];
$h->test('37. acknowledgement refuses without a resolvable director decision (exit 3)', $ackMissing['code'] === 3 && str_contains($ackMissing['output'], 'director-decision'), aiRunDetail($ackMissing));
$h->test('38. one blocked run is acknowledged verbatim and commit-check becomes eligible', $ackOk['code'] === 0
    && str_contains($ackOk['output'], 'ACKNOWLEDGED BLOCK') && str_contains($ackOk['output'], 'ELIGIBLE')
    && ($ack['block_reason'] ?? null) === $blockReason && ($ack['director_decision'] ?? null) === 'CD-29'
    && is_string($ack['acknowledged_at'] ?? null), aiRunDetail($ackOk) . "\nack=" . json_encode($ack, JSON_UNESCAPED_SLASHES));
$h->test('39. acknowledgement does not mutate status, scope conformance, or any run-record byte', $beforeAck === $afterAck
    && (json_decode($afterAck, true)['status'] ?? null) === 'blocked'
    && (json_decode($afterAck, true)['scope_conformance']['ok'] ?? null) === false, 'before_sha=' . hash('sha256', $beforeAck) . ' after_sha=' . hash('sha256', $afterAck));
$ackAgain = aiRunLedger($tool, ['commit-check', '--acknowledge-block=slice-a-block', '--reason=again', '--director-decision=CD-29', "--runs-dir={$ackRuns}"]);
$h->test('40. a named blocked run can be acknowledged only once', $ackAgain['code'] === 3 && str_contains($ackAgain['output'], 'already acknowledged once'), aiRunDetail($ackAgain));
file_put_contents($ackRuns . '/new-block.json', json_encode(array_merge($blockedRecord, ['id' => 'new-block'])) . "\n");
$newBlock = aiRunLedger($tool, ['commit-check', "--runs-dir={$ackRuns}"]);
$h->test('41. a new blocked run blocks again; acknowledgement is not a general unblock', $newBlock['code'] === 3
    && str_contains($newBlock['output'], 'new-block') && !str_contains($newBlock['output'], 'BLOCK  slice-a-block'), aiRunDetail($newBlock));

$liveAckRuns = $fixture . '/live-ack-runs'; mkdir($liveAckRuns, 0777, true);
file_put_contents($liveAckRuns . '/live.json', json_encode(['id' => 'live', 'status' => 'running', 'started_at' => date(DATE_ATOM),
    'pid' => getmypid(), 'scope_conformance' => ['ok' => false]]) . "\n");
$liveAck = aiRunLedger($tool, ['commit-check', '--acknowledge-block=live', '--reason=not finished', '--director-decision=CD-29', "--runs-dir={$liveAckRuns}"]);
$h->test('42. a still-running run cannot be acknowledged (exit 3)', $liveAck['code'] === 3 && str_contains($liveAck['output'], 'still running'), aiRunDetail($liveAck));

// ── R11: declared harness artefacts are shown, guarded, and A-F2 is unweakened (slice D) ─────────
$h->section('declared harness artefacts: shown, guarded, and A-F2 unweakened');
$repoRoot = dirname(__DIR__);
$guardContract = $fixture . '/guard-contract.md';
file_put_contents($guardContract, <<<'MD'
# CONTRACT — guard fixture
## Objective
Exercise the declared harness-artefact guards.
## Architectural constraints
- none
## Files likely affected
- `docs/guard.md` — benign in-scope path
## Acceptance criteria
- guard refusals name the path and the reason
## Required tests
- `php tests/ai_run_test.php`
## Risks
- a declaration that becomes an exemption
## Forbidden changes
- `kernel/`
- `docs/`
MD);

// GUARD 1a — a declared artefact may not be the verifier: an exact file or anything covering it.
$guardTrust = $run(['start', "--contract={$contract}", '--lane=fixture', '--name=guard-trust', '--harness-artifact=tools/ai-run.php']);
$guardTrustDir = $run(['start', "--contract={$contract}", '--lane=fixture', '--name=guard-trust-dir', '--harness-artifact=tools']);
$h->test(
    '43. GUARD 1 refuses a trust-surface artefact (exact file and covering directory), exit 2',
    $guardTrust['code'] === 2 && str_contains($guardTrust['output'], 'tools/ai-run.php') && str_contains($guardTrust['output'], 'trust surface')
        && $guardTrustDir['code'] === 2 && str_contains($guardTrustDir['output'], 'trust surface')
        && !is_file($runs . '/guard-trust.json') && !is_file($runs . '/guard-trust-dir.json'),
    aiRunDetail($guardTrust) . "\n---\n" . aiRunDetail($guardTrustDir)
);

// GUARD 1b — a declared artefact may not lie inside the contract's forbidden_scope.
$guardForbidden = $run(['start', "--contract={$guardContract}", '--lane=fixture', '--name=guard-forbidden', '--harness-artifact=docs/guard.md']);
$h->test(
    '44. GUARD 1 refuses a forbidden_scope artefact, naming the entry, exit 2',
    $guardForbidden['code'] === 2 && str_contains($guardForbidden['output'], 'forbidden_scope') && str_contains($guardForbidden['output'], 'docs')
        && !is_file($runs . '/guard-forbidden.json'),
    aiRunDetail($guardForbidden)
);

// GUARD 1c — traversal and absolute paths are refused before anything is recorded.
$guardTraversal = $run(['start', "--contract={$contract}", '--lane=fixture', '--name=guard-traversal', '--harness-artifact=../etc/passwd']);
$guardAbsolute = $run(['start', "--contract={$contract}", '--lane=fixture', '--name=guard-absolute', '--harness-artifact=/etc/passwd']);
$h->test(
    '45. a traversal or absolute artefact is refused, exit 2',
    $guardTraversal['code'] === 2 && str_contains($guardTraversal['output'], 'refused')
        && $guardAbsolute['code'] === 2 && str_contains($guardAbsolute['output'], 'refused')
        && !is_file($runs . '/guard-traversal.json') && !is_file($runs . '/guard-absolute.json'),
    aiRunDetail($guardTraversal) . "\n---\n" . aiRunDetail($guardAbsolute)
);

// GUARD 2 — an artefact declared after the evidence exists is refused.
$run($startArgs('guard-finish'));
$guardFinish = $run(['finish', '--id=guard-finish', '--exit=0', '--harness-artifact=docs/nope.txt']);
$h->test(
    '46. GUARD 2 refuses --harness-artifact on finish and leaves the run running, exit 2',
    $guardFinish['code'] === 2 && str_contains($guardFinish['output'], 'GUARD 2')
        && aiRunStatus($runs, 'guard-finish') === 'running',
    aiRunDetail($guardFinish)
);

// A declared artefact is excluded from the attributed delta AND shown in scope_conformance.
$declaredRelative = '.ai/ai-run-declared-' . bin2hex(random_bytes(6)) . '.txt';
$declaredAbsolute = $repoRoot . '/' . $declaredRelative;
$declaredStart = $run(['start', "--contract={$contract}", '--lane=fixture', '--name=declared-artifact', '--harness-artifact=' . $declaredRelative]);
$declaredStartRecord = aiRunRecord($runs, 'declared-artifact');
file_put_contents($declaredAbsolute, "written by the harness in the run name\n");
$declaredFinish = $run(['finish', '--id=declared-artifact', '--exit=0']);
$declaredRecord = aiRunRecord($runs, 'declared-artifact');
@unlink($declaredAbsolute);
$h->test(
    '47. a declared artefact is excluded from the delta and shown in scope_conformance',
    $declaredStart['code'] === 0 && $declaredFinish['code'] === 0
        && ($declaredStartRecord['harness_artifacts'] ?? null) === [$declaredRelative]
        && ($declaredRecord['scope_conformance']['ok'] ?? false) === true
        && in_array($declaredRelative, (array) ($declaredRecord['scope_conformance']['declared_harness_artifacts'] ?? []), true)
        && !in_array($declaredRelative, (array) ($declaredRecord['scope_delta_paths'] ?? []), true)
        && (array) ($declaredRecord['scope_conformance']['checked'] ?? []) === [],
    'record=' . json_encode($declaredRecord, JSON_UNESCAPED_SLASHES)
);

// A-F2 is unweakened: an undeclared out-of-scope path is detected and attributed exactly as before.
$undeclaredRelative = '.ai/ai-run-undeclared-' . bin2hex(random_bytes(6)) . '.txt';
$undeclaredAbsolute = $repoRoot . '/' . $undeclaredRelative;
$run($startArgs('undeclared-block'));
file_put_contents($undeclaredAbsolute, "written by the executor\n");
$undeclaredFinish = $run(['finish', '--id=undeclared-block', '--exit=0']);
$undeclaredRecord = aiRunRecord($runs, 'undeclared-block');
@unlink($undeclaredAbsolute);
$undeclaredOffending = array_column((array) ($undeclaredRecord['scope_conformance']['offending'] ?? []), 'path');
$h->test(
    '48. A-F2 unweakened: an undeclared out-of-scope path still blocks (exit 3)',
    $undeclaredFinish['code'] === 3 && aiRunStatus($runs, 'undeclared-block') === 'blocked'
        && in_array($undeclaredRelative, $undeclaredOffending, true)
        && ($undeclaredRecord['scope_conformance']['ok'] ?? true) === false,
    'record=' . json_encode($undeclaredRecord, JSON_UNESCAPED_SLASHES)
);

// A declaration narrows only what is attributed: the undeclared path still blocks, the declared one is shown.
$mixedDeclared = '.ai/ai-run-mixed-declared-' . bin2hex(random_bytes(6)) . '.txt';
$mixedDeclaredAbsolute = $repoRoot . '/' . $mixedDeclared;
$mixedUndeclared = '.ai/ai-run-mixed-undeclared-' . bin2hex(random_bytes(6)) . '.txt';
$mixedUndeclaredAbsolute = $repoRoot . '/' . $mixedUndeclared;
$run(['start', "--contract={$contract}", '--lane=fixture', '--name=mixed-artifact', '--harness-artifact=' . $mixedDeclared]);
file_put_contents($mixedDeclaredAbsolute, "harness\n");
file_put_contents($mixedUndeclaredAbsolute, "executor\n");
$mixedFinish = $run(['finish', '--id=mixed-artifact', '--exit=0']);
$mixedRecord = aiRunRecord($runs, 'mixed-artifact');
@unlink($mixedDeclaredAbsolute);
@unlink($mixedUndeclaredAbsolute);
$mixedOffending = array_column((array) ($mixedRecord['scope_conformance']['offending'] ?? []), 'path');
$h->test(
    '49. a declaration narrows attribution only: declared shown, undeclared still blocks',
    $mixedFinish['code'] === 3
        && in_array($mixedDeclared, (array) ($mixedRecord['scope_conformance']['declared_harness_artifacts'] ?? []), true)
        && !in_array($mixedDeclared, $mixedOffending, true)
        && in_array($mixedUndeclared, $mixedOffending, true)
        && !in_array($mixedDeclared, (array) ($mixedRecord['scope_delta_paths'] ?? []), true),
    'record=' . json_encode($mixedRecord, JSON_UNESCAPED_SLASHES)
);

// ── R10: the exceptions route is recorded at run-finish, and not otherwise ───────────────────────
// A run whose only delta is an existing test file is refused by A-F2 (scopeConformance -> check).
// With a pre-declared exception in the dispatch contract, the same run records, and the run record
// names which exception and under whose authority. Without one, it blocks exactly as before.
$h->section('CD-44 — an authorised existing-test change is recorded and attributed');
$exceptionProbeRelative = 'tests/ai-run-exception-' . bin2hex(random_bytes(6)) . '_test.php';
$exceptionProbeAbsolute = $repoRoot . '/' . $exceptionProbeRelative;
$exceptionContract = $fixture . '/exceptions-contract.md';
file_put_contents($exceptionContract, str_replace(
    "# CONTRACT — ledger fixture\n",
    "# CONTRACT — ledger fixture\nexceptions:\n  - what:     correct a stale assertion in a probe suite\n    why:      prove the route is recorded at run-finish\n    scope:    {$exceptionProbeRelative}\n    decided_when: 2020-01-01T00:00:00+00:00\n    authority: CD-44\n",
    $contractText
));
file_put_contents($exceptionProbeAbsolute, "<?php\n// probe: initial\n");
$exceptionStart = $run(['start', "--contract={$exceptionContract}", '--lane=fixture', '--name=exception-route']);
$exceptionStartRecord = aiRunRecord($runs, 'exception-route');
file_put_contents($exceptionProbeAbsolute, "<?php\n// probe: corrected\n");
$exceptionFinish = $run(['finish', '--id=exception-route', '--exit=0']);
$exceptionRecord = aiRunRecord($runs, 'exception-route');
@unlink($exceptionProbeAbsolute);
$exceptionAuthorised = is_array($exceptionRecord) && is_array($exceptionRecord['scope_conformance']['authorised_exceptions'] ?? null) ? $exceptionRecord['scope_conformance']['authorised_exceptions'] : [];
$h->test(
    '50. an authorised existing-test change finishes OK and the record names the exception and its authority',
    $exceptionStart['code'] === 0 && $exceptionFinish['code'] === 0
        && is_array($exceptionStartRecord) && ($exceptionStartRecord['declared_exceptions'][0]['authority'] ?? null) === 'CD-44'
        && is_array($exceptionRecord)
        && ($exceptionRecord['scope_conformance']['ok'] ?? false) === true
        && in_array($exceptionProbeRelative, (array) ($exceptionRecord['scope_conformance']['checked'] ?? []), true)
        && count($exceptionAuthorised) === 1
        && ($exceptionAuthorised[0]['exception']['authority'] ?? null) === 'CD-44'
        && ($exceptionAuthorised[0]['path'] ?? null) === $exceptionProbeRelative,
    aiRunDetail($exceptionStart) . "\n---\n" . aiRunDetail($exceptionFinish) . "\nstart_record=" . json_encode($exceptionStartRecord, JSON_UNESCAPED_SLASHES) . "\nfinish_record=" . json_encode($exceptionRecord, JSON_UNESCAPED_SLASHES)
);

// The control: the identical delta under a contract with no exception still blocks (exit 3).
$controlProbeRelative = 'tests/ai-run-exception-' . bin2hex(random_bytes(6)) . '_test.php';
$controlProbeAbsolute = $repoRoot . '/' . $controlProbeRelative;
file_put_contents($controlProbeAbsolute, "<?php\n// control: initial\n");
$controlStart = $run($startArgs('exception-control'));
file_put_contents($controlProbeAbsolute, "<?php\n// control: corrected\n");
$controlFinish = $run(['finish', '--id=exception-control', '--exit=0']);
$controlRecord = aiRunRecord($runs, 'exception-control');
@unlink($controlProbeAbsolute);
$controlOffending = array_column((array) ($controlRecord['scope_conformance']['offending'] ?? []), 'path');
$h->test(
    '51. the same existing-test delta without an exception still blocks (exit 3), unweakened',
    $controlStart['code'] === 0 && $controlFinish['code'] === 3
        && aiRunStatus($runs, 'exception-control') === 'blocked'
        && in_array($controlProbeRelative, $controlOffending, true)
        && ($controlRecord['scope_conformance']['ok'] ?? true) === false,
    aiRunDetail($controlFinish) . "\nrecord=" . json_encode($controlRecord, JSON_UNESCAPED_SLASHES)
);

// Pre-declaration is structural: retrofitting an exception into the contract after dispatch moves
// the contract revision, and finish refuses the run rather than honouring it.
$retrofitProbeRelative = 'tests/ai-run-exception-' . bin2hex(random_bytes(6)) . '_test.php';
$retrofitProbeAbsolute = $repoRoot . '/' . $retrofitProbeRelative;
file_put_contents($retrofitProbeAbsolute, "<?php\n// retrofit: initial\n");
$retrofitStart = $run($startArgs('exception-retrofit'));
file_put_contents($retrofitProbeAbsolute, "<?php\n// retrofit: corrected\n");
file_put_contents($contract, str_replace(
    "# CONTRACT — ledger fixture\n",
    "# CONTRACT — ledger fixture\nexceptions:\n  - what:     retrofit after dispatch\n    why:      supplied after the evidence exists\n    scope:    {$retrofitProbeRelative}\n    decided_when: 2020-01-01T00:00:00+00:00\n    authority: CD-44\n",
    $contractText
));
$retrofitFinish = $run(['finish', '--id=exception-retrofit', '--exit=0']);
$retrofitRecord = aiRunRecord($runs, 'exception-retrofit');
file_put_contents($contract, $contractText);
@unlink($retrofitProbeAbsolute);
$h->test(
    '52. an exception supplied after dispatch moves the revision and is refused (exit 3)',
    $retrofitStart['code'] === 0 && $retrofitFinish['code'] === 3
        && aiRunStatus($runs, 'exception-retrofit') === 'blocked'
        && str_contains($retrofitFinish['output'], 'contract revision moved after dispatch')
        && ($retrofitRecord['scope_conformance']['ok'] ?? true) === false,
    aiRunDetail($retrofitFinish) . "\nrecord=" . json_encode($retrofitRecord, JSON_UNESCAPED_SLASHES)
);

aiRunRemoveFixture($fixture);
$h->done();
