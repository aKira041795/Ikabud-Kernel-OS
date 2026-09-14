<?php

// NETWORK SAFETY: every child gets a stub-only HARPP PATH, sandbox HARPP_CONFIG, and HARPP_NOTIFY=0.
declare(strict_types=1);

require_once __DIR__ . '/harness/TestHarness.php';

/** Instantiate a runtime-loaded test harness without requiring PHPStan to scan the shared harness. */
function autonomyTestHarness(string $className): mixed
{
    return new $className('ai-autonomy', 'pure');
}

$h = autonomyTestHarness('TestHarness');
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
    $lifecycle = $mode === 'notified' ? 'NOTIFIED' : 'DECIDED';
    echo json_encode(['ok' => true, 'data' => ['decisions' => [['id' => 77, 'decision_key' => 'remote-decision', 'lifecycle_state' => $lifecycle, 'decision' => 'a', 'rationale' => 'Director selected A']]]]);
    exit(0);
}
echo json_encode(['ok' => true, 'data' => ['id' => 77]]);
PHP;
file_put_contents($bin . '/harpp', $stub); chmod($bin . '/harpp', 0755);
$contract = $fixture . '/contract.md'; $missingRisks = $fixture . '/missing-risks.md';
$contractText = <<<'MD'
# Fixture Task
## Objective
Exercise bounded autonomy without widening its fixture scope.
## Architectural constraints
- Keep fixture edits within the declared paths.
## Files likely affected
- `tools/migrations/` — fixture scope
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
$h->test('12a. real submit envelope records data.id', $r['code'] === 0 && str_contains($r['output'], 'DELIVERY: harpp') && $decision['transport']['harpp_decision_id'] === '77', runDetail($r) . "\ntransport:\n" . encodeForDetail($decision['transport'] ?? null));

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
$h->test('18a. real nested decisions envelope resolves lifecycle_state DECIDED', $remote['code'] === 0 && str_contains($remote['output'], 'CHOSEN: a') && $remoteDecision['resolution']['note'] === 'Director selected A' && array_slice($verbs, 1) === ['decision ack', 'decision apply'], runDetail($remote) . "\ncapture: " . encodeForDetail($remoteCalls));

$notifiedDir = $fixture . '/remote-notified';
$notifiedArgs = array_merge(array_map(static fn (string $v): string => $v === "--id={$id}" ? '--id=remote-decision' : $v, $valid), ["--contract={$contract}", "--decisions-dir={$notifiedDir}"]);
$createdNotified = $run($notifiedArgs); $beforeNotified = count(capturedCalls($log));
$notified = $run(['resume', 'remote-decision', '--from-harpp', "--decisions-dir={$notifiedDir}"], 'notified');
$notifiedDecision = json_decode((string) file_get_contents("{$notifiedDir}/remote-decision.json"), true); $notifiedCalls = array_slice(capturedCalls($log), $beforeNotified);
$notifiedVerbs = array_map(static fn (array $call): string => implode(' ', array_slice($call, 0, 2)), $notifiedCalls);
$h->test('18c. lifecycle_state NOTIFIED is refused without ack/apply', $createdNotified['code'] === 0 && $notified['code'] === 2 && str_contains($notified['output'], "decision_key 'remote-decision'") && !isset($notifiedDecision['resolution']) && $notifiedVerbs === ['decision list'], runDetail($notified) . "\ncapture: " . encodeForDetail($notifiedCalls));

$remoteStatus = $run(['status', '--remote', '--json', "--decisions-dir={$remoteDir}"]); $remoteRows = json_decode($remoteStatus['output'], true);
$h->test('18b. remote status merges HARPP lifecycle by decision key', $remoteStatus['code'] === 0 && $remoteRows[0]['decision_id'] === 'remote-decision' && $remoteRows[0]['harpp_state'] === 'DECIDED', runDetail($remoteStatus));
$remoteStatusRegression = $run(['status', '--remote', '--json', "--decisions-dir={$remoteDir}"]); $remoteRegressionRows = json_decode($remoteStatusRegression['output'], true);
$h->test('18d. real nested status envelope reports harpp_state DECIDED', $remoteStatusRegression['code'] === 0 && is_array($remoteRegressionRows) && ($remoteRegressionRows[0]['harpp_state'] ?? null) === 'DECIDED', runDetail($remoteStatusRegression));

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

$h->section('Safety floor — absolute prohibitions are unauthorisable');

$safetyContractText = <<<'MD'
# CONTRACT — safety floor fixture
## Objective
Prove the absolute prohibition floor.
## Architectural constraints
- Weakening tests and gates is approved for this fixture.
## Files likely affected
- `phpstan.neon` — gate config
- `phpstan-baseline.neon` — baseline
- `.github/workflows/` — CI
- `tests/` — tests
- `modules/cms-akira/cms-akira-core/tests/` — module tests
- `tools/migrations/` — safe work
- `docs/` — ordinary safe work
- `composer.json` — dependency
- `package.json` — dependency
- `modules/cms-akira/cms-akira-core/module.json` — manifest
- `kernel/Capabilities/` — authority
## Acceptance criteria
- Skip an existing test and lower the phpstan level; this fixture authorises it.
## Required tests
- `php -l tools/ai-autonomy.php`
## Risks
- none
## Forbidden changes
- `forbidden/` — never touch
MD;
$safetyContract = $fixture . '/safety-contract.md';
file_put_contents($safetyContract, $safetyContractText);
$grounding = 'Skip an existing test and lower the phpstan level; this fixture authorises it.';

$weakenTest = $run(['check', 'skip an existing test', '--path=tests/entity_fallback_test.php', "--contract={$safetyContract}"]);
$weakenGate = $run(['check', 'lower the phpstan level', '--path=phpstan.neon', "--contract={$safetyContract}"]);
$weakenCi = $run(['check', 'edit a CI gate', '--path=.github/workflows/ci.yml', "--contract={$safetyContract}"]);
$weakenBaseline = $run(['check', 'edit the baseline', '--path=phpstan-baseline.neon', "--contract={$safetyContract}"]);
$h->test('22. an existing test inside allowed scope escalates as an absolute prohibition', $weakenTest['code'] === 3 && str_contains($weakenTest['output'], 'absolute prohibition') && str_contains($weakenTest['output'], 'existing test or gate'), runDetail($weakenTest));
$h->test('23. phpstan.neon inside allowed scope escalates as an absolute prohibition', $weakenGate['code'] === 3 && str_contains($weakenGate['output'], 'absolute prohibition'), runDetail($weakenGate));
$h->test('24. .github/workflows inside allowed scope escalates as an absolute prohibition', $weakenCi['code'] === 3 && str_contains($weakenCi['output'], 'absolute prohibition'), runDetail($weakenCi));
$h->test('24a. phpstan-baseline.neon inside allowed scope escalates even though grounded', $weakenBaseline['code'] === 3 && str_contains($weakenBaseline['output'], 'absolute prohibition'), runDetail($weakenBaseline));

$newTest = $run(['check', 'add a new test file', '--path=tests/brand_new_probe_test.php', "--contract={$safetyContract}"]);
$h->test('25. adding a new test file under an allowed tests/ path still proceeds', $newTest['code'] === 0 && str_contains($newTest['output'], 'VERDICT: RECORD') && !str_contains($newTest['output'], 'absolute prohibition'), runDetail($newTest));
$groundedTest = $run(['check', 'skip an existing test', '--path=tests/entity_fallback_test.php', "--justify={$grounding}", "--contract={$safetyContract}"]);
$h->test('26. a grounded justification cannot authorise an absolute prohibition', $groundedTest['code'] === 3 && str_contains($groundedTest['output'], 'absolute prohibition') && !str_contains($groundedTest['output'], 'explicitly grounded'), runDetail($groundedTest));

$h->section('Taxonomy is single-sourced and the projection is complete');
$planRun = $run(['plan', '--json', "--contract={$safetyContract}"]);
$plan = json_decode($planRun['output'], true);
$requiredKeys = ['envelope', 'phases', 'absolute_prohibitions', 'contract_relative_l4', 'chair_decisions', 'deterministic_first', 'model_policy', 'model_tiers', 'decisions_dir', 'pending'];
$missingKeys = is_array($plan) ? array_values(array_diff($requiredKeys, array_keys($plan))) : $requiredKeys;
$keysTyped = is_array($plan) && is_array($plan['envelope']) && is_array($plan['phases']) && count($plan['phases']) > 0
    && is_array($plan['absolute_prohibitions']) && count($plan['absolute_prohibitions']) > 0
    && is_array($plan['contract_relative_l4']) && count($plan['contract_relative_l4']) > 0
    && is_array($plan['chair_decisions']) && count($plan['chair_decisions']) > 0
    && is_array($plan['deterministic_first']) && count($plan['deterministic_first']) > 0
    && is_array($plan['model_policy']) && is_array($plan['model_tiers']) && count($plan['model_tiers']) > 0
    && is_string($plan['decisions_dir']) && is_int($plan['pending']);
$h->test('27. plan --json emits the ten-key projection with expected types', $planRun['code'] === 0 && $missingKeys === [] && $keysTyped, runDetail($planRun) . "\nmissing=" . encodeForDetail($missingKeys));

$taxonomy = is_array($plan) && is_array($plan['l4_taxonomy'] ?? null) ? $plan['l4_taxonomy'] : [];
$matcherProbe = ['ddl' => 'tools/migrations/probe.sql', 'dependency' => 'composer.json', 'module_manifest' => 'modules/cms-akira/cms-akira-core/module.json',
    'authority' => 'kernel/Capabilities/Probe.php', 'existing_test' => 'tests/entity_fallback_test.php', 'gate_config' => 'phpstan.neon', 'gate_baseline' => 'phpstan-baseline.neon',
    'trust_surface' => 'tools/ai-run.php'];
$taxonomyTrustContract = $fixture . '/taxonomy-trust-contract.md';
file_put_contents($taxonomyTrustContract, str_replace('`docs/` — ordinary safe work', '`tools/ai-run.php` — taxonomy trust probe', $safetyContractText));
$taxonomyFailures = []; $decidableEntries = 0;
foreach ($taxonomy as $entry) {
    if (!is_array($entry) || ($entry['decidable'] ?? false) !== true) { continue; }
    $decidableEntries++;
    foreach ((array) ($entry['matchers'] ?? []) as $matcher) {
        if (!isset($matcherProbe[$matcher])) { $taxonomyFailures[] = "no probe for {$matcher}"; continue; }
        $probeContract = $matcher === 'trust_surface' ? $taxonomyTrustContract : $safetyContract;
        $probe = $run(['check', 'taxonomy probe', "--path={$matcherProbe[$matcher]}", "--contract={$probeContract}"]);
        if ($probe['code'] !== 3 || !str_contains($probe['output'], (string) $entry['reason'])) {
            $taxonomyFailures[] = "{$matcher}: exit {$probe['code']} reason=" . (str_contains($probe['output'], (string) $entry['reason']) ? 'present' : 'absent');
        }
    }
}
$projectionReasons = static fn (string $class): array => array_values(array_map(static fn (array $item): string => (string) $item['reason'], array_values(array_filter($taxonomy, static fn (array $item): bool => ($item['class'] ?? '') === $class))));
$reasonList = array_map(static fn (array $item): string => (string) $item['reason'], $taxonomy);
$singleSource = $decidableEntries > 0
    && array_values($plan['absolute_prohibitions']) === $projectionReasons('absolute')
    && array_values($plan['contract_relative_l4']) === $projectionReasons('contract_relative')
    && count($reasonList) === count(array_unique($reasonList));
$h->test('28. every path-decidable taxonomy entry escalates and the printed set is its projection', $taxonomyFailures === [] && $singleSource, "decidable entries={$decidableEntries}\nfailures=" . encodeForDetail($taxonomyFailures) . "\nreasons=" . encodeForDetail($reasonList));

$h->section('Stop invariant is checkable');
$stopZero = $run(['stop-report', '--remaining=0']);
$stopBlocked = $run(['stop-report', '--remaining=3', '--stop-reason=CONTRACT_BLOCKED']);
$stopComplete = $run(['stop-report', '--remaining=3', '--stop-reason=PROJECT_COMPLETE']);
$stopUncertainty = $run(['stop-report', '--remaining=3', '--stop-reason=UNCERTAINTY']);
$stopMalformed = $run(['stop-report', '--remaining=abc']);
$stopJsonRun = $run(['stop-report', '--remaining=3', '--stop-reason=SAFETY_BLOCKED', '--json']);
$stopJson = json_decode($stopJsonRun['output'], true);
$h->test('29. stop-report: remaining=0 exits 0', $stopZero['code'] === 0 && str_contains($stopZero['output'], 'LEGITIMATE_STOP') && str_contains($stopZero['output'], 'not idle'), runDetail($stopZero));
$h->test('30. stop-report: remaining=3 CONTRACT_BLOCKED exits 0', $stopBlocked['code'] === 0 && str_contains($stopBlocked['output'], 'LEGITIMATE_STOP'), runDetail($stopBlocked));
$h->test('31. stop-report: remaining=3 PROJECT_COMPLETE exits 3', $stopComplete['code'] === 3 && str_contains($stopComplete['output'], 'ILLEGITIMATE_STOP') && str_contains($stopComplete['output'], 'unsatisfied obligations: 3'), runDetail($stopComplete));
$h->test('32. stop-report: remaining=3 UNCERTAINTY exits 3', $stopUncertainty['code'] === 3 && str_contains($stopUncertainty['output'], 'ILLEGITIMATE_STOP'), runDetail($stopUncertainty));
$h->test('33. stop-report: malformed --remaining exits 2', $stopMalformed['code'] === 2, runDetail($stopMalformed));
$h->test('34. stop-report --json surfaces the invariant and the count', $stopJsonRun['code'] === 0 && is_array($stopJson) && ($stopJson['remaining_obligations'] ?? null) === 3 && ($stopJson['legitimate'] ?? null) === true && str_contains((string) ($stopJson['invariant'] ?? ''), 'not idle'), runDetail($stopJsonRun));

$h->section('Envelope defects are visible, not silent');
$harnessContractText = <<<'MD'
# CONTRACT — harness block fixture

harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions

## Objective
Show the harness block is detected.
## Architectural constraints
- none
## Files likely affected
- `docs/` — safe work
## Acceptance criteria
- ok
## Required tests
- `php -l tools/ai-autonomy.php`
## Risks
- none
## Forbidden changes
- `forbidden/` — never touch
MD;
$harnessContract = $fixture . '/harness-contract.md';
file_put_contents($harnessContract, $harnessContractText);
$noHarnessRun = $run(['plan', '--json', "--contract={$safetyContract}"]);
$hasHarnessRun = $run(['plan', '--json', "--contract={$harnessContract}"]);
$noHarnessData = json_decode($noHarnessRun['output'], true);
$hasHarnessData = json_decode($hasHarnessRun['output'], true);
$noHarnessWarnings = is_array($noHarnessData) ? implode("\n", (array) $noHarnessData['warnings']) : '';
$hasHarnessWarnings = is_array($hasHarnessData) ? implode("\n", (array) $hasHarnessData['warnings']) : '';
$h->test('35. plan warns when no harness: block references the standing contract', $noHarnessRun['code'] === 0 && str_contains($noHarnessWarnings, 'no harness: block'), runDetail($noHarnessRun));
$h->test('36. plan does not warn when the harness block is present', $hasHarnessRun['code'] === 0 && !str_contains($hasHarnessWarnings, 'no harness: block'), runDetail($hasHarnessRun));

$droppedContractText = <<<'MD'
# CONTRACT — dropped forbidden rule fixture
## Objective
Show a dropped forbidden bullet.
## Architectural constraints
- none
## Files likely affected
- `docs/` — safe work
## Acceptance criteria
- ok
## Required tests
- `php -l tools/ai-autonomy.php`
## Risks
- none
## Forbidden changes
- `forbidden/` — never touch
- `git add` — never stage anything
MD;
$droppedContract = $fixture . '/dropped-contract.md';
file_put_contents($droppedContract, $droppedContractText);
$droppedRun = $run(['plan', '--json', "--contract={$droppedContract}"]);
$droppedData = json_decode($droppedRun['output'], true);
$droppedWarnings = is_array($droppedData) ? implode("\n", (array) $droppedData['warnings']) : '';
$h->test('37. plan names a forbidden bullet it cannot bind to a path', $droppedRun['code'] === 0 && str_contains($droppedWarnings, 'git add') && str_contains($droppedWarnings, 'not enforced as scope'), runDetail($droppedRun));

$intersectContractText = <<<'MD'
# CONTRACT — scope intersection fixture
## Objective
Show an allowed/forbidden overlap.
## Architectural constraints
- none
## Files likely affected
- `.ai/ai-autonomy-harness.contract.md` — allowed contract
## Acceptance criteria
- ok
## Required tests
- `php -l tools/ai-autonomy.php`
## Risks
- none
## Forbidden changes
- `.ai/*.contract.md` — never edit another contract
MD;
$intersectContract = $fixture . '/intersect-contract.md';
file_put_contents($intersectContract, $intersectContractText);
$intersectRun = $run(['plan', '--json', "--contract={$intersectContract}"]);
$intersectData = json_decode($intersectRun['output'], true);
$intersectWarnings = is_array($intersectData) ? implode("\n", (array) $intersectData['warnings']) : '';
$intersectCheck = $run(['check', 'edit the referenced contract', '--path=.ai/ai-autonomy-harness.contract.md', "--contract={$intersectContract}"]);
$h->test('38. plan warns on an allowed/forbidden intersection naming both entries', $intersectRun['code'] === 0 && str_contains($intersectWarnings, 'intersects') && str_contains($intersectWarnings, '.ai/ai-autonomy-harness.contract.md') && str_contains($intersectWarnings, 'forbidden'), runDetail($intersectRun));
$h->test('39. check still applies fail-closed precedence to the forbidden entry', $intersectCheck['code'] === 3 && str_contains($intersectCheck['output'], 'forbidden'), runDetail($intersectCheck));

$h->section('The verifier trust surface is contract-unreachable (CD-21 rule 1)');
$trustPaths = ['tools/ai-run.php', 'tools/ai-autonomy.php', 'tools/ai-project.php', 'tools/ai-loop.php',
    'tools/ai-contract-lint.php', 'kernel/Workbench/Development/DevelopmentTaskContract.php',
    'tools/harpp-bridge/harpp_wake.py'];
$trustContractText = <<<'MD'
# CONTRACT — trust surface refusal fixture
## Objective
Prove the verifier trust surface is not contract-authorisable.
## Architectural constraints
- none
## Files likely affected
- `tools/ai-run.php` — the verifier
## Acceptance criteria
- refused
## Required tests
- `php -l tools/ai-run.php`
## Risks
- none
## Forbidden changes
- `forbidden/` — never touch
MD;
$trustScopeContractText = <<<'MD'
# CONTRACT — trust scope fixture
## Objective
Name the whole trust surface to prove check still refuses it inside allowed scope.
## Architectural constraints
- none
## Files likely affected
- `tools/ai-run.php`
- `tools/ai-autonomy.php`
- `tools/ai-project.php`
- `tools/ai-loop.php`
- `tools/ai-contract-lint.php`
- `kernel/Workbench/Development/DevelopmentTaskContract.php`
- `tools/harpp-bridge/harpp_wake.py`
- `tools/` — a directory capability over the verifier
- `tools/*.php` — an in-scope glob that could match a trust-surface file
- `**/*.php` — a broad in-scope glob that reaches the verifier
## Acceptance criteria
- checked
## Required tests
- `php -l tools/ai-run.php`
## Risks
- none
## Forbidden changes
- `forbidden/` — never touch
MD;
$trustContract = $fixture . '/trust-contract.md';
$trustScopeContract = $fixture . '/trust-scope-contract.md';
file_put_contents($trustContract, $trustContractText);
file_put_contents($trustScopeContract, $trustScopeContractText);
$trustPlan = $run(['plan', '--json', "--contract={$trustContract}", "--decisions-dir={$decisions}"]);
$h->test('40. plan refuses a contract that names a trust-surface path, naming it', $trustPlan['code'] === 2 && str_contains($trustPlan['output'], 'tools/ai-run.php') && str_contains($trustPlan['output'], 'not contract-authorisable'), runDetail($trustPlan));
$trustPlanAll = $run(['plan', '--json', "--contract={$trustScopeContract}", "--decisions-dir={$decisions}"]);
$h->test('40a. plan refuses a contract naming several trust-surface paths', $trustPlanAll['code'] === 2 && str_contains($trustPlanAll['output'], 'tools/ai-loop.php') && str_contains($trustPlanAll['output'], 'harpp_wake.py'), runDetail($trustPlanAll));

$trustEscalations = [];
foreach ($trustPaths as $trustPath) {
    $probe = $run(['check', 'modify the verifier', "--path={$trustPath}", "--contract={$trustScopeContract}"]);
    if ($probe['code'] !== 3 || !str_contains($probe['output'], 'VERDICT: ESCALATE') || !str_contains($probe['output'], 'absolute prohibition')) {
        $trustEscalations[] = "{$trustPath}: exit {$probe['code']}";
    }
}
$h->test('41. every enumerated trust-surface path escalates even when the contract allows it', $trustEscalations === [], 'failures=' . encodeForDetail($trustEscalations));
$trustActionOnly = $run(['check', 'edit tools/ai-run.php to widen the allowlist', "--contract={$trustScopeContract}"]);
$h->test('42. action text naming the verifier escalates without a --path', $trustActionOnly['code'] === 3 && str_contains($trustActionOnly['output'], 'VERDICT: ESCALATE') && str_contains($trustActionOnly['output'], 'trust surface'), runDetail($trustActionOnly));
$trustGlob = $run(['check', 'edit every php tool', '--path=tools/*.php', "--contract={$trustScopeContract}"]);
$h->test('43. a glob that could match a trust-surface file fails closed', $trustGlob['code'] === 3 && str_contains($trustGlob['output'], 'VERDICT: ESCALATE') && str_contains($trustGlob['output'], 'trust surface'), runDetail($trustGlob));
$trustDirectory = $run(['check', 'change the tools directory', '--path=tools/', "--contract={$trustScopeContract}"]);
$trustBroadGlob = $run(['check', 'change broad PHP scope', '--path=**/*.php', "--contract={$trustScopeContract}"]);
$h->test('43a. check escalates directory and broad-glob capabilities that cover the trust surface', $trustDirectory['code'] === 3
    && $trustBroadGlob['code'] === 3 && str_contains($trustDirectory['output'], 'trust surface') && str_contains($trustBroadGlob['output'], 'trust surface'), runDetail($trustDirectory) . "\n---\n" . runDetail($trustBroadGlob));
$ordinaryProbe = $run(['check', 'update the fixture notes', '--path=docs/guardrail-notes.md', "--contract={$safetyContract}"]);
$h->test('44. an ordinary in-scope path still records (non-vacuity guard)', $ordinaryProbe['code'] === 0 && str_contains($ordinaryProbe['output'], 'VERDICT: RECORD') && str_contains($ordinaryProbe['output'], 'level: L2'), runDetail($ordinaryProbe));

$scopeFixture = static function (string $path) use ($fixture, $trustContractText): string {
    $file = $fixture . '/scope-' . substr(hash('sha256', $path), 0, 8) . '.md';
    file_put_contents($file, str_replace('`tools/ai-run.php` — the verifier', '`' . $path . '` — coverage probe', $trustContractText));
    return $file;
};
$broadScopePlan = $run(['plan', '--json', '--contract=' . $scopeFixture('tools/'), "--decisions-dir={$decisions}"]);
$toolGlobPlan = $run(['plan', '--json', '--contract=' . $scopeFixture('tools/ai-*.php'), "--decisions-dir={$decisions}"]);
$globalGlobPlan = $run(['plan', '--json', '--contract=' . $scopeFixture('**/*.php'), "--decisions-dir={$decisions}"]);
$docsPlan = $run(['plan', '--json', '--contract=' . $scopeFixture('docs/'), "--decisions-dir={$decisions}"]);
$h->test('45. directory scope covering the verifier is refused and names a reached path', $broadScopePlan['code'] === 2 && str_contains($broadScopePlan['output'], "entry 'tools'") && str_contains($broadScopePlan['output'], 'tools/ai-run.php'), runDetail($broadScopePlan));
$h->test('46. tool glob covering the verifier is refused', $toolGlobPlan['code'] === 2 && str_contains($toolGlobPlan['output'], "entry 'tools/ai-*.php'") && str_contains($toolGlobPlan['output'], 'tools/ai-run.php'), runDetail($toolGlobPlan));
$h->test('47. broad PHP glob covering the verifier is refused', $globalGlobPlan['code'] === 2 && str_contains($globalGlobPlan['output'], "entry '**/*.php'") && str_contains($globalGlobPlan['output'], 'tools/ai-run.php'), runDetail($globalGlobPlan));
$h->test('48. docs directory remains plannable (positive control)', $docsPlan['code'] === 0 && !str_contains($docsPlan['output'], 'ERROR: contract names the verifier trust surface'), runDetail($docsPlan));
if (getenv('AI_AUTONOMY_SHOW_SCOPE_PROOFS') === '1') {
    foreach (['tools/' => $broadScopePlan, 'tools/ai-*.php' => $toolGlobPlan, '**/*.php' => $globalGlobPlan, 'docs/' => $docsPlan] as $scope => $proof) {
        fwrite(STDOUT, "PLAN_SCOPE_PROOF {$scope} internal_exit={$proof['code']}\n{$proof['output']}\nPLAN_SCOPE_PROOF_END\n");
    }
}

$chairFixture = $fixture . '/chair-decisions.md';
$amendmentsFixture = $fixture . '/trust-surface-amendments.json';
file_put_contents($chairFixture, "# Decisions\n\n## CD-99 — fixture director decision\n\nApproved.\n");
$amendMissing = $run(['trust-surface', 'amend', '--reason=fixture', "--chair-decisions={$chairFixture}", "--decisions-dir={$decisions}", "--amendments-file={$amendmentsFixture}"]);
$amendUnknown = $run(['trust-surface', 'amend', '--reason=fixture', '--director-decision=CD-404', "--chair-decisions={$chairFixture}", "--decisions-dir={$decisions}", "--amendments-file={$amendmentsFixture}"]);
$amendOk = $run(['trust-surface', 'amend', '--reason=fixture route proof', '--director-decision=CD-99', "--chair-decisions={$chairFixture}", "--decisions-dir={$decisions}", "--amendments-file={$amendmentsFixture}"]);
$amendmentDocument = json_decode((string) @file_get_contents($amendmentsFixture), true);
$amendment = is_array($amendmentDocument) && is_array($amendmentDocument['amendments'][0] ?? null) ? $amendmentDocument['amendments'][0] : [];
$h->test('49. trust-surface amend refuses absent and unknown decisions with exit 3', $amendMissing['code'] === 3 && $amendUnknown['code'] === 3 && str_contains($amendMissing['output'], 'No trust-surface file was changed'), runDetail($amendMissing) . "\n---\n" . runDetail($amendUnknown));
$h->test('50. trust-surface amend records reason, real decision, context, timestamp and hash without editing the surface', $amendOk['code'] === 0
    && ($amendment['reason'] ?? null) === 'fixture route proof' && ($amendment['director_decision'] ?? null) === 'CD-99'
    && is_array($amendment['acting_context'] ?? null) && is_string($amendment['recorded_at'] ?? null)
    && preg_match('/^[0-9a-f]{64}$/', (string) ($amendment['trust_surface_hash'] ?? '')) === 1
    && ($amendment['trust_surface_files_changed_by_route'] ?? null) === false
    && str_contains($amendOk['output'], 'NO TRUST-SURFACE FILE WAS CHANGED'), runDetail($amendOk) . "\nrecord=" . encodeForDetail($amendment));

// ── CD-44: the exceptions: block is an authority route, not a smarter matcher ───────────────────
// The four directions pull against each other and all four are asserted here: a matching exception
// authorises (RECORD); no exception leaves the prohibition absolute (ESCALATE/L4/exit 3); an
// exception may not reach the trust surface (refused); and an exception dated after dispatch has no
// effect (pre-declaration). Structural and policy refusals are also pinned.
$h->section('CD-44 — exceptions: is an authority route, not a judgement');
$exceptionBody = <<<'MD'
## Objective
Exercise the exceptions route without widening anything else.
## Architectural constraints
- none
## Files likely affected
- `tests/` — tests
- `phpstan.neon` — gate config
## Acceptance criteria
- authorised corrections record; everything else still refuses
## Required tests
- `php -l tools/ai-autonomy.php`
## Risks
- a route that quietly becomes the default
## Forbidden changes
- `kernel/` — never
MD;
$exceptionContract = static function (string $block) use ($fixture, $exceptionBody): string {
    $file = $fixture . '/exceptions-' . substr(hash('sha256', $block), 0, 10) . '.md';
    file_put_contents($file, "# CONTRACT — exceptions route fixture\n" . $block . "\n" . $exceptionBody);
    return $file;
};
$matchingBlock = <<<'MD'
exceptions:
  - what:     correct the stale runs_by_status assertion
    why:      the fixture asserted a shape the contract no longer produces
    scope:    tests/ai_run_test.php
    decided_when: 2026-09-14T15:00:00+00:00
    authority: CD-44
MD;
$matchingContract = $exceptionContract($matchingBlock);
$plainContract = $exceptionContract('');

// Direction 1 — with a matching, pre-declared exception the existing-test prohibition records.
$withException = $run(['check', 'correct a stale assertion', '--path=tests/ai_run_test.php', "--contract={$matchingContract}", '--json']);
$withExceptionData = json_decode($withException['output'], true);
$withExceptionAuthorised = is_array($withExceptionData) && is_array($withExceptionData['authorised_by_exceptions'] ?? null) ? $withExceptionData['authorised_by_exceptions'] : [];
$h->test(
    '51. a matching pre-declared exception routes an existing-test change to RECORD and names it',
    $withException['code'] === 0 && is_array($withExceptionData)
        && ($withExceptionData['verdict'] ?? null) === 'RECORD' && ($withExceptionData['authority_level'] ?? null) === 'L2'
        && count($withExceptionAuthorised) === 1
        && ($withExceptionAuthorised[0]['exception']['authority'] ?? null) === 'CD-44'
        && str_contains(implode("\n", (array) ($withExceptionData['reasons'] ?? [])), 'authorised by exception'),
    runDetail($withException)
);

// Direction 2 — the same probe without an exception is byte-for-byte the old behaviour.
$withoutException = $run(['check', 'correct a stale assertion', '--path=tests/ai_run_test.php', "--contract={$plainContract}"]);
$h->test(
    '52. without an exception the same probe is ESCALATE / L4 / exit 3 (AC2: with and without)',
    $withoutException['code'] === 3 && str_contains($withoutException['output'], 'VERDICT: ESCALATE')
        && str_contains($withoutException['output'], 'level: L4')
        && str_contains($withoutException['output'], 'absolute prohibition')
        && !str_contains($withoutException['output'], 'authorised by exception'),
    runDetail($withoutException) . "\n--- without ---\n" . runDetail($withException)
);

// Direction 3 — an exception naming the trust surface is refused; the route is not a rule-1 bypass.
$trustExceptionContract = $exceptionContract(<<<'MD'
exceptions:
  - what:     edit the verifier
    why:      probe
    scope:    tools/ai-run.php
    decided_when: 2026-09-14
    authority: CD-44
MD);
$trustExceptionPlan = $run(['plan', '--json', "--contract={$trustExceptionContract}", "--decisions-dir={$decisions}"]);
$trustExceptionCheck = $run(['check', 'anything', '--path=tests/ai_run_test.php', "--contract={$trustExceptionContract}"]);
$h->test(
    '53. an exception naming the trust surface is refused by plan and check (direction 3)',
    $trustExceptionPlan['code'] === 2 && str_contains($trustExceptionPlan['output'], 'tools/ai-run.php') && str_contains($trustExceptionPlan['output'], 'trust surface')
        && !str_contains($trustExceptionPlan['output'], 'AUTONOMY ENVELOPE')
        && $trustExceptionCheck['code'] === 2 && str_contains($trustExceptionCheck['output'], 'trust surface'),
    runDetail($trustExceptionPlan) . "\n---\n" . runDetail($trustExceptionCheck)
);
$trustDirectoryContract = $exceptionContract(<<<'MD'
exceptions:
  - what:     reach the verifier by directory capability
    why:      probe
    scope:    tools/
    decided_when: 2026-09-14
    authority: CD-44
MD);
$trustDirectoryPlan = $run(['plan', '--json', "--contract={$trustDirectoryContract}", "--decisions-dir={$decisions}"]);
$h->test(
    '53a. an exception scope covering the verifier (directory) is refused, naming a reached path',
    $trustDirectoryPlan['code'] === 2 && str_contains($trustDirectoryPlan['output'], 'trust surface') && str_contains($trustDirectoryPlan['output'], 'tools/ai-run.php'),
    runDetail($trustDirectoryPlan)
);

// D2 — an exception inside forbidden_scope is refused, naming the entry.
$forbiddenExceptionContract = $exceptionContract(<<<'MD'
exceptions:
  - what:     edit a forbidden path
    why:      probe
    scope:    kernel/Capabilities/Thing.php
    decided_when: 2026-09-14
    authority: CD-44
MD);
$forbiddenExceptionPlan = $run(['plan', '--json', "--contract={$forbiddenExceptionContract}", "--decisions-dir={$decisions}"]);
$h->test(
    '54. an exception whose scope lies inside forbidden_scope is refused, naming the entry',
    $forbiddenExceptionPlan['code'] === 2 && str_contains($forbiddenExceptionPlan['output'], 'forbidden_scope') && str_contains($forbiddenExceptionPlan['output'], 'kernel'),
    runDetail($forbiddenExceptionPlan)
);

// D2 — traversal and absolute exception scopes are refused before any policy guard sees them.
$traversalExceptionContract = $exceptionContract(<<<'MD'
exceptions:
  - what:     escape the tree
    why:      probe
    scope:    ../etc/passwd
    decided_when: 2026-09-14
    authority: CD-44
MD);
$absoluteExceptionContract = $exceptionContract(<<<'MD'
exceptions:
  - what:     absolute path
    why:      probe
    scope:    /etc/passwd
    decided_when: 2026-09-14
    authority: CD-44
MD);
$traversalExceptionPlan = $run(['plan', '--json', "--contract={$traversalExceptionContract}", "--decisions-dir={$decisions}"]);
$absoluteExceptionPlan = $run(['plan', '--json', "--contract={$absoluteExceptionContract}", "--decisions-dir={$decisions}"]);
$h->test(
    '55. a traversal or absolute exception scope is refused, naming the reason',
    $traversalExceptionPlan['code'] === 2 && str_contains($traversalExceptionPlan['output'], 'traversal')
        && $absoluteExceptionPlan['code'] === 2 && str_contains($absoluteExceptionPlan['output'], 'absolute'),
    runDetail($traversalExceptionPlan) . "\n---\n" . runDetail($absoluteExceptionPlan)
);

// D1 — every required field is validated; a missing one is refused with the field named.
$missingFieldBlocks = [
    'why' => "exceptions:\n  - what: x\n    scope: tests/ai_run_test.php\n    decided_when: 2026-09-14\n    authority: CD-44",
    'scope' => "exceptions:\n  - what: x\n    why: y\n    decided_when: 2026-09-14\n    authority: CD-44",
    'decided_when' => "exceptions:\n  - what: x\n    why: y\n    scope: tests/ai_run_test.php\n    authority: CD-44",
    'authority' => "exceptions:\n  - what: x\n    why: y\n    scope: tests/ai_run_test.php\n    decided_when: 2026-09-14",
];
$missingFieldFailures = [];
foreach ($missingFieldBlocks as $field => $block) {
    $probe = $run(['plan', '--json', '--contract=' . $exceptionContract($block), "--decisions-dir={$decisions}"]);
    if ($probe['code'] !== 2 || !str_contains($probe['output'], $field) || !str_contains($probe['output'], 'missing required field')) {
        $missingFieldFailures[] = "{$field}: exit {$probe['code']}";
    }
}
$h->test(
    '56. an entry missing why/scope/decided_when/authority is refused with the field named',
    $missingFieldFailures === [],
    'failures=' . encodeForDetail($missingFieldFailures)
);

// Direction 4 — an exception dated after dispatch has no effect; before dispatch it authorises.
$afterDispatchContract = $exceptionContract(<<<'MD'
exceptions:
  - what:     correct the assertion after seeing the red result
    why:      retrofit
    scope:    tests/ai_run_test.php
    decided_when: 2030-01-01T00:00:00+00:00
    authority: CD-44
MD);
$afterDispatch = $run(['check', 'correct a stale assertion', '--path=tests/ai_run_test.php', "--contract={$afterDispatchContract}", '--dispatch-at=2026-09-14T15:45:57+00:00']);
$beforeDispatch = $run(['check', 'correct a stale assertion', '--path=tests/ai_run_test.php', "--contract={$afterDispatchContract}", '--dispatch-at=2031-01-01T00:00:00+00:00']);
$h->test(
    '57. an exception dated after dispatch has no effect; dated before dispatch it authorises (direction 4)',
    $afterDispatch['code'] === 3 && str_contains($afterDispatch['output'], 'dated after dispatch') && str_contains($afterDispatch['output'], 'no effect')
        && str_contains($afterDispatch['output'], 'absolute prohibition')
        && $beforeDispatch['code'] === 0 && str_contains($beforeDispatch['output'], 'VERDICT: RECORD'),
    runDetail($afterDispatch) . "\n--- before dispatch ---\n" . runDetail($beforeDispatch)
);

// AC4 — the route is scoped to the existing-test prohibition only.
$gateExceptionContract = $exceptionContract(<<<'MD'
exceptions:
  - what:     lower the phpstan level
    why:      probe
    scope:    phpstan.neon
    decided_when: 2026-09-14
    authority: CD-44
MD);
$gateProbe = $run(['check', 'lower the phpstan level', '--path=phpstan.neon', "--contract={$gateExceptionContract}"]);
$overscopeProbe = $run(['check', 'correct another test', '--path=tests/ai_autonomy_test.php', "--contract={$matchingContract}"]);
$h->test(
    '58. an exception cannot route a gate-config change and does not cover paths it does not name (AC4)',
    $gateProbe['code'] === 3 && str_contains($gateProbe['output'], 'absolute prohibition') && !str_contains($gateProbe['output'], 'authorised by exception')
        && $overscopeProbe['code'] === 3 && str_contains($overscopeProbe['output'], 'absolute prohibition') && !str_contains($overscopeProbe['output'], 'authorised by exception'),
    runDetail($gateProbe) . "\n--- overscope ---\n" . runDetail($overscopeProbe)
);

// Additive format — an empty or absent exceptions block leaves the contract revision unchanged.
require_once __DIR__ . '/../kernel/Workbench/Development/DevelopmentTaskContract.php';
$emptyBlockContract = $exceptionContract("exceptions:\n");
$plainRevision = \Ikabud\Kernel\Workbench\Development\DevelopmentTaskContract::revisionId(\Ikabud\Kernel\Workbench\Development\DevelopmentTaskContract::parseCurrentTaskMarkdown((string) file_get_contents($plainContract)));
$emptyRevision = \Ikabud\Kernel\Workbench\Development\DevelopmentTaskContract::revisionId(\Ikabud\Kernel\Workbench\Development\DevelopmentTaskContract::parseCurrentTaskMarkdown((string) file_get_contents($emptyBlockContract)));
$matchingRevision = \Ikabud\Kernel\Workbench\Development\DevelopmentTaskContract::revisionId(\Ikabud\Kernel\Workbench\Development\DevelopmentTaskContract::parseCurrentTaskMarkdown((string) file_get_contents($matchingContract)));
$h->test(
    '59. additive format: an empty exceptions block leaves the revision unchanged, a declared one moves it',
    $plainRevision === $emptyRevision && $plainRevision !== $matchingRevision,
    "plain={$plainRevision} empty={$emptyRevision} matching={$matchingRevision}"
);

removeFixture($fixture);
$h->done();

/** Render test evidence without risking JSON failure.
 * @param mixed $value
 */
function encodeForDetail(mixed $value): string { $json = json_encode($value, JSON_UNESCAPED_SLASHES); return $json === false ? '<json error>' : $json; }
