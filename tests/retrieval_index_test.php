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
$run = static function (string $command) use ($root): array {
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(['bash', '-lc', $command], $descriptors, $pipes, $root);
    if (!is_resource($process)) {
        return ['exit' => 127, 'output' => 'could not start'];
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'output' => trim($stdout . $stderr)];
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

echo "\n=== summary ===\n";
printf("  %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
