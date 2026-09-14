#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Project accounting for the autonomy harness. Project markdown is the source of
 * slice order and acceptance obligations; state.json records only transitions.
 */
const PROJECT_OK = 0;
const PROJECT_USAGE = 2;
const PROJECT_BLOCKED = 3;

/** Print usage. */
function projectUsage(): void
{
    fwrite(STDOUT, <<<'TXT'
Usage:
  php tools/ai-project.php status [--project=ID] [--projects-dir=DIR] [--json]
  php tools/ai-project.php next --project=ID [--projects-dir=DIR] [--json]
  php tools/ai-project.php obligations --project=ID [--projects-dir=DIR] [--json]
  php tools/ai-project.php transition --project=ID --slice=ID --state=running|done|blocked
       [--run=ID] [--reason=TEXT] [--projects-dir=DIR] [--runs-dir=DIR] [--json]

Exit codes: 0 ok; 2 malformed project/input; 3 no eligible slice or refused transition.
A done transition is accepted only when the named completed ledger run contains
one or more claims and every claim is RE_DERIVED.
TXT
    . "\n");
}

/**
 * @param list<string> $args
 * @return array{options:array<string,string>,flags:array<string,bool>}
 */
function projectArgs(array $args): array
{
    $options = [];
    $flags = [];
    foreach ($args as $arg) {
        if ($arg === '--json' || $arg === '--help') {
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
    return ['options' => $options, 'flags' => $flags];
}

/** @param array<string,string> $options */
function projectOption(array $options, string $key, ?string $default = null): ?string
{
    return $options[$key] ?? $default;
}

function safeProjectId(?string $id): string
{
    if ($id === null || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $id) !== 1) {
        throw new InvalidArgumentException("invalid or missing --project '{$id}'");
    }
    return $id;
}

/** @return list<string> */
function markdownBullets(string $markdown, string $heading): array
{
    $inside = false;
    $result = [];
    foreach (preg_split('/\r?\n/', $markdown) ?: [] as $line) {
        if (preg_match('/^##\s+' . preg_quote($heading, '/') . '\s*$/i', trim($line)) === 1) {
            $inside = true;
            continue;
        }
        if ($inside && preg_match('/^##\s+/', trim($line)) === 1) {
            break;
        }
        if ($inside && preg_match('/^\s*(?:[-*]|\d+\.)\s+(.+)/', $line, $m) === 1) {
            $result[] = trim($m[1]);
        }
    }
    return $result;
}

/** @return list<string> */
function idsDeclaredBySlice(string $markdown, string $filename): array
{
    $prefix = implode("\n", array_slice(preg_split('/\r?\n/', $markdown) ?: [], 0, 12));
    preg_match_all('/\bS\d+\b/i', $prefix, $matches);
    $ids = array_values(array_unique(array_map('strtoupper', $matches[0])));
    if ($ids === [] && preg_match('/s(\d+)/i', $filename, $m) === 1) {
        $ids[] = 'S' . $m[1];
    }
    return $ids;
}

/**
 * @return array{id:string,path:string,project_file:string,state_file:string,slices:list<array<string,mixed>>,states:array<string,array<string,mixed>>}
 */
function loadProject(string $projectsDir, string $id): array
{
    $dir = rtrim($projectsDir, '/') . '/' . $id;
    $projectFile = $dir . '/project.md';
    $markdown = @file_get_contents($projectFile);
    if ($markdown === false) {
        throw new InvalidArgumentException("project '{$id}' has no readable project.md at {$projectFile}");
    }

    $contracts = [];
    foreach (glob($dir . '/slices/*.md') ?: [] as $file) {
        $body = @file_get_contents($file);
        if ($body === false) {
            throw new InvalidArgumentException("slice contract '{$file}' cannot be read");
        }
        foreach (idsDeclaredBySlice($body, basename($file)) as $sliceId) {
            if (isset($contracts[$sliceId]) && $contracts[$sliceId]['path'] !== $file) {
                throw new InvalidArgumentException("slice {$sliceId} is declared by more than one contract");
            }
            $contracts[$sliceId] = [
                'path' => $file,
                'acceptance_count' => max(1, count(markdownBullets($body, 'Acceptance criteria'))),
            ];
        }
    }

    $slices = [];
    foreach (preg_split('/\r?\n/', $markdown) ?: [] as $line) {
        if (!str_starts_with(trim($line), '|')) {
            continue;
        }
        $cells = array_map('trim', explode('|', trim($line, " \t|")));
        if (count($cells) < 2 || preg_match('/\bS\d+\b/i', $cells[0], $m) !== 1) {
            continue;
        }
        $sliceId = strtoupper($m[0]);
        if (str_contains(strtoupper($line), 'WITHDRAWN') || str_contains($cells[0], '~~')) {
            continue;
        }
        $contract = $contracts[$sliceId] ?? null;
        $slices[] = [
            'id' => $sliceId,
            'name' => trim(str_replace(['**', '~~'], '', $cells[1])),
            'contract' => is_array($contract) ? $contract['path'] : null,
            'acceptance_count' => is_array($contract) ? $contract['acceptance_count'] : 1,
        ];
    }
    if ($slices === []) {
        throw new InvalidArgumentException("project '{$id}' has no active rows in its Slices table");
    }

    $stateFile = $dir . '/state.json';
    $states = [];
    if (is_file($stateFile)) {
        $decoded = json_decode((string) @file_get_contents($stateFile), true);
        if (!is_array($decoded) || !is_array($decoded['slices'] ?? null)) {
            throw new InvalidArgumentException("project state '{$stateFile}' is malformed");
        }
        foreach ($decoded['slices'] as $sliceId => $state) {
            if (is_string($sliceId) && is_array($state)) {
                $states[$sliceId] = $state;
            }
        }
    }
    foreach ($slices as &$slice) {
        $sliceId = (string) $slice['id'];
        $recorded = $states[$sliceId] ?? ['state' => 'pending', 'run_id' => null];
        $state = $recorded['state'] ?? null;
        if (!in_array($state, ['pending', 'running', 'done', 'blocked'], true)) {
            throw new InvalidArgumentException("slice {$sliceId} has invalid state");
        }
        $slice['state'] = $state;
        $slice['run_id'] = $recorded['run_id'] ?? null;
        $slice['reason'] = $recorded['reason'] ?? null;
    }
    unset($slice);

    return ['id' => $id, 'path' => $dir, 'project_file' => $projectFile, 'state_file' => $stateFile, 'slices' => $slices, 'states' => $states];
}

/** @param array<string,mixed> $project */
function remainingObligations(array $project): int
{
    $remaining = 0;
    foreach ($project['slices'] as $slice) {
        if (($slice['state'] ?? null) !== 'done') {
            $remaining += (int) ($slice['acceptance_count'] ?? 1);
        }
    }
    return $remaining;
}

/**
 * @param array<string,mixed> $project
 * @return array{slice:array<string,mixed>|null,reason:string|null}
 */
function eligibleSlice(array $project): array
{
    foreach ($project['slices'] as $slice) {
        $state = (string) ($slice['state'] ?? 'pending');
        if ($state === 'done') {
            continue;
        }
        if ($state === 'blocked') {
            return ['slice' => null, 'reason' => "slice {$slice['id']} is blocked: " . ($slice['reason'] ?? 'no reason recorded')];
        }
        if ($state === 'running') {
            return ['slice' => null, 'reason' => "slice {$slice['id']} is already running under run " . ($slice['run_id'] ?? 'unknown')];
        }
        if (!is_string($slice['contract'] ?? null)) {
            return ['slice' => null, 'reason' => "slice {$slice['id']} has no contract under slices/"];
        }
        return ['slice' => $slice, 'reason' => null];
    }
    return ['slice' => null, 'reason' => 'project has no remaining slices'];
}

/** @param array<string,mixed> $value */
function writeProjectJson(string $path, array $value): void
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $temporary = $path . '.tmp.' . getmypid();
    if (@file_put_contents($temporary, $json) === false || !@rename($temporary, $path)) {
        @unlink($temporary);
        throw new InvalidArgumentException("cannot write project state '{$path}'");
    }
}

/** Assert independently persisted ledger evidence before a done transition. */
function assertRunReDerived(string $runsDir, string $runId): void
{
    $path = rtrim($runsDir, '/') . '/' . $runId . '.json';
    $record = json_decode((string) @file_get_contents($path), true);
    if (!is_array($record) || ($record['status'] ?? null) !== 'completed') {
        throw new RuntimeException("done refused: run {$runId} is missing or not completed");
    }
    $results = $record['claim_verification']['results'] ?? null;
    if (!is_array($results) || $results === []) {
        throw new RuntimeException("done refused: run {$runId} has no verified claims");
    }
    foreach ($results as $claim) {
        if (!is_array($claim) || ($claim['status'] ?? null) !== 'RE_DERIVED') {
            $status = is_array($claim) ? (string) ($claim['status'] ?? 'missing') : 'malformed';
            throw new RuntimeException("done refused: run {$runId} contains claim status {$status}");
        }
    }
}

/** @param array<string,mixed> $project */
function transitionProject(array $project, string $sliceId, string $target, ?string $runId, ?string $reason, string $runsDir): void
{
    $slice = null;
    foreach ($project['slices'] as $candidate) {
        if (($candidate['id'] ?? null) === $sliceId) {
            $slice = $candidate;
            break;
        }
    }
    if ($slice === null) {
        throw new InvalidArgumentException("unknown slice '{$sliceId}'");
    }
    $current = (string) $slice['state'];
    $allowed = $current === 'pending' ? ['running', 'blocked'] : ($current === 'running' ? ['done', 'blocked'] : []);
    if (!in_array($target, $allowed, true)) {
        throw new RuntimeException("transition refused: {$sliceId} {$current} -> {$target}");
    }
    if ($runId === null || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $runId) !== 1) {
        throw new InvalidArgumentException("transition {$target} requires a safe --run id");
    }
    if ($target === 'done') {
        assertRunReDerived($runsDir, $runId);
    }
    if ($target === 'blocked') {
        if ($reason === null || trim($reason) === '') {
            throw new InvalidArgumentException('blocked transition requires --reason');
        }
        $runPath = rtrim($runsDir, '/') . '/' . $runId . '.json';
        $run = json_decode((string) @file_get_contents($runPath), true);
        if (!is_array($run) || ($run['id'] ?? null) !== $runId) {
            throw new RuntimeException("blocked transition refused: run {$runId} has no ledger record");
        }
    }
    $states = $project['states'];
    $history = is_array($states[$sliceId]['history'] ?? null) ? $states[$sliceId]['history'] : [];
    $history[] = [
        'from' => $current,
        'to' => $target,
        'run_id' => $runId,
        'reason' => $reason,
        'at' => date(DATE_ATOM),
    ];
    $states[$sliceId] = [
        'state' => $target,
        'run_id' => $runId,
        'reason' => $reason,
        'updated_at' => date(DATE_ATOM),
        'history' => $history,
    ];
    writeProjectJson((string) $project['state_file'], ['schema' => 'ark.ai-project-state.v1', 'project' => $project['id'], 'slices' => $states]);
}

function projectMain(): int
{
    $args = array_slice($_SERVER['argv'], 1);
    if ($args === [] || in_array('--help', $args, true)) {
        projectUsage();
        return PROJECT_OK;
    }
    $command = array_shift($args);
    if (!in_array($command, ['status', 'next', 'obligations', 'transition'], true)) {
        throw new InvalidArgumentException("unknown command '{$command}'");
    }
    $parsed = projectArgs($args);
    $allowed = ['project', 'projects-dir', 'runs-dir'];
    if ($command === 'transition') {
        $allowed = array_merge($allowed, ['slice', 'state', 'run', 'reason']);
    }
    foreach (array_keys($parsed['options']) as $key) {
        if (!in_array($key, $allowed, true)) {
            throw new InvalidArgumentException("unknown option --{$key}");
        }
    }
    $projectsDir = projectOption($parsed['options'], 'projects-dir', '.ai/projects') ?? '.ai/projects';
    $json = isset($parsed['flags']['json']);

    if ($command === 'status' && !isset($parsed['options']['project'])) {
        $projects = [];
        foreach (glob(rtrim($projectsDir, '/') . '/*/project.md') ?: [] as $file) {
            $projects[] = loadProject($projectsDir, basename(dirname($file)));
        }
        if ($json) {
            fwrite(STDOUT, json_encode(['projects' => $projects], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        } else {
            foreach ($projects as $project) {
                fwrite(STDOUT, "PROJECT {$project['id']} remaining=" . remainingObligations($project) . "\n");
                foreach ($project['slices'] as $slice) {
                    fwrite(STDOUT, "  {$slice['id']} {$slice['state']} obligations={$slice['acceptance_count']} run=" . ($slice['run_id'] ?? '-') . "\n");
                }
            }
        }
        return PROJECT_OK;
    }

    $id = safeProjectId(projectOption($parsed['options'], 'project'));
    $project = loadProject($projectsDir, $id);
    if ($command === 'obligations') {
        $remaining = remainingObligations($project);
        fwrite(STDOUT, $json ? json_encode(['project' => $id, 'remaining' => $remaining], JSON_THROW_ON_ERROR) . "\n" : $remaining . "\n");
        return PROJECT_OK;
    }
    if ($command === 'status') {
        if ($json) {
            fwrite(STDOUT, json_encode(['project' => $project, 'remaining' => remainingObligations($project)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        } else {
            fwrite(STDOUT, "PROJECT {$id} remaining=" . remainingObligations($project) . "\n");
            foreach ($project['slices'] as $slice) {
                fwrite(STDOUT, "  {$slice['id']} {$slice['state']} obligations={$slice['acceptance_count']} run=" . ($slice['run_id'] ?? '-') . "\n");
            }
        }
        return PROJECT_OK;
    }
    if ($command === 'next') {
        $next = eligibleSlice($project);
        if ($json) {
            fwrite(STDOUT, json_encode(['project' => $id, 'eligible' => $next['slice'] !== null, 'slice' => $next['slice'], 'reason' => $next['reason']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        } elseif ($next['slice'] !== null) {
            fwrite(STDOUT, "NEXT {$next['slice']['id']} {$next['slice']['contract']}\n");
        } else {
            fwrite(STDOUT, "NO ELIGIBLE SLICE — {$next['reason']}\n");
        }
        return $next['slice'] !== null ? PROJECT_OK : PROJECT_BLOCKED;
    }

    $sliceId = strtoupper(projectOption($parsed['options'], 'slice') ?? '');
    $target = projectOption($parsed['options'], 'state') ?? '';
    if (!in_array($target, ['running', 'done', 'blocked'], true)) {
        throw new InvalidArgumentException("invalid --state '{$target}'");
    }
    transitionProject(
        $project,
        $sliceId,
        $target,
        projectOption($parsed['options'], 'run'),
        projectOption($parsed['options'], 'reason'),
        projectOption($parsed['options'], 'runs-dir', '.ai/runs') ?? '.ai/runs'
    );
    fwrite(STDOUT, $json ? json_encode(['project' => $id, 'slice' => $sliceId, 'state' => $target], JSON_THROW_ON_ERROR) . "\n" : "SLICE {$sliceId} -> {$target}\n");
    return PROJECT_OK;
}

try {
    exit(projectMain());
} catch (RuntimeException $e) {
    fwrite(STDERR, 'REFUSED: ' . $e->getMessage() . "\n");
    exit(PROJECT_BLOCKED);
} catch (InvalidArgumentException|JsonException $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(PROJECT_USAGE);
}
