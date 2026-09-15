#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Pre-dispatch authority pre-flight for a task contract.
 *
 * WHY THIS EXISTS
 * ---------------
 * `tools/ai-run.php` decides conformance at `finish` by asking, for every changed path:
 *
 *     php tools/ai-autonomy.php check "run changed path" --path=<p> --contract=<c> ...
 *
 * That question can be asked *before* the dispatch too. Nothing did, so a contract that was
 * doomed to block was still dispatched — the run did the work, then `finish` refused it, and
 * the only remaining remedy was a post-hoc acknowledgement.
 *
 * The specific trap it catches: a relative L4 trigger (a `module.json` edit is classified
 * "public API/capability contract change") resolves to L3 only when
 * `isGrounded($justification, $contract)` holds — and `scopeConformance()` never passes a
 * justification. A path can therefore sit in `allowed_scope`, be unambiguously required by the
 * contract's own acceptance criteria, and still be **unconditionally blocked**, because the
 * ledger has no channel to hear the justification it demands. Recorded as CD-59.
 *
 * Asking the same question first converts a post-hoc block into a pre-dispatch decision:
 * obtain the director decision before `start` (the pre-declaration razor makes a later one
 * inert), restructure the slice, or accept the block knowingly. 
 *
 * Read-only: writes nothing, dispatches nothing. It shells out to the ledger's own check, so it
 * cannot disagree with `finish`.
 *
 * Usage:
 *   php tools/ai-authority-preflight.php --contract=PATH [--json] [--quiet]
 *
 * Exit codes: 0 every allowed path would pass; 2 contract unreadable/unparseable;
 *             3 at least one allowed path will escalate and block the run.
 */

const PREFLIGHT_OK = 0;
const PREFLIGHT_USAGE = 2;
const PREFLIGHT_WILL_BLOCK = 3;

function preflightUsage(): void
{
    fwrite(STDOUT, <<<'TXT'
Usage:
  php tools/ai-authority-preflight.php --contract=PATH [--json] [--quiet]

  --contract=PATH  Contract to check (required)
  --json           Machine-readable output
  --quiet          Print nothing unless a path will block

For every path in the contract's `allowed_scope`, asks the ledger the same question `finish`
asks — so the answer cannot disagree with the verdict the run will receive. Read-only.

Exit: 0 clear; 2 contract unreadable/unparseable; 3 a path will escalate and block the run.
TXT
    . "\n");
}

/**
 * @param list<string> $argv
 * @return array{contract: string, json: bool, quiet: bool}|null
 */
function preflightOptions(array $argv): ?array
{
    $options = ['contract' => '', 'json' => false, 'quiet' => false];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--json') {
            $options['json'] = true;
        } elseif ($arg === '--quiet') {
            $options['quiet'] = true;
        } elseif (str_starts_with($arg, '--contract=')) {
            $options['contract'] = substr($arg, strlen('--contract='));
        } elseif ($arg === '--help' || $arg === '-h') {
            preflightUsage();
            exit(PREFLIGHT_OK);
        } else {
            fwrite(STDERR, "ai-authority-preflight: unrecognised argument: {$arg}\n");
            return null;
        }
    }
    if ($options['contract'] === '') {
        fwrite(STDERR, "ai-authority-preflight: --contract is required\n");
        return null;
    }
    return $options;
}

/**
 * @param list<string> $argv
 * @return array{code: int, output: string}
 */
function preflightRun(array $argv, string $cwd): array
{
    $process = @proc_open(
        $argv,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        return ['code' => 127, 'output' => 'proc_open failed'];
    }
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'output' => trim($out . $err)];
}

$options = preflightOptions($argv);
if ($options === null) {
    preflightUsage();
    exit(PREFLIGHT_USAGE);
}

$root = dirname(__DIR__);
$contract = $options['contract'];

if (!is_file($root . '/' . $contract) && !is_file($contract)) {
    fwrite(STDERR, "ai-authority-preflight: contract not found: {$contract}\n");
    exit(PREFLIGHT_USAGE);
}

// The parser is the source of truth for scope, so it is asked rather than re-implemented.
$plan = preflightRun([PHP_BINARY, $root . '/tools/ai-autonomy.php', 'plan', '--json', '--contract=' . $contract], $root);
$envelope = null;
$decoded = json_decode($plan['output'], true);
if (is_array($decoded) && is_array($decoded['envelope'] ?? null)) {
    $envelope = $decoded['envelope'];
}
if ($envelope === null) {
    fwrite(STDERR, "ai-authority-preflight: contract does not parse (exit {$plan['code']}): {$contract}\n");
    fwrite(STDERR, '  ' . substr($plan['output'], 0, 400) . "\n");
    exit(PREFLIGHT_USAGE);
}

/** @var list<array{path: string, kind: string}> $allowed */
$allowed = [];
foreach ((array) ($envelope['allowed_scope'] ?? []) as $entry) {
    if (is_array($entry) && isset($entry['path'])) {
        $allowed[] = ['path' => (string) $entry['path'], 'kind' => (string) ($entry['kind'] ?? 'file')];
    }
}

$dispatchAt = date(DATE_ATOM);
$rows = [];
$willBlock = 0;

foreach ($allowed as $entry) {
    $path = $entry['path'];
    // Mirrors finish: a path already changed at dispatch, or present at HEAD, "existed".
    $exists = is_file($root . '/' . $path) || is_dir($root . '/' . $path);

    $argv2 = [
        PHP_BINARY,
        $root . '/tools/ai-autonomy.php',
        'check',
        'run changed path',
        '--path=' . $path,
        '--contract=' . $contract,
        '--dispatch-at=' . $dispatchAt,
        '--existed-at-dispatch=' . ($exists ? '1' : '0'),
        '--json',
    ];
    $result = preflightRun($argv2, $root);
    $payload = json_decode($result['output'], true);

    $verdict = is_array($payload) ? (string) ($payload['verdict'] ?? 'UNKNOWN') : 'UNKNOWN';
    $level = is_array($payload) ? (string) ($payload['authority_level'] ?? '?') : '?';
    $reasons = is_array($payload) && is_array($payload['reasons'] ?? null)
        ? array_values(array_map('strval', $payload['reasons']))
        : [substr($result['output'], 0, 200)];

    $blocks = $verdict === 'ESCALATE';
    if ($blocks) {
        $willBlock++;
    }

    $rows[] = ['path' => $path, 'kind' => $entry['kind'], 'verdict' => $verdict, 'level' => $level, 'reasons' => $reasons];
}

if ($options['json']) {
    fwrite(STDOUT, json_encode([
        'contract' => $contract,
        'checked_at' => $dispatchAt,
        'allowed_paths' => count($rows),
        'will_block' => $willBlock,
        'rows' => $rows,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    exit($willBlock > 0 ? PREFLIGHT_WILL_BLOCK : PREFLIGHT_OK);
}

if (!$options['quiet'] || $willBlock > 0) {
    fwrite(STDOUT, "authority pre-flight — {$contract}\n");
    fwrite(STDOUT, '  allowed paths: ' . count($rows) . "\n");
    foreach ($rows as $row) {
        if ($row['verdict'] === 'ESCALATE') {
            fwrite(STDOUT, '  BLOCK  ' . $row['path'] . "  [{$row['level']}]\n");
            foreach ($row['reasons'] as $reason) {
                fwrite(STDOUT, '           - ' . $reason . "\n");
            }
        } elseif (!$options['quiet']) {
            fwrite(STDOUT, '  ok     ' . $row['path'] . "  ({$row['verdict']})\n");
        }
    }
}

if ($willBlock > 0) {
    fwrite(STDOUT, "\n  {$willBlock} allowed path(s) will escalate — this contract will block at finish.\n");
    fwrite(STDOUT, "  Obtain the director decision BEFORE dispatch: an authorisation dated after dispatch is inert.\n");
    exit(PREFLIGHT_WILL_BLOCK);
}

if (!$options['quiet']) {
    fwrite(STDOUT, "  no allowed path escalates\n");
}
exit(PREFLIGHT_OK);
