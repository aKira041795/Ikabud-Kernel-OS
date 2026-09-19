#!/usr/bin/env php
<?php
declare(strict_types=1);

/* HARPP v2 execution core. PHP 8.2, standard library only. */

const HARPP2_BOUNDARIES = [
    'destroy data',
    'weaken security',
    'weaken a check to obtain a pass',
    "act outside the objective's declared scope",
    'add a runtime dependency',
    'publish, push, or release',
    'report success without evidence, or deliver nothing while claiming delivery',
];
const STOP_CONDITIONS = ['authority', 'boundary', 'irreversibility'];

function fail(string $message, int $code = 2): never
{
    fwrite(STDERR, "[harpp2] {$message}\n");
    exit($code);
}

function rootDir(): string
{
    $root = realpath(__DIR__ . '/../..');
    if ($root === false) {
        fail('cannot locate the workspace');
    }
    return $root;
}

function cli(array $argv): array
{
    $command = $argv[1] ?? '';
    if (!in_array($command, ['run', 'status', 'journal'], true)) {
        fail('usage: php tools/harpp2/harpp2.php <run|status|journal> --objective=<file> [--max-stalls=2]');
    }
    $options = ['max-stalls' => '2'];
    foreach (array_slice($argv, 2) as $arg) {
        if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
            [$key, $value] = explode('=', substr($arg, 2), 2);
            $options[$key] = $value;
        }
    }
    if (($options['objective'] ?? '') === '') {
        fail('--objective is required');
    }
    $maxStalls = filter_var($options['max-stalls'], FILTER_VALIDATE_INT);
    if ($maxStalls === false || $maxStalls < 0 || $maxStalls > 20) {
        fail('--max-stalls must be an integer from 0 to 20');
    }
    $options['max-stalls'] = $maxStalls;
    return [$command, $options];
}

function objectiveInfo(string $argument): array
{
    $root = rootDir();
    $candidate = str_starts_with($argument, '/') ? $argument : $root . '/' . $argument;
    $path = realpath($candidate);
    if ($path === false || !is_file($path)) {
        fail("objective not found: {$argument}");
    }
    if (!str_starts_with($path, $root . '/')) {
        fail('objective is outside the workspace');
    }
    $relative = substr($path, strlen($root) + 1);
    $slug = strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', preg_replace('/\.md$/i', '', basename($path))));
    $slug = trim($slug, '-') ?: 'objective';
    return ['path' => $path, 'relative' => $relative, 'slug' => $slug, 'text' => (string)file_get_contents($path)];
}

function pathsFor(array $objective): array
{
    $dir = rootDir() . '/tools/harpp2/state';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fail('cannot create state directory');
    }
    return [
        'state' => $dir . '/' . $objective['slug'] . '.json',
        'journal' => $dir . '/' . $objective['slug'] . '.jsonl',
        'lock' => $dir . '/.driver.lock',
    ];
}

function initialState(array $objective): array
{
    return [
        'objective' => $objective['relative'], 'status' => 'not-started', 'condition' => null,
        'reason' => null, 'chunks_attempted' => 0, 'verified' => 0, 'blocked' => 0,
        'stalls' => 0, 'no_progress' => 0, 'approach' => 0, 'last_action' => null,
        'active_lane' => null, 'updated_at' => date(DATE_ATOM),
    ];
}

function loadState(array $objective, array $paths): array
{
    if (!is_file($paths['state'])) {
        return initialState($objective);
    }
    $decoded = json_decode((string)file_get_contents($paths['state']), true);
    return is_array($decoded) ? array_replace(initialState($objective), $decoded) : initialState($objective);
}

function saveState(array $state, string $path): void
{
    $state['updated_at'] = date(DATE_ATOM);
    $temporary = $path . '.tmp.' . getmypid();
    file_put_contents($temporary, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    rename($temporary, $path);
}

function journal(string $path, array $record): void
{
    $record = ['at' => date(DATE_ATOM)] + $record;
    file_put_contents($path, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);
}

function acceptanceCommands(string $text): array
{
    $commands = [];
    if (preg_match_all('/```(?:bash|sh|shell)?\s*\n(.*?)```/si', $text, $blocks)) {
        foreach ($blocks[1] as $block) {
            foreach (preg_split('/\R/', $block) as $line) {
                $line = trim($line);
                if (str_starts_with($line, '$ ')) {
                    $commands[] = trim(substr($line, 2));
                }
            }
        }
    }
    return array_values(array_unique($commands));
}

function explicitScope(string $text): array
{
    $scope = [];
    if (preg_match('/^##\s+(?:Declared\s+)?Scope\s*$\R(.*?)(?=^##\s|\z)/msi', $text, $match)) {
        preg_match_all('/`([^`]+)`/', $match[1], $items);
        $scope = $items[1];
    }
    if (preg_match('/^scope:\s*(.+)$/mi', $text, $match)) {
        $scope = array_merge($scope, preg_split('/\s*,\s*/', trim($match[1], " []\t\n\r\0\x0B")));
    }
    return normaliseScopes($scope);
}

function normaliseScopes(array $paths): array
{
    $result = [];
    foreach ($paths as $path) {
        $path = trim(str_replace('\\', '/', (string)$path), " \t\n\r\0\x0B`'\"");
        $path = preg_replace('#^\./#', '', $path);
        if ($path === '' || $path === '.' || str_contains($path, '..') || preg_match('/[|;&<>$]/', $path)) {
            continue;
        }
        $result[] = rtrim($path, '/');
    }
    return array_values(array_unique($result));
}

function declaredScope(array $objective): array
{
    $explicit = explicitScope($objective['text']);
    if ($explicit !== []) {
        return $explicit;
    }

    // Objectives written before the explicit Scope heading declare their scope through referenced paths.
    $texts = [$objective['text']];
    preg_match_all('/`((?:\.ai|modules|templates|tests|assets)\/[A-Za-z0-9_.\/-]+)`/', $objective['text'], $refs);
    foreach ($refs[1] as $ref) {
        $full = rootDir() . '/' . $ref;
        if (is_file($full) && str_ends_with($ref, '.md')) {
            $texts[] = (string)file_get_contents($full);
        }
    }
    $found = [];
    foreach ($texts as $text) {
        preg_match_all('/`((?:\.ai|modules|templates|tests|assets)\/[A-Za-z0-9_.\/-]+)`/', $text, $matches);
        $found = array_merge($found, $matches[1]);
    }
    $scope = [];
    foreach (normaliseScopes($found) as $path) {
        $parts = explode('/', $path);
        if (($parts[0] ?? '') === 'modules' && isset($parts[1])) {
            $scope[] = 'modules/' . $parts[1];
            $scope[] = 'templates/modules/' . $parts[1];
        } elseif (($parts[0] ?? '') === 'tests') {
            $scope[] = 'tests';
        } elseif (($parts[0] ?? '') !== '.ai') {
            $scope[] = $path;
        }
    }
    // The objective and its named authority records are readable inputs, not writable product scope.
    return array_values(array_unique($scope));
}

function commandAllowed(string $command): ?string
{
    // The destructive-statement pattern is SHARED with the file-delta check, so the two cannot drift.
    //
    // REPAIR 2026-09-19 -- lesson L8, in the second copy. This list matched a bare `\bDROP\b` against the
    // WHOLE command string, so `npx playwright test ... --grep "the drop is legible as it expires"` was
    // refused as "destroy data". The item's own requirement is about a weapon that DROPS, so the lane could
    // not run the test for the thing it was building: two runs, 46 minutes, both escalated, and the item
    // queued behind them never started. It equally refused the read-only `grep -rn "DROP" migrations/`.
    //
    // A guard that cries wolf is worse than none (CD-78); a guard that has been silenced is worse still. So
    // the DROP family is now the shared, precise pattern -- DROP TABLE/DATABASE, TRUNCATE TABLE, ALTER TABLE
    // ... DROP -- and every one of those is still refused (measured).
    require_once __DIR__ . '/destructive-introduction.php';
    $unsafe = [
        HARPP2_DESTRUCTIVE_PATTERN => 'destroy data',
        // MySQL accepts `TRUNCATE tbl` with TABLE omitted, so the bare identifier form must stay refused --
        // but only where it can actually execute, i.e. alongside a SQL client or an interpreter. Requiring
        // that context is what excludes a test title without excluding any real invocation.
        HARPP2_DESTRUCTIVE_CONTEXT_PATTERN => 'destroy data',
        '/(?:^|[;&|]\s*)rm\s+-[^\n]*r/i' => 'destroy data',
        '/\bgit\s+(?:push|clean|reset\s+--hard)\b/i' => 'publish, push, or release',
        '/\b(?:npm\s+publish|composer\s+publish)\b/i' => 'publish, push, or release',
    ];
    foreach ($unsafe as $pattern => $reason) {
        if (preg_match($pattern, $command)) {
            return $reason;
        }
    }
    // Read-only checks commonly silence diagnostics. Other output redirection would mutate the workspace.
    $withoutNullRedirects = preg_replace('#(?:[012]?>|&>)\s*/dev/null#', '', $command);
    if (preg_match('/(?:^|[^>])>(?![>&])/', (string)$withoutNullRedirects)) {
        return 'evidence command attempts to write';
    }
    return null;
}

function runCommand(string $command, int $timeout = 1800): array
{
    $stdoutPath = tempnam(sys_get_temp_dir(), 'harpp2-out-');
    $stderrPath = tempnam(sys_get_temp_dir(), 'harpp2-err-');
    if ($stdoutPath === false || $stderrPath === false) {
        return ['exit' => 127, 'output' => 'could not allocate command output files'];
    }
    $descriptor = [1 => ['file', $stdoutPath, 'w'], 2 => ['file', $stderrPath, 'w']];
    $process = proc_open(['timeout', '--signal=TERM', (string)$timeout, 'bash', '-lc', $command], $descriptor, $pipes, rootDir());
    if (!is_resource($process)) {
        @unlink($stdoutPath);
        @unlink($stderrPath);
        return ['exit' => 127, 'output' => 'could not start command'];
    }
    $exit = proc_close($process);
    $stdout = (string)file_get_contents($stdoutPath);
    $stderr = (string)file_get_contents($stderrPath);
    unlink($stdoutPath);
    unlink($stderrPath);
    return ['exit' => $exit, 'output' => rtrim($stdout . $stderr)];
}

function recordCommand(string $journalPath, string $kind, string $command, array $result): void
{
    journal($journalPath, [
        'event' => 'command', 'kind' => $kind, 'command' => $command,
        'exit_code' => $result['exit'], 'result' => $result['exit'] === 0 ? 'passed' : 'failed',
        'output' => substr($result['output'], 0, 12000),
    ]);
    echo '$ ' . $command . "\n" . ($result['output'] === '' ? "(no output)" : $result['output']) . "\n[exit {$result['exit']}]\n";
}

function runGates(array $commands, string $journalPath, string $kind = 'acceptance'): array
{
    if ($commands === []) {
        return ['passed' => false, 'results' => [], 'reason' => 'objective has no deterministic acceptance commands'];
    }
    $all = true;
    $results = [];
    foreach ($commands as $command) {
        if (($reason = commandAllowed($command)) !== null) {
            $result = ['exit' => 126, 'output' => "REFUSED: {$reason}"];
        } else {
            $result = runCommand($command);
        }
        recordCommand($journalPath, $kind, $command, $result);
        $results[] = ['command' => $command] + $result;
        $all = $all && $result['exit'] === 0;
    }
    return ['passed' => $all, 'results' => $results, 'reason' => $all ? null : 'one or more deterministic gates failed'];
}

function workspaceSnapshot(): array
{
    $listed = runCommand('git ls-files -co --exclude-standard -z', 120);
    if ($listed['exit'] !== 0) {
        fail('cannot inspect the working tree');
    }
    $snapshot = [];
    foreach (explode("\0", $listed['output']) as $path) {
        if ($path === '' || preg_match('#^(?:tools/harpp2/(?:state|runs|escalations)/|\.ai/harpp2-judgement\.md$)#', $path)) {
            continue;
        }
        $full = rootDir() . '/' . $path;
        if (is_file($full) && !is_link($full)) {
            $content = file_get_contents($full);
            $snapshot[$path] = ['hash' => hash('sha256', (string)$content), 'content' => strlen((string)$content) <= 1000000 ? (string)$content : null];
        }
    }
    ksort($snapshot);
    return $snapshot;
}

function changedPaths(array $before, array $after): array
{
    $paths = array_unique(array_merge(array_keys($before), array_keys($after)));
    return array_values(array_filter($paths, static fn(string $p): bool => ($before[$p]['hash'] ?? null) !== ($after[$p]['hash'] ?? null)));
}

function inScope(string $path, array $scope): bool
{
    foreach ($scope as $allowed) {
        if ($path === $allowed || str_starts_with($path, rtrim($allowed, '/') . '/')) {
            return true;
        }
    }
    return false;
}

function boundaryViolations(array $changed, array $before, array $after, array $scope, array $report): array
{
    $violations = [];
    foreach ($changed as $path) {
        if (!inScope($path, $scope)) {
            $violations[] = "outside objective scope: {$path}";
        }
        if (isset($before[$path]) && !isset($after[$path])) {
            $violations[] = "data/file destroyed: {$path}";
        }
        $old = $before[$path]['content'] ?? '';
        $new = $after[$path]['content'] ?? '';
        // Delta-aware (lesson L8, repaired 2026-09-18): a destructive statement the file ALREADY had is not
        // "introduced" by a run that merely touched the file. Only an added statement counts; a destructive
        // operation the executor reports is still a violation further down.
        require_once __DIR__ . '/destructive-introduction.php';
        $destructive = destructiveIntroduction($path, is_string($old) ? $old : null, is_string($new) ? $new : null);
        if ($destructive !== null) {
            $violations[] = $destructive;
        }
        if (in_array(basename($path), ['composer.json', 'package.json'], true) && is_string($old) && is_string($new)) {
            $oldManifest = json_decode($old, true);
            $newManifest = json_decode($new, true);
            foreach (['require', 'dependencies'] as $dependencyKey) {
                $oldDependencies = is_array($oldManifest[$dependencyKey] ?? null) ? $oldManifest[$dependencyKey] : [];
                $newDependencies = is_array($newManifest[$dependencyKey] ?? null) ? $newManifest[$dependencyKey] : [];
                if (array_diff_key($newDependencies, $oldDependencies) !== []) {
                    $violations[] = "runtime dependency added in {$path}";
                }
            }
        }
        if (is_string($old) && is_string($new) && preg_match('/(?:test|spec)/i', $path)) {
            // Assertion-set comparison, not a line diff: reindenting, reordering or splitting a test is not a
            // weakening, while deleting an assertion or loosening a bound still is (lesson L8).
            require_once __DIR__ . '/assertions.php';
            $assertionChange = classifyAssertionChange($old, $new);
            foreach ($assertionChange['removed'] as $assertion) {
                $violations[] = "test assertion removed: {$path} — {$assertion}";
            }
            foreach ($assertionChange['loosened'] as $bound) {
                $violations[] = "test assertion weakened (bound loosened): {$path} — {$bound}";
            }
        }
        if (is_string($old) && is_string($new)) {
            $removed = array_diff(preg_split('/\R/', $old), preg_split('/\R/', $new));
            if (preg_grep('/\b(?:authori[sz]|authenticat|csrf|permission|access.?control)\b/i', $removed)) {
                $violations[] = "security control may have been weakened: {$path}";
            }
        }
    }
    foreach (($report['destructive_operations'] ?? []) as $operation) {
        $violations[] = 'executor reported destructive operation: ' . (string)$operation;
    }
    return array_values(array_unique($violations));
}

function governanceOnly(array $changed, array $before, array $after): bool
{
    if ($changed === []) {
        return false;
    }
    foreach ($changed as $path) {
        $content = strtolower((string)($after[$path]['content'] ?? ''));
        $name = strtolower($path);
        $isNewDocument = !isset($before[$path]) && (bool)preg_match('/\.(?:md|txt|json|ya?ml)$/', $name);
        $governanceName = preg_match('/(?:measurement|metric|pillar|census|taxonomy|process[-_ ]?contract|status[-_ ]?report)/', $name);
        $governanceDocument = preg_match('/\b(?:measurement framework|pillar list|governance taxonomy|process status report)\b/', $content);
        if (!$isNewDocument || (!$governanceName && !$governanceDocument)) {
            return false;
        }
    }
    return true;
}

function parseReport(string $log): array
{
    $report = [];
    if (preg_match_all('/HARPP2_RESULT:\s*(\{[^\r\n]*\})/', $log, $matches)) {
        $candidate = json_decode((string)end($matches[1]), true);
        if (is_array($candidate)) {
            $report = $candidate;
        }
    }
    return $report;
}

function dispatchLane(array $objective, array $state, array $scope, int $maxStalls, string $journalPath): array
{
    $attempt = $state['chunks_attempted'] + 1;
    $strategy = match (min((int)$state['no_progress'], 2)) {
        0 => 'Choose the smallest unfinished product chunk ending in a live deterministic check.',
        1 => 'REPLAN: the previous action made no verified progress. Choose a different, smaller product change and a focused check.',
        default => 'DIFFERENT APPROACH: do not repeat the prior action; isolate and implement another concrete route to an acceptance failure.',
    };
    $antiStall = $state['stalls'] > 0
        ? "ANTI-STALL: A prior lane asked instead of acting. Do not ask anything. Make a reversible engineering decision and produce the artifact and evidence.\n"
        : '';
    $brief = "# HARPP v2 executor chunk\n\nObjective: `{$objective['relative']}`\n\nRead the objective, its authority records, and the constitution. {$strategy}\n{$antiStall}"
        . "Work only inside this declared writable scope:\n- `" . implode("`\n- `", $scope) . "`\n\n"
        . "Never destroy data, weaken security or a test, add a runtime dependency, publish, push, or release. Do not create process/governance documents. "
        . "Run focused evidence commands, but treat their output as a claim the driver will reproduce. End with exactly one single-line record:\n"
        . 'HARPP2_RESULT: {"artifact_paths":["path"],"evidence_commands":["command"],"summary":"what changed","stop_condition":null,"question":null,"destructive_operations":[]}' . "\n"
        . "If genuinely stopped, stop_condition must be exactly authority, boundary, or irreversibility and summary must name the exact blocker. "
        . "If nothing stopped you, omit it: use null. Do NOT name a breach that does not exist — a false irreversibility was once "
        . "reported for work that was merely unfinished, and it sent the chair hunting for a ceiling that was not there (CD-74). "
        . "Never put an owner question in question.\n";
    $briefPath = tempnam(sys_get_temp_dir(), 'harpp2-');
    if ($briefPath === false) {
        fail('cannot create executor brief');
    }
    file_put_contents($briefPath, $brief);
    $name = $objective['slug'] . '-' . $attempt . '-' . substr(hash('sha256', microtime(true) . ''), 0, 6);
    $model = getenv('HARPP2_MODEL') ?: 'deepseek/deepseek-v4-flash';
    $thinking = getenv('HARPP2_THINKING') ?: 'low';
    $dispatcher = getenv('HARPP2_DISPATCH') ?: rootDir() . '/tools/harpp2/dispatch.sh';
    $command = escapeshellarg($dispatcher) . ' ' . escapeshellarg($name) . ' ' . escapeshellarg($model) . ' ' . escapeshellarg($thinking) . ' ' . escapeshellarg($briefPath);
    journal($journalPath, ['event' => 'dispatch', 'action' => $strategy, 'attempt' => $attempt, 'command' => $command, 'scope' => $scope]);
    $result = runCommand($command, 3600);
    unlink($briefPath);
    recordCommand($journalPath, 'dispatch', $command, $result);
    $logPath = rootDir() . '/tools/harpp2/runs/' . $name . '.log';
    $log = is_file($logPath) ? (string)file_get_contents($logPath) : $result['output'];
    return ['name' => $name, 'action' => $strategy, 'exit' => $result['exit'], 'output' => $result['output'], 'log' => $log, 'report' => parseReport($log)];
}

function appendJudgement(string $paragraph): void
{
    $path = rootDir() . '/.ai/harpp2-judgement.md';
    file_put_contents($path, trim(preg_replace('/\s+/', ' ', $paragraph)) . "\n\n", FILE_APPEND | LOCK_EX);
}

function escalate(array &$state, array $objective, array $paths, string $condition, string $blocker): never
{
    if (!in_array($condition, STOP_CONDITIONS, true)) {
        journal($paths['journal'], ['event' => 'defective_stop', 'result' => 'continued', 'reason' => 'stop lacked authority, boundary, or irreversibility']);
        throw new RuntimeException('defective stop must continue');
    }
    $dir = rootDir() . '/tools/harpp2/escalations';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $file = $dir . '/' . $objective['slug'] . '-' . date('Ymd-His') . '.md';
    $body = "# Escalation: {$objective['relative']}\n\n**Condition:** {$condition}\n\n**Exact blocker:** {$blocker}\n\n"
        . "## Options\n\n1. Authorize a narrowly scoped exception; work can continue, but the named boundary or impact is accepted.\n"
        . "2. Change the objective to avoid the blocker; this preserves the boundary but may reduce or delay the outcome.\n"
        . "3. Leave the objective unchanged and stopped; no further workspace changes are made.\n\n"
        . "## Recommendation\n\nChoose option 2 when a safe path exists after changing scope; otherwise explicitly decide between options 1 and 3.\n";
    file_put_contents($file, $body);
    $state['status'] = 'escalated';
    $state['condition'] = $condition;
    $state['reason'] = $blocker;
    $state['blocked']++;
    $state['active_lane'] = null;
    saveState($state, $paths['state']);
    journal($paths['journal'], ['event' => 'escalation', 'condition' => $condition, 'result' => 'stopped', 'blocker' => $blocker, 'file' => substr($file, strlen(rootDir()) + 1)]);
    appendJudgement("The objective could not continue because {$blocker}; the stopping condition was {$condition}.");
    fwrite(STDERR, "[harpp2] ESCALATED ({$condition}): {$blocker}\n[harpp2] {$file}\n");
    exit(4);
}

/**
 * A NON-boundary hand-off: no-progress, an objective defect, or an unstable instrument.
 *
 * WHY THIS EXISTS (CD-74, cause 2). escalate() refuses any condition that is not authority, boundary or
 * irreversibility — so the no-progress trigger had to CLAIM `irreversibility` in order to be heard, and the
 * chair was then shown a boundary that did not exist: "three materially different attempts produced no verified
 * product progress; further blind changes would be high-impact". A finding that is not a boundary must be able
 * to say so, or the label lies about the kind of failure and the reader hunts for a ceiling that is not there.
 *
 * Status stays `escalated` so the chain still notifies the owner and writes its hand-off; `boundary: false` and
 * the condition carry the truth, and the hand-off file states it in its first line.
 */
function needsChair(array &$state, array $objective, array $paths, string $condition, string $reason): never
{
    $dir = rootDir() . '/tools/harpp2/escalations';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $file = $dir . '/' . $objective['slug'] . '-' . date('Ymd-His') . '-chair.md';
    $body = "# Chair correction needed: {$objective['relative']}\n\n"
        . "**Boundary:** NONE — this is not an authority, boundary or irreversibility stop.\n\n"
        . "**Finding ({$condition}):** {$reason}\n\n"
        . "## What this is\n\n"
        . "The automated loop stopped because it cannot correct this itself. What reaches here is an objective\n"
        . "defect (a requirement unreachable as written), an instrument defect (an unstable or vacuous check), or a\n"
        . "stalled approach — all of them the chair's to correct, none of them a director decision.\n\n"
        . "## Options\n\n"
        . "1. Correct the objective or the instrument and resume the same item.\n"
        . "2. Re-scope the item so the remaining work is achievable as written.\n"
        . "3. Abandon the item and record why.\n\n"
        . "## Recommendation\n\nOption 1 when the product artifact is present and only the check is at fault;\n"
        . "option 2 when the requirement itself is unreachable.\n";
    file_put_contents($file, $body);
    $state['status'] = 'escalated';
    $state['condition'] = $condition;
    $state['boundary'] = false;
    $state['reason'] = $reason;
    $state['blocked']++;
    $state['active_lane'] = null;
    saveState($state, $paths['state']);
    journal($paths['journal'], ['event' => 'chair_correction', 'condition' => $condition, 'boundary' => false, 'result' => 'stopped', 'reason' => $reason, 'file' => substr($file, strlen(rootDir()) + 1)]);
    appendJudgement("The objective could not continue because {$reason}; this is a {$condition} finding, NOT an authority, boundary or irreversibility stop — it is a chair correction.");
    fwrite(STDERR, "[harpp2] CHAIR CORRECTION NEEDED ({$condition}): {$reason}\n[harpp2] {$file}\n");
    exit(4);
}

function statusCommand(array $objective, array $paths): void
{
    $state = loadState($objective, $paths);
    echo "HARPP v2\nObjective: {$state['objective']}\nStatus: {$state['status']}" . ($state['condition'] ? " ({$state['condition']})" : '') . "\n"
        . "Chunks: {$state['chunks_attempted']} attempted, {$state['verified']} verified, {$state['blocked']} blocked\n"
        . 'Why: ' . ($state['reason'] ?? 'No blocker recorded.') . "\n";
}

function journalCommand(array $paths): void
{
    if (!is_file($paths['journal'])) {
        echo "(journal is empty)\n";
        return;
    }
    readfile($paths['journal']);
}

function runObjective(array $objective, array $paths, int $maxStalls): void
{
    $lock = fopen($paths['lock'], 'c+');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        fail('REFUSED: another HARPP v2 driver is running; serial dispatch requires one writer', 3);
    }
    $state = loadState($objective, $paths);
    $state['status'] = 'running';
    $state['condition'] = null;
    $state['reason'] = null;
    saveState($state, $paths['state']);
    $commands = acceptanceCommands($objective['text']);
    $scope = declaredScope($objective);
    journal($paths['journal'], ['event' => 'run_started', 'objective' => $objective['relative'], 'boundaries' => HARPP2_BOUNDARIES, 'scope' => $scope]);
    if ($scope === []) {
        escalate($state, $objective, $paths, 'authority', 'the objective declares no writable path scope');
    }
    if ($commands === []) {
        escalate($state, $objective, $paths, 'authority', 'the objective supplies no deterministic acceptance command');
    }

    $initial = runGates($commands, $paths['journal']);
    if ($initial['passed']) {
        $state['status'] = 'verified';
        $state['reason'] = 'all objective acceptance gates passed';
        saveState($state, $paths['state']);
        journal($paths['journal'], ['event' => 'objective_verified', 'result' => 'verified']);
        echo "[harpp2] objective verified\n";
        return;
    }

    while (true) {
        $before = workspaceSnapshot();
        $state['active_lane'] = 'dispatching';
        saveState($state, $paths['state']);
        $lane = dispatchLane($objective, $state, $scope, $maxStalls, $paths['journal']);
        $state['active_lane'] = null;
        $state['chunks_attempted']++;
        $state['last_action'] = $lane['action'];
        $after = workspaceSnapshot();
        $changed = changedPaths($before, $after);
        $report = $lane['report'];
        journal($paths['journal'], ['event' => 'observed', 'lane' => $lane['name'], 'exit_code' => $lane['exit'], 'changed_paths' => $changed, 'claim' => $report]);

        $violations = boundaryViolations($changed, $before, $after, $scope, $report);
        if ($violations !== []) {
            saveState($state, $paths['state']);
            escalate($state, $objective, $paths, 'boundary', implode('; ', $violations));
        }

        $evidence = array_values(array_filter((array)($report['evidence_commands'] ?? []), 'is_string'));
        $question = trim((string)($report['question'] ?? ''));
        $looksLikeQuestion = $question !== '' || ($report === [] && $changed === [] && $evidence === [] && str_contains($lane['log'], '?'));
        if ($looksLikeQuestion && $changed === [] && $evidence === []) {
            $state['stalls']++;
            $state['no_progress']++;
            $state['reason'] = 'executor lane stalled by asking instead of producing an artifact';
            journal($paths['journal'], ['event' => 'stall', 'lane' => $lane['name'], 'result' => 'redispatch', 'attempt' => $state['stalls']]);
            appendJudgement('The lane could not continue because it asked a question without producing an artifact or running evidence; no authority, boundary, or irreversibility condition applied, so the defective stop was not surfaced and the anti-stall redispatch rule continued the objective.');
            saveState($state, $paths['state']);
            if ($state['stalls'] <= $maxStalls) {
                continue;
            }
        }

        $stop = $report['stop_condition'] ?? null;
        if ($stop !== null && !in_array($stop, STOP_CONDITIONS, true)) {
            journal($paths['journal'], ['event' => 'defective_stop', 'lane' => $lane['name'], 'result' => 'continued', 'condition' => $stop]);
            $stop = null;
        }
        if ($stop !== null && $changed === []) {
            escalate($state, $objective, $paths, $stop, (string)($report['summary'] ?? 'executor reported a genuine blocker'));
        }

        $evidencePassed = $evidence !== [];
        foreach ($evidence as $evidenceCommand) {
            if (($unsafe = commandAllowed($evidenceCommand)) !== null) {
                escalate($state, $objective, $paths, 'boundary', "refused unsafe evidence command `{$evidenceCommand}`: {$unsafe}");
            }
            $result = runCommand($evidenceCommand);
            recordCommand($paths['journal'], 'chunk-evidence', $evidenceCommand, $result);
            $evidencePassed = $evidencePassed && $result['exit'] === 0;
        }
        $productChange = $changed !== [] && !governanceOnly($changed, $before, $after);
        $progress = $productChange && $evidencePassed;

        require_once __DIR__ . '/verify.php';
        $gates = runGates($commands, $paths['journal']);
        if (!$gates['passed']) {
            // A gate that fails and then passes is an unstable instrument, not a failing product
            // (CD-74 / lesson L4). Classify it, rather than letting it be read as no progress.
            $retry = runGates($commands, $paths['journal']);
            if (classifyGateOutcome($gates, $retry) === 'flaky') {
                $state['flaky'] = (int)($state['flaky'] ?? 0) + 1;
                $state['reason'] = 'acceptance failed once and passed on retry — FLAKY (harness instability, not a product failure)';
                journal($paths['journal'], ['event' => 'flaky_verification', 'result' => 'classified_flaky', 'attempt' => $state['flaky']]);
                appendJudgement('The acceptance failed once and passed on retry: classified FLAKY — harness instability, not a product failure and not no-progress. The instrument must be made deterministic before the next run.');
                $gates = $retry;
                saveState($state, $paths['state']);
            } else {
                journal($paths['journal'], ['event' => 'acceptance_failed', 'result' => 'failed_twice']);
            }
        }
        if ($progress) {
            $state['verified']++;
            $state['no_progress'] = 0;
            $state['stalls'] = 0;
            $state['approach'] = 0;
            $state['reason'] = $gates['passed'] ? 'all objective acceptance gates passed' : 'verified chunk complete; objective gates still fail';
            journal($paths['journal'], ['event' => 'chunk_result', 'lane' => $lane['name'], 'result' => 'verified', 'changed_paths' => $changed]);
            appendJudgement($gates['passed']
                ? 'The lane did not continue further because its product artifact and evidence completed the remaining acceptance gates; objective completion, not authority, boundary, or irreversibility, ended the loop.'
                : 'The lane did not continue further because serial dispatch limits one lane to one verified chunk; this did not stop the objective, and the driver continued to the next failing acceptance gate.');
        } else {
            $state['no_progress']++;
            $state['approach'] = min(2, (int)$state['approach'] + 1);
            $state['reason'] = governanceOnly($changed, $before, $after)
                ? 'lane produced only a governance artifact'
                : 'chunk lacked both a product artifact and reproducible passing evidence';
            journal($paths['journal'], ['event' => 'chunk_result', 'lane' => $lane['name'], 'result' => 'no-progress', 'changed_paths' => $changed, 'evidence_passed' => $evidencePassed]);
            appendJudgement("The lane could not continue because {$state['reason']}; no authority, boundary, or irreversibility condition applied, so the bounded no-progress rule changed the next approach instead of stopping.");
        }
        saveState($state, $paths['state']);

        if ($gates['passed']) {
            $state['status'] = 'verified';
            saveState($state, $paths['state']);
            journal($paths['journal'], ['event' => 'objective_verified', 'result' => 'verified']);
            echo "[harpp2] objective verified\n";
            return;
        }
        if ($state['no_progress'] >= 3) {
            needsChair(
                $state,
                $objective,
                $paths,
                'no_progress',
                'three approaches produced no product progress — a finding about the objective or the instrument, not a boundary'
            );
        }
    }
}

[$command, $options] = cli($argv);
$objective = objectiveInfo($options['objective']);
$paths = pathsFor($objective);
match ($command) {
    'status' => statusCommand($objective, $paths),
    'journal' => journalCommand($paths),
    'run' => runObjective($objective, $paths, $options['max-stalls']),
};
