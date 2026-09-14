#!/usr/bin/env php
<?php

declare(strict_types=1);

use Ikabud\Kernel\Workbench\Development\DevelopmentTaskContract;

require_once dirname(__DIR__) . '/kernel/Workbench/Development/DevelopmentTaskContract.php';

/**
 * ai-run.php — the authoritative run ledger and claim extractor.
 *
 * Two questions caused real harm this session and both were answered by inference from unreliable
 * signals instead of a record:
 *
 *   - "did this run succeed?"  was answered from the size of a log file. That is unsound: redirected
 *     stdout is buffered and flushed at exit, so a 0-byte log read *while a run is in progress* means
 *     only "not flushed yet" — never "produced nothing". Reading that size as an outcome produced two
 *     false conclusions about runs that had in fact written full reports.
 *   - "is this run still running?" was answered with `pgrep`. The run executes inside a wrapper, so
 *     the pattern either missed the live run or matched the checking command itself. `ps` is no
 *     better: it truncates long command lines, hiding the run's own `--name`.
 *
 * This tool removes the inference. A run is started (`start`), the dispatcher that observed the exit
 * code finishes it (`finish`), and `status` reads the pid — never the log — to answer liveness.
 *
 * THE LEDGER RECORDS; IT DOES NOT DECIDE ACCEPTANCE.
 * `finish` is only meaningful because the *dispatcher* runs it in the shell that observed the exit
 * code; a self-reported exit code with no dispatcher is theatre. `silent`, `failed` and `abandoned`
 * are recorded as facts. `--gate` is the policy layer that lets a phase refuse to advance.
 *
 * ZERO DEPENDENCIES: no bootstrap, no DB, no network, no HARPP. It requires only the dependency-free
 * contract parser, exactly like tools/ai-autonomy.php.
 *
 * Usage:
 *   php tools/ai-run.php start  --contract=PATH --lane=MODEL --name=ID [--log=PATH] [--report=PATH]
 *                               [--pid=N] [--runs-dir=DIR] [--json]
 *   php tools/ai-run.php finish --id=ID --exit=CODE [--log=PATH] [--report=PATH]
 *                               [--runs-dir=DIR] [--json]
 *   php tools/ai-run.php status [--runs-dir=DIR] [--json] [--gate]
 *   php tools/ai-run.php claims --id=ID [--runs-dir=DIR] [--json]
 *
 * The pid recorded by `start` is the process whose lifetime brackets the run. By default that is the
 * parent of the `start` invocation — the dispatcher shell that will observe the exit code and call
 * `finish`. A dispatcher that knows the run's own pid must pass `--pid=N`; otherwise liveness is a
 * proxy, and `status` says which pid it checked. Liveness is always read from the pid, never the log.
 *
 * Exit codes: 0 ok; 2 malformed input or contract; 3 the `--gate` found a silent/failed/abandoned run.
 */

const EXIT_OK = 0;
const EXIT_USAGE = 2;
const EXIT_GATE = 3;
const DEFAULT_RUNS_DIR = '.ai/runs';
/** @var list<string> */
const RUN_STATUSES = ['running', 'completed', 'silent', 'failed', 'abandoned'];
/** @var list<string> */
const GATE_STATUSES = ['silent', 'failed', 'abandoned'];

/** Print command help and contractual exit codes. */
function usage(): void
{
    fwrite(STDOUT, <<<'TXT'
Usage:
  php tools/ai-run.php start  --contract=PATH --lane=MODEL --name=ID [--log=PATH] [--report=PATH]
                              [--pid=N] [--runs-dir=DIR] [--json]
                              Record the run before it starts. Writes <runs-dir>/<ID>.json with the
                              contract revision, scope counts, lane, pid, log, started_at, status=running.

  php tools/ai-run.php finish --id=ID --exit=CODE [--log=PATH] [--report=PATH]
                              [--runs-dir=DIR] [--json]
                              Record the exit code observed by the dispatcher and classify:
                                exit != 0                         -> failed
                                exit == 0, no report/log content  -> silent
                                exit == 0, with report/log content-> completed
                              This is only meaningful because the DISPATCHER runs `finish` in the shell
                              that observed the exit code; a self-reported code with no dispatcher is
                              theatre.

  php tools/ai-run.php status [--runs-dir=DIR] [--json] [--gate]
                              Authoritative state for every run. A running record whose pid is no
                              longer alive is reconciled to abandoned. Shows age. Answers "is it still
                              running?" from the pid, never from a log's size. The recorded pid is the
                              run's lifetime marker: by default the parent of the `start` invocation
                              (the dispatcher shell); pass --pid=N to `start` when the run's own pid
                              is known.
                              --gate exits 3 when any run is silent, failed or abandoned; 0 when clean.

  php tools/ai-run.php claims --id=ID [--runs-dir=DIR] [--json]
                              Extract test-result claims from the run's report/log text, each with its
                              source line. Every claim is marked unverified: this tool RECORDS claims,
                              it does not re-derive them. Re-derivation is a later slice.
Exit codes: 0 ok; 2 malformed input or contract; 3 the --gate found a silent/failed/abandoned run.
TXT
    . "\n");
}

/** @param list<string> $arguments
 * @return array{options:array<string,list<string>>,flags:array<string,bool>,positionals:list<string>}
 */
function parseArguments(array $arguments): array
{
    $options = [];
    $flags = [];
    $positionals = [];
    foreach ($arguments as $argument) {
        if (in_array($argument, ['--json', '--gate', '--help'], true)) {
            $flags[substr($argument, 2)] = true;
        } elseif (str_starts_with($argument, '--')) {
            $parts = explode('=', substr($argument, 2), 2);
            if (count($parts) !== 2 || $parts[0] === '') {
                throw new InvalidArgumentException("malformed option '{$argument}'");
            }
            $options[$parts[0]][] = $parts[1];
        } else {
            $positionals[] = $argument;
        }
    }
    return compact('options', 'flags', 'positionals');
}

/** @param array{options:array<string,list<string>>,flags:array<string,bool>,positionals:list<string>} $parsed
 * @param list<string> $allowedOptions
 * @param list<string> $allowedFlags
 */
function validateArgumentNames(array $parsed, array $allowedOptions, array $allowedFlags): void
{
    foreach (array_keys($parsed['options']) as $name) {
        if (!in_array($name, $allowedOptions, true)) {
            throw new InvalidArgumentException("unknown option --{$name}");
        }
    }
    foreach (array_keys($parsed['flags']) as $name) {
        if (!in_array($name, $allowedFlags, true)) {
            throw new InvalidArgumentException("unknown flag --{$name}");
        }
    }
    if ($parsed['positionals'] !== []) {
        throw new InvalidArgumentException("offending argument: '{$parsed['positionals'][0]}'");
    }
}

/** @param array<string,list<string>> $options */
function option(array $options, string $name, ?string $default = null): ?string
{
    if (!isset($options[$name])) {
        return $default;
    }
    if (count($options[$name]) !== 1) {
        throw new InvalidArgumentException("offending field --{$name}: supplied more than once");
    }
    return $options[$name][0];
}

function requiredValue(?string $value, string $field): string
{
    if ($value === null || trim($value) === '') {
        throw new InvalidArgumentException("offending field --{$field}: '" . ($value ?? 'missing') . "'");
    }
    return $value;
}

function intValue(?string $value, string $field): int
{
    if ($value === null || preg_match('/^-?\d+$/', $value) !== 1) {
        throw new InvalidArgumentException("offending field --{$field}: '" . ($value ?? 'missing') . "' is not an integer");
    }
    return (int) $value;
}

/** Encode JSON without an unreported failure. */
function encodeJson(mixed $value, bool $pretty = false): string
{
    return json_encode(
        $value,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | ($pretty ? JSON_PRETTY_PRINT : 0)
    );
}

/** @return array<string,mixed> */
function loadContract(string $path): array
{
    $markdown = @file_get_contents($path);
    if ($markdown === false) {
        throw new InvalidArgumentException("contract '{$path}' cannot be read");
    }
    try {
        return DevelopmentTaskContract::parseCurrentTaskMarkdown($markdown);
    } catch (InvalidArgumentException $e) {
        throw new InvalidArgumentException("contract '{$path}' rejected: " . $e->getMessage());
    }
}

function runsDirOf(?string $runsDir): string
{
    return $runsDir === null || trim($runsDir) === '' ? DEFAULT_RUNS_DIR : rtrim($runsDir, '/');
}

function validateRunId(string $id): string
{
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $id) !== 1) {
        throw new InvalidArgumentException("offending field --name: '{$id}' is not a safe run id");
    }
    return $id;
}

function recordPath(string $runsDir, string $id): string
{
    return runsDirOf($runsDir) . '/' . validateRunId($id) . '.json';
}

/** @param array<string,mixed> $value */
function writeRecord(string $path, array $value): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new InvalidArgumentException("runs directory '{$dir}' cannot be created");
    }
    if (@file_put_contents($path, encodeJson($value, true) . "\n") === false) {
        throw new InvalidArgumentException("run record '{$path}' cannot be written");
    }
}

/** @return array<string,mixed> */
function loadRecord(string $runsDir, string $id): array
{
    $path = recordPath($runsDir, $id);
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new InvalidArgumentException("run '{$id}' has no record at {$path}");
    }
    $value = json_decode($raw, true);
    if (!is_array($value) || ($value['id'] ?? null) !== $id) {
        throw new InvalidArgumentException("run record '{$path}' is malformed");
    }
    return $value;
}

/**
 * The pid recorded by `start` when the dispatcher does not supply one. The parent of this process is
 * the shell that will observe the exit code and call `finish`, so its lifetime brackets the run.
 */
function defaultLivenessPid(): int
{
    if (function_exists('posix_getppid')) {
        $parent = posix_getppid();
        if ($parent > 1) {
            return $parent;
        }
    }
    return getmypid() ?: 0;
}

/**
 * Liveness from the pid alone. `/proc` is authoritative on Linux; `posix_kill($pid, 0)` is the
 * portable fallback, treating only ESRCH (3) as death so a permission error is not read as death.
 */
function pidIsAlive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (is_dir('/proc')) {
        return is_dir('/proc/' . $pid);
    }
    if (function_exists('posix_kill')) {
        if (@posix_kill($pid, 0)) {
            return true;
        }
        return posix_get_last_error() !== 3;
    }
    return true;
}

function fileBytes(?string $path): int
{
    if ($path === null || $path === '' || !is_file($path)) {
        return 0;
    }
    $bytes = filesize($path);
    return $bytes === false ? 0 : (int) $bytes;
}

/** Classify a finished run from measured facts only — the exit code and the presence of content. */
function classifyRun(int $exitCode, int $logBytes, int $reportBytes): string
{
    if ($exitCode !== 0) {
        return 'failed';
    }
    return ($logBytes + $reportBytes) > 0 ? 'completed' : 'silent';
}

function humanAge(int $seconds): string
{
    if ($seconds < 0) {
        $seconds = 0;
    }
    if ($seconds < 60) {
        return $seconds . 's';
    }
    if ($seconds < 3600) {
        return intdiv($seconds, 60) . 'm' . ($seconds % 60) . 's';
    }
    if ($seconds < 86400) {
        return intdiv($seconds, 3600) . 'h' . intdiv($seconds % 3600, 60) . 'm';
    }
    return intdiv($seconds, 86400) . 'd' . intdiv($seconds % 86400, 3600) . 'h';
}

/**
 * @param array<string,list<string>> $options
 */
function commandStart(array $options, bool $json): int
{
    $id = validateRunId(requiredValue(option($options, 'name'), 'name'));
    $runsDir = runsDirOf(option($options, 'runs-dir'));
    $path = recordPath($runsDir, $id);
    if (is_file($path)) {
        throw new InvalidArgumentException("run '{$id}' already has a record at {$path}");
    }

    $contractPath = requiredValue(option($options, 'contract'), 'contract');
    $parsed = loadContract($contractPath);
    $log = option($options, 'log');
    $report = option($options, 'report');
    $pidRaw = option($options, 'pid');
    $pid = $pidRaw === null ? defaultLivenessPid() : intValue($pidRaw, 'pid');

    $record = [
        'id' => $id,
        'contract' => $contractPath,
        'contract_revision' => DevelopmentTaskContract::revisionId($parsed),
        'allowed_count' => count((array) ($parsed['allowed_scope'] ?? [])),
        'forbidden_count' => count((array) ($parsed['forbidden_scope'] ?? [])),
        'lane' => requiredValue(option($options, 'lane'), 'lane'),
        'started_at' => date(DATE_ATOM),
        'pid' => $pid,
        'log' => $log,
        'report' => $report,
        'status' => 'running',
    ];
    writeRecord($path, $record);

    if ($json) {
        fwrite(STDOUT, encodeJson($record) . "\n");
        return EXIT_OK;
    }
    fwrite(STDOUT, "RUN {$id} started\n");
    fwrite(STDOUT, "  contract:  {$contractPath} @ {$record['contract_revision']}\n");
    fwrite(STDOUT, "  scope:     {$record['allowed_count']} allowed, {$record['forbidden_count']} forbidden\n");
    fwrite(STDOUT, "  lane:      {$record['lane']}\n");
    fwrite(STDOUT, "  pid:       {$pid}\n");
    fwrite(STDOUT, "  log:       " . ($log ?? '(none)') . "\n");
    fwrite(STDOUT, "  record:    {$path}\n");
    return EXIT_OK;
}

/**
 * @param array<string,list<string>> $options
 */
function commandFinish(array $options, bool $json): int
{
    $id = validateRunId(requiredValue(option($options, 'id'), 'id'));
    $runsDir = runsDirOf(option($options, 'runs-dir'));
    $exitCode = intValue(option($options, 'exit'), 'exit');
    $record = loadRecord($runsDir, $id);

    $log = option($options, 'log', is_string($record['log'] ?? null) ? $record['log'] : null);
    $report = option($options, 'report', is_string($record['report'] ?? null) ? $record['report'] : null);
    $logBytes = fileBytes($log);
    $reportBytes = fileBytes($report);

    $record['finished_at'] = date(DATE_ATOM);
    $record['exit_code'] = $exitCode;
    $record['log_bytes'] = $logBytes;
    $record['report_bytes'] = $reportBytes;
    $record['status'] = classifyRun($exitCode, $logBytes, $reportBytes);
    writeRecord(recordPath($runsDir, $id), $record);

    if ($json) {
        fwrite(STDOUT, encodeJson($record) . "\n");
        return EXIT_OK;
    }
    fwrite(STDOUT, "RUN {$id} finished: {$record['status']}\n");
    fwrite(STDOUT, "  exit:      {$exitCode}\n");
    fwrite(STDOUT, "  log:       {$logBytes} bytes" . ($log !== null ? " ({$log})" : '') . "\n");
    fwrite(STDOUT, "  report:    {$reportBytes} bytes" . ($report !== null ? " ({$report})" : '') . "\n");
    if ($record['status'] === 'silent') {
        fwrite(STDOUT, "  NOTE: exit 0 and no report after the process exited — a silent run. The work may be\n");
        fwrite(STDOUT, "        real; the evidence is not. This is recorded, not inferred: `finish` runs after the\n");
        fwrite(STDOUT, "        process exited, so its log has been flushed. Never classify from a log read mid-run.\n");
    }
    return EXIT_OK;
}

/**
 * Load every run record, reconcile a running record whose pid has died, and return the list plus any
 * unreadable records. Reconciliation is persisted so it is a recorded fact, not re-inferred per call.
 *
 * @return array{runs:list<array<string,mixed>>,errors:list<string>}
 */
function loadAllRuns(string $runsDir): array
{
    $runs = [];
    $errors = [];
    foreach (glob(runsDirOf($runsDir) . '/*.json') ?: [] as $file) {
        $raw = @file_get_contents($file);
        $value = $raw === false ? null : json_decode($raw, true);
        if (!is_array($value) || !is_string($value['id'] ?? null)) {
            $errors[] = $file;
            continue;
        }
        $id = (string) $value['id'];
        if (($value['status'] ?? null) === 'running' && !pidIsAlive((int) ($value['pid'] ?? 0))) {
            $value['status'] = 'abandoned';
            $value['reconciled_at'] = date(DATE_ATOM);
            writeRecord($file, $value);
        }
        $runs[] = $value;
    }
    usort($runs, static function (array $a, array $b): int {
        return strcmp((string) ($a['started_at'] ?? ''), (string) ($b['started_at'] ?? ''));
    });
    return ['runs' => $runs, 'errors' => $errors];
}

/**
 * @param array<string,mixed> $run
 * @return array<string,mixed>
 */
function decorateRun(array $run, int $now): array
{
    $started = is_string($run['started_at'] ?? null) ? strtotime($run['started_at']) : false;
    $age = $started === false ? 0 : max(0, $now - $started);
    $run['age_seconds'] = $age;
    $run['age'] = humanAge($age);
    return $run;
}

/**
 * @param array<string,list<string>> $options
 */
function commandStatus(array $options, bool $json, bool $gate): int
{
    $runsDir = runsDirOf(option($options, 'runs-dir'));
    $loaded = loadAllRuns($runsDir);
    $now = time();
    $runs = [];
    $blocking = [];
    foreach ($loaded['runs'] as $run) {
        $decorated = decorateRun($run, $now);
        $runs[] = $decorated;
        if (in_array((string) ($decorated['status'] ?? ''), GATE_STATUSES, true)) {
            $blocking[] = (string) $decorated['id'];
        }
    }

    if ($json) {
        fwrite(STDOUT, encodeJson([
            'runs_dir' => $runsDir,
            'runs' => $runs,
            'blocking' => $blocking,
            'gate_ok' => $blocking === [],
            'errors' => $loaded['errors'],
        ], true) . "\n");
    } else {
        fwrite(STDOUT, "RUN LEDGER — {$runsDir}\n");
        if ($runs === []) {
            fwrite(STDOUT, "  (no runs recorded)\n");
        }
        foreach ($runs as $run) {
            $exit = array_key_exists('exit_code', $run) ? (string) $run['exit_code'] : '-';
            $log = array_key_exists('log_bytes', $run) ? (int) $run['log_bytes'] : fileBytes(is_string($run['log'] ?? null) ? $run['log'] : null);
            $report = array_key_exists('report_bytes', $run) ? (int) $run['report_bytes'] : fileBytes(is_string($run['report'] ?? null) ? $run['report'] : null);
            fwrite(STDOUT, sprintf(
                "  %-28s %-10s exit=%-4s age=%-8s pid=%-7s log=%dB report=%dB lane=%s\n",
                (string) $run['id'],
                (string) ($run['status'] ?? 'unknown'),
                $exit,
                (string) $run['age'],
                (string) ($run['pid'] ?? '-'),
                $log,
                $report,
                (string) ($run['lane'] ?? '-')
            ));
        }
        foreach ($loaded['errors'] as $error) {
            fwrite(STDERR, "WARN: unreadable run record {$error}\n");
        }
        if ($gate) {
            if ($blocking === []) {
                fwrite(STDOUT, "GATE: OK\n");
            } else {
                fwrite(STDOUT, "GATE: BLOCKED — " . implode(', ', $blocking) . "\n");
            }
        }
    }

    return $gate && $blocking !== [] ? EXIT_GATE : EXIT_OK;
}

/**
 * Extract test-result claims from free text without asserting any of them. Each claim carries the
 * line it came from and is marked `unverified`; the tool never re-runs anything in this slice.
 *
 * @return list<array{kind:string,value:string,line:string,line_number:int,source:string,status:string}>
 */
function extractClaims(string $text, string $source): array
{
    /** @var list<array{kind:string,regex:string,capture:int|string}> $patterns */
    $patterns = [
        ['kind' => 'passed_fraction', 'regex' => '/(\d+)\s*\/\s*(\d+)\s+passed\b/i', 'capture' => 1],
        ['kind' => 'passed_count', 'regex' => '/\bpassed\s*=\s*(\d+)\b/i', 'capture' => 1],
        ['kind' => 'failed_count', 'regex' => '/\bfailed\s*=\s*(\d+)\b/i', 'capture' => 1],
        ['kind' => 'exit_code', 'regex' => '/\bexit\s*[=:]?\s*(\d+)\b/i', 'capture' => 1],
        ['kind' => 'result_token', 'regex' => '/\b(PASS|FAIL|SKIP)\b/', 'capture' => 1],
        ['kind' => 'tests_ok', 'regex' => '/\btests\/\S+\s+ok\b/i', 'capture' => 'full'],
    ];

    $claims = [];
    $seen = [];
    $lines = preg_split('/\r?\n/', $text) ?: [];
    foreach ($lines as $index => $line) {
        foreach ($patterns as $pattern) {
            $matches = [];
            if (preg_match_all($pattern['regex'], $line, $matches, PREG_SET_ORDER) === false) {
                continue;
            }
            foreach ($matches as $match) {
                if ($pattern['kind'] === 'passed_fraction') {
                    $value = $match[1] . '/' . $match[2];
                } elseif ($pattern['capture'] === 'full') {
                    $value = trim((string) $match[0]);
                } else {
                    $value = (string) $match[(int) $pattern['capture']];
                }
                $key = $pattern['kind'] . '|' . $value . '|' . ($index + 1) . '|' . $source;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $claims[] = [
                    'kind' => $pattern['kind'],
                    'value' => $value,
                    'line' => trim($line),
                    'line_number' => $index + 1,
                    'source' => $source,
                    'status' => 'unverified',
                ];
            }
        }
    }
    return $claims;
}

/**
 * @param array<string,list<string>> $options
 */
function commandClaims(array $options, bool $json): int
{
    $id = validateRunId(requiredValue(option($options, 'id'), 'id'));
    $runsDir = runsDirOf(option($options, 'runs-dir'));
    $record = loadRecord($runsDir, $id);

    $claims = [];
    $sources = [];
    foreach (['report', 'log'] as $field) {
        $path = is_string($record[$field] ?? null) ? $record[$field] : null;
        if ($path === null || $path === '' || !is_file($path)) {
            continue;
        }
        $text = @file_get_contents($path);
        if ($text === false) {
            throw new InvalidArgumentException("run '{$id}' {$field} '{$path}' cannot be read");
        }
        $sources[] = $path;
        $claims = array_merge($claims, extractClaims($text, $path));
    }

    $note = 'claims are extracted, not verified; re-derivation is a later slice';
    $payload = [
        'id' => $id,
        'sources' => $sources,
        'verified' => false,
        'note' => $note,
        'claims' => $claims,
    ];

    if ($json) {
        fwrite(STDOUT, encodeJson($payload, true) . "\n");
        return EXIT_OK;
    }
    fwrite(STDOUT, "CLAIMS — {$id} [UNVERIFIED]\n");
    fwrite(STDOUT, "  {$note}\n");
    if ($claims === []) {
        fwrite(STDOUT, "  (no test-result claims found)\n");
        return EXIT_OK;
    }
    foreach ($claims as $claim) {
        fwrite(STDOUT, sprintf(
            "  [unverified] %-16s %-10s %s:%d  %s\n",
            $claim['kind'],
            $claim['value'],
            $claim['source'],
            $claim['line_number'],
            $claim['line']
        ));
    }
    return EXIT_OK;
}

/** Dispatch and return a contractual exit status. */
function main(): int
{
    $args = $_SERVER['argv'];
    array_shift($args);
    if ($args === [] || in_array('--help', $args, true)) {
        usage();
        return EXIT_OK;
    }
    $command = array_shift($args);
    if (!in_array($command, ['start', 'finish', 'status', 'claims'], true)) {
        throw new InvalidArgumentException("unknown command '{$command}'");
    }
    $parsed = parseArguments($args);

    if ($command === 'start') {
        validateArgumentNames($parsed, ['contract', 'lane', 'name', 'log', 'report', 'pid', 'runs-dir'], ['json']);
        return commandStart($parsed['options'], isset($parsed['flags']['json']));
    }
    if ($command === 'finish') {
        validateArgumentNames($parsed, ['id', 'exit', 'log', 'report', 'runs-dir'], ['json']);
        return commandFinish($parsed['options'], isset($parsed['flags']['json']));
    }
    if ($command === 'claims') {
        validateArgumentNames($parsed, ['id', 'runs-dir'], ['json']);
        return commandClaims($parsed['options'], isset($parsed['flags']['json']));
    }
    validateArgumentNames($parsed, ['runs-dir'], ['json', 'gate']);
    return commandStatus($parsed['options'], isset($parsed['flags']['json']), isset($parsed['flags']['gate']));
}

try {
    exit(main());
} catch (InvalidArgumentException|JsonException $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(EXIT_USAGE);
}
