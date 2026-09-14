<?php

declare(strict_types=1);

/**
 * Self-test for the conformance lint's phantom rule.
 *
 * The rule must be sound in both directions:
 *   - a legitimate trailing-slash directory prohibition (`kernel/`, `tests/`)
 *     is never a phantom;
 *   - a genuine non-path bullet (`git add`) is still reported.
 *
 * Contract-corpus conformance (fact 4): the driver drops a genuine phantom from
 * its parsed envelope, so only the lint's rule can observe it. This test pins a
 * parseable fixture as the primary path and asserts the fallback is only used
 * for contracts the driver rejects.
 */

require_once __DIR__ . '/harness/TestHarness.php';

$h = new TestHarness('ai-contract-lint', TestHarness::MODE_PURE);
$h->fingerprint('tools/ai-contract-lint.php');

if (!function_exists('proc_open')) {
    $h->skip('ai-contract-lint cases', 'proc_open is unavailable; the lint cannot be exercised');
    $h->done();
}

/**
 * Run the lint and capture stdout/stderr and the exit code.
 *
 * @return array{code:int,output:string,command:string}
 */
function lintRun(array $arguments): array
{
    $command = array_merge([PHP_BINARY, dirname(__DIR__) . '/tools/ai-contract-lint.php'], $arguments);
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
function lintDetail(array $run): string
{
    return "command: {$run['command']}\nexit: {$run['code']}\noutput:\n{$run['output']}";
}

/** Build a parseable contract fixture. @param list<string> $forbidden */
function lintFixtureMarkdown(array $forbidden): string
{
    return "# CONTRACT — lint fixture\n"
        . "## Objective\nLint phantom fixture.\n"
        . "## Architectural constraints\n- none\n"
        . "## Files likely affected\n- `tools/` — safe work\n"
        . "## Acceptance criteria\n- ok\n"
        . "## Required tests\n- `php -l tools/ai-contract-lint.php`\n"
        . "## Risks\n- none\n"
        . "## Forbidden changes\n" . implode("\n", $forbidden) . "\n";
}

$fixtureDir = sys_get_temp_dir() . '/ikabud-contract-lint-' . bin2hex(random_bytes(6));
mkdir($fixtureDir, 0777, true);
$legit = $fixtureDir . '/legit.contract.md';
$dropped = $fixtureDir . '/dropped.contract.md';
file_put_contents($legit, lintFixtureMarkdown([
    '- `kernel/` — no kernel change',
    '- `tests/` — no test change',
    '- `phpstan-baseline.neon` — no baseline change',
]));
file_put_contents($dropped, lintFixtureMarkdown([
    '- `forbidden/` — never touch',
    '- `git add` — never stage anything',
]));

$h->section('Legitimate directory prohibitions are not phantoms (fact 3)');
$legitRun = lintRun(['--contract=' . $legit]);
$h->test(
    '1. kernel/ and tests/ prohibitions report phantoms=0 and exit 0',
    $legitRun['code'] === 0 && str_contains($legitRun['output'], 'phantoms=0'),
    lintDetail($legitRun)
);

$h->section('A genuine non-path bullet is still reported (fact 1 / fact 4)');
$droppedRun = lintRun(['--contract=' . $dropped]);
$h->test(
    '2. the dropped `git add` bullet is reported as a phantom and exits 3',
    $droppedRun['code'] === 3 && str_contains($droppedRun['output'], 'phantoms=1') && str_contains($droppedRun['output'], 'git add'),
    lintDetail($droppedRun)
);

$h->section('Corpus smoke — the fixed rule removes false positives');
$corpusRun = lintRun(['--json']);
$corpus = json_decode($corpusRun['output'], true);
$corpusOk = is_array($corpus) && is_array($corpus['contracts'] ?? null) && ($corpus['summary']['total'] ?? 0) >= 62;
$scopePath = null;
foreach (is_array($corpus['contracts'] ?? null) ? $corpus['contracts'] : [] as $contract) {
    if (($contract['name'] ?? null) === 'scope-path-semantics') {
        $scopePath = $contract;
        break;
    }
}
$h->test(
    '3. corpus lint parses every contract and the fixed rule gives 0 phantoms for directory-only prohibitions',
    $corpusOk && is_array($scopePath) && $scopePath['parse'] === true && $scopePath['phantoms'] === 0,
    'exit=' . $corpusRun['code'] . ' summary=' . json_encode($corpus['summary'] ?? null) . ' scope-path=' . json_encode($scopePath)
);

@unlink($legit);
@unlink($dropped);
@rmdir($fixtureDir);

$h->done();
