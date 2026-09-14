#!/usr/bin/env php
<?php

declare(strict_types=1);

use Ikabud\Kernel\Workbench\Development\DevelopmentTaskContract;

require_once dirname(__DIR__) . '/kernel/Workbench/Development/DevelopmentTaskContract.php';

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
  php tools/ai-project.php retry --project=ID --slice=ID --reason=TEXT
       [--projects-dir=DIR] [--json]
  php tools/ai-project.php metrics --project=ID [--chair-decisions=PATH]
       [--projects-dir=DIR] [--runs-dir=DIR] [--json] [--write]

Exit codes: 0 ok; 2 malformed project/input; 3 no eligible slice or refused transition.

retry is the recorded way out of `blocked`: it moves a blocked slice back to
`pending`, records the retry reason, and preserves the prior run id and the full
history (why it blocked is evidence, not debris). It refuses unless the slice is
blocked and a reason is given.

metrics derives the project's numbers from artefacts only (run records, the slice
table, chair-decisions headings, chair-errors.json, director-minutes.json). Any
metric that cannot be derived is null with a reason in the `unavailable` map; it is
never estimated. --write persists .ai/projects/<id>/metrics.json.
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
        if ($arg === '--json' || $arg === '--help' || $arg === '--write') {
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

/** The current git HEAD, or null when git cannot answer. */
function currentGitRev(): ?string
{
    $pipes = [];
    $process = proc_open(['git', 'rev-parse', 'HEAD'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    if (!is_resource($process)) { return null; }
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $rev = trim((string) $out);
    return preg_match('/^[0-9a-f]{40}$/', $rev) === 1 ? $rev : null;
}

/**
 * Assert independently persisted ledger evidence before a done transition.
 *
 * Binding, not merely well-formedness (mirrors harpp-bridge/harpp_wake.py:_stage_result_matches(),
 * which already pins workflow_id, stage_name and schema_version):
 *   - the record's own `id` must equal the run asked for, so a sibling file cannot be substituted;
 *   - the contract revision recorded at `start` must equal the slice contract's current revision, so
 *     evidence produced against a different contract cannot complete this slice;
 *   - the verification record's contract revision (when present) must agree with the start record;
 *   - the recorded `rev` must equal current HEAD; a dirty tree additionally requires the run's
 *     passing dispatch-baseline scope record, so concurrent pre-existing work does not false-block
 *     while an unaccounted dirty tree is still refused.
 *
 * A missing binding field is a refusal: it cannot be shown to describe this run, this contract and
 * this tree.
 */
function assertRunReDerived(string $runsDir, string $runId, ?string $expectedContractRevision = null): void
{
    $path = rtrim($runsDir, '/') . '/' . $runId . '.json';
    $record = json_decode((string) @file_get_contents($path), true);
    if (!is_array($record) || ($record['id'] ?? null) !== $runId || ($record['status'] ?? null) !== 'completed') {
        throw new RuntimeException("done refused: run {$runId} is missing, foreign, or not completed");
    }
    $recordedContract = $record['contract_revision'] ?? null;
    if (!is_string($recordedContract) || $recordedContract === '') {
        throw new RuntimeException("done refused: run {$runId} records no contract revision to bind");
    }
    if ($expectedContractRevision === null || $recordedContract !== $expectedContractRevision) {
        throw new RuntimeException("done refused: run {$runId} was started against contract revision " . $recordedContract . ', expected ' . ($expectedContractRevision ?? 'an unreadable slice contract'));
    }
    $verification = is_array($record['claim_verification'] ?? null) ? $record['claim_verification'] : [];
    $verifiedContract = $verification['contract_revision'] ?? null;
    if ($verifiedContract !== null && $verifiedContract !== $recordedContract) {
        throw new RuntimeException("done refused: run {$runId} verification binds contract revision " . (is_string($verifiedContract) ? $verifiedContract : 'invalid') . " but the run started at {$recordedContract}");
    }
    $recordedRev = $verification['rev'] ?? null;
    $currentRev = currentGitRev();
    if (!is_string($recordedRev) || $recordedRev === '' || $currentRev === null || $recordedRev !== $currentRev) {
        throw new RuntimeException("done refused: run {$runId} records rev " . (is_string($recordedRev) && $recordedRev !== '' ? $recordedRev : 'missing') . ', current HEAD is ' . ($currentRev ?? 'unavailable') . '; the evidence does not describe this tree');
    }
    if (($verification['dirty'] ?? null) !== false) {
        $scopeConformance = is_array($record['scope_conformance'] ?? null) ? $record['scope_conformance'] : [];
        if (($scopeConformance['ok'] ?? false) !== true) {
            throw new RuntimeException("done refused: run {$runId} records a dirty tree without a passing dispatch-baseline scope-conformance record");
        }
    }
    $results = $verification['results'] ?? null;
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
    $allowed = $current === 'pending' ? ['running', 'blocked'] : ($current === 'running' ? ['running', 'done', 'blocked'] : []);
    if (!in_array($target, $allowed, true)) {
        throw new RuntimeException("transition refused: {$sliceId} {$current} -> {$target}");
    }
    if ($runId === null || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $runId) !== 1) {
        throw new InvalidArgumentException("transition {$target} requires a safe --run id");
    }
    if ($target === 'done') {
        $expectedContractRevision = null;
        $contractPath = $slice['contract'] ?? null;
        if (is_string($contractPath) && is_file($contractPath)) {
            $markdown = @file_get_contents($contractPath);
            if ($markdown !== false && $markdown !== '') {
                try {
                    $expectedContractRevision = DevelopmentTaskContract::revisionId(DevelopmentTaskContract::parseCurrentTaskMarkdown($markdown));
                } catch (InvalidArgumentException) {
                    $expectedContractRevision = null;
                }
            }
        }
        assertRunReDerived($runsDir, $runId, $expectedContractRevision);
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

/** @param array<string,mixed> $project */
function retrySlice(array $project, string $sliceId, ?string $reason): void
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
    if ($current !== 'blocked') {
        throw new RuntimeException("retry refused: {$sliceId} is {$current}, not blocked");
    }
    if ($reason === null || trim($reason) === '') {
        throw new InvalidArgumentException('retry requires --reason');
    }

    $states = $project['states'];
    // The prior run id is preserved: the evidence of why it blocked is history, not debris.
    $priorRunId = is_string($slice['run_id'] ?? null) ? $slice['run_id'] : null;
    $history = is_array($states[$sliceId]['history'] ?? null) ? $states[$sliceId]['history'] : [];
    $history[] = [
        'from' => 'blocked',
        'to' => 'pending',
        'run_id' => $priorRunId,
        'reason' => $reason,
        'at' => date(DATE_ATOM),
    ];
    $states[$sliceId] = [
        'state' => 'pending',
        'run_id' => $priorRunId,
        'reason' => $reason,
        'updated_at' => date(DATE_ATOM),
        'history' => $history,
    ];
    writeProjectJson((string) $project['state_file'], ['schema' => 'ark.ai-project-state.v1', 'project' => $project['id'], 'slices' => $states]);
}

/**
 * Read every run record under $runsDir, keyed by run id.
 *
 * @return array<string,array<string,mixed>>
 */
function loadRunRecords(string $runsDir): array
{
    $records = [];
    foreach (glob(rtrim($runsDir, '/') . '/*.json') ?: [] as $file) {
        $decoded = json_decode((string) @file_get_contents($file), true);
        if (!is_array($decoded)) {
            continue;
        }
        $id = is_string($decoded['id'] ?? null) ? $decoded['id'] : basename($file, '.json');
        $records[$id] = $decoded;
    }
    ksort($records);
    return $records;
}

/**
 * Map each declared contract path to the slice ids it covers.
 *
 * @param array<string,mixed> $project
 * @return array<string,list<string>>
 */
function projectSliceContracts(array $project): array
{
    $map = [];
    foreach ($project['slices'] as $slice) {
        $contract = $slice['contract'] ?? null;
        if (is_string($contract)) {
            $map[$contract][] = (string) $slice['id'];
        }
    }
    return $map;
}

/**
 * @param array<string,mixed> $record
 * @return array{0:int,1:int}|null
 */
function runSpan(array $record): ?array
{
    $start = $record['started_at'] ?? null;
    $finish = $record['finished_at'] ?? null;
    if (!is_string($start) || !is_string($finish)) {
        return null;
    }
    $from = strtotime($start);
    $to = strtotime($finish);
    if ($from === false || $to === false || $to < $from) {
        return null;
    }
    return [$from, $to];
}

/**
 * Derive the project metric table from artefacts only.
 *
 * @param array<string,mixed> $project
 * @return array{metrics:array<string,mixed>,unavailable:array<string,string>}
 */
function deriveMetrics(array $project, string $runsDir, string $chairDecisionsFile): array
{
    $reasons = [];
    $contracts = projectSliceContracts($project);

    /** @var array<string,array<string,mixed>> $matched */
    $matched = [];
    /** @var array<string,list<string>> $sliceRuns */
    $sliceRuns = [];
    foreach (loadRunRecords($runsDir) as $id => $record) {
        $contract = $record['contract'] ?? null;
        if (!is_string($contract) || !isset($contracts[$contract])) {
            continue;
        }
        $matched[$id] = $record;
        foreach ($contracts[$contract] as $sliceId) {
            $sliceRuns[$sliceId][] = $id;
        }
    }

    $dispatched = array_keys($sliceRuns);
    sort($dispatched);

    $statuses = ['completed' => 0, 'silent' => 0, 'failed' => 0, 'abandoned' => 0, 'blocked' => 0, 'running' => 0, 'other' => 0];
    $lanes = [];
    foreach ($matched as $record) {
        $status = is_string($record['status'] ?? null) ? $record['status'] : 'other';
        $statuses[isset($statuses[$status]) ? $status : 'other']++;
        $lane = is_string($record['lane'] ?? null) ? $record['lane'] : 'unknown';
        $lanes[$lane] = ($lanes[$lane] ?? 0) + 1;
    }
    ksort($lanes);

    $completedSlices = [];
    foreach ($sliceRuns as $sliceId => $ids) {
        foreach ($ids as $id) {
            if (($matched[$id]['status'] ?? null) === 'completed') {
                $completedSlices[$sliceId] = true;
                break;
            }
        }
    }
    $completedSlices = array_keys($completedSlices);
    sort($completedSlices);

    $bySlice = [];
    foreach ($project['slices'] as $slice) {
        $sliceId = (string) $slice['id'];
        $ids = $sliceRuns[$sliceId] ?? [];
        if ($ids === []) {
            continue;
        }
        $starts = [];
        $finishes = [];
        $incomplete = null;
        foreach ($ids as $id) {
            $span = runSpan($matched[$id]);
            if ($span === null) {
                $incomplete ??= $id;
                continue;
            }
            $starts[] = $span[0];
            $finishes[] = $span[1];
        }
        if ($incomplete !== null || $starts === []) {
            $bySlice[$sliceId] = null;
            $reasons['wall_clock_seconds_by_slice.' . $sliceId] =
                "run {$incomplete} for slice {$sliceId} has no complete started_at/finished_at pair";
            continue;
        }
        $bySlice[$sliceId] = max($finishes) - min($starts);
    }

    $total = null;
    if ($matched === []) {
        $reasons['wall_clock_seconds_total'] = 'no run records match this project';
    } else {
        $starts = [];
        $finishes = [];
        $incomplete = null;
        foreach ($matched as $id => $record) {
            $span = runSpan($record);
            if ($span === null) {
                $incomplete ??= $id;
                continue;
            }
            $starts[] = $span[0];
            $finishes[] = $span[1];
        }
        if ($incomplete !== null || $starts === []) {
            $reasons['wall_clock_seconds_total'] =
                "run {$incomplete} has no complete started_at/finished_at pair";
        } else {
            $total = max($finishes) - min($starts);
        }
    }

    $claimOutcomes = ['RE_DERIVED' => 0, 'CONTRADICTED' => 0, 'UNVERIFIED' => 0, 'other' => 0];
    foreach ($matched as $record) {
        $results = $record['claim_verification']['results'] ?? null;
        if (!is_array($results)) {
            continue;
        }
        foreach ($results as $result) {
            $status = is_array($result) && is_string($result['status'] ?? null) ? $result['status'] : 'other';
            $claimOutcomes[isset($claimOutcomes[$status]) ? $status : 'other']++;
        }
    }

    $tokens = null;
    $cost = null;
    $usageByRun = [];
    if ($matched === []) {
        $reasons['tokens_total'] = $reasons['cost_usd'] = 'no run records match this project';
    } else {
        $tokenSum = 0;
        $costSum = 0.0;
        $missing = null;
        foreach ($matched as $id => $record) {
            $usage = $record['usage'] ?? null;
            $binding = is_array($record['runner_session'] ?? null) ? $record['runner_session'] : null;
            $usageByRun[$id] = [
                'session_id' => is_array($binding) ? ($binding['session_id'] ?? null) : null,
                'usage' => is_array($usage) ? $usage : null,
                'unavailable_reason' => is_array($usage) ? null : (is_string($record['usage_unavailable_reason'] ?? null)
                    ? $record['usage_unavailable_reason'] : 'run record predates runner usage binding'),
            ];
            if (!is_array($usage) || !is_numeric($usage['total_tokens'] ?? null) || !is_numeric($usage['cost_usd'] ?? null)) {
                $missing ??= $id;
                continue;
            }
            $tokenSum += (int) $usage['total_tokens'];
            $costSum += (float) $usage['cost_usd'];
        }
        if ($missing !== null) {
            $reason = (string) ($usageByRun[$missing]['unavailable_reason'] ?? 'usage artefact unavailable');
            $reasons['tokens_total'] = $reasons['cost_usd'] = "run {$missing}: {$reason}; project totals are null, not estimated";
        } else {
            $tokens = $tokenSum;
            $cost = round($costSum, 6);
        }
    }

    $chairDecisions = null;
    $markdown = @file_get_contents($chairDecisionsFile);
    if ($markdown === false) {
        $reasons['chair_decisions'] = "chair decisions file '{$chairDecisionsFile}' is not readable";
    } else {
        $count = preg_match_all('/^##\s+CD-\d+/m', $markdown);
        $chairDecisions = $count === false ? null : $count;
        if ($chairDecisions === null) {
            $reasons['chair_decisions'] = "chair decisions file '{$chairDecisionsFile}' could not be counted";
        }
    }

    $chairErrors = null;
    $errorIds = [];
    $errorsPath = rtrim((string) $project['path'], '/') . '/chair-errors.json';
    $errorsJson = is_file($errorsPath) ? json_decode((string) @file_get_contents($errorsPath), true) : null;
    if (!is_array($errorsJson) || !is_array($errorsJson['errors'] ?? null)) {
        $reasons['chair_decisions_incorrect'] =
            "no readable chair-errors.json at {$errorsPath}; incorrect decisions are never estimated";
    } else {
        foreach ($errorsJson['errors'] as $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null)) {
                $errorIds[] = $entry['id'];
            }
        }
        $chairErrors = count($errorsJson['errors']);
    }

    $contractViolations = null;
    $scopeMissing = null;
    $violationCount = 0;
    foreach ($matched as $id => $record) {
        $scope = $record['scope_conformance'] ?? null;
        if (!is_array($scope) || !is_bool($scope['ok'] ?? null)) { $scopeMissing ??= $id; continue; }
        if ($scope['ok'] === false) { $violationCount++; }
    }
    if ($matched === []) {
        $reasons['contract_violations'] = 'no run records match this project';
    } elseif ($scopeMissing !== null) {
        $reasons['contract_violations'] = "run {$scopeMissing} predates changed-path scope conformance; the total is not derivable";
    } else {
        $contractViolations = $violationCount;
    }

    $minutes = null;
    $minutesPath = rtrim((string) $project['path'], '/') . '/director-minutes.json';
    $minutesJson = is_file($minutesPath) ? json_decode((string) @file_get_contents($minutesPath), true) : null;
    if (!is_array($minutesJson) || !is_array($minutesJson['entries'] ?? null) || $minutesJson['entries'] === []) {
        $reasons['director_minutes'] = is_file($minutesPath)
            ? 'director-minutes.json has no entries; the director has not logged any minutes (not zero)'
            : 'director-minutes.json is absent; the director has not logged any minutes (not zero)';
    } else {
        $byCategory = [];
        foreach ($minutesJson['entries'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $category = is_string($entry['category'] ?? null) ? $entry['category'] : 'uncategorised';
            $byCategory[$category] = ($byCategory[$category] ?? 0) + (int) ($entry['minutes'] ?? 0);
        }
        ksort($byCategory);
        $minutes = [
            'entries' => count($minutesJson['entries']),
            'by_category' => $byCategory,
            'total' => array_sum($byCategory),
        ];
    }

    return [
        'metrics' => [
            'slices_dispatched' => count($dispatched),
            'slices_completed' => count($completedSlices),
            'slices_dispatched_ids' => $dispatched,
            'slices_completed_ids' => $completedSlices,
            'runs_by_status' => $statuses,
            'lane_distribution' => $lanes,
            'wall_clock_seconds_by_slice' => $bySlice,
            'wall_clock_seconds_total' => $total,
            'claim_outcomes' => $claimOutcomes,
            'chair_decisions' => $chairDecisions,
            'chair_decisions_incorrect' => $chairErrors,
            'chair_errors' => $errorIds,
            'contract_violations' => $contractViolations,
            'cost_usd' => $cost,
            'tokens_total' => $tokens,
            'usage_by_run' => $usageByRun,
            'director_minutes' => $minutes,
        ],
        'unavailable' => $reasons,
    ];
}

/** Format a scalar metric for the human table. */
function formatMetric(int|float|null $value): string
{
    if ($value === null) {
        return 'null';
    }
    if (is_float($value)) {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
    return (string) $value;
}

/**
 * @param array<string,mixed> $metrics
 * @param array<string,string> $unavailable
 */
function renderMetricsTable(string $id, array $metrics, array $unavailable): string
{
    $pad = static fn (string $label): string => str_pad($label, 27);
    $out = "METRICS {$id} — derived from artefacts only\n";
    $out .= $pad('slices dispatched') . ' ' . $metrics['slices_dispatched']
        . ' (' . implode(', ', $metrics['slices_dispatched_ids']) . ")\n";
    $out .= $pad('slices completed') . ' ' . $metrics['slices_completed']
        . ' (' . implode(', ', $metrics['slices_completed_ids']) . ")\n";
    foreach ($metrics['runs_by_status'] as $status => $count) {
        $out .= $pad('runs ' . $status) . ' ' . $count . "\n";
    }
    $lanes = [];
    foreach ($metrics['lane_distribution'] as $lane => $count) {
        $lanes[] = $lane . '=' . $count;
    }
    $out .= $pad('lanes') . ' ' . ($lanes === [] ? 'none' : implode(', ', $lanes)) . "\n";
    if ($metrics['wall_clock_seconds_by_slice'] === []) {
        $out .= $pad('wall-clock by slice') . ' null — no run records match this project' . "\n";
    }
    foreach ($metrics['wall_clock_seconds_by_slice'] as $sliceId => $seconds) {
        if ($seconds === null) {
            $out .= $pad('wall-clock ' . $sliceId) . ' null — '
                . ($unavailable['wall_clock_seconds_by_slice.' . $sliceId] ?? 'not derivable') . "\n";
            continue;
        }
        $out .= $pad('wall-clock ' . $sliceId) . ' ' . $seconds . "s\n";
    }
    $out .= $pad('wall-clock total') . ' ' . ($metrics['wall_clock_seconds_total'] === null
        ? 'null — ' . ($unavailable['wall_clock_seconds_total'] ?? 'not derivable')
        : $metrics['wall_clock_seconds_total'] . 's') . "\n";
    foreach ($metrics['claim_outcomes'] as $status => $count) {
        $out .= $pad('claims ' . $status) . ' ' . $count . "\n";
    }
    $out .= $pad('chair decisions') . ' ' . formatMetric($metrics['chair_decisions'])
        . ($metrics['chair_decisions'] === null ? ' — ' . ($unavailable['chair_decisions'] ?? 'not derivable') : '') . "\n";
    $incorrect = $metrics['chair_decisions_incorrect'];
    $out .= $pad('chair decisions incorrect') . ' ' . formatMetric($incorrect)
        . ($incorrect === null
            ? ' — ' . ($unavailable['chair_decisions_incorrect'] ?? 'not derivable')
            : ' (' . implode(', ', $metrics['chair_errors']) . ')')
        . "\n";
    $out .= $pad('contract violations') . ' ' . ($metrics['contract_violations'] === null
        ? 'null — ' . ($unavailable['contract_violations'] ?? 'not derivable')
        : formatMetric($metrics['contract_violations'])) . "\n";
    $out .= $pad('cost (usd)') . ' ' . ($metrics['cost_usd'] === null
        ? 'null — ' . ($unavailable['cost_usd'] ?? 'not derivable')
        : formatMetric($metrics['cost_usd'])) . "\n";
    $out .= $pad('tokens total') . ' ' . ($metrics['tokens_total'] === null
        ? 'null — ' . ($unavailable['tokens_total'] ?? 'not derivable')
        : formatMetric($metrics['tokens_total'])) . "\n";
    $minutes = $metrics['director_minutes'];
    if ($minutes === null) {
        $out .= $pad('director minutes') . ' null — ' . ($unavailable['director_minutes'] ?? 'not derivable') . "\n";
    } else {
        $pairs = [];
        foreach ($minutes['by_category'] as $category => $count) {
            $pairs[] = $category . '=' . $count;
        }
        $out .= $pad('director minutes') . ' total=' . $minutes['total'] . ' (' . implode(', ', $pairs) . ")\n";
    }
    return $out;
}

function projectMain(): int
{
    $args = array_slice($_SERVER['argv'], 1);
    if ($args === [] || in_array('--help', $args, true)) {
        projectUsage();
        return PROJECT_OK;
    }
    $command = array_shift($args);
    if (!in_array($command, ['status', 'next', 'obligations', 'transition', 'retry', 'metrics'], true)) {
        throw new InvalidArgumentException("unknown command '{$command}'");
    }
    $parsed = projectArgs($args);
    $allowed = ['project', 'projects-dir', 'runs-dir'];
    if ($command === 'metrics') {
        $allowed = array_merge($allowed, ['chair-decisions']);
    }
    if ($command === 'transition' || $command === 'retry') {
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
    if ($command === 'metrics') {
        $derived = deriveMetrics(
            $project,
            projectOption($parsed['options'], 'runs-dir', '.ai/runs') ?? '.ai/runs',
            projectOption($parsed['options'], 'chair-decisions', '.ai/chair-decisions.md') ?? '.ai/chair-decisions.md'
        );
        $output = [
            'schema' => 'ark.ai-project-metrics.v1',
            'project' => $id,
            'generated_at' => date(DATE_ATOM),
            'tool_written' => true,
            'source' => 'derived from .ai/runs/*.json matched to this project, the project slice table, '
                . 'chair-decisions.md headings, chair-errors.json and director-minutes.json; no model judgement, no estimation',
            'metrics' => $derived['metrics'],
            'unavailable' => $derived['unavailable'],
        ];
        $written = null;
        if (isset($parsed['flags']['write'])) {
            $written = rtrim((string) $project['path'], '/') . '/metrics.json';
            writeProjectJson($written, $output);
        }
        if ($json) {
            fwrite(STDOUT, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        } else {
            fwrite(STDOUT, renderMetricsTable($id, $derived['metrics'], $derived['unavailable']));
            if ($written !== null) {
                fwrite(STDOUT, "wrote {$written}\n");
            }
        }
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
    if ($command === 'retry') {
        retrySlice($project, $sliceId, projectOption($parsed['options'], 'reason'));
        fwrite(STDOUT, $json
            ? json_encode(['project' => $id, 'slice' => $sliceId, 'state' => 'pending'], JSON_THROW_ON_ERROR) . "\n"
            : "SLICE {$sliceId} blocked -> pending (retry)\n");
        return PROJECT_OK;
    }
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
