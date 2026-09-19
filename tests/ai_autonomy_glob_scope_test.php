<?php

declare(strict_types=1);

/**
 * Scope-path semantics: a glob in `## Forbidden changes` binds the files it
 * names and not the whole tree. This test proves the driver:
 *   - exposes the entry as `kind: glob` (never as its parent directory);
 *   - does not warn of an intersection with a non-matching allowed sibling;
 *   - enforces the glob against a matching path; and
 *   - does not let `*` cross a directory separator.
 *
 * The 5 cases live here rather than in `tests/ai_autonomy_test.php` because the
 * driver treats any edit to an existing test path as an absolute prohibition,
 * even when the edit only adds cases. A new file is an addition, not a
 * weakening, so the standing harness test stays byte-for-byte as it shipped.
 */

require_once __DIR__ . '/harness/TestHarness.php';

$h = new TestHarness('ai-autonomy-glob-scope', TestHarness::MODE_PURE);
$h->fingerprint('tools/ai-autonomy.php');
$h->fingerprint('kernel/Workbench/Development/DevelopmentTaskContract.php');

if (!function_exists('proc_open')) {
    $h->skip('glob scope cases', 'proc_open is unavailable; the driver cannot be exercised');
    $h->done();
}

/**
 * Run the driver and capture stdout/stderr and the exit code.
 *
 * @param list<string> $arguments
 * @return array{code:int,output:string,command:string}
 */
function globRun(array $arguments): array
{
    $command = array_merge([PHP_BINARY, dirname(__DIR__) . '/tools/ai-autonomy.php'], $arguments);
    $rendered = implode(' ', array_map('escapeshellarg', $command));
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return ['code' => 127, 'output' => 'proc_open failed', 'command' => $rendered];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'output' => trim(($stdout === false ? '' : $stdout) . ($stderr === false ? '' : $stderr)), 'command' => $rendered];
}

/** @param array{code:int,output:string,command:string} $run */
function globDetail(array $run): string
{
    return "command: {$run['command']}\nexit: {$run['code']}\noutput:\n{$run['output']}";
}

$fixtureDir = sys_get_temp_dir() . '/ikabud-glob-scope-' . bin2hex(random_bytes(6));
mkdir($fixtureDir, 0777, true);
$contract = $fixtureDir . '/glob-contract.md';
file_put_contents($contract, <<<'MD'
# CONTRACT — glob precision fixture
## Objective
Show a glob prohibition that does not forbid its parent directory.
## Architectural constraints
- none
## Files likely affected
- `.ai/notes.md` — same directory, non-matching file
## Acceptance criteria
- ok
## Required tests
- `php -l tools/ai-autonomy.php`
## Risks
- none
## Forbidden changes
- `.ai/*.other` — never edit other-pattern contracts
MD);

$planRun = globRun(['plan', '--json', "--contract={$contract}"]);
$plan = json_decode($planRun['output'], true);
$entry = is_array($plan) ? ($plan['envelope']['forbidden_scope'][0] ?? null) : null;
$warnings = is_array($plan) ? implode("\n", (array) ($plan['warnings'] ?? [])) : '';
$allowedProbe = globRun(['check', 'edit a non-matching sibling', '--path=.ai/notes.md', "--contract={$contract}"]);
$globProbe = globRun(['check', 'edit a matching glob path', '--path=.ai/foo.other', "--contract={$contract}"]);
$nestedProbe = globRun(['check', 'edit a nested path the glob must not cross', '--path=.ai/sub/foo.other', "--contract={$contract}"]);

$h->section('A glob stays precise and is enforced fail-closed');
$h->test(
    '1. the forbidden glob is kind `glob`, not its parent directory',
    $planRun['code'] === 0 && is_array($entry) && $entry['path'] === '.ai/*.other' && $entry['kind'] === 'glob',
    globDetail($planRun) . "\nentry=" . json_encode($entry)
);
$h->test(
    '2. the glob does not intersect a non-matching allowed file in the same directory',
    $planRun['code'] === 0 && !str_contains($warnings, 'intersects'),
    globDetail($planRun) . "\nwarnings:\n{$warnings}"
);
$h->test(
    '3. a non-matching allowed sibling still proceeds',
    $allowedProbe['code'] === 0 && !str_contains($allowedProbe['output'], 'ESCALATE'),
    globDetail($allowedProbe)
);
$h->test(
    '4. check enforces the glob against a matching path',
    $globProbe['code'] === 3 && str_contains($globProbe['output'], "matches forbidden entry '.ai/*.other'"),
    globDetail($globProbe)
);
$h->test(
    '5. glob `*` does not cross `/`: a nested path is out of scope, not glob-matched',
    $nestedProbe['code'] === 3
        && str_contains($nestedProbe['output'], 'outside the approved scope')
        && !str_contains($nestedProbe['output'], "matches forbidden entry '.ai/*.other'"),
    globDetail($nestedProbe)
);

@unlink($contract);
@rmdir($fixtureDir);

$h->done();
