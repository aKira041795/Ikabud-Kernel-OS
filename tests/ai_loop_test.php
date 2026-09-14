<?php

declare(strict_types=1);

require_once __DIR__ . '/harness/TestHarness.php';

$h = new TestHarness('ai-loop', TestHarness::MODE_PURE);
$h->fingerprint('tools/ai-loop.php');
$h->fingerprint('tools/ai-project.php');

/**
 * @param list<string> $args
 * @return array{code:int,output:string}
 */
function loopTestRun(array $args): array
{
    $argv = array_merge([PHP_BINARY, dirname(__DIR__) . '/tools/ai-loop.php'], $args);
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

function loopRemove(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        $item = $path . '/' . $entry;
        is_dir($item) ? loopRemove($item) : @unlink($item);
    }
    @rmdir($path);
}

/**
 * @param list<string> $modes
 * @return array{base:string,projects:string,runs:string,args:list<string>}
 */
function loopFixture(array $modes): array
{
    $base = sys_get_temp_dir() . '/ikabud-ai-loop-' . bin2hex(random_bytes(6));
    $projects = $base . '/projects';
    $runs = $base . '/runs';
    $dir = $projects . '/fixture';
    mkdir($dir . '/slices', 0777, true);
    mkdir($runs, 0777, true);
    $rows = '';
    foreach ($modes as $index => $mode) {
        $n = $index + 1;
        $rows .= "| **S{$n}** | Slice {$n} | fixture |\n";
        $executor = $base . "/executor-{$n}.php";
        $body = match ($mode) {
            'happy' => "echo \"php -l tools/ai-project.php\\nNo syntax errors detected in tools/ai-project.php\\nexit=0\\n\";\n",
            'marker' => "echo \"SOL_IMPL status=PASS\\n\";\n",
            'silent' => "// deliberately no output\n",
            'failed' => "echo \"executor failed\\n\"; exit(7);\n",
            'contradicted' => "echo \"php -l tools/ai-project.php\\nexit=1\\n\";\n",
            default => throw new RuntimeException('unknown fixture mode'),
        };
        file_put_contents($executor, "<?php\ndeclare(strict_types=1);\n" . $body);
        $dispatch = json_encode([PHP_BINARY, $executor], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($dir . "/slices/s{$n}.md", "# SLICE — S{$n}\nstatus: READY_FOR_IMPLEMENTATION\nlane: fixture\ndispatch: {$dispatch}\n\n## Objective\nFixture.\n## Architectural constraints\n- pure only\n## Files likely affected\n- `tools/ai-project.php`\n## Acceptance criteria\n- evidence is re-derived\n## Required tests\n- `php -l tools/ai-project.php`\n## Risks\n- deliberate failure\n## Forbidden changes\n- `kernel/`\n");
    }
    file_put_contents($dir . '/project.md', "# PROJECT — loop fixture\nstatus: READY_FOR_IMPLEMENTATION\n\n## Slices\n| # | Slice | Delivers |\n|---|---|---|\n" . $rows);
    return [
        'base' => $base,
        'projects' => $projects,
        'runs' => $runs,
        'args' => ['--project=fixture', '--projects-dir=' . $projects, '--runs-dir=' . $runs],
    ];
}

function loopTreeDigest(string $path): string
{
    $entries = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $entries[] = substr($file->getPathname(), strlen($path)) . ':' . hash_file('sha256', $file->getPathname());
        }
    }
    sort($entries);
    return hash('sha256', implode("\n", $entries));
}

$h->section('Refusals before the happy path');
$fixture = loopFixture(['marker', 'happy']);
$marker = loopTestRun($fixture['args']);
$state = json_decode((string) @file_get_contents($fixture['projects'] . '/fixture/state.json'), true);
$h->test('1. marker-only output blocks and never advances', $marker['code'] === 3 && str_contains($marker['output'], 'marker text is not evidence') && ($state['slices']['S1']['state'] ?? null) === 'blocked' && !isset($state['slices']['S2']), $marker['output']);
loopRemove($fixture['base']);

$fixture = loopFixture(['happy']);
file_put_contents($fixture['runs'] . '/prior.json', json_encode(['id' => 'prior', 'status' => 'failed', 'started_at' => date(DATE_ATOM), 'pid' => 0]));
$commit = loopTestRun($fixture['args']);
$h->test('2. commit-check refusal stops before ledger start or project write', $commit['code'] === 3 && str_contains($commit['output'], 'commit-check refused') && !is_file($fixture['projects'] . '/fixture/state.json') && count(glob($fixture['runs'] . '/*.json') ?: []) === 1, $commit['output']);
loopRemove($fixture['base']);

$fixture = loopFixture(['happy', 'happy']);
$before = loopTreeDigest($fixture['base']);
$dry = loopTestRun(array_merge($fixture['args'], ['--dry-run']));
$after = loopTreeDigest($fixture['base']);
$h->test('3. dry-run writes no project state and records no run', $dry['code'] === 0 && $before === $after && !is_file($fixture['projects'] . '/fixture/state.json') && (glob($fixture['runs'] . '/*') ?: []) === [], $dry['output']);
loopRemove($fixture['base']);

$h->section('Every failure mode stops immediately');
foreach (['silent', 'failed', 'contradicted'] as $offset => $mode) {
    $fixture = loopFixture([$mode, 'happy']);
    $run = loopTestRun($fixture['args']);
    $state = json_decode((string) @file_get_contents($fixture['projects'] . '/fixture/state.json'), true);
    $expected = $mode === 'contradicted' ? 'CONTRADICTED' : $mode;
    $h->test((string) ($offset + 4) . ". {$mode} stops before the second slice", $run['code'] === 3 && str_contains(strtolower($run['output']), strtolower($expected)) && ($state['slices']['S1']['state'] ?? null) === 'blocked' && !isset($state['slices']['S2']), $run['output']);
    loopRemove($fixture['base']);
}

$h->section('Unattended progression and bounds');
$fixture = loopFixture(['happy', 'happy']);
$happy = loopTestRun($fixture['args']);
$state = json_decode((string) @file_get_contents($fixture['projects'] . '/fixture/state.json'), true);
$records = glob($fixture['runs'] . '/*.json') ?: [];
$h->test('7. two slices advance unattended with ledger and RE_DERIVED gates', $happy['code'] === 0 && ($state['slices']['S1']['state'] ?? null) === 'done' && ($state['slices']['S2']['state'] ?? null) === 'done' && count($records) === 2 && substr_count($happy['output'], 'all claims RE_DERIVED') === 2, $happy['output']);
loopRemove($fixture['base']);

$fixture = loopFixture(['happy', 'happy', 'happy']);
$bounded = loopTestRun(array_merge($fixture['args'], ['--max-slices=2']));
$state = json_decode((string) @file_get_contents($fixture['projects'] . '/fixture/state.json'), true);
$h->test('8. max-slices caps advancement exactly', $bounded['code'] === 0 && str_contains($bounded['output'], 'BOUND reached max-slices=2') && ($state['slices']['S1']['state'] ?? null) === 'done' && ($state['slices']['S2']['state'] ?? null) === 'done' && !isset($state['slices']['S3']), $bounded['output']);
loopRemove($fixture['base']);

$h->done();
