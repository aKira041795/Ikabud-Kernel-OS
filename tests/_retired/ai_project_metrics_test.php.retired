<?php

declare(strict_types=1);

require_once __DIR__ . '/harness/TestHarness.php';

$h = new TestHarness('ai-project-metrics', TestHarness::MODE_PURE);
$h->fingerprint('tools/ai-project.php');

/**
 * Run the tool in a child PHP process and capture the merged output.
 *
 * @param list<string> $args
 * @return array{code:int,output:string}
 */
function metricsTestRun(array $args): array
{
    $argv = array_merge([PHP_BINARY, dirname(__DIR__) . '/tools/ai-project.php'], $args);
    $pipes = [];
    $process = proc_open(
        $argv,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        return ['code' => 127, 'output' => 'proc_open failed for ' . implode(' ', $argv)];
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'output' => ($out ?: '') . ($err ?: '')];
}

/**
 * @param list<string> $sliceIds
 */
function metricsFixtureProject(string $projects, string $id, array $sliceIds): string
{
    $dir = $projects . '/' . $id;
    mkdir($dir . '/slices', 0777, true);
    $rows = '';
    foreach ($sliceIds as $sliceId) {
        $rows .= "| **{$sliceId}** | Slice {$sliceId} | x |\n";
        file_put_contents(
            $dir . '/slices/' . strtolower($sliceId) . '.md',
            "# SLICE — {$sliceId}\nstatus: READY_FOR_IMPLEMENTATION\n## Objective\nFixture.\n"
            . "## Architectural constraints\n- pure\n## Files likely affected\n- `tools/ai-project.php`\n"
            . "## Acceptance criteria\n- criterion one\n## Required tests\n- `php -l tools/ai-project.php`\n"
            . "## Risks\n- none\n## Forbidden changes\n- `kernel/`\n"
        );
    }
    file_put_contents(
        $dir . '/project.md',
        "# PROJECT — {$id}\nstatus: READY_FOR_IMPLEMENTATION\n\n## Slices\n| # | Slice | Delivers |\n|---|---|---|\n" . $rows
    );
    return $dir;
}

/** @param array<string,mixed> $record */
function metricsRunRecord(string $runs, string $id, array $record): void
{
    file_put_contents($runs . '/' . $id . '.json', json_encode($record, JSON_PRETTY_PRINT));
}

function metricsFixtureRemove(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        $item = $path . '/' . $entry;
        is_dir($item) ? metricsFixtureRemove($item) : @unlink($item);
    }
    @rmdir($path);
}

/** @return array<string,mixed> */
function metricsJson(string $output): array
{
    $decoded = json_decode($output, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Resolve a dotted metric path against the decoded metrics payload.
 *
 * @param array<string,mixed> $payload
 */
function metricsAtPath(array $payload, string $path): mixed
{
    $node = $payload;
    foreach (explode('.', $path) as $key) {
        if (!is_array($node) || !array_key_exists($key, $node)) {
            return null;
        }
        $node = $node[$key];
    }
    return $node;
}

$base = sys_get_temp_dir() . '/ikabud-ai-metrics-' . bin2hex(random_bytes(5));
$projects = $base . '/projects';
$runs = $base . '/runs';
mkdir($projects, 0777, true);
mkdir($runs, 0777, true);

$chairDecisions = $base . '/chair-decisions.md';
file_put_contents($chairDecisions, "# Chair decisions\n\n## CD-1 — one\n\n## CD-2 — two\n\n## CD-3 — three\n");
$chairErrors = $base . '/chair-errors.json';
file_put_contents($chairErrors, json_encode(['errors' => [['id' => 'CD-6'], ['id' => 'CD-11']]]));

// ── Fixture A: partial data. Must never invent cost/tokens. ──────────────
$dirA = metricsFixtureProject($projects, 'fixture', ['S1', 'S2']);
metricsRunRecord($runs, 'run-a', [
    'id' => 'run-a',
    'contract' => $dirA . '/slices/s1.md',
    'lane' => 'L1',
    'status' => 'completed',
    'started_at' => '2026-01-01T00:00:00+00:00',
    'finished_at' => '2026-01-01T00:10:00+00:00',
    'log_bytes' => 123456,
    'claim_verification' => ['results' => [['status' => 'RE_DERIVED'], ['status' => 'CONTRADICTED']]],
]);
metricsRunRecord($runs, 'run-b', [
    'id' => 'run-b',
    'contract' => $dirA . '/slices/s2.md',
    'lane' => 'L2',
    'status' => 'running',
    'started_at' => '2026-01-01T00:05:00+00:00',
    'claim_verification' => ['results' => [['status' => 'UNVERIFIED']]],
]);
metricsRunRecord($runs, 'run-c', [
    'id' => 'run-c',
    'contract' => $dirA . '/slices/s2.md',
    'lane' => 'L3',
    'status' => 'failed',
    'started_at' => '2026-01-01T00:30:00+00:00',
]);
metricsRunRecord($runs, 'run-other', [
    'id' => 'run-other',
    'contract' => $base . '/elsewhere/other.md',
    'lane' => 'L9',
    'status' => 'completed',
    'started_at' => '2026-01-01T00:00:00+00:00',
    'finished_at' => '2026-01-01T00:01:00+00:00',
    'usage' => ['total_tokens' => 999999, 'cost_usd' => 99.0],
]);
file_put_contents($dirA . '/chair-errors.json', json_encode(['errors' => [['id' => 'CD-6'], ['id' => 'CD-11']]]));

$commonA = [
    'metrics',
    '--project=fixture',
    '--projects-dir=' . $projects,
    '--runs-dir=' . $runs,
    '--chair-decisions=' . $chairDecisions,
    '--json',
];

$h->section('Derived metrics match hand-computed values');
$resultA = metricsTestRun($commonA);
$jsonA = metricsJson($resultA['output']);
$m = $jsonA['metrics'] ?? [];
$h->test(
    '1. only runs whose contract belongs to the project are counted (3 of 4)',
    $resultA['code'] === 0
        && ($m['slices_dispatched'] ?? null) === 2
        && ($m['slices_dispatched_ids'] ?? null) === ['S1', 'S2']
        && ($m['lane_distribution'] ?? []) === ['L1' => 1, 'L2' => 1, 'L3' => 1],
    $resultA['output']
);
$h->test(
    '2. slices completed counts only slices with a completed run (S1, not S2)',
    ($m['slices_completed'] ?? null) === 1 && ($m['slices_completed_ids'] ?? null) === ['S1'],
    $resultA['output']
);
// The canonical run statuses are `tools/ai-run.php:58` RUN_STATUSES
// (running, completed, silent, failed, abandoned, blocked); `other` is the
// metric's bucket for a status outside that list. The expectation must name
// every canonical status with an explicit count, so a status silently dropped
// from the metric fails this strict comparison.
$h->test(
    '3. runs by status counts every canonical status',
    ($m['runs_by_status'] ?? null) === ['completed' => 1, 'silent' => 0, 'failed' => 1, 'abandoned' => 0, 'blocked' => 0, 'running' => 1, 'other' => 0],
    $resultA['output']
);
$h->test(
    '4. wall-clock per slice is the run span; unfinished runs are null',
    ($m['wall_clock_seconds_by_slice']['S1'] ?? null) === 600
        && array_key_exists('S2', $m['wall_clock_seconds_by_slice'] ?? [])
        && $m['wall_clock_seconds_by_slice']['S2'] === null
        && array_key_exists('wall_clock_seconds_total', $m)
        && $m['wall_clock_seconds_total'] === null,
    $resultA['output']
);

$h->section('Claim outcomes are counted per status');
$h->test(
    '5. RE_DERIVED, CONTRADICTED and UNVERIFIED are counted separately',
    ($m['claim_outcomes'] ?? null) === ['RE_DERIVED' => 1, 'CONTRADICTED' => 1, 'UNVERIFIED' => 1, 'other' => 0],
    $resultA['output']
);

$h->section('Unavailable metrics are null with a reason — no estimation path');
$unavailable = $jsonA['unavailable'] ?? [];
$h->test(
    '6. cost and tokens are null even though log_bytes is present (bytes are not tokens)',
    array_key_exists('cost_usd', $m) && $m['cost_usd'] === null
        && array_key_exists('tokens_total', $m) && $m['tokens_total'] === null
        && ($unavailable['tokens_total'] ?? '') !== ''
        && ($unavailable['cost_usd'] ?? '') !== '',
    $resultA['output']
);
$h->test(
    '7. contract violations are null with a stated reason, not guessed zero',
    array_key_exists('contract_violations', $m) && $m['contract_violations'] === null
        && ($unavailable['contract_violations'] ?? '') !== '',
    $resultA['output']
);
$allNullsHaveReasons = true;
foreach (array_keys($unavailable) as $path) {
    if (metricsAtPath($jsonA, 'metrics.' . $path) !== null) {
        $allNullsHaveReasons = false;
    }
}
$h->test(
    '8. every key in `unavailable` resolves to a null metric (no estimated number hides behind a reason)',
    $allNullsHaveReasons,
    $resultA['output']
);

$h->section('Director minutes are an input, never inferred');
$h->test(
    '9. absent director-minutes.json reports null, not zero',
    array_key_exists('director_minutes', $m) && $m['director_minutes'] === null
        && str_contains($unavailable['director_minutes'] ?? '', 'not zero'),
    $resultA['output']
);

// ── Fixture B: complete usage + logged director minutes + --write. ───────
$dirB = metricsFixtureProject($projects, 'capture', ['S1', 'S2']);
metricsRunRecord($runs, 'run-x', [
    'id' => 'run-x',
    'contract' => $dirB . '/slices/s1.md',
    'lane' => 'LX',
    'status' => 'completed',
    'started_at' => '2026-01-02T00:00:00+00:00',
    'finished_at' => '2026-01-02T00:01:00+00:00',
    'usage' => ['total_tokens' => 1000, 'cost_usd' => 0.25],
    'claim_verification' => ['results' => [['status' => 'RE_DERIVED']]],
]);
metricsRunRecord($runs, 'run-y', [
    'id' => 'run-y',
    'contract' => $dirB . '/slices/s2.md',
    'lane' => 'LY',
    'status' => 'completed',
    'started_at' => '2026-01-02T00:02:00+00:00',
    'finished_at' => '2026-01-02T00:05:00+00:00',
    'usage' => ['total_tokens' => 2000, 'cost_usd' => 0.5],
    'claim_verification' => ['results' => [['status' => 'RE_DERIVED']]],
]);
file_put_contents($dirB . '/chair-errors.json', json_encode(['errors' => [['id' => 'CD-6'], ['id' => 'CD-11']]]));
file_put_contents(
    $dirB . '/director-minutes.json',
    json_encode(['entries' => [
        ['at' => '2026-01-02T00:10:00+00:00', 'minutes' => 12, 'category' => 'concept', 'note' => ''],
        ['at' => '2026-01-02T00:11:00+00:00', 'minutes' => 5, 'category' => 'verification', 'note' => ''],
        ['at' => '2026-01-02T00:12:00+00:00', 'minutes' => 3, 'category' => 'concept', 'note' => ''],
    ]])
);
$commonB = [
    'metrics',
    '--project=capture',
    '--projects-dir=' . $projects,
    '--runs-dir=' . $runs,
    '--chair-decisions=' . $chairDecisions,
    '--json',
];
$resultB = metricsTestRun($commonB);
$jsonB = metricsJson($resultB['output']);
$mB = $jsonB['metrics'] ?? [];
$h->test(
    '10. usage present on every matched run is captured for real (tokens and cost summed)',
    $resultB['code'] === 0 && ($mB['tokens_total'] ?? null) === 3000 && ($mB['cost_usd'] ?? null) === 0.75,
    $resultB['output']
);
$h->test(
    '11. director minutes present are summed by category (concept + verification)',
    ($mB['director_minutes'] ?? null) === [
        'entries' => 3,
        'by_category' => ['concept' => 15, 'verification' => 5],
        'total' => 20,
    ],
    $resultB['output']
);
$h->test(
    '12. chair decisions are counted from headings and errors from the explicit list',
    ($mB['chair_decisions'] ?? null) === 3
        && ($mB['chair_decisions_incorrect'] ?? null) === 2
        && ($mB['chair_errors'] ?? null) === ['CD-6', 'CD-11'],
    $resultB['output']
);

$h->section('--write persists a tool-written artefact');
$writeResult = metricsTestRun(array_merge($commonB, ['--write']));
$written = $dirB . '/metrics.json';
$writtenJson = is_file($written) ? json_decode((string) file_get_contents($written), true) : null;
$h->test(
    '13. --write produces metrics.json marked tool_written with a timestamp and schema',
    $writeResult['code'] === 0
        && is_array($writtenJson)
        && ($writtenJson['tool_written'] ?? null) === true
        && ($writtenJson['schema'] ?? null) === 'ark.ai-project-metrics.v1'
        && is_string($writtenJson['generated_at'] ?? null)
        && $writtenJson['generated_at'] !== '',
    $writeResult['output']
);
$h->test(
    '14. the written artefact carries the same metrics as the printed payload',
    ($writtenJson['metrics'] ?? null) === $mB,
    $writeResult['output']
);

$h->section('Smoke run over the real project artefacts');
$smoke = metricsTestRun(['metrics', '--project=harpp-gen4']);
$h->test(
    '15. harpp-gen4 produces a table without error',
    $smoke['code'] === 0
        && str_contains($smoke['output'], 'METRICS harpp-gen4')
        && str_contains($smoke['output'], 'chair decisions'),
    $smoke['output']
);
$h->test(
    '16. the three recorded Chair errors appear in the error column',
    str_contains($smoke['output'], 'CD-6')
        && str_contains($smoke['output'], 'CD-11')
        && str_contains($smoke['output'], 'CD-12'),
    $smoke['output']
);

metricsFixtureRemove($base);
$h->done();
