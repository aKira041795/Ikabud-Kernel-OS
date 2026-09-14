#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Evidence-gated, bounded project slice dispatcher. */
const LOOP_OK = 0;
const LOOP_USAGE = 2;
const LOOP_STOPPED = 3;

function loopUsage(): void
{
    fwrite(STDOUT, <<<'TXT'
Usage:
  php tools/ai-loop.php --project=ID [--max-slices=N] [--max-repairs=N] [--dry-run] [--json]
       [--projects-dir=DIR] [--runs-dir=DIR] [--director-decision=REF]

Repair methods are declared by a one-line `repairs:` JSON object in the slice header.
Each L1-L3 value is a list of {dispatch, change, paths}. max-repairs bounds distinct
methods at one rung; exhaustion promotes and L4 always stops.

The loop checks commit eligibility before every dispatch, records start/finish,
extracts claims, invokes independent verification, and advances only when every
claim is RE_DERIVED. Marker text is never a gate. Exit: 0 complete/bounded; 2
usage error; 3 fail-closed stop.
TXT
    . "\n");
}

/**
 * @param list<string> $args
 * @return array{options:array<string,string>,flags:array<string,bool>}
 */
function loopArgs(array $args): array
{
    $options = [];
    $flags = [];
    foreach ($args as $arg) {
        if (in_array($arg, ['--dry-run', '--json', '--help'], true)) {
            $flags[substr($arg, 2)] = true;
            continue;
        }
        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            throw new InvalidArgumentException("malformed argument '{$arg}'");
        }
        [$key, $value] = explode('=', substr($arg, 2), 2);
        if ($key === '' || $value === '' || isset($options[$key])) {
            throw new InvalidArgumentException("malformed or repeated option --{$key}");
        }
        $options[$key] = $value;
    }
    foreach (array_keys($options) as $key) {
        if (!in_array($key, ['project', 'max-slices', 'max-repairs', 'projects-dir', 'runs-dir', 'director-decision'], true)) {
            throw new InvalidArgumentException("unknown option --{$key}");
        }
    }
    return ['options' => $options, 'flags' => $flags];
}

/**
 * Execute argv without a shell and capture output.
 * @param list<string> $argv
 * @return array{code:int,output:string}
 */
function loopRun(array $argv): array
{
    $pipes = [];
    $process = @proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return ['code' => 127, 'output' => 'proc_open failed'];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'output' => ($stdout === false ? '' : $stdout) . ($stderr === false ? '' : $stderr)];
}

/**
 * Dispatch to a report file as argv, never through a shell.
 * @param list<string> $argv
 */
function loopDispatch(array $argv, string $report, ?string $repairLevel = null, int $repairAttempt = 0): int
{
    if (@file_put_contents($report, '') === false) {
        return 127;
    }
    $pipes = [];
    $previousLoop = getenv('AI_LOOP_ACTIVE');
    putenv('AI_LOOP_ACTIVE=1');
    if ($repairLevel !== null) {
        putenv('AI_REPAIR_LEVEL=' . $repairLevel);
        putenv('AI_REPAIR_ATTEMPT=' . $repairAttempt);
    }
    $process = @proc_open($argv, [0 => ['pipe', 'r'], 1 => ['file', $report, 'a'], 2 => ['file', $report, 'a']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    putenv('AI_REPAIR_LEVEL');
    putenv('AI_REPAIR_ATTEMPT');
    if ($previousLoop === false) {
        putenv('AI_LOOP_ACTIVE');
    } else {
        putenv('AI_LOOP_ACTIVE=' . $previousLoop);
    }
    if (!is_resource($process)) {
        return 127;
    }
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    return proc_close($process);
}

/** @return array{lane:string,dispatch:list<string>,repairs:array<string,list<array{dispatch:list<string>,change:string,paths:list<string>}>} */
function sliceDispatch(string $contract): array
{
    $markdown = @file_get_contents($contract);
    if ($markdown === false) {
        throw new InvalidArgumentException("slice contract '{$contract}' cannot be read");
    }
    $lane = 'pi';
    $dispatch = null;
    $repairs = [];
    foreach (array_slice(preg_split('/\r?\n/', $markdown) ?: [], 0, 60) as $line) {
        if (preg_match('/^lane:\s*(.+)$/', trim($line), $m) === 1) {
            $lane = trim($m[1], " \t`");
        }
        if (preg_match('/^repairs:\s*({.*})\s*$/', trim($line), $m) === 1) {
            $decoded = json_decode($m[1], true);
            if (!is_array($decoded)) { throw new InvalidArgumentException('slice repairs must be a JSON object'); }
            foreach ($decoded as $level => $methods) {
                if (!in_array($level, ['L1', 'L2', 'L3'], true)) { throw new InvalidArgumentException("unknown repair rung {$level}"); }
                if (is_array($methods) && isset($methods['dispatch'])) { $methods = [$methods]; }
                if (!is_array($methods) || !array_is_list($methods)) { throw new InvalidArgumentException("repair rung {$level} must be a list"); }
                foreach ($methods as $method) {
                    if (!is_array($method) || !is_array($method['dispatch'] ?? null) || !is_string($method['change'] ?? null)
                        || trim($method['change']) === '' || !is_array($method['paths'] ?? null)) {
                        throw new InvalidArgumentException("repair rung {$level} requires dispatch, non-empty change and paths");
                    }
                    $argv = []; $paths = [];
                    foreach ($method['dispatch'] as $part) {
                        if (!is_string($part) || $part === '') { throw new InvalidArgumentException("repair rung {$level} has invalid dispatch argv"); }
                        $argv[] = str_replace('<CONTRACT>', $contract, $part);
                    }
                    foreach ($method['paths'] as $path) {
                        if (!is_string($path) || trim($path) === '') { throw new InvalidArgumentException("repair rung {$level} has invalid path"); }
                        $paths[] = $path;
                    }
                    $repairs[$level][] = ['dispatch' => $argv, 'change' => $method['change'], 'paths' => $paths];
                }
            }
        }
        if (preg_match('/^dispatch:\s*(\[.*\])\s*$/', trim($line), $m) === 1) {
            $decoded = json_decode($m[1], true);
            if (!is_array($decoded) || $decoded === []) {
                throw new InvalidArgumentException("slice dispatch must be a non-empty JSON argv array");
            }
            $dispatch = [];
            foreach ($decoded as $part) {
                if (!is_string($part) || $part === '') {
                    throw new InvalidArgumentException("slice dispatch argv contains a non-string or empty value");
                }
                $dispatch[] = str_replace('<CONTRACT>', $contract, $part);
            }
        }
    }
    return ['lane' => $lane, 'dispatch' => $dispatch ?? ['pi', '--print', '--approve', $contract], 'repairs' => $repairs];
}

/** @param list<string> $events */
function emitLoop(bool $json, array $events, int $code): int
{
    if ($json) {
        fwrite(STDOUT, json_encode(['exit' => $code, 'events' => $events], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    } else {
        fwrite(STDOUT, implode("\n", $events) . ($events === [] ? '' : "\n"));
    }
    return $code;
}

/** Parse the machine-readable reason emitted by a failed method. */
function loopFailureClass(string $report, string $fallback): array
{
    $text = (string) @file_get_contents($report);
    if (preg_match('/^REPAIR_FAILURE:\s*(implementation|approach|decomposition|contract)\b\s*(.*)$/mi', $text, $m) === 1) {
        return ['kind' => strtolower($m[1]), 'detail' => trim($m[1] . ' ' . $m[2])];
    }
    return ['kind' => 'implementation', 'detail' => 'implementation ' . $fallback];
}

/** Record an L4 decision request locally; this route never edits a contract or verifier. */
function loopFileL4(string $projectsDir, string $project, string $slice, string $run, string $reason): string
{
    $path = rtrim($projectsDir, '/') . '/' . $project . '/repair-decisions.json';
    $document = ['schema' => 'ark.ai-repair-decisions.v1', 'decisions' => []];
    if (is_file($path)) {
        $loaded = json_decode((string) @file_get_contents($path), true);
        if (is_array($loaded) && ($loaded['schema'] ?? null) === $document['schema'] && is_array($loaded['decisions'] ?? null)) { $document = $loaded; }
    }
    $id = 'L4-' . $slice . '-' . str_pad((string) (count($document['decisions']) + 1), 3, '0', STR_PAD_LEFT);
    $document['decisions'][] = ['id' => $id, 'slice' => $slice, 'run_id' => $run, 'reason' => $reason,
        'requested_decision' => 'keep the fixed envelope or authorise a separately recorded future contract',
        'contract_amended' => false, 'verifier_amended' => false, 'filed_at' => date(DATE_ATOM)];
    if (@file_put_contents($path, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false) {
        throw new InvalidArgumentException("cannot file L4 decision at {$path}");
    }
    return $id;
}

/**
 * The repo-relative paths the loop writes in a run's name, for declaration at `start`. Read from the
 * code, never guessed: `ai-project.php transition` rewrites `<projects>/<id>/state.json`, and
 * `loopFileL4` writes `<projects>/<id>/repair-decisions.json`. `.ai/projects/<id>/slices/` is
 * authored work and is deliberately NOT declared. A `--projects-dir` outside the repository (used
 * by tests) is omitted: it can never appear in git-status scope anyway.
 *
 * @return list<string>
 */
function loopHarnessArtifacts(string $projectsDir, string $project): array
{
    $declared = [];
    foreach ([rtrim($projectsDir, '/') . '/' . $project . '/state.json',
        rtrim($projectsDir, '/') . '/' . $project . '/repair-decisions.json'] as $candidate) {
        $relative = loopRepositoryRelativePath($candidate);
        if ($relative !== null) { $declared[] = $relative; }
    }
    return array_values(array_unique($declared));
}

/** Normalise a loop-written path to repo-relative, or null when it lies outside the repository. */
function loopRepositoryRelativePath(string $path): ?string
{
    $root = str_replace('\\', '/', dirname(__DIR__));
    $candidate = str_replace('\\', '/', $path);
    if (str_starts_with($candidate, $root . '/')) {
        $candidate = substr($candidate, strlen($root) + 1);
    } elseif (str_starts_with($candidate, '/')) {
        return null;
    }
    $candidate = preg_replace('#^\./+#', '', $candidate) ?? $candidate;
    if ($candidate === '' || preg_match('#(^|/)\.\.(/|$)#', $candidate) === 1) {
        return null;
    }
    return $candidate;
}

/**
 * Execute one attempt and append its complete evidence trail to the event stream.
 * @param list<string> $dispatch
 * @param list<string> $events
 * @return array{run:string,success:bool,failure:?string,failure_class:array{kind:string,detail:string},fingerprint:?string,scope_ok:bool}
 */
function loopAttempt(array $dispatch, string $lane, string $contract, string $project, string $slice, string $projectsDir,
    string $runsDir, ?string $directorDecision, ?string $predecessor, ?string $level, ?string $change,
    ?string $previousFailure, int $repairAttempt, array &$events): array
{
    $runTool = dirname(__DIR__) . '/tools/ai-run.php';
    $projectTool = dirname(__DIR__) . '/tools/ai-project.php';
    $runId = strtolower($project . '-' . $slice . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)));
    $report = rtrim($runsDir, '/') . '/' . $runId . '.report.txt';
    $startArgv = [PHP_BINARY, $runTool, 'start', '--contract=' . $contract, '--lane=' . $lane,
        '--name=' . $runId, '--report=' . $report, '--runs-dir=' . $runsDir];
    foreach (loopHarnessArtifacts($projectsDir, $project) as $artifact) {
        $startArgv[] = '--harness-artifact=' . $artifact;
    }
    if ($directorDecision !== null) { $startArgv[] = '--director-decision=' . $directorDecision; }
    if ($predecessor !== null) {
        $startArgv[] = '--predecessor=' . $predecessor;
        $startArgv[] = '--repair-level=' . $level;
        $startArgv[] = '--approach-change=' . $change;
        $startArgv[] = '--previous-failure=' . ($previousFailure === null ? 'unknown' : $previousFailure);
    }
    $start = loopRun($startArgv);
    if ($start['code'] !== 0) { throw new RuntimeException('ledger start failed: ' . trim($start['output'])); }
    $events[] = 'LEDGER start ' . $runId . ($predecessor === null ? '' : " predecessor={$predecessor} rung={$level} change=" . json_encode($change));
    $transition = loopRun([PHP_BINARY, $projectTool, 'transition', '--project=' . $project, '--slice=' . $slice,
        '--state=running', '--run=' . $runId, '--reason=' . ($predecessor === null ? 'initial dispatch' : "{$level}: {$change}"),
        '--projects-dir=' . $projectsDir, '--runs-dir=' . $runsDir]);
    if ($transition['code'] !== 0) { throw new RuntimeException('project running transition failed: ' . trim($transition['output'])); }

    $exit = loopDispatch($dispatch, $report, $level, $repairAttempt);
    $finish = loopRun([PHP_BINARY, $runTool, 'finish', '--id=' . $runId, '--exit=' . $exit,
        '--report=' . $report, '--runs-dir=' . $runsDir, '--json']);
    $record = json_decode($finish['output'], true);
    $status = is_array($record) ? (string) ($record['status'] ?? 'missing') : 'missing';
    $events[] = "LEDGER finish {$runId} exit={$exit} status={$status}";
    $scope = is_array($record) && is_array($record['scope_conformance'] ?? null) ? $record['scope_conformance'] : null;
    $delta = is_array($record) && is_array($record['scope_delta_paths'] ?? null) ? $record['scope_delta_paths'] : [];
    $offending = [];
    foreach (is_array($scope) && is_array($scope['offending'] ?? null) ? $scope['offending'] : [] as $item) {
        if (is_array($item) && is_string($item['path'] ?? null)) { $offending[] = $item['path']; }
    }
    $scopeOk = is_array($scope) && ($scope['ok'] ?? false) === true;
    $events[] = 'SCOPE ' . ($scopeOk ? 'OK' : 'BLOCKED') . ' delta=' . count($delta)
        . ($offending === [] ? '' : ' offending=' . implode(',', $offending));
    $failure = null;
    if (!is_array($record)) { $failure = 'missing finish report'; }
    elseif (!$scopeOk) { $failure = 'scope conformance failed' . ($offending === [] ? '' : ': ' . implode(', ', $offending)); }
    elseif ($finish['code'] !== 0) { $failure = 'finish gate refused with exit ' . $finish['code']; }
    elseif ($status !== 'completed') { $failure = "run status {$status}"; }
    if ($failure === null) {
        $claims = loopRun([PHP_BINARY, $runTool, 'claims', '--id=' . $runId, '--runs-dir=' . $runsDir, '--json']);
        $claimsPayload = json_decode($claims['output'], true);
        $claimCount = is_array($claimsPayload) && is_array($claimsPayload['claims'] ?? null) ? count($claimsPayload['claims']) : 0;
        $events[] = "CLAIMS {$runId} extracted={$claimCount} (UNVERIFIED)";
        if ($claims['code'] !== 0 || $claimCount === 0) { $failure = 'missing verified report/claims (marker text is not evidence)'; }
    }
    if ($failure === null) {
        $verify = loopRun([PHP_BINARY, $runTool, 'verify', '--run=' . $runId, '--runs-dir=' . $runsDir, '--json']);
        $verifyPayload = json_decode($verify['output'], true);
        $claims = is_array($verifyPayload) && is_array($verifyPayload['claims'] ?? null) ? $verifyPayload['claims'] : [];
        $statuses = array_map(static fn ($claim): string => is_array($claim) ? (string) ($claim['status'] ?? 'MISSING') : 'MALFORMED', $claims);
        $events[] = "VERIFY {$runId} exit={$verify['code']} statuses=" . implode(',', $statuses);
        if ($verify['code'] !== 0 || $statuses === [] || count(array_unique($statuses)) !== 1 || $statuses[0] !== 'RE_DERIVED') {
            $failure = 'claims were not all RE_DERIVED: ' . ($statuses === [] ? 'missing' : implode(',', $statuses));
        }
        $record = json_decode((string) @file_get_contents(rtrim($runsDir, '/') . '/' . $runId . '.json'), true);
    }
    $class = !$scopeOk ? ['kind' => 'contract', 'detail' => 'contract scope conformance failed'] : loopFailureClass($report, (string) $failure);
    return ['run' => $runId, 'success' => $failure === null, 'failure' => $failure, 'failure_class' => $class,
        'fingerprint' => is_array($record) && is_string($record['evidence_fingerprint'] ?? null) ? $record['evidence_fingerprint'] : null,
        'scope_ok' => $scopeOk];
}

/** The opt-in bounded repair ladder. Default max-repairs=0 retains fail-closed single-attempt mode. */
function loopRepairMain(array $parsed, int $maxRepairs): int
{
    $id = $parsed['options']['project'];
    $projectsDir = $parsed['options']['projects-dir'] ?? '.ai/projects';
    $runsDir = $parsed['options']['runs-dir'] ?? '.ai/runs';
    $directorDecision = $parsed['options']['director-decision'] ?? null;
    $json = isset($parsed['flags']['json']);
    $maxSlicesRaw = $parsed['options']['max-slices'] ?? null;
    $maxSlices = $maxSlicesRaw === null ? PHP_INT_MAX : (preg_match('/^[1-9]\d*$/', $maxSlicesRaw) === 1 ? (int) $maxSlicesRaw : 0);
    if ($maxSlices < 1) { throw new InvalidArgumentException('--max-slices must be a positive integer'); }
    $events = []; $advanced = 0;
    while ($advanced < $maxSlices) {
        $next = loopRun([PHP_BINARY, dirname(__DIR__) . '/tools/ai-project.php', 'next', '--project=' . $id,
            '--projects-dir=' . $projectsDir, '--json']);
        $payload = json_decode($next['output'], true);
        if ($next['code'] !== 0 || !is_array($payload) || !is_array($payload['slice'] ?? null)) {
            $reason = is_array($payload) ? (string) ($payload['reason'] ?? 'no eligible slice') : trim($next['output']);
            if (str_contains($reason, 'no remaining slices')) { $events[] = "PROJECT COMPLETE {$id}"; return emitLoop($json, $events, LOOP_OK); }
            $events[] = "STOP before dispatch: {$reason}"; return emitLoop($json, $events, LOOP_STOPPED);
        }
        $slice = $payload['slice']; $sliceId = (string) $slice['id']; $contract = (string) $slice['contract'];
        $policy = sliceDispatch($contract);
        $events[] = "PLAN {$sliceId} lane={$policy['lane']} contract={$contract} max_repairs={$maxRepairs}";
        if (isset($parsed['flags']['dry-run'])) { $events[] = 'DRY-RUN no ledger or project state written'; return emitLoop($json, $events, LOOP_OK); }
        $commit = loopRun([PHP_BINARY, dirname(__DIR__) . '/tools/ai-run.php', 'commit-check', '--runs-dir=' . $runsDir]);
        $events[] = 'COMMIT-CHECK before ' . $sliceId . ': exit=' . $commit['code'];
        if ($commit['code'] !== 0) { $events[] = 'STOP commit-check refused dispatch: ' . trim($commit['output']); return emitLoop($json, $events, LOOP_STOPPED); }

        $attempt = loopAttempt($policy['dispatch'], $policy['lane'], $contract, $id, $sliceId, $projectsDir, $runsDir,
            $directorDecision, null, null, null, null, 0, $events);
        $used = ['L1' => 0, 'L2' => 0, 'L3' => 0]; $signatures = []; $level = null; $priorDescriptor = null;
        while (!$attempt['success']) {
            $failure = (string) $attempt['failure'];
            if ($attempt['fingerprint'] === null) {
                $events[] = "RUNG REFUSED {$sliceId}: no new evidence from {$attempt['run']} ({$failure})";
                break;
            }
            $kind = $attempt['failure_class']['kind']; $descriptor = $attempt['failure_class']['detail'];
            $target = ['implementation' => 1, 'approach' => 2, 'decomposition' => 3, 'contract' => 4][$kind] ?? 4;
            $current = $level === null ? 0 : (int) substr($level, 1);
            if ($target <= $current) {
                if ($descriptor === $priorDescriptor || $used[$level] >= $maxRepairs) { $target = $current + 1; }
                else { $target = $current; }
            }
            while ($target < 4 && ($used['L' . $target] >= $maxRepairs || !isset($policy['repairs']['L' . $target][$used['L' . $target]]))) { $target++; }
            if ($target >= 4) {
                $decision = loopFileL4($projectsDir, $id, $sliceId, $attempt['run'], $descriptor);
                $events[] = "PROMOTION " . ($level ?? 'initial') . " -> L4 trigger={$kind}";
                $events[] = "L4 STOP {$sliceId}: decision={$decision} contract_amended=false verifier_amended=false reason={$descriptor}";
                break;
            }
            $nextLevel = 'L' . $target;
            $events[] = "PROMOTION " . ($level ?? 'initial') . " -> {$nextLevel} trigger={$kind}"
                . ($target > $current + 1 ? ' skipped-unavailable-rungs' : '');
            $method = $policy['repairs'][$nextLevel][$used[$nextLevel]];
            $signature = hash('sha256', json_encode([$method['dispatch'], $method['change'], $method['paths']], JSON_THROW_ON_ERROR));
            if (isset($signatures[$signature])) { $events[] = "RUNG REFUSED {$nextLevel}: method repeats {$signatures[$signature]}"; break; }
            $signatures[$signature] = $nextLevel;
            $autonomy = dirname(__DIR__) . '/tools/ai-autonomy.php'; $preflightOk = true;
            foreach ($method['paths'] as $path) {
                $check = loopRun([PHP_BINARY, $autonomy, 'check', "repair {$nextLevel}: {$method['change']}", '--path=' . $path, '--contract=' . $contract, '--json']);
                if ($check['code'] !== 0) { $events[] = "RUNG REFUSED {$nextLevel}: verifier preflight path={$path} exit={$check['code']} " . trim($check['output']); $preflightOk = false; break; }
            }
            if (!$preflightOk) {
                $decision = loopFileL4($projectsDir, $id, $sliceId, $attempt['run'], 'repair method reached verifier/scope gate');
                $events[] = "L4 STOP {$sliceId}: decision={$decision} contract_amended=false verifier_amended=false";
                break;
            }
            $used[$nextLevel]++; $priorFingerprint = $attempt['fingerprint']; $priorDescriptor = $descriptor; $level = $nextLevel;
            $attempt = loopAttempt($method['dispatch'], $policy['lane'], $contract, $id, $sliceId, $projectsDir, $runsDir,
                $directorDecision, $attempt['run'], $nextLevel, $method['change'], $descriptor, $used[$nextLevel], $events);
            if ($attempt['fingerprint'] === null || hash_equals((string) $priorFingerprint, (string) $attempt['fingerprint'])) {
                $attempt['success'] = false;
                $attempt['failure'] = "repair rung {$nextLevel} supplied no new evidence";
                $events[] = "RUNG REFUSED {$nextLevel}: attempt {$attempt['run']} supplied no new evidence";
                break;
            }
        }
        if (!$attempt['success']) {
            $blocked = loopRun([PHP_BINARY, dirname(__DIR__) . '/tools/ai-project.php', 'transition', '--project=' . $id,
                '--slice=' . $sliceId, '--state=blocked', '--run=' . $attempt['run'], '--reason=' . (string) $attempt['failure'],
                '--projects-dir=' . $projectsDir, '--runs-dir=' . $runsDir]);
            $events[] = "STOP {$sliceId}: {$attempt['failure']}; block-record-exit={$blocked['code']}";
            return emitLoop($json, $events, LOOP_STOPPED);
        }
        $done = loopRun([PHP_BINARY, dirname(__DIR__) . '/tools/ai-project.php', 'transition', '--project=' . $id,
            '--slice=' . $sliceId, '--state=done', '--run=' . $attempt['run'], '--projects-dir=' . $projectsDir, '--runs-dir=' . $runsDir]);
        if ($done['code'] !== 0) { $events[] = 'STOP done transition refused: ' . trim($done['output']); return emitLoop($json, $events, LOOP_STOPPED); }
        $events[] = ($level === null ? 'ADVANCE' : "REPAIR COMPLETE {$level}") . " {$sliceId}: all claims RE_DERIVED";
        $advanced++;
    }
    $events[] = "BOUND reached max-slices={$maxSlices}";
    return emitLoop($json, $events, LOOP_OK);
}

function loopMain(): int
{
    $args = array_slice($_SERVER['argv'], 1);
    if ($args === [] || in_array('--help', $args, true)) {
        loopUsage();
        return LOOP_OK;
    }
    if (getenv('AI_LOOP_ACTIVE') === '1') {
        throw new RuntimeException('nested ai-loop dispatch refused');
    }
    $parsed = loopArgs($args);
    $id = $parsed['options']['project'] ?? null;
    if ($id === null || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $id) !== 1) {
        throw new InvalidArgumentException('missing or invalid --project');
    }
    $maxRepairsRaw = $parsed['options']['max-repairs'] ?? '0';
    if (preg_match('/^\d+$/', $maxRepairsRaw) !== 1) { throw new InvalidArgumentException('--max-repairs must be a non-negative integer'); }
    $maxRepairs = (int) $maxRepairsRaw;
    if ($maxRepairs > 0) { return loopRepairMain($parsed, $maxRepairs); }
    $maxRaw = $parsed['options']['max-slices'] ?? null;
    $max = $maxRaw === null ? PHP_INT_MAX : (preg_match('/^[1-9]\d*$/', $maxRaw) === 1 ? (int) $maxRaw : 0);
    if ($max < 1) {
        throw new InvalidArgumentException("--max-slices must be a positive integer");
    }
    $projectsDir = $parsed['options']['projects-dir'] ?? '.ai/projects';
    $runsDir = $parsed['options']['runs-dir'] ?? '.ai/runs';
    $directorDecision = $parsed['options']['director-decision'] ?? null;
    $dry = isset($parsed['flags']['dry-run']);
    $json = isset($parsed['flags']['json']);
    $projectTool = dirname(__DIR__) . '/tools/ai-project.php';
    $runTool = dirname(__DIR__) . '/tools/ai-run.php';
    $events = [];
    $advanced = 0;

    while ($advanced < $max) {
        $next = loopRun([PHP_BINARY, $projectTool, 'next', '--project=' . $id, '--projects-dir=' . $projectsDir, '--json']);
        $payload = json_decode($next['output'], true);
        if ($next['code'] !== 0 || !is_array($payload) || !is_array($payload['slice'] ?? null)) {
            $reason = is_array($payload) ? (string) ($payload['reason'] ?? 'no eligible slice') : trim($next['output']);
            if (str_contains($reason, 'no remaining slices')) {
                $events[] = "PROJECT COMPLETE {$id}";
                return emitLoop($json, $events, LOOP_OK);
            }
            $events[] = "STOP before dispatch: {$reason}";
            return emitLoop($json, $events, LOOP_STOPPED);
        }
        $slice = $payload['slice'];
        $sliceId = (string) ($slice['id'] ?? '');
        $contract = (string) ($slice['contract'] ?? '');
        $dispatch = sliceDispatch($contract);
        $events[] = "PLAN {$sliceId} lane={$dispatch['lane']} contract={$contract}";
        if ($dry) {
            $advanced++;
            // Dry-run deliberately does not ask for a second slice: without changing state,
            // `next` would return the same one. It describes the next real dispatch only.
            $events[] = 'DRY-RUN no ledger or project state written';
            return emitLoop($json, $events, LOOP_OK);
        }

        $commit = loopRun([PHP_BINARY, $runTool, 'commit-check', '--runs-dir=' . $runsDir]);
        $events[] = 'COMMIT-CHECK before ' . $sliceId . ': exit=' . $commit['code'];
        if ($commit['code'] !== 0) {
            $events[] = 'STOP commit-check refused dispatch: ' . trim($commit['output']);
            return emitLoop($json, $events, LOOP_STOPPED);
        }

        $runId = strtolower($id . '-' . $sliceId . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)));
        $report = rtrim($runsDir, '/') . '/' . $runId . '.report.txt';
        $startArgv = [
            PHP_BINARY, $runTool, 'start', '--contract=' . $contract, '--lane=' . $dispatch['lane'],
            '--name=' . $runId, '--report=' . $report, '--runs-dir=' . $runsDir,
        ];
        foreach (loopHarnessArtifacts($projectsDir, $id) as $artifact) {
            $startArgv[] = '--harness-artifact=' . $artifact;
        }
        if ($directorDecision !== null) { $startArgv[] = '--director-decision=' . $directorDecision; }
        $start = loopRun($startArgv);
        if ($start['code'] !== 0) {
            $events[] = 'STOP ledger start failed: ' . trim($start['output']);
            return emitLoop($json, $events, LOOP_STOPPED);
        }
        $events[] = "LEDGER start {$runId}";
        $running = loopRun([
            PHP_BINARY, $projectTool, 'transition', '--project=' . $id, '--slice=' . $sliceId,
            '--state=running', '--run=' . $runId, '--projects-dir=' . $projectsDir, '--runs-dir=' . $runsDir,
        ]);
        if ($running['code'] !== 0) {
            $events[] = 'STOP project running transition failed: ' . trim($running['output']);
            return emitLoop($json, $events, LOOP_STOPPED);
        }

        $exit = loopDispatch($dispatch['dispatch'], $report);
        $finish = loopRun([PHP_BINARY, $runTool, 'finish', '--id=' . $runId, '--exit=' . $exit, '--report=' . $report, '--runs-dir=' . $runsDir, '--json']);
        $finishPayload = json_decode($finish['output'], true);
        $status = is_array($finishPayload) ? (string) ($finishPayload['status'] ?? 'missing') : 'missing';
        $events[] = "LEDGER finish {$runId} exit={$exit} status={$status}";
        $scope = is_array($finishPayload) && is_array($finishPayload['scope_conformance'] ?? null) ? $finishPayload['scope_conformance'] : null;
        $delta = is_array($finishPayload) && is_array($finishPayload['scope_delta_paths'] ?? null) ? $finishPayload['scope_delta_paths'] : [];
        if (is_array($scope)) {
            $offending = [];
            foreach (is_array($scope['offending'] ?? null) ? $scope['offending'] : [] as $item) {
                if (is_array($item) && is_string($item['path'] ?? null)) { $offending[] = $item['path']; }
            }
            $events[] = 'SCOPE ' . (($scope['ok'] ?? false) === true ? 'OK' : 'BLOCKED') . ' delta=' . count($delta)
                . ($offending === [] ? '' : ' offending=' . implode(',', $offending));
        }

        $failure = null;
        if (!is_array($finishPayload)) {
            $failure = 'missing finish report';
        } elseif (!is_array($scope) || ($scope['ok'] ?? false) !== true) {
            $paths = [];
            foreach (is_array($scope) && is_array($scope['offending'] ?? null) ? $scope['offending'] : [] as $item) {
                if (is_array($item) && is_string($item['path'] ?? null)) { $paths[] = $item['path']; }
            }
            $failure = 'scope conformance failed' . ($paths === [] ? '' : ': ' . implode(', ', $paths));
        } elseif ($finish['code'] !== 0) {
            $failure = 'finish gate refused with exit ' . $finish['code'];
        } elseif ($status !== 'completed') {
            $failure = "run status {$status}";
        }
        $claimsPayload = null;
        $verifyPayload = null;
        if ($failure === null) {
            $claims = loopRun([PHP_BINARY, $runTool, 'claims', '--id=' . $runId, '--runs-dir=' . $runsDir, '--json']);
            $claimsPayload = json_decode($claims['output'], true);
            $claimCount = is_array($claimsPayload) && is_array($claimsPayload['claims'] ?? null) ? count($claimsPayload['claims']) : 0;
            $events[] = "CLAIMS {$runId} extracted={$claimCount} (UNVERIFIED)";
            if ($claims['code'] !== 0 || $claimCount === 0) {
                $failure = 'missing verified report/claims (marker text is not evidence)';
            }
        }
        if ($failure === null) {
            $verify = loopRun([PHP_BINARY, $runTool, 'verify', '--run=' . $runId, '--runs-dir=' . $runsDir, '--json']);
            $verifyPayload = json_decode($verify['output'], true);
            $claims = is_array($verifyPayload) && is_array($verifyPayload['claims'] ?? null) ? $verifyPayload['claims'] : [];
            $statuses = [];
            foreach ($claims as $claim) {
                $statuses[] = is_array($claim) ? (string) ($claim['status'] ?? 'MISSING') : 'MALFORMED';
            }
            $events[] = "VERIFY {$runId} exit={$verify['code']} statuses=" . implode(',', $statuses);
            if ($verify['code'] !== 0 || $statuses === [] || count(array_unique($statuses)) !== 1 || $statuses[0] !== 'RE_DERIVED') {
                $failure = 'claims were not all RE_DERIVED: ' . ($statuses === [] ? 'missing' : implode(',', $statuses));
            }
        }
        if ($failure !== null) {
            $blocked = loopRun([
                PHP_BINARY, $projectTool, 'transition', '--project=' . $id, '--slice=' . $sliceId,
                '--state=blocked', '--run=' . $runId, '--reason=' . $failure,
                '--projects-dir=' . $projectsDir, '--runs-dir=' . $runsDir,
            ]);
            $events[] = "STOP {$sliceId}: {$failure}; block-record-exit={$blocked['code']}";
            return emitLoop($json, $events, LOOP_STOPPED);
        }

        $done = loopRun([
            PHP_BINARY, $projectTool, 'transition', '--project=' . $id, '--slice=' . $sliceId,
            '--state=done', '--run=' . $runId, '--projects-dir=' . $projectsDir, '--runs-dir=' . $runsDir,
        ]);
        if ($done['code'] !== 0) {
            $events[] = 'STOP done transition refused: ' . trim($done['output']);
            return emitLoop($json, $events, LOOP_STOPPED);
        }
        $events[] = "ADVANCE {$sliceId}: all claims RE_DERIVED";
        $advanced++;
    }

    $events[] = "BOUND reached max-slices={$max}";
    return emitLoop($json, $events, LOOP_OK);
}

try {
    exit(loopMain());
} catch (RuntimeException $e) {
    fwrite(STDERR, 'STOP: ' . $e->getMessage() . "\n");
    exit(LOOP_STOPPED);
} catch (InvalidArgumentException|JsonException $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(LOOP_USAGE);
}
