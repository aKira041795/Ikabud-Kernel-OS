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
 *                               [--pid=N] [--director-decision=REF] [--predecessor=ID]
 *                               [--repair-level=L1|L2|L3] [--approach-change=TEXT]
 *                               [--previous-failure=TEXT] [--runs-dir=DIR] [--json]
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
const RUN_STATUSES = ['running', 'completed', 'silent', 'failed', 'abandoned', 'blocked'];
/** @var list<string> */
const GATE_STATUSES = ['silent', 'failed', 'abandoned', 'blocked'];

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
 * Environment every Python shape runs under. `PYTHONDONTWRITEBYTECODE=1` stops import-time `.pyc`,
 * and `PYTHONPYCACHEPREFIX` redirects `py_compile`'s deliberate bytecode outside the repository
 * (`py_compile` writes regardless of the former). `{TMPDIR}` is resolved at execution time, because a
 * `const` cannot call sys_get_temp_dir(). Together they keep a verification from creating any new
 * path the scope gate can see.
 * @var array<string,string>
 */
const PYTHON_SHAPE_ENV = [
    'PYTHONDONTWRITEBYTECODE' => '1',
    'PYTHONPYCACHEPREFIX' => '{TMPDIR}/ai-run-pycache',
];

/**
 * The allowlist, as data rather than scattered conditionals. Each rule is an anchored pattern plus
 * the executable (`exec`) that runs it; the `pure_test` and `bridge_test` screens additionally refuse
 * a named test file that may bootstrap the app or that does not exist. The security boundary is the
 * whole table, so it can be read in one place and refused consistently.
 *
 * The Python entries are the subject's native evidence shapes (slice C). They are deliberately no
 * broader than the evidence needs: `py_compile` is a syntax check, `unittest` is bounded to this
 * repository's own `tests.*` package and a `test_*` module name, and the file test is bounded to
 * `tools/harpp-bridge/tests/<name>.py` and screened for existence. There is deliberately NO rule for
 * `python3 -c "..."` or a bare `python3 <file>`: inline code is arbitrary execution (the Python form
 * of the B-F1 vacuity hole) and must never become executable evidence.
 * @var list<array{pattern:string,exec:string,screen?:string,env?:array<string,string>}>
 */
const COMMAND_ALLOWLIST = [
    ['exec' => PHP_BINARY, 'pattern' => '/^php\s+tests\/([A-Za-z0-9_][A-Za-z0-9_.-]*)\.php$/', 'screen' => 'pure_test'],
    ['exec' => PHP_BINARY, 'pattern' => '/^php\s+-l\s+[A-Za-z0-9_][A-Za-z0-9_.\/-]*\.php$/'],
    ['exec' => PHP_BINARY, 'pattern' => '/^php\s+tools\/ai-contract-lint\.php$/'],
    ['exec' => PHP_BINARY, 'pattern' => '/^php\s+tools\/ai-contract-lint\.php\s+--json$/'],
    ['exec' => PHP_BINARY, 'pattern' => '/^php\s+tools\/ai-contract-lint\.php\s+--live-only$/'],
    ['exec' => PHP_BINARY, 'pattern' => '/^php\s+tools\/ai-contract-lint\.php\s+--contract=[A-Za-z0-9_][A-Za-z0-9_.\/-]*\.md$/'],
    // Python syntax check: an existing or missing path is fine; `..` is refused globally above.
    ['exec' => 'python3', 'env' => PYTHON_SHAPE_ENV, 'pattern' => '/^python3\s+-m\s+py_compile\s+[A-Za-z0-9_][A-Za-z0-9_.\/-]*\.py$/'],
    // Python module test: bounded to this repository's own `tests.*` package and `test_*` module name,
    // so `python3 -m unittest os` cannot name an importable module with side effects.
    ['exec' => 'python3', 'env' => PYTHON_SHAPE_ENV, 'pattern' => '/^python3\s+-m\s+unittest\s+tests\.(?:[A-Za-z_][A-Za-z0-9_]*\.)*test_[A-Za-z0-9_]*$/'],
    // Python file test: screened for existence exactly as `pure_test` screens the PHP suite.
    ['exec' => 'python3', 'env' => PYTHON_SHAPE_ENV, 'pattern' => '/^python3\s+tools\/harpp-bridge\/tests\/([A-Za-z0-9_][A-Za-z0-9_.-]*)\.py$/', 'screen' => 'bridge_test'],
];

/**
 * The verifier trust surface, mirroring tools/ai-autonomy.php:trustSurfacePaths().
 *
 * It is enumerated here because the ledger computes the integrity hash without importing the driver
 * (both files define the same top-level constants and helper functions, so requiring one from the
 * other is a fatal redeclaration). The two lists are pinned together by tests/ai_run_test.php and
 * tests/ai_autonomy_test.php; any drift is a test failure. Amendments to this list are director-only
 * (CD-21 rule 4, owner directive 2026-09-14): the Chair may propose, never perform.
 *
 * @return list<string>
 */
function trustSurfacePaths(): array
{
    return [
        'tools/ai-run.php',
        'tools/ai-autonomy.php',
        'tools/ai-project.php',
        'tools/ai-loop.php',
        'tools/ai-contract-lint.php',
        'kernel/Workbench/Development/DevelopmentTaskContract.php',
        'tools/harpp-bridge/harpp_wake.py',
    ];
}

/**
 * SHA-256 of the trust-surface files, sorted by path and concatenated. Per-file digests are returned
 * too, so commit-check can name the file(s) whose hash moved rather than only reporting an opaque
 * aggregate mismatch. A file that cannot be read yields a null digest for that entry and a null
 * aggregate: missing integrity input is recorded as unavailable, never silently hashed as empty.
 *
 * @return array{hash:?string,files:array<string,?string>}
 */
function trustSurfaceDigest(): array
{
    $root = dirname(__DIR__);
    $paths = trustSurfacePaths();
    sort($paths, SORT_STRING);
    $concat = '';
    $files = [];
    $complete = true;
    foreach ($paths as $path) {
        $content = @file_get_contents($root . '/' . $path);
        if ($content === false) {
            $files[$path] = null;
            $complete = false;
            continue;
        }
        $files[$path] = hash('sha256', $content);
        $concat .= $content;
    }

    return ['hash' => $complete ? hash('sha256', $concat) : null, 'files' => $files];
}

/**
 * The trust-surface files whose digest moved since the run recorded it, or null when the record
 * cannot be checked (an older ledger record) or already agrees.
 *
 * A run started before this integrity check existed carries no `trust_surface_hash`. That is NOT a
 * block: refusing historical runs would make the gate unusable, so a missing or `unavailable` hash
 * is skipped. Every record written from now on carries the hash, so the trade-off is bounded and
 * deliberate. A hash that IS present and does not match the current trust surface is a silent
 * widening of the verifier under the evidence, and blocks.
 *
 * @param array<string,mixed> $record
 * @return list<string>|null
 */
function trustSurfaceMismatch(array $record): ?array
{
    $recordedHash = $record['trust_surface_hash'] ?? null;
    if (!is_string($recordedHash) || $recordedHash === '' || $recordedHash === 'unavailable') {
        return null;
    }
    $current = trustSurfaceDigest();
    if (is_string($current['hash']) && authorisedTrustSurfaceTransition($recordedHash, $current['hash'])) {
        return null;
    }
    $recordedFiles = is_array($record['trust_surface_files'] ?? null) ? $record['trust_surface_files'] : [];
    $changed = [];
    foreach (trustSurfacePaths() as $path) {
        if (($recordedFiles[$path] ?? null) !== ($current['files'][$path] ?? null)) {
            $changed[] = $path;
        }
    }
    $aggregateMoved = $current['hash'] === null || !hash_equals($recordedHash, (string) $current['hash']);
    if ($aggregateMoved && $changed === []) {
        $changed[] = '(aggregate)';
    }
    if (!$aggregateMoved && $changed === []) {
        return null;
    }

    return array_values(array_unique($changed));
}

/** A director route may authorise one visible old-hash -> recorded-new-hash transition. */
function authorisedTrustSurfaceTransition(string $from, string $to): bool
{
    $document = json_decode((string) @file_get_contents(dirname(__DIR__) . '/.ai/trust-surface-amendments.json'), true);
    foreach (is_array($document) && is_array($document['amendments'] ?? null) ? $document['amendments'] : [] as $item) {
        if (!is_array($item) || ($item['trust_surface_hash'] ?? null) !== $to) { continue; }
        if (in_array($from, is_array($item['supersedes_hashes'] ?? null) ? $item['supersedes_hashes'] : [], true)) {
            return true;
        }
    }
    return false;
}

/** Print command help and contractual exit codes. */
function usage(): void
{
    fwrite(STDOUT, <<<'TXT'
Usage:
  php tools/ai-run.php start  --contract=PATH --lane=MODEL --name=ID [--log=PATH] [--report=PATH]
                              [--pid=N] [--director-decision=REF] [--predecessor=ID]
                              [--repair-level=L1|L2|L3] [--approach-change=TEXT]
                              [--previous-failure=TEXT] [--runs-dir=DIR] [--json]
                              Record the run before it starts, including the changed-path baseline.
                              A contract covering the verifier requires a real recorded director
                              decision; its reference is persisted in the ledger.

  php tools/ai-run.php finish --id=ID --exit=CODE [--log=PATH] [--report=PATH]
                              [--runs-dir=DIR] [--json]
                              Record the exit code observed by the dispatcher, compare changed paths
                              against the dispatch baseline and contract envelope, and classify:
                                scope violation                    -> blocked (exit 3)
                                exit != 0                          -> failed
                                exit == 0, no report/log content   -> silent
                                exit == 0, with report/log content -> completed
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
                              type, re_derivable, subject.command, subject.command_source, executor_claim
                              and status. `command_source` is `declared` when the evidence line carries
                              the command, `derived` when the command was bound by exact-match from a
                              test the line names (existing and pure), and null when no command is
                              bound. Every claim starts UNVERIFIED; `verify` re-derives by execution.

  php tools/ai-run.php commit-check [--runs-dir=DIR] [--json]
                              [--acknowledge-block=RUN --reason=TEXT --director-decision=REF]
                              Decide commit eligibility from the ledger, not from how the tree looks.
                              The acknowledgement route records one director-attributed blocked run
                              once in a separate append-only artefact. It never mutates the run.
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
                              A claim whose text names a test file that is missing or not pure is
                              refused during binding (`test_file_missing` / `test_file_impure`) and
                              stays UNVERIFIED.
                              A type that cannot be re-derived by a pure tool is never reported as
                              verified.

ALLOWLIST — the security boundary. Executing a command named by a report is a code-execution
surface, so only these exact shapes run, with a per-command timeout and no shell:
  php tests/<file>.php            (the file must be pure: no bootstrap, no MODE_INTEGRATION)
  php -l <file>.php
  php tools/ai-contract-lint.php [--json|--live-only|--contract=<file>.md]
  python3 -m py_compile <file>.py
  python3 -m unittest tests.<dotted.test_module>
  python3 tools/harpp-bridge/tests/<file>.py    (the file must exist)
The Python shapes run with PYTHONDONTWRITEBYTECODE=1 and an external PYTHONPYCACHEPREFIX, so they
leave no bytecode in the tree. Anything else — chaining, `python3 -c "..."` inline code, a bare
`python3 <file>`, arguments outside the shape, other interpreters — is refused with reason
`command_not_allowlisted`.

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

/** Resolve run authorisation against the repository's real decision records. */
function runDirectorDecisionExists(string $reference): bool
{
    $reference = trim($reference);
    if ($reference === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $reference) !== 1) { return false; }
    $chair = @file_get_contents(dirname(__DIR__) . '/.ai/chair-decisions.md');
    if ($chair !== false && preg_match('/^##\s+' . preg_quote($reference, '/') . '\b/m', $chair) === 1) { return true; }
    $raw = @file_get_contents(dirname(__DIR__) . '/.ai/decisions/' . $reference . '.json');
    $decision = $raw === false ? null : json_decode($raw, true);
    return is_array($decision) && ($decision['decision_id'] ?? null) === $reference
        && ($decision['schema'] ?? null) === 'ark.workbench-development-decision-request.v1';
}

/**
 * Bind a run only to a session the runner actually exposes. A parent Pi session is not attributed
 * to a different lane: absence or mismatch is explicit rather than guessed.
 *
 * @return array{binding:?array<string,string>,reason:?string}
 */
function exposedRunnerSession(string $lane): array
{
    $id = getenv('PI_SESSION_ID');
    $file = getenv('PI_SESSION_FILE');
    $provider = getenv('PI_PROVIDER');
    $model = getenv('PI_MODEL');
    if (!is_string($id) || $id === '' || !is_string($file) || $file === '') {
        return ['binding' => null, 'reason' => 'runner exposed no PI_SESSION_ID/PI_SESSION_FILE artefact'];
    }
    $exposedLane = (is_string($provider) && $provider !== '' ? $provider . '/' : '') . (is_string($model) ? $model : '');
    if ($exposedLane === '' || ($lane !== $exposedLane && $lane !== $model)) {
        return ['binding' => null, 'reason' => "exposed Pi session belongs to lane {$exposedLane}, not run lane {$lane}"];
    }
    if (!is_file($file)) {
        return ['binding' => null, 'reason' => "runner exposed session artefact '{$file}', but it is not readable"];
    }
    return ['binding' => ['session_id' => $id, 'session_file' => $file, 'provider' => (string) $provider, 'model' => (string) $model], 'reason' => null];
}

/**
 * Sum only usage objects actually present in the bound JSONL during this run. No pricing table,
 * interpolation, wall-clock conversion or placeholder is permitted.
 *
 * @param array<string,mixed> $record
 * @return array{usage:?array<string,mixed>,reason:?string}
 */
function usageFromBoundSession(array $record, string $finishedAt): array
{
    $binding = $record['runner_session'] ?? null;
    if (!is_array($binding) || !is_string($binding['session_file'] ?? null) || !is_string($binding['session_id'] ?? null)) {
        return ['usage' => null, 'reason' => is_string($record['usage_unavailable_reason'] ?? null)
            ? $record['usage_unavailable_reason'] : 'run carries no bound runner session artefact'];
    }
    $handle = @fopen($binding['session_file'], 'rb');
    if (!is_resource($handle)) {
        return ['usage' => null, 'reason' => "bound runner session artefact '{$binding['session_file']}' cannot be read"];
    }
    $start = strtotime((string) ($record['started_at'] ?? ''));
    $finish = strtotime($finishedAt);
    $sessionSeen = false;
    $messages = 0;
    $totals = ['input_tokens' => 0, 'output_tokens' => 0, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0, 'reasoning_tokens' => 0, 'total_tokens' => 0, 'cost_usd' => 0.0];
    while (($line = fgets($handle)) !== false) {
        $row = json_decode($line, true);
        if (!is_array($row)) { continue; }
        if (($row['type'] ?? null) === 'session' && ($row['id'] ?? null) === $binding['session_id']) { $sessionSeen = true; }
        $at = is_string($row['timestamp'] ?? null) ? strtotime($row['timestamp']) : false;
        if ($at === false || $start === false || $finish === false || $at < $start || $at > $finish) { continue; }
        $usage = is_array($row['message']['usage'] ?? null) ? $row['message']['usage'] : null;
        $cost = is_array($usage['cost'] ?? null) ? $usage['cost'] : null;
        if ($usage === null || $cost === null || !is_numeric($usage['totalTokens'] ?? null) || !is_numeric($cost['total'] ?? null)) { continue; }
        $messages++;
        $totals['input_tokens'] += (int) ($usage['input'] ?? 0);
        $totals['output_tokens'] += (int) ($usage['output'] ?? 0);
        $totals['cache_read_tokens'] += (int) ($usage['cacheRead'] ?? 0);
        $totals['cache_write_tokens'] += (int) ($usage['cacheWrite'] ?? 0);
        $totals['reasoning_tokens'] += (int) ($usage['reasoning'] ?? 0);
        $totals['total_tokens'] += (int) $usage['totalTokens'];
        $totals['cost_usd'] += (float) $cost['total'];
    }
    fclose($handle);
    if (!$sessionSeen) { return ['usage' => null, 'reason' => 'bound session id does not match the session artefact header']; }
    if ($messages === 0) { return ['usage' => null, 'reason' => 'bound session artefact exposes no usage messages within the run interval']; }
    $totals['cost_usd'] = round($totals['cost_usd'], 9);
    return ['usage' => array_merge($totals, [
        'messages' => $messages,
        'source' => 'bound_pi_session_jsonl',
        'session_id' => $binding['session_id'],
        'session_file' => $binding['session_file'],
    ]), 'reason' => null];
}

/** Ask plan's rule-3 implementation rather than copying its trust-surface matcher into the ledger. */
function contractRequiresDirector(string $contractPath): bool
{
    $run = executeArgv([PHP_BINARY, dirname(__DIR__) . '/tools/ai-autonomy.php', 'plan', '--json', '--contract=' . $contractPath], dirname(__DIR__), 20);
    if (!$run['timed_out'] && $run['exit_code'] === 0) { return false; }
    if (!$run['timed_out'] && $run['exit_code'] === EXIT_USAGE && str_contains($run['output'], 'trust surface')) { return true; }
    throw new InvalidArgumentException("contract '{$contractPath}' could not pass the autonomy plan check: " . trim($run['output']));
}

/** @return array{ok:bool,paths:list<string>,error:?string} */
function workingTreeChangedPaths(): array
{
    $run = executeArgv(['git', 'status', '--porcelain=v1', '-z', '--untracked-files=all'], dirname(__DIR__), 20);
    if ($run['timed_out'] || $run['exit_code'] !== 0) {
        return ['ok' => false, 'paths' => [], 'error' => trim($run['output']) ?: 'git status unavailable'];
    }
    $parts = explode("\0", $run['output']);
    $paths = [];
    for ($i = 0; $i < count($parts); $i++) {
        $item = $parts[$i];
        if ($item === '') { continue; }
        if (strlen($item) < 4) {
            return ['ok' => false, 'paths' => [], 'error' => 'ambiguous git status entry'];
        }
        $status = substr($item, 0, 2);
        $path = substr($item, 3);
        if ($path === '') { return ['ok' => false, 'paths' => [], 'error' => 'empty git status path']; }
        $paths[] = $path;
        if (str_contains($status, 'R') || str_contains($status, 'C')) {
            $old = $parts[++$i] ?? '';
            if ($old === '') { return ['ok' => false, 'paths' => [], 'error' => 'ambiguous git rename/copy entry']; }
            $paths[] = $old;
        }
    }
    sort($paths, SORT_STRING);
    return ['ok' => true, 'paths' => array_values(array_unique($paths)), 'error' => null];
}

/** @param list<string> $paths @return array<string,string> */
function changedPathFingerprints(array $paths): array
{
    $root = dirname(__DIR__); $result = [];
    foreach ($paths as $path) {
        $absolute = $root . '/' . $path;
        if (is_link($absolute)) { $result[$path] = 'symlink:' . (string) readlink($absolute); }
        elseif (is_file($absolute)) { $hash = hash_file('sha256', $absolute); $result[$path] = is_string($hash) ? 'file:' . $hash : 'unreadable'; }
        elseif (is_dir($absolute)) { $result[$path] = 'directory'; }
        else { $result[$path] = 'absent'; }
    }
    return $result;
}

function repositoryRelativePath(?string $path): ?string
{
    if ($path === null || trim($path) === '') { return null; }
    $root = str_replace('\\', '/', dirname(__DIR__));
    $candidate = str_replace('\\', '/', $path);
    if (str_starts_with($candidate, $root . '/')) { $candidate = substr($candidate, strlen($root) + 1); }
    elseif (str_starts_with($candidate, '/')) { return null; }
    $candidate = preg_replace('#^\./+#', '', $candidate) ?? $candidate;
    return $candidate !== '' && !str_contains($candidate, '../') ? $candidate : null;
}

/**
 * Evaluate changed paths by invoking `check`; this guarantees A-F2 uses the exact envelope and
 * taxonomy matcher used by interactive checks, including trust-surface directory/glob semantics.
 *
 * @param list<string> $paths
 * @return array{ok:bool,checked:list<string>,offending:list<array{path:string,reasons:list<string>}>}
 */
function scopeConformance(string $contract, string $expectedRevision, array $paths): array
{
    try {
        $finishContract = loadContract($contract);
        $finishRevision = DevelopmentTaskContract::revisionId($finishContract);
    } catch (InvalidArgumentException $e) {
        return ['ok' => false, 'checked' => $paths, 'offending' => [['path' => $contract, 'reasons' => ['dispatch contract cannot be re-read: ' . $e->getMessage()]]]];
    }
    if (!hash_equals($expectedRevision, $finishRevision)) {
        return ['ok' => false, 'checked' => $paths, 'offending' => [['path' => $contract, 'reasons' => ["contract revision moved after dispatch: expected {$expectedRevision}, observed {$finishRevision}"]]]];
    }

    $offending = [];
    foreach ($paths as $path) {
        $run = executeArgv([
            PHP_BINARY, dirname(__DIR__) . '/tools/ai-autonomy.php', 'check', 'run changed path',
            '--path=' . $path, '--contract=' . $contract, '--json',
        ], dirname(__DIR__), 20);
        $payload = json_decode($run['output'], true);
        if ($run['timed_out'] || $run['exit_code'] !== 0 || !is_array($payload) || ($payload['verdict'] ?? null) === 'ESCALATE') {
            $reasons = is_array($payload) && is_array($payload['reasons'] ?? null)
                ? array_values(array_map('strval', $payload['reasons']))
                : [trim($run['output']) ?: 'scope check unavailable'];
            $offending[] = ['path' => $path, 'reasons' => $reasons];
        }
    }
    return ['ok' => $offending === [], 'checked' => $paths, 'offending' => $offending];
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
    $directorDecision = trim((string) option($options, 'director-decision', ''));
    $requiresDirector = contractRequiresDirector($contractPath);
    if (($requiresDirector && $directorDecision === '') || ($directorDecision !== '' && !runDirectorDecisionExists($directorDecision))) {
        fwrite(STDOUT, "REFUSED: this run requires --director-decision naming a decision recorded in .ai/decisions/ or .ai/chair-decisions.md\n");
        return EXIT_GATE;
    }
    $baseline = workingTreeChangedPaths();
    if (!$baseline['ok']) {
        fwrite(STDOUT, 'REFUSED: dispatch baseline could not be captured: ' . $baseline['error'] . "\n");
        return EXIT_GATE;
    }
    $log = option($options, 'log');
    $report = option($options, 'report');
    $pidRaw = option($options, 'pid');
    $pid = $pidRaw === null ? defaultLivenessPid() : intValue($pidRaw, 'pid');
    $ignored = array_values(array_unique(array_filter([
        repositoryRelativePath($path), repositoryRelativePath($log), repositoryRelativePath($report),
    ], static fn (?string $item): bool => $item !== null)));

    $lane = requiredValue(option($options, 'lane'), 'lane');
    $predecessor = option($options, 'predecessor');
    $repairLevel = option($options, 'repair-level');
    $approachChange = option($options, 'approach-change');
    $previousFailure = option($options, 'previous-failure');
    if ($predecessor !== null) {
        $predecessor = validateRunId($predecessor);
        $prior = loadRecord($runsDir, $predecessor);
        $priorStatus = (string) ($prior['status'] ?? '');
        $verificationFailed = false;
        foreach (is_array($prior['claim_verification']['results'] ?? null) ? $prior['claim_verification']['results'] : [] as $claim) {
            if (!is_array($claim) || ($claim['status'] ?? null) !== 'RE_DERIVED') { $verificationFailed = true; break; }
        }
        if (!in_array($priorStatus, ['failed', 'silent'], true) && !($priorStatus === 'completed' && $verificationFailed)) {
            throw new InvalidArgumentException("predecessor run '{$predecessor}' is not a finished failure");
        }
        if (!in_array($repairLevel, ['L1', 'L2', 'L3'], true) || $approachChange === null || trim($approachChange) === '' || $previousFailure === null || trim($previousFailure) === '') {
            throw new InvalidArgumentException('a repair successor requires --repair-level=L1|L2|L3, --approach-change and --previous-failure');
        }
        if (($prior['contract_revision'] ?? null) !== DevelopmentTaskContract::revisionId($parsed)) {
            throw new InvalidArgumentException('repair successor refused: the contract revision/envelope moved');
        }
    } elseif ($repairLevel !== null || $approachChange !== null || $previousFailure !== null) {
        throw new InvalidArgumentException('repair metadata requires --predecessor');
    }
    $session = exposedRunnerSession($lane);

    $record = [
        'id' => $id,
        'contract' => $contractPath,
        'contract_revision' => DevelopmentTaskContract::revisionId($parsed),
        'allowed_count' => count((array) ($parsed['allowed_scope'] ?? [])),
        'forbidden_count' => count((array) ($parsed['forbidden_scope'] ?? [])),
        'lane' => $lane,
        'started_at' => date(DATE_ATOM),
        'pid' => $pid,
        'log' => $log,
        'report' => $report,
        'status' => 'running',
        'scope_baseline_paths' => $baseline['paths'],
        'scope_baseline_fingerprints' => changedPathFingerprints($baseline['paths']),
        'scope_ignored_paths' => $ignored,
        'predecessor_run_id' => $predecessor,
        'repair' => $predecessor === null ? null : [
            'level' => $repairLevel,
            'approach_change' => $approachChange,
            'previous_failure' => $previousFailure,
        ],
        'runner_session' => $session['binding'],
        'usage' => null,
        'usage_unavailable_reason' => $session['reason'] ?? 'bound session usage is captured from the artefact at finish',
        'director_authorisation' => $directorDecision === '' ? null : [
            'decision_ref' => $directorDecision,
            'recorded_at' => date(DATE_ATOM),
            'required_for_trust_surface' => $requiresDirector,
        ],
    ];
    // Bind the evidence to the verifier that produced it: if the trust surface widens after this
    // point, commit-check names the file(s) whose hash moved and blocks. Recorded before the run so
    // the binding is a pre-commitment, not a post-hoc rationalisation.
    $trustSurface = trustSurfaceDigest();
    $record['trust_surface_hash'] = $trustSurface['hash'] ?? 'unavailable';
    $record['trust_surface_files'] = $trustSurface['files'];
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
    fwrite(STDOUT, "  baseline:  " . count($baseline['paths']) . " changed path(s) at dispatch\n");
    fwrite(STDOUT, "  director:  " . ($directorDecision === '' ? '(not required)' : $directorDecision) . "\n");
    fwrite(STDOUT, "  trust:     " . ($trustSurface['hash'] ?? 'unavailable') . "\n");
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

    $current = workingTreeChangedPaths();
    $baseline = is_array($record['scope_baseline_paths'] ?? null) ? array_values(array_map('strval', $record['scope_baseline_paths'])) : null;
    $ignored = is_array($record['scope_ignored_paths'] ?? null) ? array_values(array_map('strval', $record['scope_ignored_paths'])) : [];
    if (!$current['ok'] || $baseline === null) {
        $detail = !$current['ok'] ? (string) $current['error'] : 'run has no dispatch-time changed-path baseline';
        $conformance = ['ok' => false, 'checked' => [], 'offending' => [['path' => '(working-tree)', 'reasons' => [$detail]]]];
        $delta = [];
    } else {
        $delta = array_values(array_unique(array_merge(array_diff($current['paths'], $baseline), array_diff($baseline, $current['paths']))));
        $baselineFingerprints = is_array($record['scope_baseline_fingerprints'] ?? null) ? $record['scope_baseline_fingerprints'] : [];
        $currentFingerprints = changedPathFingerprints($current['paths']);
        foreach (array_intersect($baseline, $current['paths']) as $existingPath) {
            if (array_key_exists($existingPath, $baselineFingerprints)
                && ($baselineFingerprints[$existingPath] ?? null) !== ($currentFingerprints[$existingPath] ?? null)) {
                $delta[] = $existingPath;
            }
        }
        $delta = array_values(array_diff(array_unique($delta), $ignored));
        sort($delta, SORT_STRING);
        $conformance = scopeConformance(
            (string) ($record['contract'] ?? ''),
            (string) ($record['contract_revision'] ?? ''),
            $delta
        );
    }

    $record['finished_at'] = date(DATE_ATOM);
    $record['exit_code'] = $exitCode;
    $record['log_bytes'] = $logBytes;
    $record['report_bytes'] = $reportBytes;
    $record['scope_finish_paths'] = $current['paths'];
    $record['scope_delta_paths'] = $delta;
    $record['scope_conformance'] = $conformance;
    $record['status'] = $conformance['ok'] ? classifyRun($exitCode, $logBytes, $reportBytes) : 'blocked';
    $usage = usageFromBoundSession($record, (string) $record['finished_at']);
    $record['usage'] = $usage['usage'];
    $record['usage_unavailable_reason'] = $usage['reason'];
    $evidenceParts = [];
    foreach ([$report, $log] as $evidencePath) {
        if (is_string($evidencePath) && is_file($evidencePath) && fileBytes($evidencePath) > 0) {
            $digest = hash_file('sha256', $evidencePath);
            if (is_string($digest)) { $evidenceParts[] = $digest; }
        }
    }
    $record['evidence_fingerprint'] = $evidenceParts === [] ? null : hash('sha256', implode('|', $evidenceParts));
    $record['evidence_unavailable_reason'] = $evidenceParts === [] ? 'runner produced no report or log content' : null;
    writeRecord(recordPath($runsDir, $id), $record);

    if ($json) {
        fwrite(STDOUT, encodeJson($record) . "\n");
        return $conformance['ok'] ? EXIT_OK : EXIT_GATE;
    }
    fwrite(STDOUT, "RUN {$id} finished: {$record['status']}\n");
    fwrite(STDOUT, "  exit:      {$exitCode}\n");
    fwrite(STDOUT, "  log:       {$logBytes} bytes" . ($log !== null ? " ({$log})" : '') . "\n");
    fwrite(STDOUT, "  report:    {$reportBytes} bytes" . ($report !== null ? " ({$report})" : '') . "\n");
    fwrite(STDOUT, "  scope:     " . ($conformance['ok'] ? 'OK' : 'BLOCKED') . ' delta=' . count($delta) . "\n");
    foreach ($conformance['offending'] as $offence) {
        fwrite(STDOUT, "  OFFENDING {$offence['path']}: " . implode('; ', $offence['reasons']) . "\n");
    }
    if ($record['status'] === 'silent') {
        fwrite(STDOUT, "  NOTE: exit 0 and no report after the process exited — a silent run. The work may be\n");
        fwrite(STDOUT, "        real; the evidence is not. This is recorded, not inferred: `finish` runs after the\n");
        fwrite(STDOUT, "        process exited, so its log has been flushed. Never classify from a log read mid-run.\n");
    }
    return $conformance['ok'] ? EXIT_OK : EXIT_GATE;
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
    if (
        preg_match('/^python3\s+-m\s+unittest\b/', $command) === 1
        || preg_match('/^python3\s+tools\/harpp-bridge\/tests\/[^\s]+\.py\b/', $command) === 1
    ) {
        return 'TEST_RESULT';
    }
    if (preg_match('/^python3\s+-m\s+py_compile\b/', $command) === 1) {
        return 'LINT_RESULT';
    }
    if (preg_match('/^python3\b/', $command) === 1) {
        // Every other python3 invocation is still recognised, so it becomes an explicit refusal
        // rather than an ignored line. This is the `python3 -c "..."` inline-code shape. Recognising
        // a command never authorises it: COMMAND_ALLOWLIST is the only thing that permits execution,
        // and it contains no inline-code rule.
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

/**
 * The first allowlist rule a command matches, with its capture groups, or null. This is the single
 * place the table is interpreted: `commandIsAllowlisted()` and `argvForCommand()` both read this
 * verdict, so the executable a command runs can never disagree with the rule that permitted it.
 *
 * @return array{pattern:string,exec:string,screen?:string,env?:array<string,string>,matches:list<string>}|null
 */
function matchingAllowlistRule(string $command): ?array
{
    $command = trim($command);
    if ($command === '' || str_contains($command, '..')) {
        return null;
    }
    foreach (COMMAND_ALLOWLIST as $rule) {
        if (preg_match($rule['pattern'], $command, $matches) !== 1) {
            continue;
        }
        $screen = $rule['screen'] ?? null;
        if ($screen === 'pure_test' && !testFileIsPure('tests/' . $matches[1] . '.php')) {
            return null;
        }
        if ($screen === 'bridge_test' && !is_file(dirname(__DIR__) . '/tools/harpp-bridge/tests/' . $matches[1] . '.py')) {
            return null;
        }
        return $rule + ['matches' => $matches];
    }
    return null;
}

/** The allowlist. Only these exact command shapes may be executed; everything else is refused. */
function commandIsAllowlisted(string $command): bool
{
    return matchingAllowlistRule($command) !== null;
}

/**
 * A declared command is executed as an argv array with bypass_shell, so chaining operators can never
 * be interpreted. The executable is taken from the matched allowlist rule, never from the command
 * text: the rule fixes which binary runs before the command is split into arguments. A command that
 * matches no rule returns null and is never executed.
 *
 * @return list<string>|null
 */
function argvForCommand(string $command): ?array
{
    $rule = matchingAllowlistRule($command);
    $exec = $rule === null ? null : ($rule['exec'] ?? null);
    if (!is_string($exec) || $exec === '') {
        return null;
    }
    // The anchored pattern guarantees the first token is this rule's fixed executable word; it is
    // dropped and the rule's `exec` is substituted, so the command text can never choose the binary.
    $parts = preg_split('/\s+/', trim($command)) ?: [];
    array_shift($parts);
    array_unshift($parts, $exec);
    return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
}

/**
 * Environment overrides declared by the matched rule. The `{TMPDIR}` token resolves to the system
 * temporary directory, so the Python shapes can redirect bytecode outside the repository.
 *
 * @return array<string,string>
 */
function environmentForCommand(string $command): array
{
    $rule = matchingAllowlistRule($command);
    if ($rule === null || !is_array($rule['env'] ?? null)) {
        return [];
    }
    $environment = [];
    foreach ($rule['env'] as $name => $value) {
        if (is_string($name) && is_string($value)) {
            $environment[$name] = str_replace('{TMPDIR}', sys_get_temp_dir(), $value);
        }
    }
    return $environment;
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
 * A bare result line without a declared command can still name a test we know how to re-run. The
 * command is not bound here: `deriveTestCommand` binds it and labels the route as derived.
 *
 * @return array{command:?string,type:string,re_derivable:bool}|null
 */
function parseProseClaim(string $line): ?array
{
    if (preg_match('/\b(tests\/[A-Za-z0-9_][A-Za-z0-9_.\/-]*\.php)\s+ok\b/i', $line) === 1) {
        return ['command' => null, 'type' => 'TEST_RESULT', 're_derivable' => true];
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

/**
 * A relative test path may carry a derived command only when the file exists and passes the purity
 * screen (no bootstrap, no MODE_INTEGRATION).
 *
 * @return array{ok:bool,reason:?string}
 */
function testFileDerivationVerdict(string $relativePath): array
{
    if (!is_file(dirname(__DIR__) . '/' . $relativePath)) {
        return ['ok' => false, 'reason' => 'test_file_missing'];
    }
    if (!testFileIsPure($relativePath)) {
        return ['ok' => false, 'reason' => 'test_file_impure'];
    }
    return ['ok' => true, 'reason' => null];
}

/**
 * Build the `derived` binding verdict for one candidate test path.
 *
 * @return array{command:?string,command_source:?string,derivation:array{attempted:bool,candidate:string,refused:?string}}
 */
function deriveVerdict(string $candidate): array
{
    $verdict = testFileDerivationVerdict($candidate);
    if ($verdict['ok']) {
        return [
            'command' => 'php ' . $candidate,
            'command_source' => 'derived',
            'derivation' => ['attempted' => true, 'candidate' => $candidate, 'refused' => null],
        ];
    }
    return [
        'command' => null,
        'command_source' => null,
        'derivation' => ['attempted' => true, 'candidate' => $candidate, 'refused' => $verdict['reason']],
    ];
}

/**
 * Derive a test command for a TEST_RESULT that names a test file but declares no command. The
 * binding is exact-match: an explicit `tests/<file>.php` token, or a bare name for which
 * `tests/<name>.php` exists. A named but missing or impure file is refused and the claim stays
 * UNVERIFIED. A command is never invented silently.
 *
 * @return array{command:?string,command_source:?string,derivation:array{attempted:bool,candidate:?string,refused:?string}|null}
 */
function deriveTestCommand(string $type, string $text): array
{
    $none = ['command' => null, 'command_source' => null, 'derivation' => null];
    if ($type !== 'TEST_RESULT') {
        return $none;
    }
    if (preg_match('/\btests\/([A-Za-z0-9_][A-Za-z0-9_.\/-]*)\.php\b/', $text, $matches) === 1) {
        return deriveVerdict('tests/' . $matches[1] . '.php');
    }
    if (preg_match_all('/\b([A-Za-z0-9_][A-Za-z0-9_]*)\b/', $text, $matches) >= 1) {
        foreach ($matches[1] as $token) {
            $candidate = 'tests/' . $token . '.php';
            if (is_file(dirname(__DIR__) . '/' . $candidate)) {
                return deriveVerdict($candidate);
            }
        }
    }
    return $none;
}

/**
 * A line carrying an allowlisted `php ...` invocation together with its result on the same line
 * declares its own command. Shell chaining is refused here so the bound command is exactly the safe
 * invocation shown, never a fragment of a larger shell expression.
 *
 * @param array<string,mixed> $evidence
 * @return array{command:string,type:string,re_derivable:bool}|null
 */
function parseInlineCommand(string $line, array $evidence): ?array
{
    if ($evidence === []) {
        return null;
    }
    $candidate = trim($line);
    $candidate = preg_replace('/^[>$]\s*/', '', $candidate) ?? $candidate;
    if (strpbrk($candidate, ";|&`") !== false || str_contains($candidate, '$(')) {
        return null;
    }
    $patterns = [
        '/(?:^|[\s`$>])(php\s+tests\/[A-Za-z0-9_][A-Za-z0-9_.\/-]*\.php)\b/',
        '/(?:^|[\s`$>])(php\s+-l\s+[A-Za-z0-9_][A-Za-z0-9_.\/-]*\.php)\b/',
        '/(?:^|[\s`$>])(php\s+tools\/ai-contract-lint\.php(?:\s+--(?:json|live-only|contract=[A-Za-z0-9_][A-Za-z0-9_.\/-]*\.md))?)\b/',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $candidate, $matches) !== 1) {
            continue;
        }
        $command = trim($matches[1]);
        $type = classifyCommand($command);
        if ($type === null) {
            continue;
        }
        return ['command' => $command, 'type' => $type, 're_derivable' => claimReDerivable($type)];
    }
    return null;
}

/**
 * Build a prose claim, binding a derived command when the text names an existing pure test.
 *
 * @param array{command:?string,type:string,re_derivable:bool} $prose
 * @param array<string,mixed> $evidence
 * @return array<string,mixed>
 */
function buildProseClaim(string $runId, int &$sequence, array $prose, string $source, int $lineNumber, string $text, array $evidence): array
{
    $binding = deriveTestCommand($prose['type'], $text);
    $claim = buildClaim(
        $runId,
        ++$sequence,
        $prose['type'],
        $binding['command'],
        $source,
        $lineNumber,
        $text,
        $binding['command_source'],
        $binding['derivation']
    );
    $claim['executor_claim'] = $evidence;
    return $claim;
}

/**
 * @param array{attempted:bool,candidate:?string,refused:?string}|null $derivation
 * @return array<string,mixed>
 */
function buildClaim(string $runId, int $sequence, string $type, ?string $command, string $source, int $lineNumber, string $text, ?string $commandSource = null, ?array $derivation = null): array
{
    $subject = [
        'command' => $command,
        'command_source' => $commandSource,
        'source' => $source,
        'line' => $lineNumber,
        'text' => trim($text),
    ];
    if ($derivation !== null) {
        $subject['derivation'] = $derivation;
    }
    return [
        'claim_id' => 'CLM-' . $runId . '-' . $sequence,
        'run' => $runId,
        'type' => $type,
        're_derivable' => claimReDerivable($type),
        'subject' => $subject,
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
        $evidence = parseEvidence($line);
        // A single line that carries an allowlisted command AND its result declares its own command.
        $inline = parseInlineCommand($line, $evidence);
        if ($inline !== null) {
            $claim = buildClaim($runId, ++$sequence, $inline['type'], $inline['command'], $source, $index + 1, $line, 'declared');
            $claim['executor_claim'] = $evidence;
            $claims[] = $claim;
            $active = count($claims) - 1;
            continue;
        }
        $parsed = parseCommandLine($line);
        if ($parsed !== null) {
            $claims[] = buildClaim($runId, ++$sequence, $parsed['type'], $parsed['command'], $source, $index + 1, $line, 'declared');
            $active = count($claims) - 1;
            continue;
        }
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
                $claims[] = buildProseClaim($runId, $sequence, $prose, $source, $index + 1, $line, $evidence);
            }
            continue;
        }
        if ($active === null) {
            $prose = parseProseClaim($line);
            if ($prose !== null) {
                $claims[] = buildProseClaim($runId, $sequence, $prose, $source, $index + 1, $line, []);
            }
        }
    }
    return $claims;
}

/**
 * Execute an argv array directly, with no shell, under a wall-clock timeout. The tree is observed,
 * never rewritten. A non-empty `$environment` is merged over the inherited environment (so PATH and
 * friends survive) and is how a rule redirects Python bytecode out of the repository.
 *
 * @param list<string> $argv
 * @param array<string,string> $environment
 * @return array{exit_code:?int,output:string,timed_out:bool}
 */
function executeArgv(array $argv, string $cwd, int $timeoutSeconds, array $environment = []): array
{
    if (!function_exists('proc_open')) {
        return ['exit_code' => null, 'output' => 'proc_open unavailable', 'timed_out' => false];
    }
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $processEnv = null;
    if ($environment !== []) {
        $inherited = getenv();
        $processEnv = array_merge(is_array($inherited) ? $inherited : [], $environment);
    }
    $process = @proc_open($argv, $descriptors, $pipes, $cwd, $processEnv, ['bypass_shell' => true]);
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
        $origin = is_string($subject['command_source'] ?? null) ? (string) $subject['command_source'] : 'none';
        fwrite(STDOUT, sprintf(
            "  [%s] %-22s %-16s [%-8s] %s  %s:%d\n",
            $status,
            $type,
            $kind,
            $origin,
            $command,
            $source,
            $line
        ));
    }
    return EXIT_OK;
}

/** @return array{schema:string,acknowledgements:list<array<string,mixed>>} */
function loadBlockAcknowledgements(string $runsDir): array
{
    $path = runsDirOf($runsDir) . '/.acknowledged-blocks.v1';
    if (!is_file($path)) { return ['schema' => 'ark.ai-block-acknowledgements.v1', 'acknowledgements' => []]; }
    $value = json_decode((string) @file_get_contents($path), true);
    if (!is_array($value) || ($value['schema'] ?? null) !== 'ark.ai-block-acknowledgements.v1' || !is_array($value['acknowledgements'] ?? null)) {
        throw new InvalidArgumentException("block acknowledgement artefact '{$path}' is malformed");
    }
    return $value;
}

/** @param array{schema:string,acknowledgements:list<array<string,mixed>>} $document */
function writeBlockAcknowledgements(string $runsDir, array $document): void
{
    writeRecord(runsDirOf($runsDir) . '/.acknowledged-blocks.v1', $document);
}

/**
 * Record one exceptional, director-attributed acknowledgement outside the immutable run record.
 * The block remains a block; commit-check merely recognises this one named historical exception.
 *
 * @param array<string,list<string>> $options
 */
function acknowledgeBlock(array $options): int
{
    $runsDir = runsDirOf(option($options, 'runs-dir'));
    $id = validateRunId(requiredValue(option($options, 'acknowledge-block'), 'acknowledge-block'));
    $decision = trim((string) option($options, 'director-decision', ''));
    if (!runDirectorDecisionExists($decision)) {
        fwrite(STDOUT, "REFUSED: --director-decision must resolve to a recorded decision in .ai/decisions/ or .ai/chair-decisions.md\n");
        return EXIT_GATE;
    }
    $reason = requiredValue(option($options, 'reason'), 'reason');
    $loaded = loadAllRuns($runsDir);
    $run = null;
    foreach ($loaded['runs'] as $candidate) {
        if (($candidate['id'] ?? null) === $id) { $run = $candidate; break; }
    }
    if (!is_array($run)) { throw new InvalidArgumentException("run '{$id}' has no readable record"); }
    if (($run['status'] ?? null) === 'running') {
        fwrite(STDOUT, "REFUSED: run {$id} is still running; a live run cannot be acknowledged as a historical block\n");
        return EXIT_GATE;
    }
    if (($run['status'] ?? null) !== 'blocked' || ($run['scope_conformance']['ok'] ?? null) !== false) {
        fwrite(STDOUT, "REFUSED: run {$id} is not a finished scope-conformance block\n");
        return EXIT_GATE;
    }
    $document = loadBlockAcknowledgements($runsDir);
    foreach ($document['acknowledgements'] as $item) {
        if (is_array($item) && ($item['run_id'] ?? null) === $id) {
            fwrite(STDOUT, "REFUSED: blocked run {$id} was already acknowledged once\n");
            return EXIT_GATE;
        }
    }
    $recordPath = recordPath($runsDir, $id);
    $before = hash_file('sha256', $recordPath);
    $ack = [
        'run_id' => $id,
        'block_reason' => $reason,
        'director_decision' => $decision,
        'acknowledged_at' => date(DATE_ATOM),
        'run_status_observed' => 'blocked',
        'scope_conformance_ok_observed' => false,
        'run_record_sha256' => $before,
    ];
    $document['acknowledgements'][] = $ack;
    writeBlockAcknowledgements($runsDir, $document);
    $after = hash_file('sha256', $recordPath);
    if (!is_string($before) || !is_string($after) || !hash_equals($before, $after)) {
        throw new InvalidArgumentException("run '{$id}' changed while its block was acknowledged");
    }
    fwrite(STDOUT, "ACKNOWLEDGED BLOCK {$id}\n");
    fwrite(STDOUT, "  reason:   {$reason}\n  decision: {$decision}\n  recorded: {$ack['acknowledged_at']}\n");
    fwrite(STDOUT, "  immutable: status=blocked scope_conformance.ok=false sha256={$after}\n");
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
    if (option($options, 'acknowledge-block') !== null) {
        $acknowledged = acknowledgeBlock($options);
        if ($acknowledged !== EXIT_OK) { return $acknowledged; }
    } elseif (option($options, 'reason') !== null || option($options, 'director-decision') !== null) {
        throw new InvalidArgumentException('--reason and --director-decision require --acknowledge-block');
    }
    $loaded = loadAllRuns($runsDir);
    $ackDocument = loadBlockAcknowledgements($runsDir);
    $acknowledgedIds = [];
    foreach ($ackDocument['acknowledgements'] as $ack) {
        if (is_array($ack) && is_string($ack['run_id'] ?? null)) { $acknowledgedIds[$ack['run_id']] = true; }
    }
    $successors = [];
    foreach ($loaded['runs'] as $candidate) {
        $prior = $candidate['predecessor_run_id'] ?? null;
        if (is_string($prior) && $prior !== '') { $successors[$prior][] = (string) ($candidate['id'] ?? ''); }
    }
    $now = time();
    $blocking = [];
    $total = 0;
    foreach ($loaded['runs'] as $run) {
        $total++;
        $decorated = decorateRun($run, $now);
        $status = (string) ($decorated['status'] ?? 'unknown');
        if ($status === 'blocked' && isset($acknowledgedIds[(string) ($decorated['id'] ?? '')])) {
            continue;
        }
        if (in_array($status, ['failed', 'silent'], true) && isset($successors[(string) ($decorated['id'] ?? '')])) {
            // The immutable failed attempt remains evidence; its linked successor now carries the
            // gate. If that chain is unfinished, its tip still blocks.
            continue;
        }
        if ($status === 'completed') {
            $changed = trustSurfaceMismatch($decorated);
            if ($changed !== null) {
                $blocking[] = [
                    'id' => (string) ($decorated['id'] ?? 'unknown'),
                    'status' => 'trust_surface_mismatch',
                    'pid' => (int) ($decorated['pid'] ?? 0),
                    'age' => (string) ($decorated['age'] ?? '0s'),
                    'changed' => $changed,
                ];
            }
            continue;
        }
        $blocking[] = [
            'id' => (string) ($decorated['id'] ?? 'unknown'),
            'status' => $status,
            'pid' => (int) ($decorated['pid'] ?? 0),
            'age' => (string) ($decorated['age'] ?? '0s'),
            'changed' => [],
        ];
    }
    // An unreadable or malformed record cannot be shown to be non-blocking, so it is blocking. The
    // previous behaviour dropped it from the gate with only a stderr warning, so a corrupt record
    // (or a deliberately planted one) made commit-check report ELIGIBLE while a real run was
    // in-flight. Naming the file is what makes the block actionable.
    foreach ($loaded['errors'] as $file) {
        $blocking[] = [
            'id' => basename((string) $file),
            'status' => 'unreadable',
            'pid' => 0,
            'age' => '0s',
            'changed' => [],
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
                $changed = is_array($item['changed'] ?? null) && $item['changed'] !== [] ? '  changed=' . implode(',', $item['changed']) : '';
                fwrite(STDOUT, "  BLOCK  {$item['id']}  {$item['status']}  age={$item['age']}  pid={$item['pid']}{$changed}\n");
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
            $derivation = is_array($subject['derivation'] ?? null) ? $subject['derivation'] : [];
            $refused = is_string($derivation['refused'] ?? null) ? $derivation['refused'] : null;
            $verification['reason'] = $refused ?? 'no_command_declared';
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
        $execution = executeArgv($argv, dirname(__DIR__), $timeoutSeconds, environmentForCommand($command));
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

    // Evidence binds to the revision it was verified against, so it cannot silently drift. The
    // contract revision recorded at `start` is carried into the verification record so the two
    // cannot be swapped independently: a later replay against a different contract revision is
    // refused by the `done` gate even if the results themselves look valid.
    $record['claim_verification'] = [
        'verified_at' => date(DATE_ATOM),
        'contract_revision' => is_string($record['contract_revision'] ?? null) ? $record['contract_revision'] : null,
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
        validateArgumentNames($parsed, ['contract', 'lane', 'name', 'log', 'report', 'pid', 'director-decision', 'predecessor', 'repair-level', 'approach-change', 'previous-failure', 'runs-dir'], ['json']);
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
        validateArgumentNames($parsed, ['runs-dir', 'acknowledge-block', 'reason', 'director-decision'], ['json']);
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
