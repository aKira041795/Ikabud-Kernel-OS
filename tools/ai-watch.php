#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Read-only live monitor for AI development runs.
 *
 * WHY THIS EXISTS
 * ---------------
 * `tools/ai-run.php` records a pid at `start` and reconciles a dead pid to `abandoned`
 * only when somebody happens to poll `status`. `tools/ai-loop.php` (599 lines) contains
 * no reference to a pid, a liveness check, a log or a timeout anywhere. So while a slice
 * is executing, nothing watches it:
 *
 *   - a process that dies mid-flight leaves a record that still reads `running`, and
 *     nothing says so until a human polls `status`;
 *   - a process that hangs holds the single dispatch slot forever with no timeout;
 *   - two concurrent lanes silently corrupt the ledger (the late `finish` absorbs the
 *     other run's changed files) and nothing detects it.
 *
 * This tool closes the observability half of that gap WITHOUT touching the trust surface.
 * It is strictly observational: it never writes to `.ai/runs/`, so reconciliation
 * authority stays in exactly one place (`ai-run.php status`). Two tools that both
 * reconcile would disagree the moment their logic drifted.
 *
 * The remaining half — heartbeat, per-run log capture and a hang timeout inside the
 * ledger itself — is a trust-surface change and is therefore filed for owner
 * authorisation rather than made here.
 *
 * Usage:
 *   php tools/ai-watch.php [--runs-dir=DIR] [--json] [--watch[=SECONDS]]
 *
 * Exit codes: 0 no in-flight anomaly; 2 malformed usage or unreadable runs dir;
 *             3 in-flight anomaly (a run recorded `running` whose process is dead, or
 *             more than one live run at once).
 */

const WATCH_OK = 0;
const WATCH_USAGE = 2;
const WATCH_ANOMALY = 3;

const DEFAULT_RUNS_DIR = '.ai/runs';
const DEFAULT_INTERVAL = 5;

/** How much of a live process's command line to show as evidence. */
const CMDLINE_PREVIEW = 72;

function watchUsage(): void
{
    fwrite(STDOUT, <<<'TXT'
Usage:
  php tools/ai-watch.php [--runs-dir=DIR] [--json] [--watch[=SECONDS]]

  --runs-dir=DIR     Run ledger directory (default: .ai/runs)
  --json             Machine-readable output
  --watch[=SECONDS]  Refresh continuously (default interval: 5s). Ctrl-C to stop.

Read-only: never writes to the ledger. Answers "what is running right now, and is the
process that owns it actually alive?" — the question `pgrep` used to be asked, and the
question nothing in the harness currently answers while a slice is executing.

Exit: 0 clean; 2 usage; 3 an in-flight anomaly (stale running record, or >1 live run).
TXT
    . "\n");
}

/**
 * @param list<string> $argv
 * @return array{runs_dir: string, json: bool, watch: bool, interval: int}|null
 */
function watchOptions(array $argv): ?array
{
    $options = [
        'runs_dir' => DEFAULT_RUNS_DIR,
        'json' => false,
        'watch' => false,
        'interval' => DEFAULT_INTERVAL,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--json') {
            $options['json'] = true;
        } elseif ($arg === '--watch') {
            $options['watch'] = true;
        } elseif (str_starts_with($arg, '--watch=')) {
            $raw = substr($arg, strlen('--watch='));
            if ($raw === '' || !ctype_digit($raw) || (int) $raw < 1) {
                fwrite(STDERR, "ai-watch: --watch interval must be a positive integer of seconds\n");
                return null;
            }
            $options['watch'] = true;
            $options['interval'] = (int) $raw;
        } elseif (str_starts_with($arg, '--runs-dir=')) {
            $raw = substr($arg, strlen('--runs-dir='));
            if ($raw === '') {
                fwrite(STDERR, "ai-watch: --runs-dir requires a directory\n");
                return null;
            }
            $options['runs_dir'] = rtrim($raw, '/');
        } elseif ($arg === '--help' || $arg === '-h') {
            watchUsage();
            exit(WATCH_OK);
        } else {
            fwrite(STDERR, "ai-watch: unrecognised argument: {$arg}\n");
            return null;
        }
    }

    return $options;
}

/**
 * Mirrors `pidIsAlive()` in tools/ai-run.php exactly, so this monitor can never disagree
 * with the ledger about whether a process is alive.
 */
function watchPidIsAlive(int $pid): bool
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

/**
 * Evidence, not judgement: shown for live runs so a human can see what is actually
 * running. No liveness decision is derived from it.
 */
function watchCmdline(int $pid): string
{
    $path = '/proc/' . $pid . '/cmdline';
    if ($pid <= 0 || !is_readable($path)) {
        return '';
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return '';
    }
    $text = trim(str_replace("\0", ' ', $raw));
    if (strlen($text) > CMDLINE_PREVIEW) {
        $text = substr($text, 0, CMDLINE_PREVIEW) . '…';
    }
    return $text;
}

function watchHumanize(int $seconds): string
{
    if ($seconds < 0) {
        return '?';
    }
    if ($seconds < 60) {
        return $seconds . 's';
    }
    if ($seconds < 3600) {
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        return $s > 0 ? "{$m}m{$s}s" : "{$m}m";
    }
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return $m > 0 ? "{$h}h{$m}m" : "{$h}h";
}

/**
 * @return list<array<string, mixed>>
 */
function watchLoadRecords(string $dir): array
{
    $records = [];
    $files = glob($dir . '/*.json');
    if ($files === false) {
        return $records;
    }
    foreach ($files as $file) {
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            continue;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['id'])) {
            continue;
        }
        $records[] = $decoded;
    }
    return $records;
}

/**
 * A row's `state` is deliberately conservative: it distinguishes "recorded as running and
 * the process really is there" from "recorded as running and the process is gone", because
 * only the second is a defect that needs attention right now.
 *
 * @param array<string, mixed> $record
 * @return array{id: string, status: string, pid: int, lane: string, age: int, state: string, note: string}
 */
function watchRow(array $record): array
{
    $id = (string) ($record['id'] ?? '?');
    $status = (string) ($record['status'] ?? 'unknown');
    $pid = (int) ($record['pid'] ?? 0);
    $lane = (string) ($record['lane'] ?? '-');
    $started = (string) ($record['started_at'] ?? '');
    $startedAt = $started !== '' ? strtotime($started) : false;
    $age = $startedAt !== false ? max(0, time() - $startedAt) : -1;

    $note = '';

    if ($status === 'running') {
        if (watchPidIsAlive($pid)) {
            $state = 'LIVE';
            $note = watchCmdline($pid);
        } else {
            $state = 'STALE';
            $note = "recorded running, pid {$pid} is gone — died without finish";
        }
    } elseif ($status === 'blocked') {
        $state = 'BLOCKED';
        $note = 'finish conformance failed — check report; may be awaiting a decision';
    } elseif ($status === 'completed') {
        $state = 'done';
    } else {
        $state = strtoupper($status);
    }

    return [
        'id' => $id,
        'status' => $status,
        'pid' => $pid,
        'lane' => $lane,
        'age' => $age,
        'state' => $state,
        'note' => $note,
    ];
}

/**
 * @param list<array{id: string, status: string, pid: int, lane: string, age: int, state: string, note: string}> $rows
 * @return list<string>
 */
function watchAnomalies(array $rows): array
{
    $anomalies = [];
    $live = [];

    foreach ($rows as $row) {
        if ($row['state'] === 'LIVE') {
            $live[] = $row['id'];
        } elseif ($row['state'] === 'STALE') {
            $anomalies[] = "run '{$row['id']}' is recorded running but its process is dead";
        }
    }

    if (count($live) > 1) {
        $anomalies[] = 'concurrent live runs (' . implode(', ', $live) . ') — two lanes at once '
            . 'corrupts the ledger: the later finish absorbs the other run\'s files';
    }

    return $anomalies;
}

/**
 * @param list<array{id: string, status: string, pid: int, lane: string, age: int, state: string, note: string}> $rows
 * @param list<string> $anomalies
 */
function watchRender(array $rows, array $anomalies, bool $json, string $runsDir): int
{
    $inFlight = array_values(array_filter(
        $rows,
        static fn (array $r): bool => in_array($r['state'], ['LIVE', 'STALE'], true)
    ));

    if ($json) {
        fwrite(STDOUT, json_encode([
            'runs_dir' => $runsDir,
            'checked_at' => date('c'),
            'in_flight' => count($inFlight),
            'live' => count(array_filter($rows, static fn (array $r): bool => $r['state'] === 'LIVE')),
            'rows' => $rows,
            'anomalies' => $anomalies,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return $anomalies === [] ? WATCH_OK : WATCH_ANOMALY;
    }

    fwrite(STDOUT, 'run ledger: ' . $runsDir . '  (' . date('H:i:s') . ")\n");

    if ($inFlight === []) {
        fwrite(STDOUT, "in flight: none\n");
    } else {
        fwrite(STDOUT, 'in flight: ' . count($inFlight) . "\n");
        foreach ($inFlight as $row) {
            fwrite(STDOUT, sprintf(
                "  %-8s %-28s pid=%-8d age=%-8s %s\n",
                $row['state'],
                $row['id'],
                $row['pid'],
                watchHumanize($row['age']),
                $row['lane']
            ));
            if ($row['note'] !== '') {
                fwrite(STDOUT, '           ' . $row['note'] . "\n");
            }
        }
    }

    $waiting = array_values(array_filter($rows, static fn (array $r): bool => $r['state'] === 'BLOCKED'));
    $done = count(array_filter($rows, static fn (array $r): bool => $r['state'] === 'done'));
    fwrite(STDOUT, sprintf("ledger: %d recorded — %d completed, %d blocked\n", count($rows), $done, count($waiting)));

    if ($waiting !== []) {
        $names = array_slice(array_column($waiting, 'id'), 0, 6);
        fwrite(STDOUT, '  blocked: ' . implode(', ', $names)
            . (count($waiting) > 6 ? ' …' : '') . "\n");
    }

    if ($anomalies !== []) {
        fwrite(STDOUT, "\nANOMALY\n");
        foreach ($anomalies as $anomaly) {
            fwrite(STDOUT, '  ! ' . $anomaly . "\n");
        }
    }

    return $anomalies === [] ? WATCH_OK : WATCH_ANOMALY;
}

$options = watchOptions($argv);
if ($options === null) {
    watchUsage();
    exit(WATCH_USAGE);
}

$runsDir = $options['runs_dir'];
if (!is_dir($runsDir)) {
    fwrite(STDERR, "ai-watch: runs directory not found: {$runsDir}\n");
    exit(WATCH_USAGE);
}

if (!$options['watch']) {
    $rows = array_map('watchRow', watchLoadRecords($runsDir));
    exit(watchRender($rows, watchAnomalies($rows), $options['json'], $runsDir));
}

$exit = WATCH_OK;
$stop = false;

// Ctrl-C must exit with the last status the monitor actually observed, not with a
// meaningless zero. Without this the loop's condition is unconditionally true and the
// tool cannot report anything on the way out.
if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals') && defined('SIGINT')) {
    pcntl_async_signals(true);
    $onSignal = static function () use (&$stop): void {
        $stop = true;
    };
    pcntl_signal(SIGINT, $onSignal);
    if (defined('SIGTERM')) {
        pcntl_signal(SIGTERM, $onSignal);
    }
}

while (!$stop) {
    $rows = array_map('watchRow', watchLoadRecords($runsDir));
    $anomalies = watchAnomalies($rows);

    if (!$options['json']) {
        fwrite(STDOUT, "\033[2J\033[H");
    }
    $exit = watchRender($rows, $anomalies, $options['json'], $runsDir);

    sleep($options['interval']);
}

exit($exit);
