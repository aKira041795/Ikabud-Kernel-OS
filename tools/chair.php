#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * CHAIR — the development loop.
 *
 * WHAT THIS REPLACES, AND WHY
 * tools/harpp2/ (objectives, phases, gate maps, escalations, chain, dispatch) plus the
 * ai-autonomy / ai-run / ai-project trio was ~300KB that measured the PROCESS: contracts about
 * contracts, journals, ladders, and an escalation taxonomy whose whole purpose was to ask the
 * director questions the chair is appointed to answer. Measured 2026-09-19: one item took 46
 * minutes through that machinery and was refused at the end over a substring, while a peer item
 * done directly took 10, and four of the machinery's own measurements were wrong in ways that
 * cost more than the work they guarded.
 *
 * This is the replacement, and it is deliberately small. It does not invent a control plane --
 * the kernel already has one, and this drives it:
 *
 *   kernel/Workbench/Development/DevelopmentTaskRepository  durable tasks, immutable revisions,
 *                                                           timeline, evidence refs, index lock
 *   kernel/Workbench/Development/DevelopmentLifecycle       the states a task moves through
 *   kernel/Workbench/Development/DevelopmentArtifactIngestor scope classification, architecture
 *                                                           import, baseline capture
 *   kernel/Workbench/Development/DevelopmentVerificationArtifact  signed result artifacts
 *
 * THE FIVE RULES, AND WHAT EACH ONE IS FOR
 *
 * 1. THE PROBE IS THE ACCEPTANCE. A task declares one command that decides it. There is no gate
 *    map, no phase list, no second opinion. If the probe is wrong the task is wrong, and the fix
 *    is to fix the probe.
 *
 * 2. THE BASELINE IS TAKEN BEFORE ANY WORK. `run` executes the probe first. A probe that passes
 *    before the work is done is measuring the wrong property -- that is how a moon threshold
 *    scored a pale-cyan planet as "grey" on 2026-09-19. Here that case is not a judgement call:
 *    the run stops and says the task is already satisfied.
 *
 * 3. THE CHAIR DECIDES. Failure promotes the reasoning level and retries; exhaustion records a
 *    BLOCKED decision with the options that were considered. Nothing is referred to the director.
 *    The director is informed when something ships, never asked to unblock what was delegated.
 *
 * 4. CONTEXT IS RETRIEVED, NOT DUMPED. The brief is assembled from the task's own scope, ranked
 *    by relevance to its objective. No whole-repository reads.
 *
 * 5. THE FLOOR IS ABSOLUTE. Destructive commands are refused -- rm -rf, pushes, real DROP /
 *    TRUNCATE statements -- and every refusal pattern is proved in BOTH directions by
 *    `--self-test`, so a policy that cries wolf is caught here rather than in a live run.
 *
 * usage:
 *   php tools/chair.php plan   --contract=<file.md> [--task=<id>] [--actor=<id>]
 *   php tools/chair.php run    --task=<id> [--attempts=2] [--lane=<model>:<thinking>,...] [--dry]
 *   php tools/chair.php probe  --task=<id>
 *   php tools/chair.php decide --task=<id> --decision=<text> [--rationale=<text>]
 *   php tools/chair.php status [--task=<id>]
 *   php tools/chair.php --self-test
 */

require_once __DIR__ . '/../kernel/Workbench/Development/DevelopmentTaskContract.php';
require_once __DIR__ . '/../kernel/Workbench/Development/DevelopmentTaskRepository.php';
require_once __DIR__ . '/../kernel/Workbench/Development/DevelopmentLifecycle.php';
require_once __DIR__ . '/../kernel/Workbench/Development/GitEvidenceResolver.php';
require_once __DIR__ . '/../kernel/Workbench/Development/DevelopmentArtifactIngestor.php';

require_once __DIR__ . '/../kernel/Workbench/Retrieval/RetrievalIndex.php';

use Ikabud\Kernel\Workbench\Retrieval\RetrievalIndex;
use Ikabud\Kernel\Workbench\Development\DevelopmentArtifactIngestor;
use Ikabud\Kernel\Workbench\Development\DevelopmentLifecycle;
use Ikabud\Kernel\Workbench\Development\DevelopmentTaskRepository;

// dirname, not __DIR__ . '/..': the root is compared against walked paths, and a non-normalised
// '/tools/..' makes every relative-path computation depend on string luck.
const CHAIR_ROOT = __DIR__ . '/..';

// ─────────────────────────────────────────────────────────────────────────────────────────────────
// The floor. Absolute, and proved in both directions by --self-test.
// ─────────────────────────────────────────────────────────────────────────────────────────────────
//
// Single-sourced deliberately. On 2026-09-19 the same destructive pattern lived in two places, was
// repaired in one, and the surviving copy refused `npx playwright test --grep "the drop is legible"`;
// the word "drop" cost two runs and 46 minutes. One pattern, one place, both directions asserted.

/** SQL statements that destroy stored data. DROP must name a TABLE or DATABASE: a bare word is prose. */
const CHAIR_DESTRUCTIVE_SQL = '/\b(?:DROP\s+(?:TABLE|DATABASE)|TRUNCATE\s+TABLE|ALTER\s+TABLE\b[^;]*\bDROP\b)/i';

/** MySQL's short `TRUNCATE tbl`, which only counts where it can execute. */
const CHAIR_DESTRUCTIVE_CONTEXT = '/\b(?:mysql|mariadb|psql|sqlite3|php|python3?|node|perl|ruby)\b[^\n]*\bTRUNCATE\b/i';

const CHAIR_UNSAFE = [
    CHAIR_DESTRUCTIVE_SQL => 'destroys stored data',
    CHAIR_DESTRUCTIVE_CONTEXT => 'destroys stored data',
    '/(?:^|[;&|]\s*)rm\s+-[^\n]*r/i' => 'destroys files',
    '/\bgit\s+(?:push|clean|reset\s+--hard)\b/i' => 'publishes or discards work',
    '/\b(?:npm|composer)\s+publish\b/i' => 'publishes',
];

/** Null when the command may run, else the reason it may not. */
function commandRefusal(string $command): ?string
{
    foreach (CHAIR_UNSAFE as $pattern => $reason) {
        if (preg_match($pattern, $command) === 1) {
            return $reason;
        }
    }

    return null;
}

// ─────────────────────────────────────────────────────────────────────────────────────────────────
// Output. One shape everywhere, so a run's result is readable at a glance and greppable.
// ─────────────────────────────────────────────────────────────────────────────────────────────────

function say(string $line = ''): void
{
    fwrite(STDOUT, $line . PHP_EOL);
}

function fail(string $message): never
{
    fwrite(STDERR, 'chair: ' . $message . PHP_EOL);
    exit(2);
}

/** @param array<string,string> $fields */
function record(string $kind, array $fields): void
{
    $parts = [];
    foreach ($fields as $key => $value) {
        $parts[] = $key . '=' . (str_contains($value, ' ') ? '"' . $value . '"' : $value);
    }
    say(sprintf('[%s] %s', $kind, implode(' ', $parts)));
}

// ─────────────────────────────────────────────────────────────────────────────────────────────────
// Options. No framework: a handful of --name=value flags is the whole surface.
// ─────────────────────────────────────────────────────────────────────────────────────────────────

/** @return array{command:string, options:array<string,string>, flags:list<string>} */
function cli(array $argv): array
{
    $command = '';
    $options = [];
    $flags = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--') && str_contains($argument, '=')) {
            [$name, $value] = explode('=', substr($argument, 2), 2);
            $options[$name] = $value;
            continue;
        }
        if (str_starts_with($argument, '--')) {
            $flags[] = substr($argument, 2);
            continue;
        }
        if ($command === '') {
            $command = $argument;
        }
    }

    return ['command' => $command, 'options' => $options, 'flags' => $flags];
}

function repository(): DevelopmentTaskRepository
{
    return new DevelopmentTaskRepository(CHAIR_ROOT . '/storage/private/workbench/development');
}

function ingestor(DevelopmentTaskRepository $repo): DevelopmentArtifactIngestor
{
    return new DevelopmentArtifactIngestor($repo, null, CHAIR_ROOT);
}

// ─────────────────────────────────────────────────────────────────────────────────────────────────
// The probe: the one command that decides a task.
// ─────────────────────────────────────────────────────────────────────────────────────────────────

/**
 * Flatten a contract section to text.
 *
 * The stored contract is not the markdown: DevelopmentTaskContract normalizes sections into arrays
 * of bullets, so `required_tests` comes back as a list. Casting that to string yields the word
 * "Array" and the probe silently disappears -- measured 2026-09-19, the first run of this tool failed
 * with "declares no probe" while the probe was sitting right there in the revision.
 */
function sectionText(mixed $value): string
{
    if (is_array($value)) {
        $lines = [];
        foreach ($value as $item) {
            $lines[] = is_array($item) ? (string) json_encode($item, JSON_UNESCAPED_SLASHES) : (string) $item;
        }

        return implode("\n", $lines);
    }

    return (string) $value;
}

/** @param array<string,mixed> $contract @return list<string> */
function taskProbes(array $contract): array
{
    // NOT contractProbes(): that one hunts for the `## Required tests` heading in markdown, and the
    // stored section has no heading -- it is the bullet values themselves. Handing stored text to the
    // markdown reader returned an empty list and the tool reported "declares no probe" while the probe
    // sat in the revision. Measured 2026-09-19, on this tool's own first run.
    return probeLines(sectionText($contract['required_tests'] ?? ''));
}

/**
 * The contract a task was created from.
 *
 * getTask() returns the TASK RECORD: state, objective, approved_scope, baseline, verification,
 * contract_revision -- and no contract. The contract lives in the immutable revision it names, so a
 * reader that expects `contract` on the task finds nothing and reports a contract with no probe in it.
 * Measured 2026-09-19: the first run of this tool failed exactly that way while the probe sat in the
 * revision file.
 *
 * @param array<string,mixed> $task
 * @return array<string,mixed>
 */
function taskContract(DevelopmentTaskRepository $repo, array $task): array
{
    $revision = (string) ($task['contract_revision'] ?? '');
    if ($revision === '') {
        return [];
    }

    return (array) ($repo->getRevision((string) $task['task_id'], $revision)['contract'] ?? []);
}

/**
 * The paths a task declares it may change.
 *
 * @param array<string,mixed> $contract
 * @return list<string>
 */
function taskScope(array $contract): array
{
    $paths = [];
    foreach (['allowed_scope', 'files_affected'] as $key) {
        foreach ((array) ($contract[$key] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['path'])) {
                $paths[] = (string) $entry['path'];
            } elseif (is_string($entry)) {
                $paths[] = $entry;
            }
        }
    }

    return array_values(array_unique($paths));
}

/**
 * Command-shaped lines in a block of text. The one place a probe is recognised.
 *
 * @return list<string>
 */
function probeLines(string $text): array
{
    $probes = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        $candidate = trim((string) $line, " \t-*`\r");
        if (preg_match('#^(php|npx|composer|node|vendor/bin/[a-z]+|bash|sh)\b#i', $candidate) !== 1) {
            continue;
        }
        $probes[] = $candidate;
    }

    return array_values(array_unique($probes));
}

/**
 * Read the probe out of a contract's `## Required tests` section in markdown.
 *
 * The kernel's contract format is the interface -- it is parsed, validated and stored by
 * DevelopmentTaskContract, so the probe rides in a section that already exists rather than in a
 * new file format. The first command-shaped line wins: a task that needs two commands to decide
 * it has not decided what it is asking for.
 *
 * @return list<string>
 */
function contractProbes(string $markdown): array
{
    $section = '';
    $inSection = false;
    foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
        if (preg_match('/^#{1,3}\s+Required tests\s*$/i', (string) $line) === 1) {
            $inSection = true;
            continue;
        }
        if ($inSection && preg_match('/^#{1,3}\s+/', (string) $line) === 1) {
            break;
        }
        if ($inSection) {
            $section .= (string) $line . "\n";
        }
    }

    $probes = probeLines($section);

    return array_values(array_unique($probes));
}

/**
 * Run the probe and return its verdict.
 *
 * @return array{command:string, exit:int, output:string}
 */
function runProbe(string $command, int $timeout = 900): array
{
    $refusal = commandRefusal($command);
    if ($refusal !== null) {
        fail("refusing the probe `{$command}`: {$refusal}");
    }

    $process = proc_open(
        ['timeout', '--signal=TERM', (string) $timeout, 'bash', '-lc', $command],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        CHAIR_ROOT
    );
    if (!is_resource($process)) {
        fail("could not start the probe: {$command}");
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    // The tail is what carries the verdict: a passing suite prints tens of lines, and the last
    // few say "N passed" or name the failure. Keeping all of it would drown the run's own record.
    $combined = trim($stdout . $stderr);
    $lines = preg_split('/\R/', $combined) ?: [];
    $tail = implode("\n", array_slice($lines, -12));

    return ['command' => $command, 'exit' => $exit, 'output' => $tail];
}

// ─────────────────────────────────────────────────────────────────────────────────────────────────
// Context: the persisted retrieval index, not a walk per call.
// ─────────────────────────────────────────────────────────────────────────────────────────────────

/**
 * The repository's retrieval index.
 *
 * The context a lane receives comes from kernel/Workbench/Retrieval, not from a directory walk done
 * here. The difference is not elegance: a walk scores from scratch on every call and forgets what it
 * learned, so nothing can ask "is this still current?". The index is incremental by content hash and
 * answers that question directly, which is what lets a caller trust it instead of re-reading files.
 */
function chairIndex(): RetrievalIndex
{
    return new RetrievalIndex(CHAIR_ROOT . '/storage/private/retrieval', CHAIR_ROOT);
}

/**
 * Rank the files inside a task's declared scope against its objective.
 *
 * The scope is refreshed first, so the context is current by construction: only files whose content
 * changed are re-read, and a scope of a dozen files costs a few milliseconds.
 *
 * @param list<string> $scope repository-relative files or directories
 * @return list<array{path:string, score:int, lines:int}>
 */
function retrieveContext(string $objective, array $scope, int $limit = 12): array
{
    $index = chairIndex();
    if ($scope !== []) {
        $index->index($scope);
    }
    $result = $index->search($objective, $limit, $scope);

    return array_map(
        static fn (array $hit): array => ['path' => $hit['path'], 'score' => $hit['score'], 'lines' => $hit['lines']],
        $result['hits']
    );
}

// ─────────────────────────────────────────────────────────────────────────────────────────────────
// The brief a lane receives.
// ─────────────────────────────────────────────────────────────────────────────────────────────────

function briefPath(string $taskId): string
{
    $dir = CHAIR_ROOT . '/storage/private/chair/briefs';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fail("cannot create {$dir}");
    }

    return $dir . '/' . $taskId . '-' . gmdate('Ymd-His') . '.md';
}

/** @param array<string,mixed> $task */
function writeBrief(array $task, string $objective, array $context, string $probe, string $previous): string
{
    $lines = [];
    $lines[] = '# BRIEF — ' . $task['task_id'];
    $lines[] = '';
    $lines[] = '## Objective';
    $lines[] = $objective;
    $lines[] = '';
    $lines[] = '## The probe: the only thing that decides this task';
    $lines[] = 'Run it before you finish. It must pass, and it must have failed before you started.';
    $lines[] = '';
    $lines[] = '```';
    $lines[] = $probe;
    $lines[] = '```';
    $lines[] = '';
    if ($context !== []) {
        $lines[] = '## Retrieved context (ranked for this objective — read these, not the repository)';
        foreach ($context as $entry) {
            $lines[] = sprintf('- `%s` (%d lines, relevance %d)', $entry['path'], $entry['lines'], $entry['score']);
        }
        $lines[] = '';
    }
    if ($previous !== '') {
        $lines[] = '## The previous attempt failed like this';
        $lines[] = '```';
        $lines[] = $previous;
        $lines[] = '```';
        $lines[] = '';
    }
    $lines[] = '## Rules that are not negotiable';
    $lines[] = '- Work only inside the declared scope. Anything else is a finding, not a fix.';
    $lines[] = '- Never weaken the probe, a test, a threshold, or a gate to obtain a pass.';
    $lines[] = '- Do not add a dependency, change a schema, or touch auth.';
    $lines[] = '- Report the command you ran and its real output. A claim without a command is not evidence.';
    $lines[] = '- You are not asked to justify the work. Ordinary engineering decisions are yours.';

    $path = briefPath((string) $task['task_id']);
    file_put_contents($path, implode("\n", $lines) . "\n");

    return $path;
}

// ─────────────────────────────────────────────────────────────────────────────────────────────────
// Scope: what the run actually changed, judged against what it was allowed to change.
// ─────────────────────────────────────────────────────────────────────────────────────────────────

/**
 * Changed paths in the working tree, excluding the harness's own scratch storage.
 *
 * @return list<string>
 */
function changedPaths(): array
{
    $output = shell_exec('cd ' . escapeshellarg(CHAIR_ROOT) . ' && git status --porcelain 2>/dev/null');
    $paths = [];
    foreach (preg_split('/\R/', (string) $output) ?: [] as $line) {
        if (trim($line) === '') {
            continue;
        }
        $path = trim(substr($line, 3));
        if (str_contains($path, ' -> ')) {
            $path = trim(explode(' -> ', $path)[1]);
        }
        if (str_starts_with($path, 'storage/private/') || str_starts_with($path, '.ai/')) {
            continue;
        }
        $paths[] = $path;
    }

    return array_values(array_unique($paths));
}

// ─────────────────────────────────────────────────────────────────────────────────────────────────
// Which model, for which work.
// ─────────────────────────────────────────────────────────────────────────────────────────────────
//
// Delegation is only worth it when the loop is cheap to run, and the probe is what makes it cheap: any
// model's output is judged identically, so a wrong lane costs one cycle rather than a bad merge. That is
// what makes it safe to pick a lane by the KIND of work instead of by price alone.
//
// Measured 2026-09-19: the harness's own note recorded that the game's visual pass went better on sol, and
// that starting on flash "would spend an attempt to learn nothing". The reason string is the durable part;
// the model name is just today's best answer to it, and rots as models are retired.

const CHAIR_LANES = [
    'mechanical' => [
        'lane' => 'deepseek/deepseek-v4-flash:low',
        'why' => 'Bulk edits, a rename, a declared field, a census, a repetitive sweep. Fast and cheap, and the probe catches the mistakes that matter.',
    ],
    'visual' => [
        'lane' => 'openai-codex/gpt-5.6-sol:medium',
        'why' => 'Drawing, layout, palette, game feel, anything judged by looking at it. The rendered-canvas work on this project went better on sol than on flash.',
    ],
    'reasoning' => [
        'lane' => 'openai-codex/gpt-5.6-sol:high',
        'why' => 'An architecture contradiction, an unresolved design, or a second failure on the same probe. Spend here only when a cheaper lane has already failed to.',
    ],
];

/** The lane for a kind of work, or null when the kind is not one the registry knows. */
function laneFor(string $kind): ?array
{
    return CHAIR_LANES[$kind] ?? null;
}

// ─────────────────────────────────────────────────────────────────────────────────────────────────
// Commands
// ─────────────────────────────────────────────────────────────────────────────────────────────────

function commandPlan(array $options): int
{
    $contractPath = $options['contract'] ?? '';
    if ($contractPath === '' || !is_file($contractPath)) {
        fail('plan needs --contract=<file.md> pointing at a kernel-format contract');
    }
    $markdown = (string) file_get_contents($contractPath);

    $probes = contractProbes($markdown);
    if ($probes === []) {
        fail('the contract declares no probe: put the deciding command in `## Required tests`');
    }
    foreach ($probes as $probe) {
        $refusal = commandRefusal($probe);
        if ($refusal !== null) {
            fail("the contract's probe `{$probe}` is refused by policy: {$refusal}");
        }
    }

    $repo = repository();
    $actor = $options['actor'] ?? 'chair';
    $importOptions = [
        'source_path' => ltrim(str_replace(dirname(__DIR__), '', realpath($contractPath) ?: $contractPath), '/'),
    ];
    if (($options['task'] ?? '') !== '') {
        $importOptions['task_id'] = $options['task'];
    }
    if (($options['lane'] ?? '') !== '') {
        $importOptions['lane'] = $options['lane'];
    }
    $result = ingestor($repo)->importArchitecture($markdown, [
        'id' => $actor,
        'role' => 'chair',
        'surface' => 'tools/chair.php',
    ], $importOptions);

    $taskId = (string) $result['task_id'];
    record('PLANNED', [
        'task' => $taskId,
        'revision' => (string) ($result['revision'] ?? '-'),
        'created' => !empty($result['created']) ? 'yes' : 'no',
        'state' => $repo->getTask($taskId)['state'] ?? DevelopmentLifecycle::REQUESTED,
    ]);
    say('  probe: ' . $probes[0]);
    if (count($probes) > 1) {
        say('  note: ' . count($probes) . ' command-shaped lines found in Required tests; the first is the probe.');
    }

    return 0;
}

/** Print the lane registry: what kind of work goes where, and the reason it goes there. */
function commandLanes(): int
{
    record('LANES', ['n' => (string) count(CHAIR_LANES)]);
    foreach (CHAIR_LANES as $kind => $entry) {
        say(sprintf('  %-12s %s', $kind, $entry['lane']));
        say(sprintf('  %-12s   %s', '', $entry['why']));
    }
    say('');
    say('  A kind is chosen per task with --kind=<kind>. The probe judges every lane identically,');
    say('  so a wrong guess costs one cycle, not a bad merge -- which is what makes routing by');
    say('  strength of work safe rather than merely cheap.');

    return 0;
}

function commandProbe(array $options): int
{
    $taskId = $options['task'] ?? '';
    if ($taskId === '') {
        fail('probe needs --task=<id>');
    }
    $repo = repository();
    $task = $repo->getTask($taskId);
    $contract = taskContract($repo, $task);
    $probes = taskProbes($contract);
    if ($probes === []) {
        fail("task {$taskId} declares no probe");
    }

    $result = runProbe($probes[0]);
    record('PROBE', [
        'task' => $taskId,
        'exit' => (string) $result['exit'],
        'verdict' => $result['exit'] === 0 ? 'PASS' : 'FAIL',
    ]);
    say($result['output']);

    return $result['exit'] === 0 ? 0 : 1;
}

/** @param array<string,string> $options @param list<string> $flags */
function commandRun(array $options, array $flags): int
{
    $taskId = $options['task'] ?? '';
    if ($taskId === '') {
        fail('run needs --task=<id>');
    }

    $repo = repository();
    $task = $repo->getTask($taskId);
    $contract = taskContract($repo, $task);
    $objective = trim((string) ($task['objective'] ?? '')) ?: trim(sectionText($contract['objective'] ?? ''));
    $probes = taskProbes($contract);
    if ($probes === []) {
        fail("task {$taskId} declares no probe; put the deciding command in `## Required tests`");
    }
    $probe = $probes[0];
    $scope = taskScope($contract);

    $attempts = max(1, (int) ($options['attempts'] ?? 2));

    // A kind of work names its lane; --lane overrides it outright. Retrying promotes one rung, because a
    // second failure on the same probe is evidence about the executor, not about the task.
    $ladder = $options['lane'] ?? '';
    if ($ladder === '') {
        $kind = $options['kind'] ?? '';
        $chosen = $kind !== '' ? laneFor($kind) : null;
        if ($kind !== '' && $chosen === null) {
            fail('unknown --kind=' . $kind . '; known kinds: ' . implode(', ', array_keys(CHAIR_LANES)));
        }
        $ladder = $chosen['lane'] ?? 'deepseek/deepseek-v4-flash:low,openai-codex/gpt-5.6-sol:medium';
        if ($chosen !== null) {
            record('ROUTED', ['kind' => $kind, 'lane' => $chosen['lane'], 'why' => $chosen['why']]);
        }
    }
    $lanes = [];
    foreach (explode(',', $ladder) as $rung) {
        if (trim($rung) !== '') {
            $lanes[] = trim($rung);
        }
    }

    record('RUN', ['task' => $taskId, 'state' => (string) ($task['state'] ?? '-'), 'attempts' => (string) $attempts]);

    // RULE 2. The baseline, before anything is implemented.
    $baseline = runProbe($probe);
    record('BASELINE', ['exit' => (string) $baseline['exit'], 'verdict' => $baseline['exit'] === 0 ? 'ALREADY-PASSES' : 'RED']);
    if ($baseline['exit'] === 0) {
        say('  The probe passes before any work. Either the task is already satisfied, or the');
        say('  probe is measuring the wrong property. Both are findings; neither needs a lane.');
        commandDecide([
            'task' => $taskId,
            'decision' => 'no work dispatched: the probe already passes',
            'rationale' => 'A probe that passes before the work is done is measuring the wrong '
                . 'property (2026-09-19: a moon threshold scored a pale-cyan planet as grey). '
                . 'Verify the probe against the unfixed product before dispatching.',
        ]);
        return 0;
    }

    $previous = '';
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $lane = $lanes[min($attempt - 1, count($lanes) - 1)] ?? $lanes[0];
        $context = retrieveContext($objective, $scope);
        $brief = writeBrief($task, $objective, $context, $probe, $previous);
        record('ATTEMPT', ['n' => (string) $attempt, 'lane' => $lane, 'brief' => $brief]);

        $before = changedPaths();

        // Checked BEFORE dispatching, not after: a dry run that has already spent a lane is not dry.
        if (in_array('dry', $flags, true)) {
            record('DRY', ['lane' => $lane, 'brief' => $brief]);
            say('  The brief is written and the baseline is measured. Nothing was dispatched.');
            return 0;
        }

        $exit = dispatchLane($lane, $brief, $attempt);
        record('LANE', ['n' => (string) $attempt, 'exit' => (string) $exit]);

        $after = runProbe($probe);
        record('PROBE', ['exit' => (string) $after['exit'], 'verdict' => $after['exit'] === 0 ? 'PASS' : 'FAIL']);
        say($after['output']);

        $changed = array_values(array_diff(changedPaths(), $before));
        if ($changed !== []) {
            record('CHANGED', ['paths' => (string) count($changed), 'files' => implode(',', array_slice($changed, 0, 6))]);
        }

        if ($after['exit'] === 0) {
            record('VERIFIED', ['task' => $taskId, 'attempt' => (string) $attempt, 'probe' => $probe]);
            say('  Evidence is the probe above, run against the tree the lane produced.');
            say('  A warm client can still hold a stale asset: if the change is served, check the');
            say('  entry page versions its assets from the live file mtimes.');
            return 0;
        }

        $previous = $after['output'];
        if ($attempt < $attempts) {
            record('PROMOTE', ['reason' => 'probe failed', 'next' => $lanes[min($attempt, count($lanes) - 1)] ?? $lane]);
        }
    }

    // RULE 3. Exhaustion is a recorded decision, not a question for the director.
    commandDecide([
        'task' => $taskId,
        'decision' => 'parked after ' . $attempts . ' attempts',
        'rationale' => 'The probe still fails. Options, in the order the chair would take them: '
            . '(a) the probe is wrong -- fix it and restate the task; '
            . '(b) the task is too large -- split it so each part has its own probe; '
            . '(c) the work is genuinely hard -- keep the lane and raise reasoning. '
            . 'Nothing here needs the director: it needs the next decision, which is the chair\'s.',
    ]);
    record('BLOCKED', ['task' => $taskId, 'attempts' => (string) $attempts]);

    return 3;
}

/** Hand the brief to a configured lane. Absent a lane, the brief IS the deliverable. */
function dispatchLane(string $lane, string $brief, int $attempt): int
{
    $pi = trim((string) shell_exec('command -v pi 2>/dev/null'));
    if ($pi === '') {
        record('NO-LANE', ['reason' => 'pi is not on PATH', 'brief' => $brief]);
        say('  The brief is written. Run it by hand, or install a lane, and re-run this command.');

        return 127;
    }

    [$model, $thinking] = array_pad(explode(':', $lane, 2), 2, 'low');
    $prompt = 'Read the brief at ' . $brief . ' and implement it exactly. '
        . 'The probe in that brief decides the task: run it and report its real output. '
        . 'Work only inside the declared scope. Do not weaken the probe or any test.';

    $log = CHAIR_ROOT . '/storage/private/chair/runs';
    if (!is_dir($log) && !mkdir($log, 0775, true) && !is_dir($log)) {
        fail("cannot create {$log}");
    }
    $logFile = $log . '/' . basename($brief, '.md') . '-attempt' . $attempt . '.log';
    $command = sprintf(
        '%s --print --approve --model %s --thinking %s %s > %s 2>&1',
        escapeshellarg($pi),
        escapeshellarg($model),
        escapeshellarg($thinking),
        escapeshellarg($prompt),
        escapeshellarg($logFile)
    );

    // setsid: a lane that outlives this process is still a lane, and a signal to the parent must not
    // kill work mid-write. See the harness notes: nohup/backgrounding broke pi with EBADF.
    $exit = 0;
    passthru('setsid bash -lc ' . escapeshellarg($command), $exit);

    return $exit;
}

function commandDecide(array $options): int
{
    $taskId = $options['task'] ?? '';
    $decision = $options['decision'] ?? '';
    if ($taskId === '' || $decision === '') {
        fail('decide needs --task=<id> and --decision=<text>');
    }

    $dir = CHAIR_ROOT . '/storage/private/chair/decisions';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fail("cannot create {$dir}");
    }
    $entry = [
        'at' => gmdate(DATE_ATOM),
        'task' => $taskId,
        'decision' => $decision,
        'rationale' => $options['rationale'] ?? '',
        'authority' => 'chair',
        'owner_intervention' => 'not required',
    ];
    file_put_contents($dir . '/' . $taskId . '.jsonl', json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

    record('DECISION', ['task' => $taskId, 'chair' => $decision]);

    return 0;
}

function commandStatus(array $options): int
{
    $repo = repository();
    $taskId = $options['task'] ?? '';

    if ($taskId === '') {
        $tasks = $repo->listTasks();
        if ($tasks === []) {
            say('no tasks.');
            return 0;
        }
        record('TASKS', ['n' => (string) count($tasks)]);
        foreach ($tasks as $task) {
            say(sprintf('  %-34s %-16s %s', (string) ($task['task_id'] ?? '?'), (string) ($task['state'] ?? '?'), (string) ($task['title'] ?? '')));
        }

        return 0;
    }

    $task = $repo->getTask($taskId);
    $contract = taskContract($repo, $task);
    record('TASK', [
        'task' => $taskId,
        'state' => (string) ($task['state'] ?? '-'),
        'revision' => (string) ($task['contract_revision'] ?? '-'),
    ]);
    say('  objective: ' . (trim((string) ($task['objective'] ?? '')) ?: '-'));

    foreach (taskProbes($contract) as $probe) {
        say('  probe: ' . $probe);
    }

    $decisions = CHAIR_ROOT . '/storage/private/chair/decisions/' . $taskId . '.jsonl';
    if (is_file($decisions)) {
        say('  decisions:');
        foreach (preg_split('/\R/', (string) file_get_contents($decisions)) ?: [] as $line) {
            $entry = json_decode(trim($line), true);
            if (is_array($entry)) {
                say(sprintf('    %s  %s', (string) ($entry['at'] ?? ''), (string) ($entry['decision'] ?? '')));
            }
        }
    }

    return 0;
}

// ─────────────────────────────────────────────────────────────────────────────────────────────────
// Self-test: the floor is proved in BOTH directions, or it is not trusted.
// ─────────────────────────────────────────────────────────────────────────────────────────────────
//
// A guard that refuses legitimate work is worse than no guard, because it is trusted. The DROP
// pattern lived in two files, was repaired in one, and the survivor refused a Playwright grep over
// the word "drop" -- 46 minutes lost. So this asserts what MUST be refused AND what must not.

function selfTest(): int
{
    $pass = 0;
    $fail = 0;
    $check = static function (string $label, bool $ok) use (&$pass, &$fail): void {
        if ($ok) {
            $pass++;
            say('  [PASS] ' . $label);
            return;
        }
        $fail++;
        say('  [FAIL] ' . $label);
    };

    say('refused — these really are destructive:');
    foreach ([
        'mysql -e "DROP TABLE users"' => 'DROP TABLE',
        'mysql -e "DROP DATABASE app"' => 'DROP DATABASE',
        'mysql -e "TRUNCATE TABLE x"' => 'TRUNCATE TABLE',
        'mysql -e "ALTER TABLE t DROP COLUMN c"' => 'ALTER TABLE ... DROP COLUMN',
        'mysql -e "TRUNCATE users"' => 'MySQL TRUNCATE without TABLE',
        'rm -rf storage/cache' => 'rm -rf',
        'git push origin main' => 'git push',
        'git clean -fdx' => 'git clean',
        'npm publish' => 'npm publish',
    ] as $command => $label) {
        $check($label . ' is refused', commandRefusal($command) !== null);
    }

    say('allowed — refusing these is the false positive that cost 46 minutes:');
    foreach ([
        'npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "the drop is legible as it expires @p12"' => 'a probe whose name contains "drop"',
        'php tools/chair.php probe --task=task-1' => 'this tool',
        'php tools/harpp2/gates/x.php --phase=13' => 'a gate',
        'grep -rn "DROP" migrations/' => 'a read-only search for DROP',
        'git status --porcelain' => 'git status',
        'git diff --stat' => 'git diff',
        'composer test' => 'the suite',
    ] as $command => $label) {
        $check($label . ' is allowed', commandRefusal($command) === null);
    }

    say('probe extraction from the kernel contract format:');
    $contract = "# CONTRACT — fixture\n## Objective\nDo a thing.\n"
        . "## Architectural constraints\n- none\n## Files likely affected\n- `src/`\n"
        . "## Acceptance criteria\n- it works\n"
        . "## Required tests\n- `php tests/thing_test.php`\n- prose that is not a command\n"
        . "## Risks\n- none\n## Forbidden changes\n- `tests/`\n";
    $probes = contractProbes($contract);
    $check('a backticked command is found', ($probes[0] ?? '') === 'php tests/thing_test.php');
    $check('prose in the same section is not mistaken for a probe', count($probes) === 1);
    $check('a contract with no command-shaped line yields no probe', contractProbes("# CONTRACT\n## Required tests\n- none\n") === []);

    // The STORED shape, which is not the markdown: DevelopmentTaskContract normalizes a section into a
    // list of bullet values with no heading of its own. This case exists because the first version of
    // this tool read the stored section with the markdown reader and reported "declares no probe" while
    // the probe was in the revision -- a self-test that only covered the raw markdown would have passed.
    $stored = ['required_tests' => ['`php tests/thing_test.php`', 'plain prose']];
    $check('the stored array shape yields the probe', taskProbes($stored) === ['php tests/thing_test.php']);
    $check('a stored section that is a plain string also works', taskProbes(['required_tests' => '`npx playwright test x.spec.ts`']) === ['npx playwright test x.spec.ts']);
    $check('scope is read from allowed_scope entries', taskScope(['allowed_scope' => [['path' => 'tools/chair.php', 'kind' => 'file']]]) === ['tools/chair.php']);

    say('retrieval:');
    $ranked = retrieveContext('star swarm moon crater rendering', ['public/star-swarm'], 5);
    $check('the objective retrieves files rather than nothing', $ranked !== []);
    $check('retrieval is bounded by the requested limit', count($ranked) <= 5);

    say('');
    say(sprintf('  => %d passed, %d failed', $pass, $fail));

    return $fail === 0 ? 0 : 1;
}

// ─────────────────────────────────────────────────────────────────────────────────────────────────

$cli = cli($argv);
$cli['options']['_flags'] = implode(',', $cli['flags']);

if (in_array('self-test', $cli['flags'], true)) {
    exit(selfTest());
}

if ($cli['command'] === '') {
    say('usage: php tools/chair.php <plan|run|probe|decide|status> [options]');
    say('       php tools/chair.php --self-test');
    exit(0);
}

exit(match ($cli['command']) {
    'plan' => commandPlan($cli['options']),
    'run' => commandRun($cli['options'], $cli['flags']),
    'probe' => commandProbe($cli['options']),
    'lanes' => commandLanes(),
    'decide' => commandDecide($cli['options']),
    'status' => commandStatus($cli['options']),
    default => fail('unknown command: ' . $cli['command']),
});
