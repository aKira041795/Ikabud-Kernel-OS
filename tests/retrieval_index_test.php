<?php

declare(strict_types=1);

/**
 * The chair harness and the retrieval index must keep their controls green.
 *
 * WHY THIS IS A TEST AND NOT A HABIT (2026-09-19)
 * Both tools carry their own `--self-test`, and a self-test that nothing runs is a self-test that
 * rots -- the repository already had one guard whose suite was never written, and its failure mode was
 * silence (a `--self-test` flag that exited 0 without printing anything). So the suite runs them, and
 * this file asserts the exit codes rather than trusting that someone will remember.
 *
 * It also exercises the index the way the harness does, because the unit controls run in a sandbox:
 * proving the class works on a temp tree does not prove it works on THIS repository, where the walk is
 * real, the file count is in the thousands, and the paths are the ones the callers actually pass.
 */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;

$check = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        printf("  [PASS] %s\n", $label);
        return;
    }
    $fail++;
    printf("  [FAIL] %s%s\n", $label, $detail === '' ? '' : "\n         {$detail}");
};

/** Run a command from the repository root and return its exit code and output. */
// ONE pipe, drained to EOF, and a wall-clock bound.
//
// This used to open two pipes and drain them in sequence -- stdout to EOF, then stderr. That is a
// deadlock, not a style question: a pipe holds 64 KiB, so a command that writes more than that to the
// SECOND stream fills it, blocks in write(), and the parent blocks in read() on a stream that will
// never close. Measured 2026-09-20: `run.php recall` emitted 1576 PHP warnings, blew past the buffer,
// and this file hung forever -- the harness killed it at its 900 s budget, recorded that as a RED
// baseline, and dispatched a lane which then hung in the same probe for 42 minutes. Merging stderr into
// stdout (`2>&1`) leaves exactly one pipe to drain, so the deadlock cannot form; `timeout` means a
// command that hangs here reports a verdict (124) instead of stopping the suite that is measuring it.
$run = static function (string $command) use ($root): array {
    $process = proc_open(
        ['timeout', '--signal=TERM', '300', 'bash', '-lc', $command . ' 2>&1'],
        [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        $root
    );
    if (!is_resource($process)) {
        return ['exit' => 127, 'output' => 'could not start'];
    }
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return ['exit' => proc_close($process), 'output' => trim($output)];
};

echo "=== the retrieval index's own controls ===\n";
$retrieval = $run('php kernel/Workbench/Retrieval/run.php --self-test');
$check(
    'the retrieval index passes its controls',
    $retrieval['exit'] === 0,
    'exit ' . $retrieval['exit'] . ': ' . substr($retrieval['output'], -300)
);

echo "\n=== the chair harness's own controls ===\n";
$chair = $run('php tools/chair.php --self-test');
$check(
    'the chair harness passes its controls, both directions of the floor',
    $chair['exit'] === 0,
    'exit ' . $chair['exit'] . ': ' . substr($chair['output'], -300)
);

echo "\n=== the index against THIS repository, which is what callers actually use ===\n";
$stats = $run('php kernel/Workbench/Retrieval/run.php stats');
$check(
    'stats reports an index over the repository rather than an empty one',
    preg_match('/documents (\d+)/', $stats['output'], $match) === 1 && (int) $match[1] > 500,
    $stats['output']
);

// A query a developer would really ask, against real files. If retrieval cannot find the moon in a
// game it lives in, it is not retrieval -- it is a term counter with a JSON file behind it.
$search = $run('php kernel/Workbench/Retrieval/run.php search "cratered moon nursery grey" --limit 5');
$check('a real query finds the rendered-canvas spec that asserts the moon', str_contains($search['output'], 'star-swarm-pixels.spec.ts'), $search['output']);
$check('and the game code that draws it', str_contains($search['output'], 'star-swarm.js'), $search['output']);

$scope = $run('php kernel/Workbench/Retrieval/run.php search "carrier pickup lance" --limit 5 --scope=public');
$check(
    'scope bounds the search: nothing outside the prefix is returned',
    !str_contains($scope['output'], 'tests/browser/'),
    $scope['output']
);

echo "\n=== retired material: the harness this repository no longer runs is not current instruction ===\n";
// The measurement this guards (2026-09-19): the query below returned
// `tools/harpp2/projects/star-swarm-galaga.json` TIED FOR FIRST with the live game code, because the
// staleness gate compares mtime and hash and a retired file never changes -- so it is permanently
// "current". A lane briefed from it would implement a harness that was retired the same day. This runs
// the acceptance command itself, against THIS repository, because a sandbox cannot see what a lane is
// actually handed.
$retired = $run('php kernel/Workbench/Retrieval/run.php search "star swarm carrier drop pickup weapon stage" --limit=8');
$check(
    'a real query does not brief a lane from the retired harness',
    !str_contains($retired['output'], 'tools/harpp2/'),
    $retired['output']
);
// And the other direction, so the material stays readable: the exclusion is a gate on what a brief
// contains, not a deletion of what is on disk.
$retiredAsked = $run('php kernel/Workbench/Retrieval/run.php search "star swarm carrier drop pickup weapon stage" --limit=8 --include-retired');
$check(
    'and --include-retired still reaches it, so the material is kept rather than lost',
    str_contains($retiredAsked['output'], 'tools/harpp2/'),
    $retiredAsked['output']
);

echo "\n=== recall: retrieval is measured against a known answer, not against a file count ===\n";
// `search` returning *a* document is not evidence that it returned the RIGHT one, and until this
// existed nothing measured the difference. The cases and the ranks they must appear within are in
// run.php with the reason each one exists; printed here so the ranks are evidence rather than a claim.
$recall = $run('php kernel/Workbench/Retrieval/run.php recall');
echo $recall['output'] . "\n";
$check(
    'every known query retrieves its known answer within its rank',
    $recall['exit'] === 0,
    'exit ' . $recall['exit'] . ': ' . substr($recall['output'], -300)
);

// The other direction, and the reason to believe the check above: the SAME cases pointed at a scope
// that cannot contain the answer must MISS. A recall check that cannot report a miss would pass
// whatever it was handed, which is exactly how the previous state of this file reported success.
$recallControl = $run('php kernel/Workbench/Retrieval/run.php recall --control');
$check(
    'and the same check reports a MISS when the answer cannot be in scope',
    $recallControl['exit'] === 0 && str_contains($recallControl['output'], '[MISS]'),
    'exit ' . $recallControl['exit'] . ': ' . substr($recallControl['output'], -300)
);

echo "\n=== summary ===\n";
printf("  %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
