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
    $probes = [];
    foreach ($modes as $index => $mode) {
        $n = $index + 1;
        $rows .= "| **S{$n}** | Slice {$n} | fixture |\n";
        $executor = $base . "/executor-{$n}.php";
        $probeRelative = '.ai/loop-scope-probe-' . substr(hash('sha256', $base . '|' . $n), 0, 12) . '.txt';
        $probeAbsolute = dirname(__DIR__) . '/' . $probeRelative;
        if (in_array($mode, ['out-of-scope', 'in-scope', 'pre-existing'], true)) { $probes[] = $probeAbsolute; }
        if ($mode === 'pre-existing') { file_put_contents($probeAbsolute, "present before dispatch\n"); }
        $success = "echo \"php -l tools/ai-project.php\\nNo syntax errors detected in tools/ai-project.php\\nexit=0\\n\";\n";
        $body = match ($mode) {
            'happy', 'pre-existing' => $success,
            'out-of-scope', 'in-scope' => 'file_put_contents(' . var_export($probeAbsolute, true) . ', "written by run\\n");' . "\n" . $success,
            'marker' => "echo \"SOL_IMPL status=PASS\\n\";\n",
            'silent' => "// deliberately no output\n",
            'failed' => "echo \"executor failed\\n\"; exit(7);\n",
            'contradicted' => "echo \"php -l tools/ai-project.php\\nexit=1\\n\";\n",
            default => throw new RuntimeException('unknown fixture mode'),
        };
        $allowed = $mode === 'in-scope' ? $probeRelative : 'docs/';
        file_put_contents($executor, "<?php\ndeclare(strict_types=1);\n" . $body);
        $dispatch = json_encode([PHP_BINARY, $executor], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($dir . "/slices/s{$n}.md", "# SLICE — S{$n}\nstatus: READY_FOR_IMPLEMENTATION\nlane: fixture\ndispatch: {$dispatch}\n\n## Objective\nFixture.\n## Architectural constraints\n- pure only\n## Files likely affected\n- `{$allowed}`\n## Acceptance criteria\n- evidence is re-derived\n## Required tests\n- `php -l tools/ai-project.php`\n## Risks\n- deliberate failure\n## Forbidden changes\n- `kernel/`\n");
    }
    file_put_contents($dir . '/project.md', "# PROJECT — loop fixture\nstatus: READY_FOR_IMPLEMENTATION\n\n## Slices\n| # | Slice | Delivers |\n|---|---|---|\n" . $rows);
    return [
        'base' => $base,
        'projects' => $projects,
        'runs' => $runs,
        'probes' => $probes,
        'args' => ['--project=fixture', '--projects-dir=' . $projects, '--runs-dir=' . $runs],
    ];
}

/**
 * @param array<string,string> $bodies executor body by initial/L1/L2/L3
 * @param array<string,list<string>> $paths repair preflight paths by rung
 * @return array{base:string,projects:string,runs:string,args:list<string>,contract:string,sentinel:string}
 */
function loopRepairFixture(array $bodies, array $paths = [], bool $trustContract = false): array
{
    $base = sys_get_temp_dir() . '/ikabud-ai-repair-' . bin2hex(random_bytes(6));
    $projects = $base . '/projects'; $runs = $base . '/runs'; $dir = $projects . '/repair';
    mkdir($dir . '/slices', 0777, true); mkdir($runs, 0777, true);
    file_put_contents($dir . '/project.md', "# PROJECT — repair fixture\nstatus: READY_FOR_IMPLEMENTATION\n\n## Slices\n| # | Slice | Delivers |\n|---|---|---|\n| **S1** | Repair | fixture |\n");
    $dispatches = [];
    foreach ($bodies as $level => $body) {
        $executor = $base . '/executor-' . strtolower($level) . '.php';
        file_put_contents($executor, "<?php\ndeclare(strict_types=1);\n" . $body . "\n");
        $dispatches[$level] = [PHP_BINARY, $executor];
    }
    $repairs = [];
    foreach (['L1', 'L2', 'L3'] as $level) {
        if (!isset($dispatches[$level])) { continue; }
        $repairs[$level] = [['dispatch' => $dispatches[$level], 'change' => "distinct {$level} method", 'paths' => $paths[$level] ?? ['docs/repair-fixture.md']]];
    }
    $allowed = $trustContract ? '`tools/ai-loop.php` — deliberately declared verifier probe' : '`docs/` — bounded fixture scope';
    $contract = $dir . '/slices/s1.md';
    file_put_contents($contract, "# SLICE — S1\nstatus: READY_FOR_IMPLEMENTATION\nlane: fixture\ndispatch: "
        . json_encode($dispatches['initial'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        . "\nrepairs: " . json_encode($repairs, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        . "\n\n## Objective\nRepair fixture.\n## Architectural constraints\n- fixed envelope\n## Files likely affected\n- {$allowed}\n## Acceptance criteria\n- repair completes\n## Required tests\n- `php -l tools/ai-project.php`\n## Risks\n- replay\n## Forbidden changes\n- `kernel/`\n");
    $args = ['--project=repair', '--projects-dir=' . $projects, '--runs-dir=' . $runs, '--max-repairs=1'];
    if ($trustContract) { $args[] = '--director-decision=CD-28'; }
    return ['base' => $base, 'projects' => $projects, 'runs' => $runs, 'args' => $args, 'contract' => $contract, 'sentinel' => $base . '/sentinel'];
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

$h->section('A-F2 binds actual changed paths to the dispatch-time envelope');
$fixture = loopFixture(['out-of-scope']);
$scopeBlocked = loopTestRun($fixture['args']);
$scopeState = json_decode((string) @file_get_contents($fixture['projects'] . '/fixture/state.json'), true);
$scopeRecords = glob($fixture['runs'] . '/*.json') ?: [];
$scopeRecord = $scopeRecords === [] ? null : json_decode((string) file_get_contents($scopeRecords[0]), true);
$scopeProbe = (string) ($fixture['probes'][0] ?? '');
$scopeRelative = $scopeProbe === '' ? '' : substr($scopeProbe, strlen(dirname(__DIR__)) + 1);
$h->test('9. an out-of-scope write blocks the slice, ledger and event stream', $scopeBlocked['code'] === 3
    && ($scopeState['slices']['S1']['state'] ?? null) === 'blocked'
    && ($scopeRecord['status'] ?? null) === 'blocked'
    && ($scopeRecord['scope_conformance']['ok'] ?? true) === false
    && in_array($scopeRelative, array_column((array) ($scopeRecord['scope_conformance']['offending'] ?? []), 'path'), true)
    && str_contains($scopeBlocked['output'], 'SCOPE BLOCKED') && str_contains($scopeBlocked['output'], $scopeRelative), $scopeBlocked['output'] . "\nrecord=" . json_encode($scopeRecord, JSON_UNESCAPED_SLASHES));
if (getenv('AI_LOOP_SHOW_SCOPE_PROOFS') === '1') { fwrite(STDOUT, "SCOPE_PROOF_OUT_OF_SCOPE internal_exit={$scopeBlocked['code']}\n{$scopeBlocked['output']}SCOPE_PROOF_END\n"); }
@unlink($scopeProbe);
loopRemove($fixture['base']);

$fixture = loopFixture(['in-scope']);
$scopeAllowed = loopTestRun($fixture['args']);
$scopeState = json_decode((string) @file_get_contents($fixture['projects'] . '/fixture/state.json'), true);
$scopeRecords = glob($fixture['runs'] . '/*.json') ?: [];
$scopeRecord = $scopeRecords === [] ? null : json_decode((string) file_get_contents($scopeRecords[0]), true);
$h->test('10. an all-in-scope changed-path delta advances (positive control)', $scopeAllowed['code'] === 0
    && ($scopeState['slices']['S1']['state'] ?? null) === 'done'
    && ($scopeRecord['scope_conformance']['ok'] ?? false) === true
    && count((array) ($scopeRecord['scope_delta_paths'] ?? [])) === 1
    && str_contains($scopeAllowed['output'], 'SCOPE OK delta=1') && str_contains($scopeAllowed['output'], 'ADVANCE S1'), $scopeAllowed['output'] . "\nrecord=" . json_encode($scopeRecord, JSON_UNESCAPED_SLASHES));
if (getenv('AI_LOOP_SHOW_SCOPE_PROOFS') === '1') { fwrite(STDOUT, "SCOPE_PROOF_IN_SCOPE internal_exit={$scopeAllowed['code']}\n{$scopeAllowed['output']}SCOPE_PROOF_END\n"); }
@unlink((string) ($fixture['probes'][0] ?? ''));
loopRemove($fixture['base']);

$fixture = loopFixture(['pre-existing']);
$scopeBaseline = loopTestRun($fixture['args']);
$scopeState = json_decode((string) @file_get_contents($fixture['projects'] . '/fixture/state.json'), true);
$scopeRecords = glob($fixture['runs'] . '/*.json') ?: [];
$scopeRecord = $scopeRecords === [] ? null : json_decode((string) file_get_contents($scopeRecords[0]), true);
$scopeProbe = (string) ($fixture['probes'][0] ?? '');
$scopeRelative = $scopeProbe === '' ? '' : substr($scopeProbe, strlen(dirname(__DIR__)) + 1);
$h->test('11. a pre-existing out-of-scope modification is baseline, not attributed to the run', $scopeBaseline['code'] === 0
    && ($scopeState['slices']['S1']['state'] ?? null) === 'done'
    && in_array($scopeRelative, (array) ($scopeRecord['scope_baseline_paths'] ?? []), true)
    && !in_array($scopeRelative, (array) ($scopeRecord['scope_delta_paths'] ?? []), true)
    && str_contains($scopeBaseline['output'], 'SCOPE OK delta=0') && str_contains($scopeBaseline['output'], 'ADVANCE S1'), $scopeBaseline['output'] . "\nrecord=" . json_encode($scopeRecord, JSON_UNESCAPED_SLASHES));
if (getenv('AI_LOOP_SHOW_SCOPE_PROOFS') === '1') { fwrite(STDOUT, "SCOPE_PROOF_PRE_EXISTING internal_exit={$scopeBaseline['code']}\n{$scopeBaseline['output']}SCOPE_PROOF_END\n"); }
@unlink($scopeProbe);
loopRemove($fixture['base']);

$h->section('S7 bounded repair ladder: promote, link evidence, and stop at L4');
$successBody = 'echo "php -l tools/ai-project.php\\nNo syntax errors detected in tools/ai-project.php\\nexit=0\\n";';
$fixture = loopRepairFixture([
    'initial' => 'echo "REPAIR_FAILURE: implementation compile-error\\nnew evidence initial\\n"; exit(7);',
    'L1' => $successBody,
]);
$l1 = loopTestRun($fixture['args']);
$l1State = json_decode((string) @file_get_contents($fixture['projects'] . '/repair/state.json'), true);
$l1Records = array_map(static fn (string $file): array => (array) json_decode((string) file_get_contents($file), true), glob($fixture['runs'] . '/*.json') ?: []);
// Order by the recorded predecessor link, not by started_at: two runs can share a whole second,
// and a chain of runs must be asserted by the chain, not by a timestamp tie-break.
$l1Initial = null; $l1Successor = null;
foreach ($l1Records as $l1Record) {
    if (($l1Record['predecessor_run_id'] ?? null) === null) { $l1Initial = $l1Record; }
    else { $l1Successor = $l1Record; }
}
$h->test('12. an implementation failure is repaired at L1 and completes as a linked new run', $l1['code'] === 0
    && str_contains($l1['output'], 'PROMOTION initial -> L1 trigger=implementation')
    && str_contains($l1['output'], 'REPAIR COMPLETE L1') && count($l1Records) === 2
    && $l1Initial !== null && $l1Successor !== null
    && ($l1Successor['predecessor_run_id'] ?? null) === ($l1Initial['id'] ?? null)
    && ($l1Successor['repair']['level'] ?? null) === 'L1'
    && ($l1State['slices']['S1']['state'] ?? null) === 'done', $l1['output'] . "\nrecords=" . json_encode($l1Records, JSON_UNESCAPED_SLASHES));
loopRemove($fixture['base']);

$fixture = loopRepairFixture([
    'initial' => 'echo "REPAIR_FAILURE: approach architecture-dead-end\\nnew evidence approach\\n"; exit(7);',
    'L2' => $successBody,
]);
$l2 = loopTestRun($fixture['args']);
$h->test('13. an approach failure visibly promotes directly to L2, never replays L1', $l2['code'] === 0
    && str_contains($l2['output'], 'PROMOTION initial -> L2 trigger=approach')
    && !str_contains($l2['output'], 'rung=L1') && str_contains($l2['output'], 'REPAIR COMPLETE L2'), $l2['output']);
loopRemove($fixture['base']);

$fixture = loopRepairFixture([
    'initial' => 'echo "REPAIR_FAILURE: implementation same-failure\\nbase evidence\\n"; exit(7);',
    'L1' => 'echo "REPAIR_FAILURE: implementation same-failure\\nnew evidence from distinct L1 method\\n"; exit(7);',
    'L2' => $successBody,
]);
$exhausted = loopTestRun($fixture['args']);
$h->test('14. exhausted/same failure promotes L1 -> L2 rather than replaying L1', $exhausted['code'] === 0
    && substr_count($exhausted['output'], 'rung=L1') === 1
    && str_contains($exhausted['output'], 'PROMOTION L1 -> L2 trigger=implementation')
    && str_contains($exhausted['output'], 'REPAIR COMPLETE L2'), $exhausted['output']);
loopRemove($fixture['base']);

$fixture = loopRepairFixture(['initial' => 'echo "REPAIR_FAILURE: contract acceptance-needs-widening\\ncontract evidence\\n"; exit(7);']);
$contractBefore = hash_file('sha256', $fixture['contract']);
$l4 = loopTestRun($fixture['args']);
$contractAfter = hash_file('sha256', $fixture['contract']);
$decisions = json_decode((string) @file_get_contents($fixture['projects'] . '/repair/repair-decisions.json'), true);
$decision = is_array($decisions['decisions'][0] ?? null) ? $decisions['decisions'][0] : [];
$h->test('15. a contract-changing condition files L4 and stops without amending contract/verifier', $l4['code'] === 3
    && str_contains($l4['output'], 'PROMOTION initial -> L4 trigger=contract') && str_contains($l4['output'], 'L4 STOP')
    && $contractBefore === $contractAfter && ($decision['contract_amended'] ?? null) === false
    && ($decision['verifier_amended'] ?? null) === false, $l4['output'] . "\ndecision=" . json_encode($decision, JSON_UNESCAPED_SLASHES));
loopRemove($fixture['base']);

$sentinelBody = 'file_put_contents(' . var_export(sys_get_temp_dir() . '/should-be-replaced', true) . ', "executed");';
$fixture = loopRepairFixture([
    'initial' => 'echo "REPAIR_FAILURE: implementation needs-repair\\ninitial evidence\\n"; exit(7);',
    'L1' => $sentinelBody,
], ['L1' => ['tools/ai-loop.php']], true);
// Point the declared method at a fixture sentinel; preflight must refuse before this process runs.
$repairContract = (string) file_get_contents($fixture['contract']);
$sentinelBodyPath = sys_get_temp_dir() . '/should-be-replaced'; @unlink($sentinelBodyPath);
$trustRefusal = loopTestRun($fixture['args']);
$h->test('16. a rung declaring a verifier path is structurally refused before dispatch', $trustRefusal['code'] === 3
    && str_contains($trustRefusal['output'], 'RUNG REFUSED L1: verifier preflight path=tools/ai-loop.php exit=3')
    && str_contains($trustRefusal['output'], 'absolute prohibition') && !is_file($sentinelBodyPath), $trustRefusal['output']);
loopRemove($fixture['base']); @unlink($sentinelBodyPath);

$fixture = loopRepairFixture(['initial' => '// no output', 'L1' => $successBody]);
$noEvidence = loopTestRun($fixture['args']);
$h->test('17. ladder refuses to advance when the failed attempt supplies no new evidence', $noEvidence['code'] === 3
    && str_contains($noEvidence['output'], 'RUNG REFUSED S1: no new evidence')
    && !str_contains($noEvidence['output'], 'rung=L1'), $noEvidence['output']);
loopRemove($fixture['base']);

$h->done();
