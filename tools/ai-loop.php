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
  php tools/ai-loop.php --project=ID [--max-slices=N] [--dry-run] [--json]
       [--projects-dir=DIR] [--runs-dir=DIR]

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
        if (!in_array($key, ['project', 'max-slices', 'projects-dir', 'runs-dir'], true)) {
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
function loopDispatch(array $argv, string $report): int
{
    if (@file_put_contents($report, '') === false) {
        return 127;
    }
    $pipes = [];
    $previousLoop = getenv('AI_LOOP_ACTIVE');
    putenv('AI_LOOP_ACTIVE=1');
    $process = @proc_open($argv, [0 => ['pipe', 'r'], 1 => ['file', $report, 'a'], 2 => ['file', $report, 'a']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
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

/** @return array{lane:string,dispatch:list<string>} */
function sliceDispatch(string $contract): array
{
    $markdown = @file_get_contents($contract);
    if ($markdown === false) {
        throw new InvalidArgumentException("slice contract '{$contract}' cannot be read");
    }
    $lane = 'pi';
    $dispatch = null;
    foreach (array_slice(preg_split('/\r?\n/', $markdown) ?: [], 0, 30) as $line) {
        if (preg_match('/^lane:\s*(.+)$/', trim($line), $m) === 1) {
            $lane = trim($m[1], " \t`");
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
    return ['lane' => $lane, 'dispatch' => $dispatch ?? ['pi', '--print', '--approve', $contract]];
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
    $maxRaw = $parsed['options']['max-slices'] ?? null;
    $max = $maxRaw === null ? PHP_INT_MAX : (preg_match('/^[1-9]\d*$/', $maxRaw) === 1 ? (int) $maxRaw : 0);
    if ($max < 1) {
        throw new InvalidArgumentException("--max-slices must be a positive integer");
    }
    $projectsDir = $parsed['options']['projects-dir'] ?? '.ai/projects';
    $runsDir = $parsed['options']['runs-dir'] ?? '.ai/runs';
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
        $start = loopRun([
            PHP_BINARY, $runTool, 'start', '--contract=' . $contract, '--lane=' . $dispatch['lane'],
            '--name=' . $runId, '--report=' . $report, '--runs-dir=' . $runsDir,
        ]);
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

        $failure = null;
        if ($finish['code'] !== 0 || !is_array($finishPayload)) {
            $failure = 'missing finish report';
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
