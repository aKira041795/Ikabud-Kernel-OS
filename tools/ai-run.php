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

/**
 * Claim types recognised from a report, each with an honest re-derivability by a pure deterministic
 * tool. `false` means the tool must not — and does not — present the claim as verified.
 * @var array<string,bool>
 */
const CLAIM_TYPES = [
    'TEST_RESULT' => true,
    'LINT_RESULT' => true,
    'CONTRACT_CONFORMANCE' => true,
    'ARTIFACT_HASH' => true,
    'FILE_SCOPE' => true,
    'BROWSER_JOURNEY' => false,
    'PERFORMANCE_MEASUREMENT' => false,
    'MIGRATION_STATE' => false,
];

/**
 * The allowlist, as data rather than scattered conditionals. Each rule is an anchored pattern; the
 * `pure_test` screen additionally rejects `php tests/<file>.php` when the file may bootstrap the app.
 * The security boundary is the whole table, so it can be read in one place and refused consistently.
 * @var list<array{pattern:string,screen?:string}>
 */
const COMMAND_ALLOWLIST = [
    ['pattern' => '/^php\s+tests\/([A-Za-z0-9_][A-Za-z0-9_.-]*)\.php$/', 'screen' => 'pure_test'],
    ['pattern' => '/^php\s+-l\s+[A-Za-z0-9_][A-Za-z0-9_.\/-]*\.php$/'],
    ['pattern' => '/^php\s+tools\/ai-contract-lint\.php$/'],
    ['pattern' => '/^php\s+tools\/ai-contract-lint\.php\s+--json$/'],
    ['pattern' => '/^php\s+tools\/ai-contract-lint\.php\s+--live-only$/'],
    ['pattern' => '/^php\s+tools\/ai-contract-lint\.php\s+--contract=[A-Za-z0-9_][A-Za-z0-9_.\/-]*\.md$/'],
];

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
                              Extract claims from the run's report/log as STRUCTURED objects: claim_id,
                              type, re_derivable, subject.command, executor_claim and status. Every
                              claim starts UNVERIFIED; `verify` re-derives by execution.

  php tools/ai-run.php commit-check [--runs-dir=DIR] [--json]
                              Decide commit eligibility from the ledger, not from how the tree looks.
                              Exits 0 only when every recorded run is `completed` (or there are no
                              runs); exits 3 and names each run that is running, silent, failed or
                              abandoned. Cheap and read-only: it performs only the reconciliation
                              `status` already performs. The failure it prevents is silent — a tree
                              looks coherent while a run is still writing to it.

  php tools/ai-run.php verify --run=ID [--claim=CLAIM_ID] [--runs-dir=DIR] [--json]
                              [--timeout=SECONDS]
                              Re-derive re-derivable claims by executing their declared commands,
                              capturing the real exit code and output. Records
                              method=independent_execution, verifier=deterministic, the observed
                              values, and the tree binding (git rev-parse HEAD + dirty flag). Sets
                              RE_DERIVED when the observation agrees and CONTRADICTED when it does
                              not, recording both claimed and observed. A command that is not on the
                              allowlist is refused, never executed, and shown so a human can decide.
                              A type that cannot be re-derived by a pure tool is never reported as
                              verified.

ALLOWLIST — the security boundary. Executing a command named by a report is a code-execution
surface, so only these exact shapes run, with a per-command timeout and no shell:
  php tests/<file>.php            (the file must be pure: no bootstrap, no MODE_INTEGRATION)
  php -l <file>.php
  php tools/ai-contract-lint.php [--json|--live-only|--contract=<file>.md]
Anything else — chaining, arguments outside the shape, other interpreters — is refused with
reason `command_not_allowlisted`.

Exit codes: 0 ok; 2 malformed input or contract; 3 the --gate/commit-check found a
            silent/failed/abandoned/running run, or verify found a CONTRADICTED claim.
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

/** Honest re-derivability for a recognised claim type. */
function claimReDerivable(string $type): bool
{
    return CLAIM_TYPES[$type] ?? false;
}

/**
 * Recognise the claim type of a declared command by its prefix, or null when the line is not a
 * command. Classification is separate from the allowlist: a recognised command may still be refused.
 */
function classifyCommand(string $command): ?string
{
    $command = trim($command);
    if ($command === '') {
        return null;
    }
    if (preg_match('/^(npx\s+)?playwright\b/i', $command) === 1) {
        return 'BROWSER_JOURNEY';
    }
    if (preg_match('/^php\s+ikabud\s+migrate\b/', $command) === 1) {
        return 'MIGRATION_STATE';
    }
    if (preg_match('/^(ab|wrk|hyperfine|siege)\b/', $command) === 1) {
        return 'PERFORMANCE_MEASUREMENT';
    }
    if (preg_match('/^(sha256sum|md5sum|git\s+hash-object)\b/', $command) === 1) {
        return 'ARTIFACT_HASH';
    }
    if (preg_match('/^git\s+(status|diff)\b/', $command) === 1) {
        return 'FILE_SCOPE';
    }
    if (preg_match('/^php\s+tools\/ai-contract-lint\.php\b/', $command) === 1) {
        return 'CONTRACT_CONFORMANCE';
    }
    if (
        preg_match('/^php\s+tests\/[^\s]+\.php\b/', $command) === 1
        || preg_match('/^composer\s+test\b/', $command) === 1
        || preg_match('/^php\s+scripts\/run-tests\.php\b/', $command) === 1
        || preg_match('/^(php\s+)?vendor\/bin\/phpunit\b/', $command) === 1
    ) {
        return 'TEST_RESULT';
    }
    if (
        preg_match('/^php\s+-l\s+\S+/', $command) === 1
        || preg_match('/^(php\s+)?vendor\/bin\/phpstan\b/', $command) === 1
        || preg_match('/^vendor\/bin\/(phpcs|php-cs-fixer)\b/', $command) === 1
    ) {
        return 'LINT_RESULT';
    }
    return null;
}

/** A test file is safe to execute only when it is pure: no bootstrap, no integration mode. */
function testFileIsPure(string $relativePath): bool
{
    $path = dirname(__DIR__) . '/' . $relativePath;
    $content = @file_get_contents($path);
    if ($content === false) {
        return false;
    }
    return !str_contains($content, 'MODE_INTEGRATION') && !str_contains($content, 'bootstrap.php');
}

/** The allowlist. Only these exact command shapes may be executed; everything else is refused. */
function commandIsAllowlisted(string $command): bool
{
    $command = trim($command);
    if ($command === '' || str_contains($command, '..')) {
        return false;
    }
    foreach (COMMAND_ALLOWLIST as $rule) {
        if (preg_match($rule['pattern'], $command, $matches) !== 1) {
            continue;
        }
        if (($rule['screen'] ?? null) === 'pure_test') {
            return testFileIsPure('tests/' . $matches[1] . '.php');
        }
        return true;
    }
    return false;
}

/**
 * A declared command is executed as an argv array with bypass_shell, so chaining operators can never
 * be interpreted. Only allowlisted `php ...` commands reach here.
 *
 * @return list<string>|null
 */
function argvForCommand(string $command): ?array
{
    $command = trim($command);
    if (!str_starts_with($command, 'php ')) {
        return null;
    }
    $parts = preg_split('/\s+/', substr($command, 4)) ?: [];
    if ($parts === [] || $parts[0] === '') {
        return null;
    }
    array_unshift($parts, PHP_BINARY);
    return $parts;
}

/**
 * Parse a whole line as a command declaration. Whole lines only, so prose that merely mentions a
 * command is not promoted into an executable claim.
 *
 * @return array{command:string,type:string,re_derivable:bool}|null
 */
function parseCommandLine(string $line): ?array
{
    $candidate = trim($line);
    $candidate = preg_replace('/^[>$]\s*/', '', $candidate) ?? $candidate;
    $candidate = trim($candidate);
    if (strlen($candidate) > 2 && str_starts_with($candidate, '`') && str_ends_with($candidate, '`')) {
        $candidate = trim(substr($candidate, 1, -1));
    }
    $type = classifyCommand($candidate);
    if ($type === null) {
        return null;
    }
    return ['command' => $candidate, 'type' => $type, 're_derivable' => claimReDerivable($type)];
}

/** @return array<string,mixed> */
function parseEvidence(string $line): array
{
    $evidence = [];
    if (preg_match('/(\d+)\s*\/\s*(\d+)\s+passed\b/i', $line, $matches) === 1) {
        $evidence['passed'] = (int) $matches[1];
        $evidence['total'] = (int) $matches[2];
    } elseif (preg_match('/\bpassed\s*[=:]\s*(\d+)/i', $line, $matches) === 1) {
        $evidence['passed'] = (int) $matches[1];
    }
    if (preg_match('/\bfailed\s*[=:]\s*(\d+)/i', $line, $matches) === 1) {
        $evidence['failed'] = (int) $matches[1];
    }
    if (preg_match('/\bexit\s*[=:]?\s*(\d+)\b/i', $line, $matches) === 1) {
        $evidence['exit_code'] = (int) $matches[1];
    }
    if (stripos($line, 'No syntax errors detected') !== false) {
        $evidence['syntax_ok'] = true;
    }
    return $evidence;
}

/**
 * A bare result line without a command can still name a test we know how to re-run.
 *
 * @return array{command:?string,type:string,re_derivable:bool}|null
 */
function parseProseClaim(string $line): ?array
{
    if (preg_match('/\b(tests\/[A-Za-z0-9_][A-Za-z0-9_.\/-]*\.php)\s+ok\b/i', $line, $matches) === 1) {
        return ['command' => 'php ' . $matches[1], 'type' => 'TEST_RESULT', 're_derivable' => true];
    }
    if (preg_match('/(\d+)\s*\/\s*(\d+)\s+passed\b/i', $line) === 1
        || preg_match('/\b(passed|failed)\s*[=:]\s*\d+/i', $line) === 1) {
        return ['command' => null, 'type' => 'TEST_RESULT', 're_derivable' => true];
    }
    if (stripos($line, 'No syntax errors') !== false) {
        return ['command' => null, 'type' => 'LINT_RESULT', 're_derivable' => true];
    }
    return null;
}

/** @return array<string,mixed> */
function buildClaim(string $runId, int $sequence, string $type, ?string $command, string $source, int $lineNumber, string $text): array
{
    return [
        'claim_id' => 'CLM-' . $runId . '-' . $sequence,
        'run' => $runId,
        'type' => $type,
        're_derivable' => claimReDerivable($type),
        'subject' => [
            'command' => $command,
            'source' => $source,
            'line' => $lineNumber,
            'text' => trim($text),
        ],
        'executor_claim' => [],
        'verification' => [
            'method' => null,
            'verifier' => null,
            'observed_exit' => null,
            'observed' => null,
        ],
        'status' => 'UNVERIFIED',
    ];
}

/**
 * Turn report/log prose into structured claims. A command line opens a block; result evidence on the
 * following lines attaches to it. Result-only lines become claims with no command, marked for later
 * refusal rather than silently trusted.
 *
 * @return list<array<string,mixed>>
 */
function extractClaims(string $text, string $source, string $runId, int &$sequence): array
{
    $claims = [];
    $active = null;
    $lines = preg_split('/\r?\n/', $text) ?: [];
    foreach ($lines as $index => $line) {
        // A section heading or code-fence boundary ends the active command block, so evidence from a
        // later section cannot be attributed to an earlier command.
        if (preg_match('/^\s*(?:```|#{1,6}\s|\*\*|---)/', $line) === 1) {
            $active = null;
        }
        $parsed = parseCommandLine($line);
        if ($parsed !== null) {
            $claims[] = buildClaim($runId, ++$sequence, $parsed['type'], $parsed['command'], $source, $index + 1, $line);
            $active = count($claims) - 1;
            continue;
        }
        $evidence = parseEvidence($line);
        if ($evidence !== []) {
            if ($active !== null) {
                foreach ($evidence as $key => $value) {
                    $claims[$active]['executor_claim'][$key] = $value;
                }
            } else {
                $prose = parseProseClaim($line);
                if ($prose === null) {
                    continue;
                }
                $claim = buildClaim($runId, ++$sequence, $prose['type'], $prose['command'], $source, $index + 1, $line);
                $claim['executor_claim'] = $evidence;
                $claims[] = $claim;
            }
            continue;
        }
        if ($active === null) {
            $prose = parseProseClaim($line);
            if ($prose !== null) {
                $claims[] = buildClaim($runId, ++$sequence, $prose['type'], $prose['command'], $source, $index + 1, $line);
            }
        }
    }
    return $claims;
}

/**
 * Execute an argv array directly, with no shell, under a wall-clock timeout. The tree is observed,
 * never rewritten.
 *
 * @param list<string> $argv
 * @return array{exit_code:?int,output:string,timed_out:bool}
 */
function executeArgv(array $argv, string $cwd, int $timeoutSeconds): array
{
    if (!function_exists('proc_open')) {
        return ['exit_code' => null, 'output' => 'proc_open unavailable', 'timed_out' => false];
    }
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $process = @proc_open($argv, $descriptors, $pipes, $cwd, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return ['exit_code' => null, 'output' => 'proc_open failed', 'timed_out' => false];
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = '';
    $exitCode = null;
    $timedOut = false;
    $deadline = microtime(true) + max(1, $timeoutSeconds);
    while (true) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $output .= ($stdout === false ? '' : $stdout) . ($stderr === false ? '' : $stderr);
        $status = proc_get_status($process);
        if (!$status['running']) {
            $exitCode = (int) $status['exitcode'];
            break;
        }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            proc_terminate($process);
            break;
        }
        usleep(20000);
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    $output .= ($stdout === false ? '' : $stdout) . ($stderr === false ? '' : $stderr);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    return ['exit_code' => $timedOut ? null : $exitCode, 'output' => $output, 'timed_out' => $timedOut];
}

/** @return array{rev:?string,dirty:bool} */
function treeBinding(): array
{
    $root = dirname(__DIR__);
    $rev = null;
    $revRun = executeArgv(['git', 'rev-parse', 'HEAD'], $root, 10);
    if (!$revRun['timed_out'] && $revRun['exit_code'] === 0) {
        $candidate = trim($revRun['output']);
        $rev = $candidate === '' ? null : $candidate;
    }
    $dirty = false;
    $statusRun = executeArgv(['git', 'status', '--porcelain'], $root, 10);
    if (!$statusRun['timed_out'] && $statusRun['exit_code'] === 0) {
        $dirty = trim($statusRun['output']) !== '';
    }
    return ['rev' => $rev, 'dirty' => $dirty];
}

/** @return array<string,mixed> */
function parseObserved(string $output, int $exitCode): array
{
    $observed = ['exit_code' => $exitCode];
    if (preg_match('/(\d+)\s*\/\s*(\d+)\s+passed\b/i', $output, $matches) === 1) {
        $observed['passed'] = (int) $matches[1];
        $observed['total'] = (int) $matches[2];
    } elseif (preg_match('/\bpassed\s*[=:]\s*(\d+)/i', $output, $matches) === 1) {
        $observed['passed'] = (int) $matches[1];
    }
    if (preg_match('/\bfailed\s*[=:]\s*(\d+)/i', $output, $matches) === 1) {
        $observed['failed'] = (int) $matches[1];
    }
    if (stripos($output, 'No syntax errors detected') !== false) {
        $observed['syntax_ok'] = true;
    } elseif (stripos($output, 'Parse error') !== false || stripos($output, 'syntax error') !== false) {
        $observed['syntax_ok'] = false;
    }
    return $observed;
}

/**
 * @param array<string,mixed> $expected
 * @param array<string,mixed> $observed
 * @return array{status:string,mismatches:array<string,array{claimed:mixed,observed:mixed}>,reason:?string}
 */
function evaluateClaim(array $expected, array $observed): array
{
    if ($expected === []) {
        return ['status' => 'UNVERIFIED', 'mismatches' => [], 'reason' => 'nothing_to_compare'];
    }
    $mismatches = [];
    $unobservable = [];
    foreach ($expected as $key => $value) {
        $key = (string) $key;
        if (!array_key_exists($key, $observed)) {
            $unobservable[] = $key;
            continue;
        }
        if ($observed[$key] !== $value) {
            $mismatches[$key] = ['claimed' => $value, 'observed' => $observed[$key]];
        }
    }
    if ($mismatches !== []) {
        return ['status' => 'CONTRADICTED', 'mismatches' => $mismatches, 'reason' => null];
    }
    if ($unobservable !== []) {
        return ['status' => 'UNVERIFIED', 'mismatches' => [], 'reason' => 'observed_value_missing: ' . implode(', ', $unobservable)];
    }
    return ['status' => 'RE_DERIVED', 'mismatches' => [], 'reason' => null];
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
    $sequence = 0;
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
        $claims = array_merge($claims, extractClaims($text, $path, $id, $sequence));
    }

    $note = 'claims are extracted UNVERIFIED; run `verify` to re-derive the ones whose type is re_derivable and whose command is allowlisted';
    $payload = [
        'id' => $id,
        'sources' => $sources,
        'verified' => false,
        'note' => $note,
        'claim_types' => CLAIM_TYPES,
        'claims' => $claims,
    ];

    if ($json) {
        fwrite(STDOUT, encodeJson($payload, true) . "\n");
        return EXIT_OK;
    }
    fwrite(STDOUT, "CLAIMS — {$id} [UNVERIFIED]\n");
    fwrite(STDOUT, "  {$note}\n");
    if ($claims === []) {
        fwrite(STDOUT, "  (no claims found)\n");
        return EXIT_OK;
    }
    foreach ($claims as $claim) {
        $subject = is_array($claim['subject'] ?? null) ? $claim['subject'] : [];
        $command = is_string($subject['command'] ?? null) ? $subject['command'] : '(no command)';
        $source = is_string($subject['source'] ?? null) ? $subject['source'] : '?';
        $line = is_int($subject['line'] ?? null) ? $subject['line'] : 0;
        $type = is_string($claim['type'] ?? null) ? $claim['type'] : 'UNKNOWN';
        $status = is_string($claim['status'] ?? null) ? $claim['status'] : 'UNVERIFIED';
        $kind = ($claim['re_derivable'] ?? false) === true ? 're-derivable' : 'human/procedure';
        fwrite(STDOUT, sprintf(
            "  [%s] %-22s %-16s %s  %s:%d\n",
            $status,
            $type,
            $kind,
            $command,
            $source,
            $line
        ));
    }
    return EXIT_OK;
}

/**
 * Commit eligibility is decided by the ledger, not by how the tree looks. A running run is writing to
 * the tree right now; a silent, failed or abandoned run never produced a final state. Only when every
 * recorded run is `completed` is a commit eligible.
 *
 * @param array<string,list<string>> $options
 */
function commandCommitCheck(array $options, bool $json): int
{
    $runsDir = runsDirOf(option($options, 'runs-dir'));
    $loaded = loadAllRuns($runsDir);
    $now = time();
    $blocking = [];
    $total = 0;
    foreach ($loaded['runs'] as $run) {
        $total++;
        $decorated = decorateRun($run, $now);
        $status = (string) ($decorated['status'] ?? 'unknown');
        if ($status === 'completed') {
            continue;
        }
        $blocking[] = [
            'id' => (string) ($decorated['id'] ?? 'unknown'),
            'status' => $status,
            'pid' => (int) ($decorated['pid'] ?? 0),
            'age' => (string) ($decorated['age'] ?? '0s'),
        ];
    }

    if ($json) {
        fwrite(STDOUT, encodeJson([
            'runs_dir' => $runsDir,
            'runs_count' => $total,
            'eligible' => $blocking === [],
            'blocking' => $blocking,
            'errors' => $loaded['errors'],
        ], true) . "\n");
    } else {
        fwrite(STDOUT, "COMMIT-CHECK — {$runsDir}\n");
        if ($blocking === []) {
            if ($total === 0) {
                fwrite(STDOUT, "  ELIGIBLE — no runs recorded; nothing can be mid-flight\n");
            } else {
                fwrite(STDOUT, "  ELIGIBLE — all {$total} run(s) are completed\n");
            }
        } else {
            fwrite(STDOUT, "  NOT ELIGIBLE — committing now would capture a non-final state; " . count($blocking) . " run(s) are not completed\n");
            foreach ($blocking as $item) {
                fwrite(STDOUT, "  BLOCK  {$item['id']}  {$item['status']}  age={$item['age']}  pid={$item['pid']}\n");
            }
        }
        foreach ($loaded['errors'] as $error) {
            fwrite(STDERR, "WARN: unreadable run record {$error}\n");
        }
    }

    return $blocking === [] ? EXIT_OK : EXIT_GATE;
}

/**
 * Re-derive claims by execution. This OBSERVES the tree: it never repairs, formats or rewrites it.
 * The only write is the evidence record persisted under the run ledger.
 *
 * @param array<string,list<string>> $options
 */
function commandVerify(array $options, bool $json, int $timeoutSeconds): int
{
    $id = validateRunId(requiredValue(option($options, 'run'), 'run'));
    $runsDir = runsDirOf(option($options, 'runs-dir'));
    $record = loadRecord($runsDir, $id);
    $only = option($options, 'claim');

    $claims = [];
    $sequence = 0;
    foreach (['report', 'log'] as $field) {
        $path = is_string($record[$field] ?? null) ? $record[$field] : null;
        if ($path === null || $path === '' || !is_file($path)) {
            continue;
        }
        $text = @file_get_contents($path);
        if ($text === false) {
            throw new InvalidArgumentException("run '{$id}' {$field} '{$path}' cannot be read");
        }
        $claims = array_merge($claims, extractClaims($text, $path, $id, $sequence));
    }

    if ($only !== null) {
        $filtered = [];
        foreach ($claims as $claim) {
            if (($claim['claim_id'] ?? null) === $only) {
                $filtered[] = $claim;
            }
        }
        $claims = $filtered;
        if ($claims === []) {
            throw new InvalidArgumentException("run '{$id}' has no claim '{$only}'");
        }
    }

    $binding = treeBinding();
    $attempted = 0;
    $contradicted = 0;
    $results = [];
    foreach ($claims as $claim) {
        $subject = is_array($claim['subject'] ?? null) ? $claim['subject'] : [];
        $command = is_string($subject['command'] ?? null) ? $subject['command'] : null;
        $expected = is_array($claim['executor_claim'] ?? null) ? $claim['executor_claim'] : [];
        $reDerivable = ($claim['re_derivable'] ?? false) === true;
        /** @var array<string,mixed> $verification */
        $verification = [
            'method' => null,
            'verifier' => null,
            'observed_exit' => null,
            'observed' => null,
        ];

        // A claim that cannot be re-derived must never look verified.
        if (!$reDerivable) {
            $verification['reason'] = 'not_re_derivable_by_pure_tool';
            $claim['verification'] = $verification;
            $results[] = $claim;
            continue;
        }
        if ($command === null) {
            $verification['reason'] = 'no_command_declared';
            $claim['verification'] = $verification;
            $results[] = $claim;
            continue;
        }
        // Prove the refusal path before the happy path: the command is shown, not executed.
        if (!commandIsAllowlisted($command)) {
            $verification['reason'] = 'command_not_allowlisted';
            $verification['detail'] = 'the command is shown but was NOT executed; a human must decide';
            $claim['verification'] = $verification;
            $results[] = $claim;
            continue;
        }
        $argv = argvForCommand($command);
        if ($argv === null) {
            $verification['reason'] = 'command_not_allowlisted';
            $claim['verification'] = $verification;
            $results[] = $claim;
            continue;
        }
        $attempted++;
        $execution = executeArgv($argv, dirname(__DIR__), $timeoutSeconds);
        if ($execution['timed_out']) {
            $verification['reason'] = 'timeout';
            $verification['observed_exit'] = null;
            $verification['observed'] = ['timeout_seconds' => $timeoutSeconds];
            $claim['verification'] = $verification;
            $results[] = $claim;
            continue;
        }
        $observed = parseObserved((string) $execution['output'], (int) $execution['exit_code']);
        $evaluation = evaluateClaim($expected, $observed);
        $verification['method'] = 'independent_execution';
        $verification['verifier'] = 'deterministic';
        $verification['observed_exit'] = $observed['exit_code'];
        $verification['observed'] = $observed;
        $verification['binding'] = $binding;
        if ($evaluation['status'] === 'CONTRADICTED') {
            $verification['mismatches'] = $evaluation['mismatches'];
            $claim['status'] = 'CONTRADICTED';
            $contradicted++;
        } elseif ($evaluation['status'] === 'UNVERIFIED') {
            $verification['reason'] = $evaluation['reason'];
            $claim['status'] = 'UNVERIFIED';
        } else {
            $claim['status'] = 'RE_DERIVED';
        }
        $claim['verification'] = $verification;
        $results[] = $claim;
    }

    // Evidence binds to the revision it was verified against, so it cannot silently drift.
    $record['claim_verification'] = [
        'verified_at' => date(DATE_ATOM),
        'rev' => $binding['rev'],
        'dirty' => $binding['dirty'],
        'results' => $results,
    ];
    writeRecord(recordPath($runsDir, $id), $record);

    $summary = [
        'attempted' => $attempted,
        're_derived' => 0,
        'contradicted' => 0,
        'refused' => 0,
        'not_re_derivable' => 0,
        'unverified' => 0,
    ];
    foreach ($results as $claim) {
        $status = is_string($claim['status'] ?? null) ? $claim['status'] : 'UNVERIFIED';
        $verification = $claim['verification'];
        $reason = is_string($verification['reason'] ?? null) ? $verification['reason'] : '';
        if ($status === 'RE_DERIVED') {
            $summary['re_derived']++;
        } elseif ($status === 'CONTRADICTED') {
            $summary['contradicted']++;
        } elseif ($reason === 'not_re_derivable_by_pure_tool') {
            $summary['not_re_derivable']++;
        } elseif ($reason === 'command_not_allowlisted') {
            $summary['refused']++;
        } else {
            $summary['unverified']++;
        }
    }

    if ($json) {
        fwrite(STDOUT, encodeJson([
            'run' => $id,
            'binding' => $binding,
            'summary' => $summary,
            'claims' => $results,
        ], true) . "\n");
    } else {
        fwrite(STDOUT, "VERIFY — {$id}\n");
        $rev = is_string($binding['rev']) ? $binding['rev'] : '(unavailable)';
        fwrite(STDOUT, "  binding: rev={$rev} dirty=" . ($binding['dirty'] ? 'yes' : 'no') . "\n");
        foreach ($results as $claim) {
            $subject = is_array($claim['subject'] ?? null) ? $claim['subject'] : [];
            $command = is_string($subject['command'] ?? null) ? $subject['command'] : '(no command)';
            $type = is_string($claim['type'] ?? null) ? $claim['type'] : 'UNKNOWN';
            $status = is_string($claim['status'] ?? null) ? $claim['status'] : 'UNVERIFIED';
            fwrite(STDOUT, "  [{$status}] {$type}  {$command}\n");
            $verification = $claim['verification'];
            if ($status === 'CONTRADICTED') {
                $mismatches = is_array($verification['mismatches'] ?? null) ? $verification['mismatches'] : [];
                foreach ($mismatches as $key => $mismatch) {
                    $claimed = is_array($mismatch) ? ($mismatch['claimed'] ?? null) : null;
                    $observedValue = is_array($mismatch) ? ($mismatch['observed'] ?? null) : null;
                    fwrite(STDOUT, "      claimed {$key}=" . encodeJson($claimed) . ' observed ' . encodeJson($observedValue) . "\n");
                }
            } elseif ($status === 'RE_DERIVED') {
                fwrite(STDOUT, "      claimed " . encodeJson($claim['executor_claim'] ?? []) . ' observed ' . encodeJson($verification['observed'] ?? null) . "\n");
            } elseif (is_string($verification['reason'] ?? null)) {
                fwrite(STDOUT, '      reason: ' . $verification['reason'] . "\n");
            }
        }
        fwrite(STDOUT, "  summary: attempted={$summary['attempted']} re-derived={$summary['re_derived']} contradicted={$summary['contradicted']} refused={$summary['refused']} not_re_derivable={$summary['not_re_derivable']} unverified={$summary['unverified']}\n");
    }

    return $contradicted > 0 ? EXIT_GATE : EXIT_OK;
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
    if (!in_array($command, ['start', 'finish', 'status', 'claims', 'commit-check', 'verify'], true)) {
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
    if ($command === 'commit-check') {
        validateArgumentNames($parsed, ['runs-dir'], ['json']);
        return commandCommitCheck($parsed['options'], isset($parsed['flags']['json']));
    }
    if ($command === 'verify') {
        validateArgumentNames($parsed, ['run', 'claim', 'runs-dir', 'timeout'], ['json']);
        $timeout = option($parsed['options'], 'timeout');
        return commandVerify($parsed['options'], isset($parsed['flags']['json']), $timeout === null ? 120 : intValue($timeout, 'timeout'));
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
