<?php

declare(strict_types=1);

require_once __DIR__ . '/harness/TestHarness.php';

$h = new TestHarness('ai-project', TestHarness::MODE_PURE);
$h->fingerprint('tools/ai-project.php');

/**
 * @param list<string> $args
 * @return array{code:int,output:string}
 */
function projectTestRun(array $args): array
{
    $argv = array_merge([PHP_BINARY, dirname(__DIR__) . '/tools/ai-project.php'], $args);
    $pipes = [];
    $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return ['code' => 127, 'output' => 'proc_open failed'];
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'output' => ($out ?: '') . ($err ?: '')];
}

function projectFixture(string $root, string $id = 'fixture'): void
{
    $dir = $root . '/' . $id;
    mkdir($dir . '/slices', 0777, true);
    file_put_contents($dir . '/project.md', "# PROJECT — fixture\nstatus: READY_FOR_IMPLEMENTATION\n\n## Slices\n| # | Slice | Delivers |\n|---|---|---|\n| **S1** | First | x |\n| **S2** | Second | y |\n");
    foreach ([1, 2] as $n) {
        file_put_contents($dir . "/slices/s{$n}.md", "# SLICE — S{$n}\nstatus: READY_FOR_IMPLEMENTATION\n## Objective\nFixture.\n## Architectural constraints\n- pure\n## Files likely affected\n- `tools/ai-project.php`\n## Acceptance criteria\n- criterion one\n- criterion two\n## Required tests\n- `php -l tools/ai-project.php`\n## Risks\n- none\n## Forbidden changes\n- `kernel/`\n");
    }
}

function removeProjectFixture(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        $item = $path . '/' . $entry;
        is_dir($item) ? removeProjectFixture($item) : @unlink($item);
    }
    @rmdir($path);
}

$base = sys_get_temp_dir() . '/ikabud-ai-project-' . bin2hex(random_bytes(5));
$projects = $base . '/projects';
$runs = $base . '/runs';
mkdir($projects, 0777, true);
mkdir($runs, 0777, true);
projectFixture($projects);
$common = ['--project=fixture', '--projects-dir=' . $projects, '--runs-dir=' . $runs];

$h->section('Derived obligations and first eligible slice');
$obligations = projectTestRun(array_merge(['obligations'], $common));
$h->test('1. two slices with two criteria each derive four remaining obligations', $obligations['code'] === 0 && trim($obligations['output']) === '4', $obligations['output']);
$next = projectTestRun(array_merge(['next'], $common));
$h->test('2. next returns the first ordered slice', $next['code'] === 0 && str_contains($next['output'], 'NEXT S1'), $next['output']);

$h->section('Done requires independently re-derived claims');
file_put_contents($runs . '/bad.json', json_encode(['id' => 'bad', 'status' => 'completed', 'claim_verification' => ['results' => [['status' => 'UNVERIFIED']]]]));
projectTestRun(array_merge(['transition', '--slice=S1', '--state=running', '--run=bad'], $common));
$badDone = projectTestRun(array_merge(['transition', '--slice=S1', '--state=done', '--run=bad'], $common));
$h->test('3. UNVERIFIED claims cannot mark a slice done', $badDone['code'] === 3 && str_contains($badDone['output'], 'UNVERIFIED'), $badDone['output']);
file_put_contents($runs . '/good.json', json_encode(['id' => 'good', 'status' => 'completed', 'claim_verification' => ['results' => [['status' => 'RE_DERIVED']]]]));
// Recreate to avoid weakening the state machine after the deliberate refusal.
removeProjectFixture($projects . '/fixture');
projectFixture($projects);
projectTestRun(array_merge(['transition', '--slice=S1', '--state=running', '--run=good'], $common));
$goodDone = projectTestRun(array_merge(['transition', '--slice=S1', '--state=done', '--run=good'], $common));
$after = projectTestRun(array_merge(['obligations'], $common));
$h->test('4. RE_DERIVED evidence marks done and removes only that slice obligations', $goodDone['code'] === 0 && trim($after['output']) === '2', $goodDone['output'] . $after['output']);
$second = projectTestRun(array_merge(['next'], $common));
$h->test('5. after S1 completes, next returns S2', $second['code'] === 0 && str_contains($second['output'], 'NEXT S2'), $second['output']);

$h->section('Blocked is fail closed');
file_put_contents($runs . '/block-evidence.json', json_encode(['id' => 'block-evidence', 'status' => 'failed']));
projectTestRun(array_merge(['transition', '--slice=S2', '--state=blocked', '--run=block-evidence', '--reason=fixture contradiction'], $common));
$blocked = projectTestRun(array_merge(['next'], $common));
$h->test('6. a blocked slice refuses next with the recorded reason', $blocked['code'] === 3 && str_contains($blocked['output'], 'blocked') && str_contains($blocked['output'], 'fixture contradiction'), $blocked['output']);

$h->section('Retry is the recorded way out of blocked');
$retryReason = 'claim binding repaired; re-derive the S4 run';
$retry = projectTestRun(array_merge(['retry', '--slice=S2', '--reason=' . $retryReason], $common));
$stateFile = $projects . '/fixture/state.json';
$retryState = json_decode((string) @file_get_contents($stateFile), true);
$retryHistory = is_array($retryState['slices']['S2']['history'] ?? null) ? $retryState['slices']['S2']['history'] : [];
$retryEntry = is_array(end($retryHistory)) ? end($retryHistory) : [];
$h->test(
    '7. retry moves blocked -> pending, records the reason and preserves the prior run id',
    $retry['code'] === 0
        && ($retryState['slices']['S2']['state'] ?? null) === 'pending'
        && ($retryState['slices']['S2']['reason'] ?? null) === $retryReason
        && ($retryState['slices']['S2']['run_id'] ?? null) === 'block-evidence'
        && ($retryEntry['from'] ?? null) === 'blocked'
        && ($retryEntry['to'] ?? null) === 'pending'
        && ($retryEntry['reason'] ?? null) === $retryReason
        && ($retryEntry['run_id'] ?? null) === 'block-evidence',
    $retry['output'] . json_encode($retryState['slices']['S2'] ?? null)
);
$retryNext = projectTestRun(array_merge(['next'], $common));
$h->test('8. after a retry, next returns the slice again', $retryNext['code'] === 0 && str_contains($retryNext['output'], 'NEXT S2'), $retryNext['output']);

$notBlocked = projectTestRun(array_merge(['retry', '--slice=S2', '--reason=again'], $common));
$h->test(
    '9. retry refuses a slice that is not blocked',
    $notBlocked['code'] === 3 && str_contains($notBlocked['output'], 'not blocked'),
    $notBlocked['output']
);

projectTestRun(array_merge(['transition', '--slice=S2', '--state=blocked', '--run=block-evidence', '--reason=blocked again'], $common));
$emptyReason = projectTestRun(array_merge(['retry', '--slice=S2', '--reason= '], $common));
$h->test(
    '10. retry refuses an empty reason',
    $emptyReason['code'] === 2 && str_contains($emptyReason['output'], 'requires --reason'),
    $emptyReason['output']
);
$doneRetry = projectTestRun(array_merge(['retry', '--slice=S1', '--reason=should refuse'], $common));
$h->test(
    '11. retry refuses a done slice',
    $doneRetry['code'] === 3 && str_contains($doneRetry['output'], 'not blocked'),
    $doneRetry['output']
);

removeProjectFixture($base);
$h->done();
