#!/usr/bin/env php
<?php

declare(strict_types=1);

use Ikabud\Kernel\Workbench\Development\DevelopmentTaskContract;

require_once dirname(__DIR__) . '/kernel/Workbench/Development/DevelopmentTaskContract.php';

const EXIT_OK = 0;
const EXIT_USAGE = 2;
const EXIT_ESCALATE = 3;
const EXIT_NOT_DELIVERED = 4;
const DEFAULT_CONTRACT = '.ai/current-task.md';
const DEFAULT_DECISIONS_DIR = '.ai/decisions';

/** @return list<string> */
function l4Triggers(): array
{
    return [
        'any path outside allowed_scope', 'anything in forbidden_scope', 'schema/DDL/migration change',
        'auth, authorisation, policy or security weakening', 'new runtime dependency',
        'public API/capability contract change', 'cross-module coupling or ownership change',
        'data deletion or irreversible migration',
        'disabling, skipping, deleting or weakening an existing test or gate to get a pass',
        'editing a quality-gate baseline', 'a second failed repair attempt on the same failure',
        'any acceptance criterion that cannot be met without widening scope', 'attempt/budget exhaustion',
    ];
}

/** Print command help and contractual exit codes. */
function usage(): void
{
    fwrite(STDOUT, <<<'TXT'
Usage:
  php tools/ai-autonomy.php plan [--contract=PATH] [--decisions-dir=DIR] [--json] [--emit-manifest=PATH|--manifest]
  php tools/ai-autonomy.php check [<action words...>] [--path=PATH]... [--level=L0|L1|L2|L3|L4]
                                  [--justify=TEXT] [--contract=PATH] [--json]
  php tools/ai-autonomy.php defer --task=ID --question=TEXT --why=TEXT
                                  --option=ID|LABEL|EFFECT|COST|BLAST_RADIUS|REVERSIBILITY [--option=...]
                                  --recommend=ID [--priority=low|normal|high|critical] [--contract=PATH]
                                  [--decisions-dir=DIR] [--id=DECISION_ID]
  php tools/ai-autonomy.php defer --retry=DECISION_ID [--contract=PATH] [--decisions-dir=DIR]
  php tools/ai-autonomy.php resume <decision-id> (--choose=OPTION_ID|--from-harpp) [--decisions-dir=DIR]
  php tools/ai-autonomy.php status [--decisions-dir=DIR] [--remote] [--state=STATE] [--json]
  php tools/ai-autonomy.php notify --type=PROGRESS|DECISION_REQUIRED|BLOCKED|RELEASE_READY|FAILED --body=TEXT
                                  [--conversation=N] [--title=TEXT]
Exit codes: 0 ok; 2 malformed input or contract; 3 L4 escalation; 4 local decision/message not delivered.
TXT
    . "\n");
}

/** @param list<string> $arguments
 * @return array{options:array<string,list<string>>,flags:array<string,bool>,positionals:list<string>}
 */
function parseArguments(array $arguments): array
{
    $options = []; $flags = []; $positionals = [];
    foreach ($arguments as $argument) {
        if (in_array($argument, ['--json', '--help', '--remote', '--from-harpp', '--manifest'], true)) {
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
}

/** @param array<string,list<string>> $options */
function option(array $options, string $name, ?string $default = null): ?string
{
    if (!isset($options[$name])) { return $default; }
    if (count($options[$name]) !== 1) {
        throw new InvalidArgumentException("offending field --{$name}: supplied more than once");
    }
    return $options[$name][0];
}

/** @return array<string,mixed> */
function loadContract(string $path): array
{
    $markdown = @file_get_contents($path);
    if ($markdown === false) { throw new InvalidArgumentException("contract '{$path}' cannot be read"); }
    try { return DevelopmentTaskContract::parseCurrentTaskMarkdown($markdown); }
    catch (InvalidArgumentException $e) { throw new InvalidArgumentException("contract '{$path}' rejected: " . $e->getMessage()); }
}

/** Encode JSON without an unreported failure. */
function encodeJson(mixed $value, bool $pretty = false): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | ($pretty ? JSON_PRETTY_PRINT : 0));
}

/** @return list<array<string,mixed>> */
function decisions(string $directory): array
{
    $items = [];
    foreach (is_dir($directory) ? (glob(rtrim($directory, '/') . '/*.json') ?: []) : [] as $file) {
        $value = json_decode((string) @file_get_contents($file), true);
        if (is_array($value) && ($value['schema'] ?? null) === 'ark.workbench-development-decision-request.v1') { $items[] = $value; }
    }
    usort($items, static fn (array $a, array $b): int => strcmp((string) $a['decision_id'], (string) $b['decision_id']));
    return $items;
}

/** Run a process without interpolating user input into a shell command.
 * @param list<string> $command
 * @return array{code:int,stdout:string,stderr:string}
 */
function runProcess(array $command): array
{
    if (!function_exists('proc_open')) { return ['code' => 127, 'stdout' => '', 'stderr' => 'proc_open unavailable']; }
    $pipes = [];
    $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) { return ['code' => 127, 'stdout' => '', 'stderr' => 'process could not start']; }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return ['code' => proc_close($process), 'stdout' => $stdout === false ? '' : trim($stdout), 'stderr' => $stderr === false ? '' : trim($stderr)];
}

/** Locate HARPP through PATH, never through a hardcoded path. */
function locateHarpp(): ?string
{
    $result = runProcess(['sh', '-c', 'command -v harpp']);
    return $result['code'] === 0 && trim($result['stdout']) !== '' ? trim($result['stdout']) : null;
}

/** Invoke the external HARPP client.
 * @param list<string> $arguments
 * @return array{code:int,stdout:string,stderr:string}|null
 */
function harpp(array $arguments): ?array
{
    $binary = locateHarpp();
    return $binary === null ? null : runProcess(array_merge([$binary], $arguments));
}

/** Extract decision rows from supported HARPP JSON envelopes.
 * @return list<array<string,mixed>>
 */
function remoteRows(string $json): array
{
    $value = json_decode($json, true);
    if (!is_array($value)) { return []; }
    foreach (['decisions', 'items', 'data'] as $key) {
        if (isset($value[$key]) && is_array($value[$key])) { $value = $value[$key]; break; }
    }
    if (array_is_list($value)) { return array_values(array_filter($value, 'is_array')); }
    return isset($value['decision_key']) ? [$value] : [];
}

/** Warn about parser-visible prose masquerading as forbidden paths.
 * @param array<string,mixed> $contract
 */
function scopeWarnings(array $contract): void
{
    foreach ((array) ($contract['forbidden_scope'] ?? []) as $entry) {
        $path = (string) ($entry['path'] ?? '');
        if (preg_match('/^[A-Za-z0-9_-]+$/', $path) === 1 && !file_exists($path)) {
            fwrite(STDOUT, "WARN suspicious scope entry (prose?): {$path}\n");
        }
    }
}

/** Create the governed-loop manifest.
 * @param array<string,mixed> $contract
 * @return array<string,mixed>
 */
function workflowManifest(array $contract, string $contractPath): array
{
    $commands = [];
    foreach ((array) ($contract['required_tests'] ?? []) as $line) {
        $text = (string) $line;
        if (preg_match('#`(php tests/[^`]+|vendor/bin/phpstan [^`]+)`#', $text, $m) === 1) {
            $commands[] = trim($m[1]);
        } elseif (preg_match('#^(php tests/\S+|vendor/bin/phpstan .+?)(?:\s+[—-]\s+|$)#', $text, $m) === 1) {
            $commands[] = trim($m[1]);
        }
    }
    $fallback = $commands[0] ?? 'git diff --check';
    $names = ['architect', 'implement', 'review', 'release-gate']; $stages = [];
    foreach ($names as $index => $name) {
        $stages[] = ['name' => $name, 'model' => 'deepseek-v4-flash', 'prompt_file' => $contractPath,
            'marker' => strtoupper(str_replace('-', '_', $name)) . '_COMPLETE', 'verify' => $commands[$index] ?? $fallback, 'timeout' => 1200];
    }
    return ['title' => 'Ikabud governed autonomy loop', 'stages' => $stages];
}

/** @param array<string,mixed> $contract */
function commandPlan(array $contract, string $contractPath, string $directory, bool $json, ?string $manifestPath, bool $manifestStdout): int
{
    $pending = count(array_filter(decisions($directory), static fn (array $i): bool => !isset($i['resolution'])));
    $allowed = (array) $contract['allowed_scope']; $forbidden = (array) $contract['forbidden_scope'];
    $phases = ['architect     tools/ai-task "<task>" -> tools/pi-arch-debate.py | tools/pi-arch-review.sh -> .ai/current-task.md',
        "implement     pi --print --approve '<contract>'", 'review        pi-arch-review.sh / Code Reviewer lane; CHANGES_REQUIRED returns to implement',
        'release-gate  php ikabud workbench:task:record --stage=release-gate --result=... --envelope=...'];
    if ($manifestPath !== null || $manifestStdout) {
        $manifest = encodeJson(workflowManifest($contract, $contractPath), true) . "\n";
        if ($manifestStdout) { fwrite(STDOUT, $manifest); }
        else {
            if (@file_put_contents((string) $manifestPath, $manifest) === false) { throw new InvalidArgumentException("manifest '{$manifestPath}' could not be written"); }
        }
        $shownPath = $manifestStdout ? '<path>' : (string) $manifestPath;
        $line = "harpp workflow start --manifest={$shownPath} --conversation=<id> --workspace=\$(pwd) --authority-level=L2 --max-repairs=3\n";
        fwrite($manifestStdout ? STDERR : STDOUT, $line);
        return EXIT_OK;
    }
    if ($json) {
        fwrite(STDOUT, encodeJson(['envelope' => ['objective' => $contract['objective'], 'contract_revision' => DevelopmentTaskContract::revisionId($contract), 'allowed_scope' => $allowed, 'forbidden_scope' => $forbidden], 'phases' => $phases, 'l4_triggers' => l4Triggers(), 'decisions_dir' => $directory, 'pending' => $pending]) . "\n");
        return EXIT_OK;
    }
    scopeWarnings($contract);
    fwrite(STDOUT, "AUTONOMY ENVELOPE — {$contractPath}\nobjective: {$contract['objective']}\ncontract revision: " . DevelopmentTaskContract::revisionId($contract) . "\nallowed scope (" . count($allowed) . "):\n");
    foreach ($allowed as $entry) { fwrite(STDOUT, "  - {$entry['path']} ({$entry['kind']})\n"); }
    fwrite(STDOUT, 'forbidden scope (' . count($forbidden) . "):\n");
    foreach ($forbidden as $entry) { fwrite(STDOUT, "  - {$entry['path']} ({$entry['kind']})\n"); }
    fwrite(STDOUT, "phases (run unattended; only L4 stops the run):\n"); foreach ($phases as $phase) { fwrite(STDOUT, "  {$phase}\n"); }
    fwrite(STDOUT, "automatic escalation (L4):\n"); foreach (l4Triggers() as $trigger) { fwrite(STDOUT, "  - {$trigger}\n"); }
    fwrite(STDOUT, "decisions dir: {$directory}\npending decisions: {$pending}\n");
    return EXIT_OK;
}

/** @param array{path:string,kind:string} $entry */
function pathMatches(string $path, array $entry): bool
{
    return $entry['kind'] === 'file' ? $path === $entry['path'] : $path === $entry['path'] || str_starts_with($path, $entry['path'] . '/');
}

/**
 * @param list<string> $paths
 * @return list<string>
 */
function sensitiveReasons(array $paths): array
{
    $reasons = [];
    foreach ($paths as $path) {
        if (preg_match('#(^|/)migrations(/|$)#i', $path) === 1 || str_ends_with(strtolower($path), '.sql')) { $reasons[] = 'schema or DDL change'; }
        if (str_starts_with($path, '.github/workflows/')) { $reasons[] = 'CI gate change'; }
        if (preg_match('#(^|/)(composer\.json|composer\.lock|package\.json|package-lock\.json)$#', $path) === 1) { $reasons[] = 'dependency change'; }
        if (preg_match('#(^|/)phpstan-baseline\.neon$#', $path) === 1) { $reasons[] = 'quality-gate baseline change'; }
        if (preg_match('#(^|/)module\.json$#', $path) === 1) { $reasons[] = 'module manifest/contract change'; }
        if (preg_match('#kernel/Capabilities|CapabilityAuthorization|SecurityHeaders|auth|JWT|policy#i', $path) === 1) { $reasons[] = 'authority or security path'; }
    }
    return array_values(array_unique($reasons));
}

/** Normalize whitespace and case. */
function normalizedText(string $text): string { return strtolower(trim((string) preg_replace('/\s+/', ' ', $text))); }

/** @param array<string,mixed> $contract */
function isGrounded(?string $justification, array $contract): bool
{
    if ($justification === null || strlen(normalizedText($justification)) < 12) { return false; }
    $needle = normalizedText($justification);
    foreach (['constraints', 'acceptance'] as $field) {
        foreach ((array) $contract[$field] as $line) { if (str_contains(normalizedText((string) $line), $needle)) { return true; } }
    }
    return false;
}

/** Apply the deterministic authority tripwire.
 * @param array<string,mixed> $contract
 * @param list<string> $paths
 */
function commandCheck(array $contract, string $action, array $paths, string $level, ?string $justification, bool $json): int
{
    $level = strtoupper($level);
    if (!in_array($level, ['L0', 'L1', 'L2', 'L3', 'L4'], true)) { throw new InvalidArgumentException("offending field --level: '{$level}'"); }
    $reasons = []; $resolved = $level;
    foreach ($paths as &$path) {
        try { $path = DevelopmentTaskContract::normalizePath($path); }
        catch (InvalidArgumentException $e) { throw new InvalidArgumentException("offending field --path '{$path}': " . $e->getMessage()); }
    }
    unset($path);
    foreach ($paths as $path) {
        foreach ((array) $contract['forbidden_scope'] as $entry) {
            if (pathMatches($path, $entry)) { $resolved = 'L4'; $suffix = $entry['kind'] === 'directory' ? ' by directory prefix' : ''; $reasons[] = "path '{$path}' matches forbidden entry '{$entry['path']}'{$suffix}"; break 2; }
        }
    }
    if ($resolved !== 'L4') {
        foreach ($paths as $path) {
            $matched = false;
            foreach (array_merge((array) $contract['allowed_scope'], (array) $contract['baseline_scope']) as $entry) {
                if (pathMatches($path, $entry)) { $matched = true; if ($entry['kind'] === 'directory' && $path !== $entry['path']) { $reasons[] = "path '{$path}' matched '{$entry['path']}' by directory prefix"; } break; }
            }
            if (!$matched) { $resolved = 'L4'; $reasons[] = "path '{$path}' is outside the approved scope"; break; }
        }
    }
    if ($resolved !== 'L4') {
        $sensitive = sensitiveReasons($paths);
        if ($sensitive !== []) {
            $reasons = array_merge($reasons, $sensitive);
            if (isGrounded($justification, $contract)) { $resolved = 'L3'; $reasons[] = 'sensitive change is explicitly grounded in contract acceptance/constraints'; }
            else { $resolved = 'L4'; $reasons[] = 'justification is absent or not grounded in contract acceptance/constraints'; }
        }
    }
    $verdict = in_array($resolved, ['L0', 'L1'], true) ? 'PROCEED' : (in_array($resolved, ['L2', 'L3'], true) ? 'RECORD' : 'ESCALATE');
    if ($json) { fwrite(STDOUT, encodeJson(['verdict' => $verdict, 'authority_level' => $resolved, 'action' => $action, 'paths' => $paths, 'reasons' => $reasons]) . "\n"); }
    else {
        fwrite(STDOUT, "VERDICT: {$verdict}\nlevel: {$resolved}\naction: {$action}\npaths:\n"); foreach ($paths as $path) { fwrite(STDOUT, "  - {$path}\n"); }
        fwrite(STDOUT, "reasons:\n"); foreach ($reasons as $reason) { fwrite(STDOUT, "  - {$reason}\n"); }
        if ($verdict === 'ESCALATE') { fwrite(STDOUT, "next: file an L4 decision — php tools/ai-autonomy.php defer --task=<task_id> --question=\"...\" --option=...\n"); }
    }
    return $verdict === 'ESCALATE' ? EXIT_ESCALATE : EXIT_OK;
}

/** Require a non-empty field. */
function requiredValue(?string $value, string $field): string
{
    if ($value === null || trim($value) === '') { throw new InvalidArgumentException("offending field --{$field}: '" . ($value ?? 'missing') . "'"); }
    return $value;
}

/**
 * @param list<string> $rawOptions
 * @return list<array{id:string,label:string,effect:string,cost:string,blast_radius:string,reversibility:string}>
 */
function decisionOptions(array $rawOptions): array
{
    if (count($rawOptions) < 2 || count($rawOptions) > 4) { throw new InvalidArgumentException('offending field --option count: ' . count($rawOptions) . ' (expected 2-4)'); }
    $result = []; $ids = [];
    foreach ($rawOptions as $raw) {
        $fields = explode('|', $raw);
        if (count($fields) !== 6 || count(array_filter($fields, static fn (string $v): bool => trim($v) !== '')) !== 6) { throw new InvalidArgumentException("offending field --option: '{$raw}' (expected exactly 6 non-empty fields)"); }
        [$id, $label, $effect, $cost, $blastRadius, $reversibility] = $fields;
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $id) !== 1 || in_array($id, $ids, true)) { throw new InvalidArgumentException("offending option id: '{$id}'"); }
        if (!in_array($reversibility, ['reversible', 'partially_reversible', 'irreversible'], true)) { throw new InvalidArgumentException("offending reversibility: '{$reversibility}'"); }
        $ids[] = $id; $result[] = compact('id', 'label', 'effect', 'cost') + ['blast_radius' => $blastRadius, 'reversibility' => $reversibility];
    }
    return $result;
}

/** Escape a Markdown cell. */
function markdownCell(string $value): string { return str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], $value); }

/** Render the decision body sent to HARPP.
 * @param list<array{id:string,label:string,effect:string,cost:string,blast_radius:string,reversibility:string}> $options
 */
function decisionBody(array $options, string $recommend): string
{
    $lines = ['Options:'];
    foreach ($options as $item) { $lines[] = "{$item['id']}: {$item['label']} — {$item['effect']} (cost {$item['cost']}; blast radius {$item['blast_radius']}; {$item['reversibility']})"; }
    $lines[] = "Recommendation: {$recommend}"; $lines[] = 'Default if no response: stop';
    return implode("\n", $lines);
}

/** Persist a JSON artifact atomically enough for this local driver.
 * @param array<string,mixed> $decision
 */
function writeDecision(string $path, array $decision): void
{
    if (@file_put_contents($path, encodeJson($decision, true) . "\n") === false) { throw new InvalidArgumentException("decision artifact '{$path}' could not be written"); }
}

/** Attempt delivery and update transport.
 * @param array<string,mixed> $decision
 */
function deliverDecision(array &$decision, string $jsonPath, string $contractPath, string $directory): int
{
    $recommendation = (array) $decision['recommendation'];
    $result = harpp(['decision', 'submit', '--title=' . (string) $decision['question'], '--body=' . decisionBody((array) $decision['options'], (string) $recommendation['option_id']),
        '--context=' . (string) $decision['why_now'], '--requested=' . (string) $recommendation['option_id'], '--priority=' . (string) ($decision['_priority'] ?? 'normal'),
        '--source=ikabudsix', '--workbench-state=ARCHITECTURE_DECISION_REQUIRED', '--decision-key=' . (string) $decision['decision_id']]);
    unset($decision['_priority']);
    $transport = ['channel' => 'local-only', 'attempted' => $result !== null, 'delivered' => false, 'suppressed' => false, 'harpp_decision_id' => null, 'error' => null];
    if ($result === null) { $transport['error'] = 'harpp unavailable on PATH'; }
    else {
        $payload = json_decode($result['stdout'], true);
        $suppressed = is_array($payload) && ($payload['suppressed'] ?? false) === true;
        $ok = $result['code'] === 0 && is_array($payload) && ($payload['ok'] ?? true) !== false && !$suppressed;
        $transport['suppressed'] = $suppressed;
        $transport['delivered'] = $ok;
        $transport['channel'] = $ok ? 'harpp-cli' : 'local-only';
        $remoteId = is_array($payload) ? ($payload['id'] ?? $payload['decision_id'] ?? (($payload['decision']['id'] ?? null))) : null;
        $transport['harpp_decision_id'] = $remoteId === null ? null : (string) $remoteId;
        if (!$ok && !$suppressed) { $transport['error'] = $result['stderr'] !== '' ? $result['stderr'] : ($result['stdout'] !== '' ? $result['stdout'] : "harpp exited {$result['code']}"); }
    }
    $decision['transport'] = $transport; writeDecision($jsonPath, $decision);
    if ($transport['delivered']) { fwrite(STDOUT, "DELIVERY: harpp\n"); return EXIT_OK; }
    fwrite(STDOUT, "DELIVERY: local-only — director NOT notified\nphp tools/ai-autonomy.php defer --retry={$decision['decision_id']} --contract={$contractPath} --decisions-dir={$directory}\n");
    return EXIT_NOT_DELIVERED;
}

/**
 * @param array<string,mixed> $contract
 * @param array<string,list<string>> $options
 */
function commandDefer(array $contract, array $options, string $directory, string $contractPath): int
{
    $retry = option($options, 'retry');
    if ($retry !== null) {
        if (preg_match('/^[A-Za-z0-9._-]+$/', $retry) !== 1) { throw new InvalidArgumentException("offending field --retry: '{$retry}'"); }
        $path = rtrim($directory, '/') . "/{$retry}.json"; $decision = json_decode((string) @file_get_contents($path), true);
        if (!is_array($decision) || ($decision['decision_id'] ?? null) !== $retry) { throw new InvalidArgumentException("decision file '{$path}' is missing or malformed"); }
        $decision['_priority'] = option($options, 'priority', 'normal');
        return deliverDecision($decision, $path, $contractPath, $directory);
    }
    $task = requiredValue(option($options, 'task'), 'task'); $question = requiredValue(option($options, 'question'), 'question'); $why = requiredValue(option($options, 'why'), 'why');
    if (preg_match('/^[A-Za-z0-9._-]+$/', $task) !== 1) { throw new InvalidArgumentException("offending field --task: '{$task}'"); }
    $parsedOptions = decisionOptions($options['option'] ?? []); $recommend = requiredValue(option($options, 'recommend'), 'recommend');
    if (!in_array($recommend, array_column($parsedOptions, 'id'), true)) { throw new InvalidArgumentException("offending field --recommend: '{$recommend}' is not an option id"); }
    $priority = option($options, 'priority', 'normal') ?? 'normal';
    if (!in_array($priority, ['low', 'normal', 'high', 'critical'], true)) { throw new InvalidArgumentException("offending field --priority: '{$priority}'"); }
    $count = count(array_filter(decisions($directory), static fn (array $i): bool => ($i['task_id'] ?? null) === $task));
    $id = option($options, 'id') ?? "{$task}-d" . ($count + 1);
    if (preg_match('/^[A-Za-z0-9._-]+$/', $id) !== 1) { throw new InvalidArgumentException("offending field --id: '{$id}'"); }
    $state = requiredValue(option($options, 'state', 'pre-change'), 'state');
    $resume = option($options, 'resume') ?? "php tools/ai-autonomy.php resume {$id} --choose=<OPTION> --decisions-dir={$directory}";
    if (!str_contains($resume, '<OPTION>')) { throw new InvalidArgumentException("offending field --resume: '{$resume}' must contain <OPTION>"); }
    $modelRaw = option($options, 'by-model'); $gitRaw = option($options, 'git-head'); $now = date(DATE_ATOM);
    $recommendation = ['option_id' => $recommend, 'rationale' => "Option '{$recommend}' best preserves the approved task boundary."];
    $decision = ['schema' => 'ark.workbench-development-decision-request.v1', 'schema_version' => '1.0', 'decision_id' => $id, 'task_id' => $task,
        'contract_revision' => DevelopmentTaskContract::revisionId($contract), 'raised_at' => $now,
        'raised_by' => ['role' => option($options, 'by-role', 'implement'), 'model' => $modelRaw === null || $modelRaw === 'null' ? null : $modelRaw, 'harness' => option($options, 'by-harness', 'pi')],
        'authority_level' => 'L4', 'question' => $question, 'why_now' => $why, 'options' => $parsedOptions, 'recommendation' => $recommendation,
        'default_if_no_response' => 'stop', 'impact_of_no_decision' => option($options, 'impact', 'The bounded run remains stopped until the director answers.'),
        'already_done' => $options['done'] ?? [], 'checkpoint' => ['state' => $state, 'git_head' => $gitRaw === null || $gitRaw === 'null' ? null : $gitRaw, 'resume_command' => $resume],
        'evidence_refs' => $options['evidence'] ?? [], 'transport' => ['channel' => 'local-only', 'attempted' => false, 'delivered' => false, 'suppressed' => false, 'harpp_decision_id' => null, 'error' => null], '_priority' => $priority];
    $stage = ['schema' => 'ark.workbench-development-stage-result.v1', 'schema_version' => '1.0', 'stage' => 'architect', 'task_id' => $task,
        'actor' => ['role' => $decision['raised_by']['role'], 'model' => $decision['raised_by']['model'], 'harness' => $decision['raised_by']['harness'], 'context_governor' => null],
        'result' => 'architecture_decision_required', 'recorded_at' => $now, 'summary' => $question, 'unresolved_findings' => [['severity' => 'P1', 'summary' => $question]]];
    if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) { throw new InvalidArgumentException("offending field --decisions-dir: '{$directory}' cannot be created"); }
    $base = rtrim($directory, '/') . "/{$id}"; $jsonPath = $base . '.json'; $markdownPath = $base . '.md'; $stagePath = $base . '.stage.json';
    if (file_exists($jsonPath) || file_exists($markdownPath) || file_exists($stagePath)) { throw new InvalidArgumentException("offending field --id: '{$id}' already exists"); }
    $localDecision = $decision; unset($localDecision['_priority']); writeDecision($jsonPath, $localDecision);
    $brief = "# Decision {$id}\n\n## Question\n\n{$question}\n\n## Why now\n\n{$why}\n\n## Options\n\n| id | label | effect | cost | blast radius | reversibility |\n|---|---|---|---|---|---|\n";
    foreach ($parsedOptions as $item) { $brief .= '| ' . implode(' | ', array_map('markdownCell', array_values($item))) . " |\n"; }
    $brief .= "\n## Recommendation\n\n`{$recommend}` — {$recommendation['rationale']}\n\n`default_if_no_response: stop`\n\n## Impact of no decision\n\n{$decision['impact_of_no_decision']}\n\n## Already done\n\n" . (($decision['already_done']) === [] ? "- None.\n" : implode("\n", array_map(static fn (string $v): string => "- {$v}", $decision['already_done'])) . "\n") . "\n## Evidence refs\n\n" . (($decision['evidence_refs']) === [] ? "- None.\n" : implode("\n", array_map(static fn (string $v): string => "- {$v}", $decision['evidence_refs'])) . "\n") . "\n## Checkpoint\n\n- State: {$state}\n- Git head: " . ($decision['checkpoint']['git_head'] ?? 'null') . "\n- Resume command: `{$resume}`\n";
    if (@file_put_contents($markdownPath, $brief) === false || @file_put_contents($stagePath, encodeJson($stage, true) . "\n") === false) { throw new InvalidArgumentException("decision companion artifact could not be written"); }
    fwrite(STDOUT, "DECISION FILED LOCALLY\n{$jsonPath}\n{$markdownPath}\n{$stagePath}\nphp ikabud workbench:task:record {$task} --stage=architect --result=architecture_decision_required --envelope={$stagePath}\n");
    return deliverDecision($decision, $jsonPath, $contractPath, $directory);
}

/** Resolve a local decision, optionally from a DECIDED HARPP row.
 * @param array<string,list<string>> $options
 */
function commandResume(string $id, array $options, string $directory, bool $fromHarpp): int
{
    if (preg_match('/^[A-Za-z0-9._-]+$/', $id) !== 1) { throw new InvalidArgumentException("offending decision-id: '{$id}'"); }
    $path = rtrim($directory, '/') . "/{$id}.json"; $decision = json_decode((string) @file_get_contents($path), true);
    if (!is_array($decision) || ($decision['schema'] ?? null) !== 'ark.workbench-development-decision-request.v1') { throw new InvalidArgumentException("decision file '{$path}' is missing or malformed"); }
    if (isset($decision['resolution'])) { throw new InvalidArgumentException("decision '{$id}' is already answered"); }
    $remoteId = null; $choose = option($options, 'choose'); $note = option($options, 'note', '') ?? ''; $source = 'local';
    if ($fromHarpp) {
        if ($choose !== null) { throw new InvalidArgumentException('--choose and --from-harpp are mutually exclusive'); }
        $result = harpp(['decision', 'list', '--remote', '--state=DECIDED']);
        if ($result === null || $result['code'] !== 0) { throw new InvalidArgumentException('HARPP unavailable or DECIDED list failed'); }
        $match = null;
        foreach (remoteRows($result['stdout']) as $row) { if (($row['decision_key'] ?? null) === $id && strtoupper((string) ($row['state'] ?? '')) === 'DECIDED') { $match = $row; break; } }
        if ($match === null) { throw new InvalidArgumentException("no DECIDED HARPP decision matches decision_key '{$id}'"); }
        $choose = (string) ($match['decision'] ?? $match['answer'] ?? $match['decision_text'] ?? '');
        $note = (string) ($match['rationale'] ?? ''); $remoteId = (string) ($match['id'] ?? $match['decision_id'] ?? ''); $source = 'harpp';
    }
    $choose = requiredValue($choose, $fromHarpp ? 'HARPP decision' : 'choose'); $chosen = null;
    foreach ((array) $decision['options'] as $item) { if (is_array($item) && ($item['id'] ?? null) === $choose) { $chosen = $item; break; } }
    if ($chosen === null) { throw new InvalidArgumentException("offending choice: '{$choose}' is not an option id"); }
    if ($fromHarpp) {
        if ($remoteId === '') { throw new InvalidArgumentException('matched HARPP decision has no id'); }
        $ack = harpp(['decision', 'ack', $remoteId]); $apply = $ack !== null && $ack['code'] === 0 ? harpp(['decision', 'apply', $remoteId]) : null;
        if ($ack === null || $ack['code'] !== 0 || $apply === null || $apply['code'] !== 0) { fwrite(STDOUT, "DELIVERY: local-only — director NOT notified\n"); return EXIT_NOT_DELIVERED; }
    }
    $decision['resolution'] = ['chosen_option_id' => $choose, 'decided_by' => option($options, 'by', 'director'), 'decided_at' => date(DATE_ATOM), 'note' => $note, 'source' => $source];
    writeDecision($path, $decision); $checkpoint = (array) $decision['checkpoint'];
    fwrite(STDOUT, "CHOSEN: {$choose} — {$chosen['label']}\n" . str_replace('<OPTION>', $choose, (string) $checkpoint['resume_command']) . "\n");
    return EXIT_OK;
}

/** List decisions, optionally merged with HARPP lifecycle state. */
function commandStatus(string $directory, bool $json, bool $remote, ?string $state): int
{
    $remoteByKey = [];
    if ($remote) {
        $args = ['decision', 'list', '--remote']; if ($state !== null) { $args[] = "--state={$state}"; }
        $result = harpp($args);
        if ($result === null || $result['code'] !== 0) { fwrite(STDERR, "HARPP unavailable; showing local decisions only\n"); }
        else { foreach (remoteRows($result['stdout']) as $row) { $key = (string) ($row['decision_key'] ?? ''); if ($key !== '') { $remoteByKey[$key] = $row; } } }
    }
    $rows = [];
    foreach (decisions($directory) as $item) {
        $resolution = is_array($item['resolution'] ?? null) ? $item['resolution'] : null; $id = (string) $item['decision_id']; $remoteRow = $remoteByKey[$id] ?? null;
        $rows[] = ['decision_id' => $id, 'task_id' => (string) $item['task_id'], 'state' => $resolution === null ? 'PENDING' : 'RESOLVED',
            'harpp_state' => is_array($remoteRow) ? ($remoteRow['state'] ?? null) : null, 'question' => (string) $item['question'], 'chosen_option_id' => $resolution['chosen_option_id'] ?? null];
        unset($remoteByKey[$id]);
    }
    foreach ($remoteByKey as $key => $row) { $rows[] = ['decision_id' => $key, 'task_id' => '', 'state' => 'REMOTE', 'harpp_state' => $row['state'] ?? null, 'question' => $row['title'] ?? '', 'chosen_option_id' => null]; }
    if ($json) { fwrite(STDOUT, encodeJson($rows) . "\n"); }
    else { fwrite(STDOUT, "DECISIONS\n"); if ($rows === []) { fwrite(STDOUT, "no decisions\n"); } foreach ($rows as $row) { $suffix = $remote ? '  HARPP:' . ($row['harpp_state'] ?? 'unknown') : ''; fwrite(STDOUT, "{$row['decision_id']}  {$row['state']}{$suffix}  {$row['question']}\n"); } }
    return EXIT_OK;
}

/** Send an idempotent progress message through HARPP.
 * @param array<string,list<string>> $options
 */
function commandNotify(array $options): int
{
    $type = requiredValue(option($options, 'type'), 'type'); $body = requiredValue(option($options, 'body'), 'body');
    if (!in_array($type, ['PROGRESS', 'DECISION_REQUIRED', 'BLOCKED', 'RELEASE_READY', 'FAILED'], true)) { throw new InvalidArgumentException("offending field --type: '{$type}'"); }
    $args = ['msg', 'send', '--body=' . $type . ': ' . $body, '--idempotency-key=' . hash('sha256', 'ikabudsix|run|' . $type . '|' . hash('sha256', $body))];
    foreach (['conversation' => 'conversation-id', 'title' => 'title'] as $input => $output) { $value = option($options, $input); if ($value !== null) { $args[] = "--{$output}={$value}"; } }
    $result = harpp($args); $payload = $result === null ? null : json_decode($result['stdout'], true);
    $delivered = $result !== null && $result['code'] === 0 && is_array($payload) && ($payload['ok'] ?? true) !== false && ($payload['suppressed'] ?? false) !== true;
    if ($delivered) { fwrite(STDOUT, "DELIVERY: harpp\n"); return EXIT_OK; }
    fwrite(STDOUT, "DELIVERY: local-only — director NOT notified\n"); return EXIT_NOT_DELIVERED;
}

/** Dispatch and return a contractual exit status. */
function main(): int
{
    $args = $_SERVER['argv']; array_shift($args);
    if ($args === [] || in_array('--help', $args, true)) { usage(); return EXIT_OK; }
    $command = array_shift($args); if (!in_array($command, ['plan', 'check', 'defer', 'resume', 'status', 'notify'], true)) { throw new InvalidArgumentException("unknown command '{$command}'"); }
    $p = parseArguments($args);
    if ($command === 'plan') {
        validateArgumentNames($p, ['contract', 'decisions-dir', 'emit-manifest'], ['json', 'manifest']); if ($p['positionals'] !== []) { throw new InvalidArgumentException("offending argument: '{$p['positionals'][0]}'"); }
        $path = option($p['options'], 'contract', DEFAULT_CONTRACT) ?? DEFAULT_CONTRACT;
        return commandPlan(loadContract($path), $path, option($p['options'], 'decisions-dir', DEFAULT_DECISIONS_DIR) ?? DEFAULT_DECISIONS_DIR, isset($p['flags']['json']), option($p['options'], 'emit-manifest'), isset($p['flags']['manifest']));
    }
    if ($command === 'check') {
        validateArgumentNames($p, ['path', 'level', 'justify', 'contract'], ['json']); $path = option($p['options'], 'contract', DEFAULT_CONTRACT) ?? DEFAULT_CONTRACT;
        return commandCheck(loadContract($path), implode(' ', $p['positionals']), $p['options']['path'] ?? [], option($p['options'], 'level', 'L2') ?? 'L2', option($p['options'], 'justify'), isset($p['flags']['json']));
    }
    if ($command === 'defer') {
        validateArgumentNames($p, ['task', 'question', 'why', 'option', 'recommend', 'priority', 'impact', 'done', 'evidence', 'state', 'resume', 'git-head', 'by-role', 'by-model', 'by-harness', 'id', 'retry', 'contract', 'decisions-dir'], []);
        if ($p['positionals'] !== []) { throw new InvalidArgumentException("offending argument: '{$p['positionals'][0]}'"); }
        $path = option($p['options'], 'contract', DEFAULT_CONTRACT) ?? DEFAULT_CONTRACT;
        return commandDefer(loadContract($path), $p['options'], option($p['options'], 'decisions-dir', DEFAULT_DECISIONS_DIR) ?? DEFAULT_DECISIONS_DIR, $path);
    }
    if ($command === 'resume') {
        validateArgumentNames($p, ['choose', 'note', 'by', 'decisions-dir'], ['from-harpp']); if (count($p['positionals']) !== 1) { throw new InvalidArgumentException('resume requires exactly one decision-id'); }
        return commandResume($p['positionals'][0], $p['options'], option($p['options'], 'decisions-dir', DEFAULT_DECISIONS_DIR) ?? DEFAULT_DECISIONS_DIR, isset($p['flags']['from-harpp']));
    }
    if ($command === 'notify') { validateArgumentNames($p, ['type', 'body', 'conversation', 'title'], []); if ($p['positionals'] !== []) { throw new InvalidArgumentException('notify accepts no positional arguments'); } return commandNotify($p['options']); }
    validateArgumentNames($p, ['decisions-dir', 'state'], ['json', 'remote']); if ($p['positionals'] !== []) { throw new InvalidArgumentException("offending argument: '{$p['positionals'][0]}'"); }
    return commandStatus(option($p['options'], 'decisions-dir', DEFAULT_DECISIONS_DIR) ?? DEFAULT_DECISIONS_DIR, isset($p['flags']['json']), isset($p['flags']['remote']), option($p['options'], 'state'));
}

try { exit(main()); }
catch (InvalidArgumentException|JsonException $e) { fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n"); exit(EXIT_USAGE); }
