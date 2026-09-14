#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Contract-corpus conformance lint.
 *
 * Read-only. For every `.ai/*.contract.md` it reports whether the kernel
 * parser accepts the contract, whether it carries a `harness:` reference
 * block, whether any forbidden-scope entry looks like prose masquerading as
 * a path, and its declared status/class.
 *
 * Parsing is delegated to `tools/ai-autonomy.php plan --json`; the kernel
 * parser is never re-implemented here. The only local parsing is the
 * phantom heuristic, which by design reads the driver's `forbidden_scope`.
 *
 * Exit codes:
 *   0  every `live` contract parses and has no phantoms
 *   3  a `live` contract fails to parse and/or has phantoms
 *   2  usage error
 */

const EXIT_OK = 0;
const EXIT_USAGE = 2;
const EXIT_LIVE_FAILURE = 3;

const LIVE_CLASSES = ['READY_FOR_IMPLEMENTATION', 'QUEUED', 'BLOCKING', 'READY_FOR_MEASUREMENT'];
const STALE_CLASSES = ['DONE', 'SHIPPED', 'CLOSED', 'COMPLETE', 'SUPERSEDED', 'ADOPTED'];
const STANDING_CONTRACT = '.ai/ai-autonomy-harness.contract.md';

/** @return string absolute repository root */
function repoRoot(): string
{
    $root = realpath(dirname(__DIR__));
    return $root === false ? dirname(__DIR__) : $root;
}

/**
 * Run the driver's `plan --json` for one contract.
 *
 * @return array{code:int,forbidden:list<array{path:string,kind:string}>}
 */
function planContract(string $root, string $relativeContract): array
{
    $command = [
        PHP_BINARY,
        $root . '/tools/ai-autonomy.php',
        'plan',
        '--json',
        '--contract=' . $relativeContract,
    ];

    $pipes = [];
    $process = @proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        null,
        ['bypass_shell' => true]
    );

    if (!is_resource($process)) {
        return ['code' => 127, 'forbidden' => []];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    // Drain stderr so a verbose driver cannot deadlock on a full pipe. The
    // content is discarded: the deliverable says to use the exit code.
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    $forbidden = [];
    if ($stdout !== false && trim($stdout) !== '') {
        $decoded = json_decode($stdout, true);
        if (is_array($decoded)) {
            $entries = $decoded['envelope']['forbidden_scope'] ?? null;
            if (is_array($entries)) {
                foreach ($entries as $entry) {
                    if (is_array($entry) && isset($entry['path'])) {
                        $forbidden[] = ['path' => (string) $entry['path'], 'kind' => (string) ($entry['kind'] ?? 'file')];
                    }
                }
            }
        }
    }

    return ['code' => $code, 'forbidden' => $forbidden];
}

/**
 * Whether the markdown carries a `harness:` block referencing the standing
 * contract.
 */
function hasHarnessReference(string $markdown): bool
{
    $lines = preg_split('/\r?\n/', $markdown) ?: [];
    $inBlock = false;

    foreach ($lines as $line) {
        if (preg_match('/^[ \t]*harness:\s*$/', $line) === 1) {
            $inBlock = true;
            continue;
        }
        if ($inBlock) {
            if (preg_match('/^[ \t]+references:\s*(.+?)\s*$/', $line, $m) === 1
                && str_contains($m[1], STANDING_CONTRACT)) {
                return true;
            }
            // A line with no leading whitespace ends the block.
            if (preg_match('/^\S/', $line) === 1) {
                $inBlock = false;
            }
        }
    }

    return false;
}

/**
 * Fallback phantom detection for contracts the driver rejects: read the raw
 * `## Forbidden changes` section and apply the same prose-as-path signature.
 *
 * @return list<string>
 */
function rawForbiddenTokens(string $markdown): array
{
    $lines = preg_split('/\r?\n/', $markdown) ?: [];
    $inSection = false;
    $tokens = [];

    foreach ($lines as $line) {
        if (preg_match('/^#{1,3}\s+Forbidden changes\s*$/i', $line) === 1) {
            $inSection = true;
            continue;
        }
        if ($inSection && preg_match('/^#{1,3}\s+/', $line) === 1) {
            break;
        }
        if (!$inSection) {
            continue;
        }
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '```')) {
            continue;
        }
        $token = '';
        if (preg_match('/`([^`]+)`/', $trimmed, $m) === 1) {
            $token = $m[1];
        } else {
            $parts = preg_split('/\s+/', $trimmed);
            $token = $parts[0] ?? '';
        }
        $token = trim($token, " \t\n\r\0\x0B,;:-");
        if ($token !== '') {
            $tokens[] = $token;
        }
    }

    return $tokens;
}

/** A "single bare word with no `/` or `.`" is the prose-as-path signature. */
function isPhantom(string $token): bool
{
    return preg_match('/^[A-Za-z0-9_-]+$/', $token) === 1;
}

/**
 * @return array{value:string|null,placeholder:bool}
 */
function extractStatus(string $markdown): array
{
    foreach (preg_split('/\r?\n/', $markdown) ?: [] as $line) {
        if (preg_match('/^status:\s*(.*?)\s*$/', $line, $m) === 1) {
            $value = trim($m[1]);
            return [
                'value' => $value,
                'placeholder' => str_contains($value, 'PASS|FAIL|PARTIAL|BLOCKED'),
            ];
        }
    }

    return ['value' => null, 'placeholder' => false];
}

function classify(?string $status, bool $placeholder): string
{
    if ($status === null || $placeholder) {
        return 'unknown';
    }
    $head = strtoupper(strtok($status, " \t") ?: $status);
    if (in_array($head, LIVE_CLASSES, true)) {
        return 'live';
    }
    if (in_array($head, STALE_CLASSES, true)) {
        return 'stale';
    }

    return 'unknown';
}

/**
 * @return list<array<string,mixed>>
 */
function collectContracts(string $root): array
{
    $files = glob($root . '/.ai/*.contract.md') ?: [];
    sort($files);

    $contracts = [];
    foreach ($files as $file) {
        $relative = '.ai/' . basename($file);
        $markdown = (string) @file_get_contents($file);

        $plan = planContract($root, $relative);
        $parseOk = $plan['code'] === 0;

        if ($parseOk) {
            $tokens = array_map(static fn (array $e): string => $e['path'], $plan['forbidden']);
        } else {
            $tokens = rawForbiddenTokens($markdown);
        }

        $phantoms = array_values(array_unique(array_filter($tokens, 'isPhantom')));

        $status = extractStatus($markdown);

        $contracts[] = [
            'name' => basename($file, '.contract.md'),
            'file' => $relative,
            'parse' => $parseOk,
            'parse_exit' => $plan['code'],
            'harness_ref' => hasHarnessReference($markdown),
            'phantoms' => count($phantoms),
            'phantom_entries' => $phantoms,
            'status' => $status['value'],
            'status_label' => $status['value'] === null ? 'none' : ($status['placeholder'] ? 'PLACEHOLDER' : $status['value']),
            'placeholder' => $status['placeholder'],
            'class' => classify($status['value'], $status['placeholder']),
        ];
    }

    return $contracts;
}

/**
 * @param list<array<string,mixed>> $contracts
 * @return array<string,mixed>
 */
function summarize(array $contracts): array
{
    $perClass = ['live' => 0, 'stale' => 0, 'unknown' => 0];
    $liveParseFailures = 0;
    $liveWithPhantoms = 0;
    $withPhantoms = 0;
    $missingStatus = 0;

    foreach ($contracts as $c) {
        $perClass[$c['class']]++;
        if ($c['phantoms'] > 0) {
            $withPhantoms++;
        }
        if ($c['status'] === null) {
            $missingStatus++;
        }
        if ($c['class'] === 'live') {
            if (!$c['parse']) {
                $liveParseFailures++;
            }
            if ($c['phantoms'] > 0) {
                $liveWithPhantoms++;
            }
        }
    }

    return [
        'total' => count($contracts),
        'live' => $perClass['live'],
        'stale' => $perClass['stale'],
        'unknown' => $perClass['unknown'],
        'live_parse_failures' => $liveParseFailures,
        'live_with_phantoms' => $liveWithPhantoms,
        'with_phantoms' => $withPhantoms,
        'missing_status' => $missingStatus,
    ];
}

function usageError(string $message): int
{
    fwrite(STDERR, "ERROR: {$message}\n");
    fwrite(STDERR, "usage: php tools/ai-contract-lint.php [--json] [--live-only]\n");
    return EXIT_USAGE;
}

/**
 * @param list<array<string,mixed>> $contracts
 */
function printTable(array $contracts): void
{
    foreach ($contracts as $c) {
        $phantomSuffix = '';
        if ($c['phantoms'] > 0) {
            $phantomSuffix = ' [' . implode(', ', $c['phantom_entries']) . ']';
        }
        printf(
            "%-52s parse=%-4s harness_ref=%-3s phantoms=%-2d status=%-40s class=%s%s\n",
            $c['name'],
            $c['parse'] ? 'ok' : 'FAIL',
            $c['harness_ref'] ? 'yes' : 'no',
            $c['phantoms'],
            (string) $c['status_label'],
            $c['class'],
            $phantomSuffix
        );
    }

    $summary = summarize($contracts);
    printf(
        "\nSUMMARY total=%d live=%d stale=%d unknown=%d live_parse_failures=%d live_with_phantoms=%d with_phantoms=%d missing_status=%d\n",
        $summary['total'],
        $summary['live'],
        $summary['stale'],
        $summary['unknown'],
        $summary['live_parse_failures'],
        $summary['live_with_phantoms'],
        $summary['with_phantoms'],
        $summary['missing_status']
    );
}

function main(): int
{
    $args = array_slice($_SERVER['argv'], 1);
    $json = false;
    $liveOnly = false;

    foreach ($args as $arg) {
        if ($arg === '--json') {
            $json = true;
        } elseif ($arg === '--live-only') {
            $liveOnly = true;
        } else {
            return usageError("unknown argument '{$arg}'");
        }
    }

    $root = repoRoot();
    $contracts = collectContracts($root);
    $summary = summarize($contracts);

    if ($liveOnly) {
        $contracts = array_values(array_filter($contracts, static fn (array $c): bool => $c['class'] === 'live'));
    }

    if ($json) {
        fwrite(STDOUT, json_encode([
            'contracts' => $contracts,
            'summary' => $summary,
            'live_only' => $liveOnly,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    } else {
        printTable($contracts);
    }

    return ($summary['live_parse_failures'] === 0 && $summary['live_with_phantoms'] === 0)
        ? EXIT_OK
        : EXIT_LIVE_FAILURE;
}

exit(main());
