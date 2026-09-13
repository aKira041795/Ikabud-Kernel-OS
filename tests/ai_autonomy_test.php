<?php

// NETWORK SAFETY: every child gets a stub-only HARPP PATH, sandbox HARPP_CONFIG, and HARPP_NOTIFY=0.
declare(strict_types=1);

require_once __DIR__ . '/harness/TestHarness.php';

/** Static-analysis view of the runtime-loaded pure test harness. */
interface AiAutonomyTestHarness
{
    public function fingerprint(string $relativePath): void;
    public function section(string $title): void;
    public function test(string $label, bool $condition, string $detail = ''): void;
    public function skip(string $label, string $reason = ''): void;
    public function basePath(): string;
    public function done(): void;
}

// @phpstan-ignore-next-line TestHarness is loaded above.
$h = new TestHarness('ai-autonomy', TestHarness::MODE_PURE);
/** @var AiAutonomyTestHarness $h */
$h->fingerprint('tools/ai-autonomy.php');
$h->fingerprint('kernel/Workbench/Schemas/development-decision-request.v1.schema.json');

/**
 * @param list<string> $arguments
 * @return array{code:int,output:string,command:string}
 */
function autonomyRun(string $tool, array $arguments, string $bin, string $config, string $log, string $mode = 'success'): array
{
    $command = array_merge([PHP_BINARY, $tool], $arguments);
    $rendered = implode(' ', array_map('escapeshellarg', $command));
    $pipes = [];
    $environment = ['PATH' => $bin . ':/usr/bin:/bin', 'HARPP_CONFIG' => $config, 'HARPP_NOTIFY' => '0', 'HARPP_STUB_LOG' => $log, 'HARPP_STUB_MODE' => $mode];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment, ['bypass_shell' => true]);
    if (!is_resource($process)) { return ['code' => 127, 'output' => 'proc_open failed', 'command' => $rendered]; }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return ['code' => proc_close($process), 'output' => trim(($stdout === false ? '' : $stdout) . ($stderr === false ? '' : $stderr)), 'command' => $rendered . ' [HARPP_NOTIFY=0; stub PATH]'];
}

/** @param array{code:int,output:string,command:string} $run */
function runDetail(array $run): string { return "command: {$run['command']}\nexit: {$run['code']}\noutput:\n{$run['output']}"; }

/** @return list<list<string>> */
function capturedCalls(string $log): array
{
    $calls = [];
    foreach (is_file($log) ? (file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
        $row = json_decode($line, true); if (is_array($row)) { $calls[] = array_values(array_map('strval', $row)); }
    }
    return $calls;
}

/** Delete a fixture tree without shelling out. */
function removeFixture(string $path): void
{
    if (!is_dir($path)) { return; }
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') { continue; }
        $child = $path . '/' . $name; is_dir($child) ? removeFixture($child) : unlink($child);
    }
    rmdir($path);
}

if (!function_exists('proc_open')) {
    $h->skip('ai-autonomy CLI cases', 'proc_open is unavailable; subprocess safety cannot be verified');
    $h->done();
}

$fixture = sys_get_temp_dir() . '/ikabud-ai-autonomy-' . bin2hex(random_bytes(6));
$bin = $fixture . '/bin'; $decisions = $fixture . '/decisions'; $config = $fixture . '/config.json'; $log = $fixture . '/harpp.jsonl';
mkdir($bin, 0777, true); file_put_contents($config, "{}\n");
$stub = <<<'PHP'
#!/usr/bin/env php
<?php
$log = getenv('HARPP_STUB_LOG');
file_put_contents($log, json_encode(array_slice($argv, 1), JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
$mode = getenv('HARPP_STUB_MODE') ?: 'success';
$args = array_slice($argv, 1);
if ($mode === 'fail') { fwrite(STDERR, "stub failure\n"); exit(1); }
if ($mode === 'suppressed') { echo '{"ok":true,"suppressed":true}'; exit(0); }
if (array_slice($args, 0, 2) === ['decision', 'list']) {
    echo json_encode([['id' => 77, 'decision_key' => 'remote-decision', 'state' => 'DECIDED', 'decision' => 'a', 'rationale' => 'Director selected A']]);
    exit(0);
}
echo json_encode(['ok' => true, 'id' => 77]);
PHP;
file_put_contents($bin . '/harpp', $stub); chmod($bin . '/harpp', 0755);
$contract = $fixture . '/contract.md'; $missingRisks = $fixture . '/missing-risks.md';
$contractText = <<<'MD'
# Fixture Task
## Objective
Exercise bounded autonomy without widening its fixture scope.
## Architectural constraints
- Keep all fixture edits under tools/.
## Files likely affected
- `tools/` — fixture scope
- `README.fixture.md` — fixture file
## Acceptance criteria
- DDL migration is explicitly approved for this fixture.
- Scope violations always stop.
## Required tests
- php tests/ai_autonomy_test.php
## Risks
- A permissive implementation could hide a scope violation.
## Forbidden changes
- `forbidden/` — never touch this directory
- No dependency changes.
MD;
file_put_contents($contract, $contractText);
file_put_contents($missingRisks, str_replace("## Risks\n- A permissive implementation could hide a scope violation.\n", '', $contractText));
$tool = $h->basePath() . '/tools/ai-autonomy.php'; $common = ["--contract={$contract}", "--decisions-dir={$decisions}"];
$run = static fn (array $args, string $mode = 'success'): array => autonomyRun($tool, $args, $bin, $config, $log, $mode);

$h->section('Envelope and fail-closed contract parsing');
$r = $run(array_merge(['plan'], $common));
$h->test('1. plan prints the autonomy envelope', $r['code'] === 0 && str_contains($r['output'], 'AUTONOMY ENVELOPE') && str_contains($r['output'], 'allowed scope'), runDetail($r));
$r = $run(['plan', "--contract={$missingRisks}", "--decisions-dir={$decisions}"]);
$h->test('2. missing Risks fails closed', $r['code'] === 2 && str_contains($r['output'], 'Risks'), runDetail($r));

$h->section('Deterministic L0-L4 tripwire');
$r = $run(['check', 'edit allowed file', '--path=README.fixture.md', '--level=L1', "--contract={$contract}"]);
$h->test('3. L1 allowed file proceeds', $r['code'] === 0 && str_contains($r['output'], 'VERDICT: PROCEED') && str_contains($r['output'], 'level: L1'), runDetail($r));
$r = $run(['check', 'edit forbidden file', '--path=forbidden/App.php', '--level=L2', "--contract={$contract}"]);
$h->test('4. forbidden directory escalates with prefix evidence', $r['code'] === 3 && str_contains($r['output'], 'VERDICT: ESCALATE') && str_contains($r['output'], 'directory prefix'), runDetail($r));
$r = $run(['check', 'edit unknown module', '--path=modules/not-in-scope.php', "--contract={$contract}"]);
$h->test('5. out-of-scope path escalates', $r['code'] === 3 && str_contains($r['output'], 'outside the approved scope'), runDetail($r));
$ddl = ['check', 'create migration', '--path=tools/migrations/001.sql', "--contract={$contract}"];
$r = $run($ddl); $h->test('6. in-scope DDL escalates without justification', $r['code'] === 3 && str_contains($r['output'], 'schema or DDL change'), runDetail($r));
$r = $run(array_merge($ddl, ['--justify=DDL migration is explicitly approved for this fixture.']));
$h->test('7. contract-grounded DDL resolves to L3 record', $r['code'] === 0 && str_contains($r['output'], 'VERDICT: RECORD') && str_contains($r['output'], 'level: L3'), runDetail($r));
$r = $run(array_merge($ddl, ['--justify=todo']));
$h->test('8. ungrounded DDL escalates', $r['code'] === 3 && str_contains($r['output'], 'not grounded'), runDetail($r));
$r = $run(['check', 'self-classified', '--level=L4', '--path=README.fixture.md', "--contract={$contract}"]);
$h->test('9. explicit L4 escalates', $r['code'] === 3 && str_contains($r['output'], 'VERDICT: ESCALATE'), runDetail($r));
$r = $run(['check', 'default record', '--path=README.fixture.md', "--contract={$contract}"]);
$h->test('10. default L2 records', $r['code'] === 0 && str_contains($r['output'], 'VERDICT: RECORD'), runDetail($r));

$h->section('HARPP-bound decision lifecycle');
$one = ['defer', '--task=fixture', '--question=Choose?', '--why=Boundary', '--option=a|A|Effect|Low|One|reversible', '--recommend=a'];
$single = $run(array_merge($one, $common));
$bad = $run(array_merge(['defer', '--task=fixture', '--question=Choose?', '--why=Boundary', '--option=a|A|Effect|Low|One|reversible', '--option=b|B|Effect|Low|Two|reversible', '--recommend=bogus'], $common));
$h->test('11. malformed options fail closed before HARPP', $single['code'] === 2 && $bad['code'] === 2 && str_contains($single['output'], 'option count') && str_contains($bad['output'], 'bogus'), runDetail($single) . "\n" . runDetail($bad));
$id = 'fixture-decision';
$valid = ['defer', '--task=fixture', '--question=Which bounded route?', '--why=The contract cannot select ownership.', '--option=a|Keep local|Contain implementation|Low|One file|reversible', '--option=b|Widen ownership|Move responsibility|High|Two modules|partially_reversible', '--recommend=a', "--id={$id}", '--done=Analysis complete'];
$r = $run(array_merge($valid, $common)); $jsonPath = "{$decisions}/{$id}.json"; $decision = is_file($jsonPath) ? json_decode((string) file_get_contents($jsonPath), true) : null;
$schema = json_decode((string) file_get_contents($h->basePath() . '/kernel/Workbench/Schemas/development-decision-request.v1.schema.json'), true);
$allRequired = is_array($decision) && is_array($schema) && array_diff($schema['required'], array_keys($decision)) === [];
$calls = capturedCalls($log); $submit = end($calls); $submitText = is_array($submit) ? implode("\n", $submit) : '';
$artifacts = $r['code'] === 0 && is_file("{$decisions}/{$id}.md") && is_file("{$decisions}/{$id}.stage.json") && $allRequired
    && $decision['authority_level'] === 'L4' && $decision['transport']['delivered'] === true && str_contains($r['output'], 'DELIVERY: harpp')
    && array_slice((array) $submit, 0, 2) === ['decision', 'submit'] && str_contains($submitText, '--title=Which bounded route?')
    && str_contains($submitText, '--requested=a') && str_contains($submitText, '--workbench-state=ARCHITECTURE_DECISION_REQUIRED') && str_contains($submitText, "--decision-key={$id}");
$h->test('12. successful deferral writes artifacts and exact HARPP payload', $artifacts, runDetail($r) . "\ncapture:\n{$submitText}");

$failDir = $fixture . '/failed'; $failArgs = array_merge(array_map(static fn (string $v): string => $v === "--id={$id}" ? '--id=failed-decision' : $v, $valid), ["--contract={$contract}", "--decisions-dir={$failDir}"]);
$r = $run($failArgs, 'fail'); $failed = json_decode((string) @file_get_contents("{$failDir}/failed-decision.json"), true);
$h->test('13. failed HARPP exits 4 loudly but retains artifact', $r['code'] === 4 && is_array($failed) && $failed['transport']['delivered'] === false && str_contains($r['output'], 'director NOT notified'), runDetail($r));
$beforeRetry = count(capturedCalls($log));
$retry = $run(['defer', '--retry=failed-decision', "--contract={$contract}", "--decisions-dir={$failDir}"]);
$retried = json_decode((string) file_get_contents("{$failDir}/failed-decision.json"), true); $retryCalls = array_slice(capturedCalls($log), $beforeRetry);
$retryText = isset($retryCalls[0]) ? implode("\n", $retryCalls[0]) : '';
$h->test('13b. retry redelivers the same idempotency key', $retry['code'] === 0 && $retried['transport']['delivered'] === true && count($retryCalls) === 1 && str_contains($retryText, '--decision-key=failed-decision'), runDetail($retry) . "\ncapture:\n{$retryText}");
$suppDir = $fixture . '/suppressed'; $suppArgs = array_merge(array_map(static fn (string $v): string => $v === "--id={$id}" ? '--id=suppressed-decision' : $v, $valid), ["--contract={$contract}", "--decisions-dir={$suppDir}"]);
$r = $run($suppArgs, 'suppressed'); $suppressed = json_decode((string) @file_get_contents("{$suppDir}/suppressed-decision.json"), true);
$h->test('14. HARPP suppression is non-delivery', $r['code'] === 4 && is_array($suppressed) && $suppressed['transport']['suppressed'] === true && $suppressed['transport']['delivered'] === false, runDetail($r));

$r = $run(['status', '--json', "--decisions-dir={$decisions}"]); $status = json_decode($r['output'], true);
$h->test('15. local status reports pending', $r['code'] === 0 && $status[0]['decision_id'] === $id && $status[0]['state'] === 'PENDING', runDetail($r));
$bogus = $run(['resume', $id, '--choose=bogus', "--decisions-dir={$decisions}"]); $resumed = $run(['resume', $id, '--choose=a', '--by=director', "--decisions-dir={$decisions}"]);
$after = $run(['status', '--json', "--decisions-dir={$decisions}"]); $afterStatus = json_decode($after['output'], true);
$h->test('16. local choice validates and resolves once', $bogus['code'] === 2 && $resumed['code'] === 0 && $afterStatus[0]['state'] === 'RESOLVED' && $afterStatus[0]['chosen_option_id'] === 'a', runDetail($bogus) . "\n" . runDetail($resumed));
$again = $run(['resume', $id, '--choose=a', "--decisions-dir={$decisions}"]);
$h->test('17. answered decision cannot be answered twice', $again['code'] === 2 && str_contains($again['output'], 'already answered'), runDetail($again));

$remoteDir = $fixture . '/remote'; $remoteArgs = array_merge(array_map(static fn (string $v): string => $v === "--id={$id}" ? '--id=remote-decision' : $v, $valid), ["--contract={$contract}", "--decisions-dir={$remoteDir}"]);
$created = $run($remoteArgs); $beforeRemote = count(capturedCalls($log)); $remote = $run(['resume', 'remote-decision', '--from-harpp', "--decisions-dir={$remoteDir}"]);
$remoteDecision = json_decode((string) file_get_contents("{$remoteDir}/remote-decision.json"), true); $remoteCalls = array_slice(capturedCalls($log), $beforeRemote);
$verbs = array_map(static fn (array $call): string => implode(' ', array_slice($call, 0, 2)), $remoteCalls);
$h->test('18. DECIDED HARPP answer resolves then ack/apply in order', $created['code'] === 0 && $remote['code'] === 0 && $remoteDecision['resolution']['source'] === 'harpp' && $verbs === ['decision list', 'decision ack', 'decision apply'], runDetail($remote) . "\ncapture: " . encodeForDetail($remoteCalls));
$remoteStatus = $run(['status', '--remote', '--json', "--decisions-dir={$remoteDir}"]); $remoteRows = json_decode($remoteStatus['output'], true);
$h->test('18b. remote status merges HARPP lifecycle by decision key', $remoteStatus['code'] === 0 && $remoteRows[0]['decision_id'] === 'remote-decision' && $remoteRows[0]['harpp_state'] === 'DECIDED', runDetail($remoteStatus));

$manifest =  $fixture . '/workflow.json'; $r = $run(['plan', "--emit-manifest={$manifest}", "--contract={$contract}"]); $manifestData = json_decode((string) file_get_contents($manifest), true);
$stageNames = is_array($manifestData) ? array_column($manifestData['stages'], 'name') : [];
$h->test('19. manifest has exactly four governed stages', $r['code'] === 0 && $stageNames === ['architect', 'implement', 'review', 'release-gate'] && str_contains($r['output'], "harpp workflow start --manifest={$manifest}"), runDetail($r));

$notify = $run(['notify', '--type=PROGRESS', '--body=Implementation started', '--conversation=42']);
$quietNotify = $run(['notify', '--type=FAILED', '--body=Delivery probe'], 'suppressed');
$h->test('20. notify is idempotent and suppression is loud', $notify['code'] === 0 && str_contains($notify['output'], 'DELIVERY: harpp') && $quietNotify['code'] === 4 && str_contains($quietNotify['output'], 'director NOT notified'), runDetail($notify) . "\n" . runDetail($quietNotify));

$allCalls = capturedCalls($log);
$realBinaryExcluded = !is_file('/usr/bin/harpp') && !is_file('/bin/harpp');
$stubOnly = is_executable($bin . '/harpp') && $realBinaryExcluded && !str_contains(getenv('PATH') ?: '', $bin);
fwrite(STDOUT, '  HARPP_STUB_EVIDENCE captured=' . count($allCalls) . " child_PATH=stub:/usr/bin:/bin HARPP_NOTIFY=0 real_binary_excluded=" . ($realBinaryExcluded ? 'yes' : 'no') . "\n");
$h->test('21. every HARPP call was captured by isolated stub', $stubOnly && count($allCalls) >= 10, "child PATH={$bin}:/usr/bin:/bin\nHARPP_NOTIFY=0 on every invocation\nstub={$bin}/harpp\ncaptured=" . count($allCalls) . "\n" . encodeForDetail($allCalls));

removeFixture($fixture);
$h->done();

/** Render test evidence without risking JSON failure.
 * @param mixed $value
 */
function encodeForDetail(mixed $value): string { $json = json_encode($value, JSON_UNESCAPED_SLASHES); return $json === false ? '<json error>' : $json; }
