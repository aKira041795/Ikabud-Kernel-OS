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
 *    `advance` IS this rule, bounded: attempt 1 on the task's own lane, each failure one rung more
 *    capable, and `--max` attempts later a recorded blocked decision rather than one more attempt.
 *
 * 4. CONTEXT IS RETRIEVED, NOT DUMPED. The brief is assembled from the task's own scope, ranked
 *    by relevance to its objective. No whole-repository reads.
 *
 * 5. THE FLOOR IS ABSOLUTE. Destructive commands are refused -- rm -rf, pushes, real DROP /
 *    TRUNCATE statements -- and every refusal pattern is proved in BOTH directions by
 *    `--self-test`, so a policy that cries wolf is caught here rather than in a live run.
 *
 * usage:
 *   php tools/chair.php plan    --contract=<file.md> [--task=<id>] [--actor=<id>]
 *   php tools/chair.php run     --task=<id> [--attempts=2] [--lane=<model>:<thinking>,...] [--falsify] [--dry]
 *   php tools/chair.php advance --task=<id> [--max=3] [--kind=<kind>] [--lane=<model>:<thinking>] [--falsify]
 *   php tools/chair.php probe   --task=<id>
 *   php tools/chair.php decide  --task=<id> --decision=<text> [--rationale=<text>]
 *   php tools/chair.php status  [--task=<id>]
 *   php tools/chair.php --self-test
 */

require_once __DIR__ . '/../kernel/Workbench/Development/DevelopmentTaskContract.php';
require_once __DIR__ . '/../kernel/Workbench/Development/DevelopmentTaskRepository.php';
require_once __DIR__ . '/../kernel/Workbench/Development/DevelopmentLifecycle.php';
require_once __DIR__ . '/../kernel/Workbench/Development/GitEvidenceResolver.php';
require_once __DIR__ . '/../kernel/Workbench/Development/DevelopmentArtifactIngestor.php';

require_once __DIR__ . '/../kernel/Workbench/Development/AssertionChange.php';
require_once __DIR__ . '/../kernel/Workbench/Retrieval/RetrievalIndex.php';

use Ikabud\Kernel\Workbench\Development\AssertionChange;
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
// The ledger, the lock, and the director channel.
//
// The retired harness recorded run state properly and the lean replacement did not:
// `grep -cE "flock|LOCK_EX|ledger|commit-check" tools/chair.php` returned 0 on 2026-09-19. Two runs
// could write the same tree, nothing survived a run as a record, and the repository rule "never
// commit during a live run" could not be asked because there was nothing to ask.
//
// And `grep -c harpp tools/chair.php` returned 3 -- all of them comments or a comparison table. The
// harness could think and could not speak. HARPP was healthy the whole time (25 decisions on the
// server, `harpp` on PATH, `tools/harpp-bridge/` in-tree); the wire was simply absent.
// ─────────────────────────────────────────────────────────────────────────────────────────────────

function chairStateDir(): string
{
    $dir = CHAIR_ROOT . '/storage/private/chair';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fail("cannot create {$dir}");
    }

    return $dir;
}

/** The ledger file the harness records into, or the redirect a self-test has put in its place. */
function ledgerPath(): string
{
    return ledgerOverride() ?? chairStateDir() . '/ledger.jsonl';
}

/**
 * Read where the ledger points, or move it. `null` means the normal path.
 *
 * The flag distinguishes a read from a redirect because `null` is a MEANINGFUL redirect target -- back to
 * the production ledger -- so "set it to null" cannot be told from "just read it" without it. A redirect
 * returns the previous value, so a caller can restore exactly what it found from a `finally`: that is what
 * keeps the move from outliving the code that asked for it, including when a check throws.
 *
 * The path is overridable because --self-test wrote its own records into the production ledger (see the
 * note on ledgerRedirectedSelfTest()). One value the helpers read is a smaller change than teaching every
 * call site to skip itself during a test, and it keeps the redirection visible in one place.
 *
 * @param string|null $path     the path to redirect to, or null for the normal ledger
 * @param bool        $redirect whether this call IS the redirect, rather than a read
 * @return string|null the previous redirect when redirecting, otherwise the current one
 */
function ledgerOverride(?string $path = null, bool $redirect = false): ?string
{
    static $current = null;
    if (!$redirect) {
        return $current;
    }
    $previous = $current;
    $current = $path;

    return $previous;
}

/**
 * Remove every line containing $needle from a file, leaving the rest byte-identical.
 *
 * Used by the self-test and nowhere else: it proves the ledger helpers still write the production ledger
 * by writing one marker record there and taking it straight back out. A self-test that left a record
 * behind while asserting it left none would be the defect, not the fix.
 */
function ledgerStripLine(string $path, string $needle): void
{
    $kept = [];
    foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
        if (!str_contains((string) $line, $needle)) {
            $kept[] = (string) $line;
        }
    }
    file_put_contents($path, implode("\n", $kept), LOCK_EX);
}

/** @param array<string,mixed> $entry */
function ledgerAppend(array $entry): void
{
    $entry['at'] = $entry['at'] ?? gmdate(DATE_ATOM);
    file_put_contents(
        ledgerPath(),
        json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

/** @return list<array<string,mixed>> */
function ledgerRead(): array
{
    $path = ledgerPath();
    if (!is_file($path)) {
        return [];
    }
    $entries = [];
    foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
        $decoded = json_decode(trim($line), true);
        if (is_array($decoded)) {
            $entries[] = $decoded;
        }
    }

    return $entries;
}

/**
 * @return resource|null null when the lock is already held and $blocking is false
 */
function lockAcquire(bool $blocking)
{
    $path = chairStateDir() . '/.run.lock';
    $handle = fopen($path, 'c');
    if ($handle === false) {
        fail("cannot open {$path}");
    }
    $flags = $blocking ? LOCK_EX : (LOCK_EX | LOCK_NB);
    if (!flock($handle, $flags)) {
        fclose($handle);

        return null;
    }

    return $handle;
}

/** @param resource $handle */
function lockRelease($handle): void
{
    flock($handle, LOCK_UN);
    fclose($handle);
}

/**
 * A run holds the lock for its whole life, so two runs cannot write the same tree and a commit can be
 * refused while one is live. The lock is released even when the body throws.
 *
 * The run id is handed to the body, so a record a body writes -- a BLOCKED entry, say -- can name the
 * run that made it instead of floating in the ledger unattributable.
 *
 * @param array<string,string> $options
 */
function withRunLock(array $options, callable $body): int
{
    $handle = lockAcquire(false);
    if ($handle === null) {
        record('LOCKED', ['reason' => 'another run holds the lock']);

        return 3;
    }

    $run = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
    ledgerAppend([
        'phase' => 'start',
        'run' => $run,
        'task' => $options['task'] ?? '',
        'lane' => $options['lane'] ?? '',
        'kind' => $options['kind'] ?? '',
        'mode' => $options['mode'] ?? '',
    ]);

    try {
        $exit = $body($run);
    } catch (Throwable $error) {
        ledgerAppend(['phase' => 'finish', 'run' => $run, 'exit' => 2, 'error' => $error->getMessage()]);
        lockRelease($handle);
        throw $error;
    }

    ledgerAppend(['phase' => 'finish', 'run' => $run, 'exit' => $exit]);
    lockRelease($handle);

    return $exit;
}

/**
 * Commit eligibility is decided by the ledger, not by how the tree looks -- a tree can look coherent
 * while a run is still writing to it.
 *
 * An abandoned run must have an unblock route. The retired ledger's lack of one is a recorded defect
 * (brief §3.21), so an unfinished record with a free lock is reported as abandoned and does NOT block
 * unless --strict is given.
 *
 * @param list<string> $flags
 */
function commandCommitCheck(array $flags): int
{
    $handle = lockAcquire(false);
    if ($handle === null) {
        record('COMMIT', ['eligible' => 'no', 'reason' => 'a run holds the lock']);

        return 3;
    }
    lockRelease($handle);

    $open = [];
    $closed = [];
    foreach (ledgerRead() as $entry) {
        $run = (string) ($entry['run'] ?? '');
        if ((string) ($entry['phase'] ?? '') === 'start') {
            $open[$run] = $entry;
        } elseif ((string) ($entry['phase'] ?? '') === 'finish') {
            $closed[$run] = true;
        }
    }
    $abandoned = array_keys(array_diff_key($open, $closed));

    if ($abandoned === []) {
        record('COMMIT', ['eligible' => 'yes', 'reason' => 'no run is live and the ledger is closed']);

        return 0;
    }

    $strict = in_array('strict', $flags, true);
    record('COMMIT', ['eligible' => $strict ? 'no' : 'yes', 'abandoned' => (string) count($abandoned)]);
    foreach ($abandoned as $run) {
        say('  unfinished run: ' . $run . ' (abandoned, not live -- the lock is free)');
    }

    return $strict ? 3 : 0;
}

// ── the director channel ────────────────────────────────────────────────────────────────────────
//
// "No silent non-delivery": the local artifact is written FIRST, and delivery is claimed only on an
// acknowledged HARPP submission. An undelivered decision prints exactly the line below and exits 4.

const CHAIR_UNDELIVERED = 'DELIVERY: local-only — director NOT notified';

/**
 * Only an acknowledged submission counts.
 *
 * Grounded in the bridge's real contract (`tools/harpp-bridge/harpp_client.py`), not in a guess:
 * `submit_decision()` returns `{"ok": True, "suppressed": True}` when notifications are disabled.
 * **`ok` is true and nobody is notified.** Reading that as delivery would be silent non-delivery
 * wearing the costume of success, which is the exact failure this invariant exists to prevent -- and
 * the trap is that it looks right. Suppression is therefore checked BEFORE success.
 */
function deliveryAcknowledged(string $output, int $exit): bool
{
    if ($exit !== 0) {
        return false;
    }

    $decoded = json_decode(trim($output), true);
    if (!is_array($decoded)) {
        // A command that answered with something that is not an acknowledgement has not acknowledged.
        return false;
    }

    if (($decoded['suppressed'] ?? false) === true) {
        return false;
    }

    return ($decoded['ok'] ?? false) === true;
}

/**
 * Parse `id|label|effect|cost|blast_radius|reversibility` entries separated by `;`.
 *
 * The deferred-decision contract names six fields per option, so all six are required. A field left as
 * a placeholder would be a decorative artifact, which is the failure this whole file exists to avoid.
 *
 * @return list<array<string,string>>
 */
function decisionOptions(string $raw): array
{
    $options = [];
    foreach (explode(';', $raw) as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') {
            continue;
        }
        $parts = array_map('trim', explode('|', $chunk));
        if (count($parts) !== 6) {
            // Thrown rather than exited so the self-test can prove the rejection in both directions.
            throw new RuntimeException(
                'each --options entry needs six fields, id|label|effect|cost|blast_radius|reversibility,'
                . ' separated by ";" -- got ' . count($parts)
            );
        }
        $options[] = [
            'id' => $parts[0],
            'label' => $parts[1],
            'effect' => $parts[2],
            'cost' => $parts[3],
            'blast_radius' => $parts[4],
            'reversibility' => $parts[5],
        ];
    }

    return $options;
}

/**
 * @return array{ok:bool, output:string, exit:int, command:string}
 */
function deliverDecision(string $decisionKey, string $title, string $body, string $requested): array
{
    $harpp = trim((string) shell_exec('command -v harpp 2>/dev/null'));
    if ($harpp === '') {
        return ['ok' => false, 'output' => '', 'exit' => 127, 'command' => 'harpp is not on PATH'];
    }

    $command = sprintf(
        '%s decision submit --title=%s --body=%s --requested=%s --priority=%s --source=%s --decision-key=%s 2>&1',
        escapeshellarg($harpp),
        escapeshellarg($title),
        escapeshellarg($body),
        escapeshellarg($requested),
        escapeshellarg('high'),
        escapeshellarg('chair'),
        escapeshellarg($decisionKey)
    );

    $lines = [];
    $exit = 0;
    exec($command, $lines, $exit);
    $output = implode("\n", $lines);

    return [
        'ok' => deliveryAcknowledged($output, $exit),
        'output' => $output,
        'exit' => $exit,
        'command' => $command,
    ];
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
 * One markdown section: what lies between a heading and the next heading.
 *
 * Both readers here need the same scan -- the probe lives in `## Required tests`, the acceptance criteria
 * in `## Acceptance criteria` -- and the acceptance criteria come from the MARKDOWN rather than from the
 * stored revision. That is not a preference: DevelopmentTaskContract::bulletLines() strips the `- `
 * marker, so a wrapped bullet is STORED as two entries with nothing left to say the second is a
 * continuation. Measured 2026-09-19: the revision of star-swarm-arsenal holds
 * `'... (checked by hand; no'` and `'probe covers it, ... forgotten).'` as separate criteria, and only the
 * markdown still knows they are one bullet.
 */
function markdownSection(string $markdown, string $heading): string
{
    $starts = '/^#{1,3}\s+' . preg_quote($heading, '/') . '\s*$/i';
    $section = '';
    $inSection = false;
    foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
        if (preg_match($starts, (string) $line) === 1) {
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

    return $section;
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
    $probes = probeLines(markdownSection($markdown, 'Required tests'));

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
    // realpath, NOT CHAIR_ROOT -- and this was a real defect, measured 2026-09-19 while wiring the
    // staleness gate. CHAIR_ROOT is deliberately unnormalised ('.../tools/..'), and the index computes
    // every stored key AND every lookup by stripping the root it was given. A root containing '..'
    // strips nothing, so keys became absolute-with-the-leading-slash-dropped, and isCurrent() then
    // looked for the file at <root>/<that key> -- a path that cannot exist. `isCurrent()` returned
    // false for a file that had JUST been indexed, so the staleness gate could never be satisfied and
    // every index() call wrote a duplicate mangled key. Normalising the root fixes both.
    return new RetrievalIndex(CHAIR_ROOT . '/storage/private/retrieval', realpath(CHAIR_ROOT) ?: CHAIR_ROOT);
}

/**
 * Did the run delete or loosen an assertion in a test file it touched?
 *
 * The probe is the acceptance, and a probe can pass while the lane has removed the assertions that gave
 * it meaning. That is the one way a green probe lies, so it is checked rather than assumed. Extracted
 * from the retired harpp2 (see kernel/Workbench/Development/AssertionChange.php for why the comparison
 * is behavioural rather than a line diff).
 *
 * @param list<string> $changed repository-relative paths
 * @return list<string> findings, empty when nothing was weakened
 */
function assertionDrift(array $changed): array
{
    $findings = [];
    foreach ($changed as $path) {
        if (preg_match('/(_test\.php|Test\.php|\.spec\.ts)$/', $path) !== 1 || !is_file(CHAIR_ROOT . '/' . $path)) {
            continue;
        }
        $old = shell_exec('cd ' . escapeshellarg(CHAIR_ROOT) . ' && git show ' . escapeshellarg('HEAD:' . $path) . ' 2>/dev/null');
        if ($old === null || $old === '') {
            continue; // a new test file has nothing to weaken
        }
        // REPOSITORY_MARKERS, not the default: this repository's tests assert with `$check(...)`, which
        // the jest/phpunit default set does not recognise. Measured -- with the default markers, deleting
        // a real assertion from a real test file was reported as a clean change.
        $result = AssertionChange::analyse((string) $old, (string) file_get_contents(CHAIR_ROOT . '/' . $path), AssertionChange::REPOSITORY_MARKERS);
        foreach ($result['removed'] as $assertion) {
            $findings[] = "{$path}: removed: {$assertion}";
        }
        foreach ($result['loosened'] as $loosened) {
            $findings[] = "{$path}: loosened: {$loosened}";
        }
    }

    return $findings;
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

/**
 * Which of these retrieved paths is the index describing WRONGLY?
 *
 * The index answers this directly (`isCurrent`), which is the whole reason context comes from a
 * persisted index rather than a walk: a walk has no memory to be stale. Measured 2026-09-19: `stats()`
 * reported `stale 8` while the harness handed context to lanes without ever asking. A lane briefed from
 * a description of code that has since changed is not merely unhelpful -- it is confidently wrong.
 *
 * @param list<string> $paths repository-relative paths, as retrieval returns them
 * @return list<string>
 */
function staleContextPaths(array $paths): array
{
    $index = chairIndex();
    $stale = [];
    foreach ($paths as $path) {
        if (!$index->isCurrent($path)) {
            $stale[] = $path;
        }
    }

    return $stale;
}

/**
 * Null when the retrieved context may be handed to a lane, else why it may not.
 *
 * Fail closed, deliberately: a stale path that a FORCED re-index could not repair is a path whose file
 * is gone, and the honest response is to stop rather than to brief a lane from a memory of deleted code.
 *
 * @param list<string> $stale
 */
function staleContextRefusal(array $stale): ?string
{
    if ($stale === []) {
        return null;
    }

    return 'refusing to brief a lane with stale context: ' . implode(', ', array_slice($stale, 0, 8))
        . (count($stale) > 8 ? ' (+' . (count($stale) - 8) . ' more)' : '')
        . ' did not come back current after a forced re-index. The index is describing something that is '
        . 'not on disk, so the retrieval is wrong rather than merely old. Fail closed and re-index the '
        . 'repository before dispatching: a lane briefed from a stale index is confidently wrong.';
}

/**
 * The context for a brief, with staleness checked, repaired and re-checked rather than assumed.
 *
 * Three steps, in this order and no other: look (`isCurrent`), repair (`index` with force, because
 * mtime+size is exactly what fails to notice the change being repaired), then RETRIEVE AGAIN so the
 * ranking reflects what was just indexed. Anything still stale after that is a refusal, not a warning.
 *
 * @param list<string> $scope repository-relative files or directories
 * @return list<array{path:string, score:int, lines:int}>
 */
function currentContext(string $objective, array $scope, int $limit = 12): array
{
    $context = retrieveContext($objective, $scope, $limit);
    $found = staleContextPaths(array_column($context, 'path'));
    $repaired = 'no';

    if ($found !== []) {
        chairIndex()->index($found, true);
        $context = retrieveContext($objective, $scope, $limit);
        $refusal = staleContextRefusal(staleContextPaths(array_column($context, 'path')));
        if ($refusal !== null) {
            fail($refusal);
        }
        $repaired = 'yes';
    }

    record('CONTEXT', [
        'files' => (string) count($context),
        'stale' => (string) count($found),
        'repaired' => $repaired,
    ]);

    return $context;
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
 * The status CODE is kept rather than thrown away, because reverting needs it: a path HEAD holds is
 * restored with `git checkout HEAD --`, while a path a run CREATED has no committed copy and must be
 * MOVED aside -- deleting it would destroy work nothing else has a copy of.
 *
 * @return array<string,string> repository-relative path => the two-character git status code
 */
function workingTreeEntries(): array
{
    $output = shell_exec('cd ' . escapeshellarg(CHAIR_ROOT) . ' && git status --porcelain 2>/dev/null');
    $entries = [];
    foreach (preg_split('/\R/', (string) $output) ?: [] as $line) {
        if (trim($line) === '') {
            continue;
        }
        $code = substr($line, 0, 2);
        $path = trim(substr($line, 3));
        if (str_contains($path, ' -> ')) {
            $path = trim(explode(' -> ', $path)[1]);
        }
        if (str_starts_with($path, 'storage/private/') || str_starts_with($path, '.ai/')) {
            continue;
        }
        $entries[$path] = $code;
    }

    return $entries;
}

/** @return list<string> */
function changedPaths(): array
{
    return array_keys(workingTreeEntries());
}

// ─────────────────────────────────────────────────────────────────────────────────────────────────
// Falsification: take the change away and watch the probe go red.
//
// On 2026-09-19 the chair proved a probe was not vacuous BY HAND -- revert the changed files to HEAD,
// re-run the probe expecting RED, restore, then verify the restore by hash. The loop is mechanical and
// easy to skip, which is precisely why it is a command: a green probe proves the probe passes, and only
// the revert proves it passes BECAUSE OF THE CHANGE.
// ─────────────────────────────────────────────────────────────────────────────────────────────────

/** Does HEAD hold a copy of this path -- i.e. can `git checkout HEAD --` bring it back? */
function committedInHead(string $path): bool
{
    $ignored = [];
    $exit = 0;
    exec('cd ' . escapeshellarg(CHAIR_ROOT) . ' && git cat-file -e '
        . escapeshellarg('HEAD:' . $path) . ' 2>/dev/null', $ignored, $exit);

    return $exit === 0;
}

/** Where a newly-added file waits while the probe is run against its absence. */
function falsifyAsideName(string $path): string
{
    return str_replace('/', '%', $path);
}

/**
 * Null when the revert may proceed, else why it must not.
 *
 * The guard exists because `git checkout HEAD --` is indifferent to whose work it discards. It is only
 * ever pointed at files the RUN changed, but a tree that was already dirty means the run's change and
 * someone else's are the same path, and a revert that swept that up would destroy work with no committed
 * copy -- the one outcome that must never depend on a probe's verdict.
 *
 * @param list<string> $runChanged paths this run changed
 * @param list<string> $baseline   paths already uncommitted BEFORE the run started
 */
function falsifyRefusal(array $runChanged, array $baseline): ?string
{
    $foreign = array_values(array_diff($baseline, $runChanged));
    if ($foreign === []) {
        return null;
    }

    return 'cannot falsify: the working tree already had ' . count($foreign) . ' uncommitted change(s) '
        . 'this run did not make (' . implode(', ', array_slice($foreign, 0, 6))
        . (count($foreign) > 6 ? ', ...' : '') . '). Reverting to HEAD must never be able to sweep up '
        . 'work that is not the run\'s. Commit or stash those paths, then re-run with --falsify.';
}

/**
 * Null when the probe went RED without the change, else the finding.
 *
 * A probe that still passes once the change is gone is measuring something else -- the moon-threshold
 * failure of 2026-09-19 in its general form. That is a finding about the INSTRUMENT, so the message says
 * so rather than blaming a lane that did nothing wrong.
 */
function probeDependenceRefusal(int $exitAfterRevert): ?string
{
    if ($exitAfterRevert !== 0) {
        return null;
    }

    return 'the probe does not depend on the change: it still PASSES with the run\'s changes reverted, so '
        . 'it is not measuring what this task claims to be about. The finding is about the probe, not the '
        . 'lane -- the next action is to fix the instrument.';
}

/**
 * Which files did not come back exactly as they were?
 *
 * A restore is not done when the copy returns; it is done when the bytes are proven identical. Content
 * hashes on both sides, so "RESTORE OK" is a measurement rather than a hope.
 *
 * @param array<string,string> $expected path => sha256 before the revert
 * @param array<string,string> $actual   path => sha256 after the restore
 * @return list<string>
 */
function restoreVerified(array $expected, array $actual): array
{
    $mismatches = [];
    foreach ($expected as $path => $hash) {
        if (($actual[$path] ?? null) !== $hash) {
            $mismatches[] = (string) $path;
        }
    }

    return $mismatches;
}

/**
 * Back up, in memory, the files that are about to be taken away -- before anything is reverted.
 *
 * Two classifications, because they need opposite reverts: a path HEAD holds is restored from HEAD,
 * while a path the run CREATED has no committed copy and is MOVED aside. A file the run DELETED is
 * backed up from HEAD, because HEAD is the only place its bytes still exist.
 *
 * @param list<string> $paths repository-relative
 * @return array{added:list<string>, modified:list<string>, contents:array<string,string>, hashes:array<string,string>, aside:string}
 */
function falsifyBackup(array $paths): array
{
    $aside = chairStateDir() . '/falsify/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
    if (!is_dir($aside) && !mkdir($aside, 0775, true) && !is_dir($aside)) {
        fail("cannot create the falsify backup directory {$aside}");
    }

    $backup = ['added' => [], 'modified' => [], 'contents' => [], 'hashes' => [], 'aside' => $aside];
    foreach ($paths as $path) {
        $absolute = CHAIR_ROOT . '/' . $path;
        if (committedInHead($path)) {
            $backup['modified'][] = $path;
            $contents = is_file($absolute)
                ? (string) file_get_contents($absolute)
                : (string) shell_exec('cd ' . escapeshellarg(CHAIR_ROOT) . ' && git show '
                    . escapeshellarg('HEAD:' . $path) . ' 2>/dev/null');
        } else {
            $backup['added'][] = $path;
            $contents = is_file($absolute) ? (string) file_get_contents($absolute) : '';
        }
        $backup['contents'][$path] = $contents;
        $backup['hashes'][$path] = hash('sha256', $contents);
    }

    return $backup;
}

/**
 * Take the change away.
 *
 * @param array<string,mixed> $backup
 */
function falsifyRevert(array $backup): void
{
    if ($backup['modified'] !== []) {
        $output = [];
        $exit = 0;
        exec('cd ' . escapeshellarg(CHAIR_ROOT) . ' && git checkout HEAD -- '
            . implode(' ', array_map('escapeshellarg', $backup['modified'])) . ' 2>&1', $output, $exit);
        if ($exit !== 0) {
            // Nothing has been moved aside yet and every backup is still in memory, so failing here is
            // fail-closed rather than half-reverted. The shutdown net restores whatever did change.
            fail('could not revert to HEAD: ' . implode(' | ', $output));
        }
    }

    foreach ($backup['added'] as $path) {
        $absolute = CHAIR_ROOT . '/' . $path;
        if (is_file($absolute) && !@rename($absolute, $backup['aside'] . '/' . falsifyAsideName($path))) {
            fail("could not move {$path} aside");
        }
    }
}

/**
 * Put the files back, and prove it byte by byte.
 *
 * Idempotent, because two things call it: the `finally` in falsifyProbe(), and the shutdown handler that
 * exists because `try/finally` does NOT run when PHP exits -- and runProbe() can exit(2) if it cannot
 * start the probe at all. Both paths must leave the tree exactly as the lane left it.
 *
 * @param array<string,mixed> $backup
 * @return array{ok:bool, mismatches:list<string>}
 */
function falsifyRestore(array $backup): array
{
    foreach ($backup['modified'] as $path) {
        @file_put_contents(CHAIR_ROOT . '/' . $path, (string) $backup['contents'][$path]);
    }
    foreach ($backup['added'] as $path) {
        $aside = $backup['aside'] . '/' . falsifyAsideName($path);
        if (is_file($aside)) {
            @rename($aside, CHAIR_ROOT . '/' . $path);
        }
    }

    $actual = [];
    foreach (array_keys($backup['hashes']) as $path) {
        $absolute = CHAIR_ROOT . '/' . $path;
        $actual[$path] = is_file($absolute) ? hash('sha256', (string) @file_get_contents($absolute)) : '';
    }
    $mismatches = restoreVerified($backup['hashes'], $actual);
    if (is_dir((string) $backup['aside'])) {
        @rmdir((string) $backup['aside']);
        // And the parent, when this was the last backup in it: a self-test (or a refusal before the first
        // revert) must not leave scratch state behind that a later reader has to interpret.
        @rmdir(dirname((string) $backup['aside']));
    }

    return ['ok' => $mismatches === [], 'mismatches' => $mismatches];
}

/**
 * The backup a terminating process must still restore, or null when none is in flight.
 *
 * A `finally` covers an exception; it does not cover `exit()`, which PHP runs without unwinding -- and
 * runProbe() exits when it cannot start the probe. Losing the restore in that window would leave the
 * tree reverted, which is the one outcome the whole mechanism exists to prevent, so the in-flight
 * backup is registered as a shutdown handler as well.
 *
 * @param array<string,mixed>|false|null $arm false to disarm, an array to arm, null to read
 * @return array<string,mixed>|null
 */
function falsifyInFlight(array|false|null $arm = null): ?array
{
    static $current = null;
    if ($arm !== null) {
        $current = $arm === false ? null : $arm;
    }

    return $current;
}

/**
 * Revert, re-run the probe expecting RED, restore, and verify the restore.
 *
 * @param list<string> $runChanged paths this run changed
 * @param list<string> $baseline   paths already uncommitted before the run started
 * @return array{verdict:string, exit:int, output:string}
 */
function falsifyProbe(string $probe, array $runChanged, array $baseline): array
{
    $refusal = falsifyRefusal($runChanged, $baseline);
    if ($refusal !== null) {
        fail($refusal);
    }

    $backup = falsifyBackup($runChanged);
    falsifyInFlight($backup);
    record('FALSIFY', ['phase' => 'backup', 'files' => (string) count($runChanged), 'aside' => $backup['aside']]);

    try {
        falsifyRevert($backup);
        record('FALSIFY', ['phase' => 'reverted', 'files' => (string) count($runChanged)]);
        $after = runProbe($probe);
    } finally {
        $restore = falsifyRestore($backup);
        falsifyInFlight(false);
    }

    if ($restore['mismatches'] !== []) {
        record('RESTORE', ['verdict' => 'FAILED', 'files' => (string) count($restore['mismatches'])]);
        foreach (array_slice($restore['mismatches'], 0, 8) as $path) {
            say('    not restored: ' . $path);
        }
        say('  the backup is kept at ' . $backup['aside'] . ' -- restore it by hand before anything else.');
        fail('RESTORE FAILED: ' . count($restore['mismatches']) . ' file(s) did not come back '
            . 'byte-identical to their content before the falsification');
    }

    record('RESTORE', ['verdict' => 'OK', 'files' => (string) count($backup['hashes'])]);
    say('  RESTORE OK -- every file is byte-identical to its content before the falsification.');

    $exit = (int) ($after['exit'] ?? -1);
    $dependenceRefusal = probeDependenceRefusal($exit);
    $verdict = $dependenceRefusal === null
        ? 'probe-depends-on-the-change'
        : 'probe-does-not-depend-on-the-change';
    record('FALSIFY', ['verdict' => $verdict, 'probe_exit' => (string) $exit]);

    return ['verdict' => $verdict, 'exit' => $exit, 'output' => (string) ($after['output'] ?? '')];
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

/**
 * The attempt ladder, cheap to expensive, read from the registry.
 *
 * The registry's ORDER is the ladder, and the order is the point: a second failure on the same probe is
 * evidence about the executor, not about the task, so it must raise the reasoning level rather than
 * replay the attempt. That is the repository's repair ladder -- L1 implementation repair, L2
 * implementation-strategy change, L3 task decomposition -- expressed as a list of lanes.
 *
 * @return list<string>
 */
function registryLadder(): array
{
    $ladder = [];
    foreach (CHAIR_LANES as $entry) {
        $ladder[] = $entry['lane'];
    }

    return $ladder;
}

/**
 * How many attempts an advance is allowed.
 *
 * Bounded, because the bound is what makes exhaustion a RECORDED decision instead of a mood: `--max`
 * over the cap is clamped rather than obeyed, and an absent or unreadable value falls back to the
 * default rather than to a single attempt (one attempt is `run`, not an advance).
 */
function advanceAttempts(?string $raw): int
{
    $requested = (int) ($raw ?? '3');
    if ($requested <= 0) {
        return 3;
    }

    return min(5, $requested);
}

/**
 * The lane per attempt: start where the task was routed, promote one rung per failure, never past the
 * top. Clamping rather than refusing at the top rung, because running out of rungs is NOT a stop --
 * `--max` is the bound, and exhaustion is what produces the recorded decision.
 *
 * @param list<string> $ladder
 * @return list<string>
 */
function attemptLanes(array $ladder, int $startIndex, int $max): array
{
    if ($ladder === []) {
        return [];
    }

    $top = count($ladder) - 1;
    $start = $startIndex >= 0 ? min($startIndex, $top) : 0;
    $lanes = [];
    for ($attempt = 0; $attempt < $max; $attempt++) {
        $lanes[] = $ladder[min($start + $attempt, $top)];
    }

    return $lanes;
}

/**
 * The attempt plan for an advance: the registry ladder, entered at the lane the task was routed to.
 *
 * `--lane` names the rung to start on. When it IS a registry lane, promotion continues up the registry
 * from there. When the registry does not know it, the advance is bounded to that lane alone -- a lane an
 * operator named explicitly is not silently replaced by a rung they did not ask for.
 *
 * Thrown rather than exited for an unknown `--kind`, so --self-test can prove the rejection in both
 * directions, exactly as decisionOptions() does for a malformed option.
 *
 * @return list<string>
 */
function advancePlan(string $lane, string $kind, int $max): array
{
    $ladder = registryLadder();

    if ($lane !== '') {
        $index = array_search($lane, $ladder, true);

        return attemptLanes($index === false ? [$lane] : $ladder, $index === false ? 0 : (int) $index, $max);
    }

    $start = 0;
    if ($kind !== '') {
        $entry = laneFor($kind);
        if ($entry === null) {
            throw new RuntimeException(
                'unknown --kind=' . $kind . '; known kinds: ' . implode(', ', array_keys(CHAIR_LANES))
            );
        }
        $index = array_search($entry['lane'], $ladder, true);
        $start = $index === false ? 0 : (int) $index;
    }

    return attemptLanes($ladder, $start, $max);
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

    // The question the chair forgot to ask, asked by the tool instead.
    try {
        // From the markdown, not from the stored revision: the revision has lost the bullets, so a
        // wrapped criterion cannot be told from two separate ones there. See markdownSection().
        $coverage = acceptanceCoverage(markdownSection($markdown, 'Acceptance criteria'));
        record('ACCEPTANCE', [
            'measured' => (string) count($coverage['measured']),
            'unprobed' => (string) count($coverage['unprobed']),
        ]);
        foreach ($coverage['unprobed'] as $criterion) {
            say('  unprobed: ' . $criterion);
        }
    } catch (Throwable $error) {
        // Reporting must never be the reason a plan fails. Say so rather than dying.
        say('  acceptance coverage: unavailable (' . $error->getMessage() . ')');
    }

    return 0;
}

/**
 * Which acceptance criteria say how they are checked.
 *
 * The most expensive lesson of 2026-09-19, and it happened twice. A contract required weapons to
 * persist and the HUD to name the held weapon. Both were written as prose, no probe measured either,
 * and so the lane -- correctly -- satisfied the thing that decided the task and left the rest. The run
 * reported PASS while two acceptance criteria were unmet. Nothing was wrong with the lane.
 *
 * This deliberately does NOT block. A guard that refuses on a heuristic would cry wolf, and a guard
 * that cries wolf is worse than none. It reports, on every plan, the criteria that do not name the
 * thing that measures them -- which is the question the chair should have asked before spending a lane.
 *
 * @param array<string,mixed> $contract
 * @return array{measured:list<string>, unprobed:list<string>}
 */
/**
 * The acceptance criteria of a contract -- one entry per CRITERION, not per line.
 *
 * The unit is the bullet, and getting it wrong was a defect measured on 2026-09-19 in this very
 * function: a contract with SIX criteria wrapped two of them across lines, `preg_split('/\R/')` treated
 * every physical line as a criterion, and `plan` reported `measured=4 unprobed=5` -- four of those five
 * "unprobed" items were fragments of the two real criteria. A report that flags fragments is a report
 * that gets ignored, and this one exists to be acted on.
 *
 * A continuation line is one that does not itself start a new bullet: `-`, `*`, or a number followed by
 * `.`. The join applies ONLY to a criterion a bullet introduced, so text that is not a bulleted list at
 * all keeps its one-criterion-per-line reading: joining there would swallow genuinely separate criteria,
 * which is the direction that catches an over-eager fix.
 *
 * @return list<string>
 */
function acceptanceCriteria(string $text): array
{
    $criteria = [];
    // Whether the criterion being built is a BULLET, and so whether a non-bullet line continues it.
    $wrapped = false;

    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        $line = (string) $line;
        if (trim($line) === '') {
            continue;
        }
        if (preg_match('/^\s*(?:[-*\x{2022}]|\d+\.)\s+/u', $line) === 1) {
            $criterion = trim($line, " \t-*\u{2022}");
            if ($criterion === '') {
                $wrapped = false;
                continue;
            }
            $criteria[] = $criterion;
            $wrapped = true;
            continue;
        }
        if ($wrapped) {
            $criteria[count($criteria) - 1] .= ' ' . trim($line);
            continue;
        }
        $criteria[] = trim($line);
    }

    return $criteria;
}

/**
 * Which acceptance criteria say how they are checked.
 *
 * The most expensive lesson of 2026-09-19, and it happened twice. A contract required weapons to
 * persist, and the HUD to name the held weapon. Both were written as prose, no probe measured either,
 * and so the lane -- correctly -- satisfied the thing that decided the task and left the rest. The run
 * reported PASS while two acceptance criteria were unmet. Nothing was wrong with the lane.
 *
 * This deliberately does NOT block. A guard that refuses on a heuristic would cry wolf, and a guard that
 * cries wolf is worse than none -- and the first version of this function did exactly that: it split
 * wrapped bullets and reported the fragments as unprobed. It reports, on every plan, the criteria that do
 * not name the thing that measures them, which is the question the chair should have asked before
 * spending a lane. The unit it counts in is the criterion; see acceptanceCriteria().
 *
 * @param string $acceptanceSection the `## Acceptance criteria` section, as markdown
 * @return array{measured:list<string>, unprobed:list<string>}
 */
function acceptanceCoverage(string $acceptanceSection): array
{
    $measured = [];
    $unprobed = [];

    foreach (acceptanceCriteria($acceptanceSection) as $criterion) {
        // Measured means the criterion NAMES its instrument: a probe tag, or a runnable command.
        if (preg_match('/@p\d+/', $criterion) === 1
            || preg_match('/`(?:npx|php|composer|vendor\/bin)\b[^`]*`/', $criterion) === 1) {
            $measured[] = $criterion;
            continue;
        }
        $unprobed[] = $criterion;
    }

    return ['measured' => $measured, 'unprobed' => $unprobed];
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
    say('  `advance` reads THIS order as its ladder: attempt 1 on the task\'s kind, each failure one');
    say('  rung further down the list, and a recorded blocked decision when --max is reached.');

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

/**
 * `run` -- replay a ladder the operator named, bounded by --attempts.
 *
 * @param array<string,string> $options
 * @param list<string>        $flags
 */
function commandRun(array $options, array $flags, string $run = ''): int
{
    $taskId = $options['task'] ?? '';
    if ($taskId === '') {
        fail('run needs --task=<id>');
    }
    $attempts = max(1, (int) ($options['attempts'] ?? 2));

    return attemptLoop(
        $taskId,
        $options,
        $flags,
        attemptLanes(laneSelection($options), 0, $attempts),
        $run,
        'run'
    );
}

/**
 * `advance` -- the bounded attempt ladder.
 *
 * `run --attempts=N` REPEATS the same attempt; the doctrine requires the opposite: a second failure
 * promotes the reasoning level (L1 implementation repair -> L2 implementation-strategy change -> L3 task
 * decomposition), and exhaustion produces a recorded blocked decision rather than one more try. That is
 * what this command is -- `run` with a ladder that means something, and a stop that is written down.
 *
 * @param array<string,string> $options
 * @param list<string>        $flags
 */
function commandAdvance(array $options, array $flags, string $run = ''): int
{
    $taskId = $options['task'] ?? '';
    if ($taskId === '') {
        fail('advance needs --task=<id>');
    }

    $max = advanceAttempts($options['max'] ?? null);
    try {
        $plan = advancePlan($options['lane'] ?? '', $options['kind'] ?? '', $max);
    } catch (RuntimeException $error) {
        fail($error->getMessage());
    }

    record('LADDER', [
        'attempts' => (string) count($plan),
        'lanes' => implode(' > ', $plan),
    ]);

    return attemptLoop($taskId, $options, $flags, $plan, $run, 'advance');
}

/**
 * The lane ladder a `run` was asked for: an explicit list, or the lane of a declared kind of work.
 *
 * @param array<string,string> $options
 * @return list<string>
 */
function laneSelection(array $options): array
{
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

    return $lanes;
}

/**
 * The bounded attempt loop, shared by `run` and `advance`.
 *
 * Everything after the lane is chosen is identical -- baseline, brief, dispatch, probe, drift,
 * falsification -- so it lives here once and only the PLAN differs: `run` replays a ladder an operator
 * named, `advance` promotes from the registry. The lane for attempt N is decided before the loop starts,
 * so the bound is a fact rather than a running total.
 *
 * @param array<string,string> $options
 * @param list<string>        $flags
 * @param list<string>        $plan the lane for each attempt, already bounded
 */
function attemptLoop(string $taskId, array $options, array $flags, array $plan, string $run, string $mode): int
{
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
    $attempts = count($plan);
    if ($attempts === 0) {
        fail('no attempt plan: the lane ladder is empty');
    }

    record('RUN', [
        'task' => $taskId,
        'state' => (string) ($task['state'] ?? '-'),
        'attempts' => (string) $attempts,
        'mode' => $mode,
    ]);

    // Captured ONCE, before any attempt. Falsification needs the tree as the RUN found it, not as the
    // current attempt found it: the guard asks whether the tree held work that is not this run's, and the
    // revert set is everything the run changed -- otherwise a pass bought on attempt 2 by attempt 1's
    // edits would be "falsified" with those very edits still in the tree, and the probe would still pass.
    $runBaseline = changedPaths();
    if (in_array('falsify', $flags, true)) {
        // Asked BEFORE a lane is spent: a refusal that arrives after a run has already done the work has
        // still cost the run, which is the opposite of what a guard is for.
        $earlyRefusal = falsifyRefusal([], $runBaseline);
        if ($earlyRefusal !== null) {
            fail($earlyRefusal);
        }
    }

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
        $lane = $plan[$attempt - 1];
        $context = currentContext($objective, $scope);
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
            // A green probe is not yet a verified run: the lane may have bought it by weakening the
            // tests around it. Checked here, against HEAD, before anything is recorded as done.
            $drift = assertionDrift($changed);
            if ($drift !== []) {
                record('WEAKENED', ['task' => $taskId, 'findings' => (string) count($drift)]);
                foreach (array_slice($drift, 0, 6) as $finding) {
                    say('  ' . $finding);
                }
                commandDecide([
                    'task' => $taskId,
                    'decision' => 'pass refused: the run weakened its own tests',
                    'rationale' => 'The probe passes, but assertions were removed or loosened in the files '
                        . 'this run touched. A pass obtained that way proves nothing, so it is not recorded '
                        . 'as one. The chair decides: restore the assertions and re-run, or -- if the check '
                        . 'was genuinely wrong -- correct the check openly, with the reason, which is a '
                        . 'different change from deleting it.',
                ]);
                return 3;
            }

            // Mechanised falsification, before anything is recorded as verified: a green probe is not yet
            // evidence, because it might be green without the change. The revert is what makes the pass
            // mean something, and it is precisely the step that is easy to skip by hand.
            if (in_array('falsify', $flags, true)) {
                $falsified = falsifyProbe(
                    $probe,
                    array_values(array_diff(changedPaths(), $runBaseline)),
                    $runBaseline
                );
                $dependenceRefusal = probeDependenceRefusal($falsified['exit']);
                if ($dependenceRefusal !== null) {
                    say('  ' . $dependenceRefusal);
                    commandDecide([
                        'task' => $taskId,
                        'decision' => 'probe refused: it does not depend on the change',
                        'rationale' => 'The probe passed with the run\'s changes reverted, so it does not '
                            . 'measure what this task claims. The finding is about the instrument, not the '
                            . 'lane: correct the probe, with the reason, before the next attempt -- and do '
                            . 'not weaken it to obtain a pass.',
                    ]);

                    return 3;
                }
                say('  Falsified: the probe went RED with the change reverted, so it measures the change.');
            }

            if ($attempt > 1) {
                // Failed once, passed on retry. That is harness instability, not a clean pass, and calling
                // it clean would hide exactly the flakiness this project has been bitten by.
                record('FLAKY', ['task' => $taskId, 'attempt' => (string) $attempt]);
                commandDecide([
                    'task' => $taskId,
                    'decision' => 'verified, classified FLAKY (passed on attempt ' . $attempt . ')',
                    'rationale' => 'The probe failed first and passed on retry. Recorded as instrument '
                        . 'instability so it is not mistaken later for a clean pass, and so the probe is '
                        . 'made deterministic rather than trusted.',
                ]);
            }

            // Which lane got there, and after how many attempts: a pass on the third attempt from the top
            // rung is a different fact about the task than a pass on the first, and the record says which.
            record('VERIFIED', [
                'task' => $taskId,
                'attempt' => (string) $attempt,
                'lane' => $lane,
                'probe' => $probe,
            ]);

            // Tell the index which files actually served this task. That is the one signal lexical
            // search cannot derive for itself, and reporting it back is what makes retrieval improve
            // with use instead of only growing.
            $used = chairIndex()->recordUse($changed);
            if ($used > 0) {
                record('INDEX', ['used' => (string) $used]);
            }

            say('  Evidence is the probe above, run against the tree the lane produced.');
            say('  A warm client can still hold a stale asset: if the change is served, check the');
            say('  entry page versions its assets from the live file mtimes.');
            return 0;
        }

        $previous = $after['output'];
        if ($attempt < $attempts) {
            record('PROMOTE', [
                'reason' => 'probe failed',
                'from' => $lane,
                'next' => $plan[$attempt],
            ]);
        }
    }

    // RULE 3. Exhaustion is a recorded decision, not a question for the director -- and not one more
    // attempt. The ladder ended; what follows is a REASON, in the ledger and in the task's decisions.
    return commandExhausted($taskId, $plan, $run);
}

/**
 * The ledger record of an exhausted run.
 *
 * Phase `blocked`, never `start`: commit-check reads a `start` without a `finish` as a live or abandoned
 * run, and exhaustion is neither -- it is a finished decision, and it must not be able to block a commit.
 *
 * @return array<string,mixed>
 */
function blockedEntry(string $taskId, int $attempts, string $stopReason, string $run = ''): array
{
    return [
        'phase' => 'blocked',
        'run' => $run,
        'task' => $taskId,
        'attempts' => $attempts,
        'stop_reason' => $stopReason,
    ];
}

/**
 * Stop, and say why in a form that survives the run.
 *
 * `run` and `advance` both end here once the plan is spent: a chair decision carrying the options that
 * were considered, a BLOCKED line naming the stop reason and the attempts made, and a ledger entry so the
 * stop is a fact about the run rather than a line of output somebody has to remember.
 *
 * @param list<string> $plan
 */
function commandExhausted(string $taskId, array $plan, string $run): int
{
    commandDecide([
        'task' => $taskId,
        'decision' => 'parked after ' . count($plan) . ' attempts (stop_reason=ATTEMPTS_EXHAUSTED)',
        'rationale' => 'The probe still fails after every lane in the ladder was tried. Options, in the '
            . 'order the chair would take them: (a) the probe is wrong -- fix it and restate the task; '
            . '(b) the task is too large -- split it so each part has its own probe; (c) the work is '
            . 'genuinely hard -- keep the top lane and raise reasoning further. Nothing here needs the '
            . 'director: it needs the next decision, which is the chair\'s.',
    ]);
    ledgerAppend(blockedEntry($taskId, count($plan), 'ATTEMPTS_EXHAUSTED', $run));
    record('BLOCKED', [
        'task' => $taskId,
        'attempts' => (string) count($plan),
        'stop_reason' => 'ATTEMPTS_EXHAUSTED',
        'lanes' => implode(' > ', $plan),
    ]);
    say('  No further attempt will be made. The ladder ended at ' . $plan[count($plan) - 1] . '.');

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

/**
 * A chair decision is recorded; an L4 is delivered.
 *
 * The distinction is the doctrine's: a decision the chair can make is provenance the owner may inspect
 * afterwards, while an L4 is an interruption and therefore requires an acknowledged delivery. Filing
 * one as a local file and a good intention is exactly the silent non-delivery the policy forbids, and
 * it is what this file did before 2026-09-19.
 *
 * `$flags` defaults to empty because the IN-LOOP callers record a chair decision and have no flags of
 * their own: every call site inside a run passed one argument, and the declaration needed two, so the
 * baseline-already-passes path, the weakened-assertions path and the exhaustion path all died with
 * `ArgumentCountError` -- paths the self-test could not reach. A default is the honest fix: those
 * callers never meant to escalate, and `--escalate` remains a flag only the command line can pass.
 *
 * @param array<string,string> $options
 * @param list<string> $flags
 */
function commandDecide(array $options, array $flags = []): int
{
    $taskId = $options['task'] ?? '';
    if ($taskId === '') {
        fail('decide needs --task=<id>');
    }

    $dir = chairStateDir() . '/decisions';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fail("cannot create {$dir}");
    }

    if (!in_array('escalate', $flags, true)) {
        $decision = $options['decision'] ?? '';
        if ($decision === '') {
            fail('decide needs --decision=<text>, or --escalate to file an L4 with the director');
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
        record('DECISION', ['task' => $taskId, 'chair' => $decision, 'owner_intervention' => 'not required']);

        return 0;
    }

    // ── L4: the deferred-decision contract, shape and all ────────────────────────────────────────
    $title = $options['title'] ?? '';
    $recommend = $options['recommend'] ?? '';
    $rationale = $options['rationale'] ?? '';
    $choices = [];
    try {
        $choices = decisionOptions((string) ($options['options'] ?? ''));
    } catch (RuntimeException $error) {
        fail($error->getMessage());
    }

    if ($title === '') {
        fail('--escalate needs --title=<short title>');
    }
    if (count($choices) < 2 || count($choices) > 4) {
        fail('--escalate needs 2 to 4 mutually exclusive --options entries, got ' . count($choices));
    }
    $ids = array_column($choices, 'id');
    if ($recommend === '' || !in_array($recommend, $ids, true)) {
        fail('--escalate needs --recommend=<id> naming one of: ' . implode(', ', $ids));
    }

    $decisionKey = $taskId . '-' . gmdate('Ymd-His');
    $artifact = [
        'urn' => 'urn:ikabud:workbench:development-decision-request:v1',
        'decision_key' => $decisionKey,
        'decision_id' => $decisionKey,
        'task' => $taskId,
        'title' => $title,
        'authority' => 'L4',
        'options' => $choices,
        'recommendation' => ['option' => $recommend, 'why' => $rationale],
        'default_if_no_response' => 'stop',
        'resume' => 'php tools/chair.php run --task=' . $taskId,
        'transport' => ['status' => 'pending'],
        'filed_at' => gmdate(DATE_ATOM),
    ];

    // The local artifact is written BEFORE delivery is attempted. A decision that is not delivered is
    // still a filed decision; a decision that is neither is a lost one.
    $path = $dir . '/' . $decisionKey . '.json';
    file_put_contents($path, json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    record('L4', ['task' => $taskId, 'key' => $decisionKey, 'artifact' => $path]);

    $body = $title . "\n\nOptions:\n"
        . implode("\n", array_map(
            static fn (array $option): string => sprintf(
                '  %s: %s -- %s (cost: %s; blast radius: %s; reversibility: %s)',
                $option['id'],
                $option['label'],
                $option['effect'],
                $option['cost'],
                $option['blast_radius'],
                $option['reversibility']
            ),
            $choices
        ))
        . "\n\nRecommendation: " . $recommend . ($rationale !== '' ? " -- {$rationale}" : '')
        . "\n\nDefault if no response: stop."
        . "\nResume: php tools/chair.php run --task=" . $taskId;

    $delivery = deliverDecision($decisionKey, $title, $body, $recommend . ': ' . $rationale);

    if ($delivery['ok']) {
        record('DELIVERED', ['key' => $decisionKey]);
        ledgerAppend(['phase' => 'decision', 'key' => $decisionKey, 'task' => $taskId, 'delivered' => true]);

        return 0;
    }

    say(CHAIR_UNDELIVERED);
    say('  retry: ' . $delivery['command']);
    say('  exit:  ' . $delivery['exit']);
    if (trim($delivery['output']) !== '') {
        say('  said:  ' . trim($delivery['output']));
    }
    say('  the artifact is at ' . $path . ' and the work is parked at a resumable checkpoint.');
    ledgerAppend(['phase' => 'decision', 'key' => $decisionKey, 'task' => $taskId, 'delivered' => false]);

    return 4;
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

/**
 * `--self-test` with the ledger moved to a scratch file for its duration.
 *
 * DEFECT, measured 2026-09-19: the self-test wrote its records into the PRODUCTION ledger. Of the 23
 * entries in storage/private/chair/ledger.jsonl, 19 were self-test noise -- `self-test-<hex>` runs, plus
 * two fabricated runs (an `abandoned` start and several `blocked` stops) -- and `commit-check` reads that
 * file to decide whether a run is live. The authoritative record of what the harness actually did was
 * mostly test output, which is a defect in the instrument rather than in anything it measured. (The
 * production ledger was cleaned once, with a comment recording what was removed; every REAL run record
 * was left alone, because those are evidence.)
 *
 * `try/finally` rather than a tidy-up line at the end of the test: the real path must come back even when
 * a check throws. The self-test measures both directions itself -- its records land in the scratch file,
 * a lifted redirect still writes the production ledger, and the production ledger is byte-identical
 * afterwards -- because "the self-test stopped writing noise" bought by breaking ledger writing
 * everywhere would be a worse defect than the one being repaired.
 */
function ledgerRedirectedSelfTest(): int
{
    $scratch = chairStateDir() . '/selftest-ledger-' . bin2hex(random_bytes(4)) . '.jsonl';
    $previous = ledgerOverride($scratch, true);

    try {
        return selfTest();
    } finally {
        ledgerOverride($previous, true);
        @unlink($scratch);
    }
}

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

    // The production ledger, held as raw bytes so the checks at the end of this test can prove it comes
    // out of a whole self-test unchanged. Read by path rather than through ledgerPath(): this is the file
    // under test, and a check that compared the redirect against itself would prove nothing.
    $productionLedger = chairStateDir() . '/ledger.jsonl';
    $productionBefore = is_file($productionLedger) ? (string) file_get_contents($productionLedger) : '';
    $scratchLedger = ledgerPath();

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

    say('assertion weakening is caught, and moving an assertion is not:');
    // One assertion per line, because that is the shape this comparison is built for: it treats a LINE
    // as an assertion. My first fixtures put two on one line and the controls failed -- correctly, and
    // for a reason worth keeping visible: on a single-line style the whole line is one unit, so adding a
    // third assertion reads as replacing the line. That limitation is asserted below rather than assumed
    // away, because a project that writes assertions on one line is not covered by this guard.
    $before = "test('x', () => {\n  expect(a).toBeGreaterThan(8);\n  expect(b).toBe(1);\n});";
    $removedOne = "test('x', () => {\n  expect(b).toBe(1);\n});";
    $loosenedOne = "test('x', () => {\n  expect(a).toBeGreaterThan(2);\n  expect(b).toBe(1);\n});";
    $reindented = "test('x', () => {\n\t\texpect(a).toBeGreaterThan(8);\n\t\texpect(b).toBe(1);\n});";
    $rewrapped = "test('x', () => {\n  expect(a).toBeGreaterThan(8);\n  expect(b)\n    .toBe(1);\n});";
    $grown = "test('x', () => {\n  expect(a).toBeGreaterThan(8);\n  expect(b).toBe(1);\n  expect(c).toBe(2);\n});";
    $oneLine = "expect(a).toBeGreaterThan(8); expect(b).toBe(1);";

    $check('deleting an assertion is caught', AssertionChange::analyse($before, $removedOne)['removed'] !== []);
    $check('loosening a bound is caught', AssertionChange::analyse($before, $loosenedOne)['loosened'] !== []);
    $check('reindenting an assertion is NOT a removal', AssertionChange::analyse($before, $reindented)['ok']);
    $check('adding an assertion is not a weakening', AssertionChange::analyse($before, $grown)['ok']);
    $check('growth is visible in the counts', AssertionChange::analyse($before, $grown)['counts'] === ['old' => 2, 'new' => 3]);
    // The two limits, asserted rather than assumed. Both come from the same design choice -- a LINE is
    // an assertion -- and both are safe for this repository, whose PHP tests write one check per line and
    // whose Playwright specs write one expect per statement. A project that minifies its assertions, or
    // reflows one across lines, is NOT covered, and a reader should know that rather than discover it.
    $check(
        'KNOWN LIMITATION 1: two assertions on ONE line are one unit',
        AssertionChange::analyse($oneLine, $oneLine . " expect(c).toBe(2);")['removed'] !== []
    );
    $check(
        'KNOWN LIMITATION 2: reflowing one assertion across two lines reads as a removal',
        AssertionChange::analyse($before, $rewrapped)['removed'] !== []
    );

    // False positives, asserted because they were REAL: this repository's markers were widened to catch
    // its own `$check(...)`, and the first attempt lost the word boundaries -- after which a comment
    // saying "RETIRED ASSERTIONS" and the line `$fail = 0;` counted as assertions while the genuine
    // `$check(...)` line did not. A guard that counts comments is a guard that reports removals that
    // never happened, which is how a reader learns to distrust it.
    // Escaped, because in a double-quoted string a bare `$check(` interpolates the closure this very
    // test uses and fatals with "Object of class Closure could not be converted to string".
    $phpStyle = "\$check(\$a !== [], 'a message');";
    $check('the repository idiom $check(...) is recognised', AssertionChange::canonical($phpStyle, AssertionChange::REPOSITORY_MARKERS) !== null);
    // With REPOSITORY_MARKERS, which is the point: the first version of this control called analyse()
    // without them, so it fed a repo-idiom fixture to the DEFAULT markers, which do not know `$check(`,
    // found nothing to compare and reported no removal. The control was wrong, not the guard.
    $check('and deleting it IS caught', AssertionChange::analyse($phpStyle, "// gone", AssertionChange::REPOSITORY_MARKERS)['removed'] !== []);
    $check('the default markers alone do NOT see the repo idiom', AssertionChange::canonical($phpStyle) === null);
    $check('a comment mentioning assertions is NOT an assertion', AssertionChange::canonical(' * RETIRED ASSERTIONS, 2026-09-19', AssertionChange::REPOSITORY_MARKERS) === null);
    $check('a variable named $fail is NOT an assertion', AssertionChange::canonical('$fail = 0;', AssertionChange::REPOSITORY_MARKERS) === null);
    $check('a variable named $fail is not an assertion on the default markers either', AssertionChange::canonical('$fail = 0;') === null);

    say('retrieval:');
    $ranked = retrieveContext('star swarm moon crater rendering', ['public/star-swarm'], 5);
    $check('the objective retrieves files rather than nothing', $ranked !== []);
    $check('retrieval is bounded by the requested limit', count($ranked) <= 5);

    // The staleness gate. Measured 2026-09-19: `stats()` reported `stale 8` and the harness briefed lanes
    // from the index anyway. A lane handed a description of code that has changed is confidently wrong,
    // so this is checked, repaired, re-checked, and refused if the repair did not work.
    say('context staleness, and the repair that must work before a lane sees anything:');
    $selftestFile = 'storage/cache/chair-selftest-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents(CHAIR_ROOT . '/' . $selftestFile, "<?php // staleness self-test\n");
    chairIndex()->index([$selftestFile], true);
    $check('a file that has just been indexed reads as current', staleContextPaths([$selftestFile]) === []);
    file_put_contents(CHAIR_ROOT . '/' . $selftestFile, "<?php // changed after indexing, and longer\n");
    $check('a file changed after indexing reads as STALE', staleContextPaths([$selftestFile]) === [$selftestFile]);
    chairIndex()->index([$selftestFile], true);
    $check('a forced re-index repairs the staleness', staleContextPaths([$selftestFile]) === []);
    $check('fresh context is handed over rather than refused', staleContextRefusal([]) === null);
    $check(
        'stale context is refused, and the refusal names the path',
        str_contains((string) staleContextRefusal([$selftestFile]), $selftestFile)
    );
    unlink(CHAIR_ROOT . '/' . $selftestFile);
    $check(
        'a path that is GONE cannot be made current, so it stays stale and the gate fails closed',
        staleContextPaths([$selftestFile]) === [$selftestFile]
    );
    chairIndex()->forget([$selftestFile]);

    // The bounded attempt ladder. `run --attempts=N` repeated an attempt; the doctrine requires a second
    // failure to promote the reasoning level, so the promotion is asserted rather than assumed.
    say('the bounded attempt ladder:');
    $ladder = registryLadder();
    $check(
        'the ladder is the registry, cheapest first and top last',
        count($ladder) === count(CHAIR_LANES)
        && $ladder[0] === CHAIR_LANES['mechanical']['lane']
        && $ladder[count($ladder) - 1] === CHAIR_LANES['reasoning']['lane']
    );
    $check(
        'attempt 1 uses the lane the task was routed to',
        advancePlan('', 'visual', 3)[0] === CHAIR_LANES['visual']['lane']
        && advancePlan('', 'mechanical', 3)[0] === CHAIR_LANES['mechanical']['lane']
    );
    $check(
        'a failure PROMOTES to a more capable lane rather than repeating the attempt',
        advancePlan('', 'mechanical', 3)[1] === CHAIR_LANES['visual']['lane']
        && advancePlan('', 'visual', 3)[1] === CHAIR_LANES['reasoning']['lane']
    );
    $check(
        'promotion stops at the top rung instead of inventing a fourth lane',
        advancePlan('', 'visual', 3) === [
            CHAIR_LANES['visual']['lane'],
            CHAIR_LANES['reasoning']['lane'],
            CHAIR_LANES['reasoning']['lane'],
        ]
    );
    $check('the plan never exceeds --max, whatever the ladder holds', count(advancePlan('', 'mechanical', 2)) === 2);
    $check(
        'a lane the registry does not know is used as named, not silently upgraded to a registry rung',
        advancePlan('some/model:low', '', 2) === ['some/model:low', 'some/model:low']
    );
    $check('a known --kind is accepted', advancePlan('', 'mechanical', 1) === [CHAIR_LANES['mechanical']['lane']]);
    $unknownKindRejected = false;
    try {
        advancePlan('', 'nonsense', 1);
    } catch (RuntimeException $error) {
        $unknownKindRejected = true;
    }
    $check('an unknown --kind is rejected rather than silently defaulted', $unknownKindRejected);
    $check('the default advance is three attempts', advanceAttempts(null) === 3);
    $check('--max is honoured', advanceAttempts('1') === 1);
    $check('--max is bounded, so exhaustion is bounded too', advanceAttempts('99') === 5);
    $check('an unreadable --max falls back to the default, not to one attempt', advanceAttempts('abc') === 3);

    say('exhaustion is a recorded blocked decision, and never one more attempt:');
    $blockedTask = 'selftest-blocked-' . bin2hex(random_bytes(3));
    $check(
        'exhaustion returns 3 rather than reporting success',
        commandExhausted($blockedTask, ['a:low', 'b:high'], 'selftest-run') === 3
    );
    $blocked = null;
    foreach (ledgerRead() as $entry) {
        if (($entry['task'] ?? '') === $blockedTask) {
            $blocked = $entry;
        }
    }
    $check(
        'the ledger carries a blocked record for the exhausted run',
        is_array($blocked) && ($blocked['phase'] ?? '') === 'blocked'
    );
    $check(
        'the blocked record names the stop reason, the attempts made, and the run',
        is_array($blocked)
        && ($blocked['stop_reason'] ?? '') === 'ATTEMPTS_EXHAUSTED'
        && ($blocked['attempts'] ?? 0) === 2
        && ($blocked['run'] ?? '') === 'selftest-run'
    );
    // The other direction, and the reason the phase is `blocked` and not `start`: a stop is a FINISHED
    // decision, so it must not read as a live or abandoned run and block a commit.
    $check('a blocked record does NOT make commit-check see a live run', commandCommitCheck([]) === 0);
    unlink(chairStateDir() . '/decisions/' . $blockedTask . '.jsonl');

    // Falsification -- the loop the chair ran by hand on 2026-09-19, mechanised. Every guard is proved in
    // both directions: the revert must refuse a tree it did not make, and the probe must go red.
    say('falsification -- the probe must go RED when the change is taken away:');
    $check('a tree whose only changes are the run\'s own may be falsified', falsifyRefusal(['src/a.php'], []) === null);
    $foreignRefusal = falsifyRefusal(['src/a.php'], ['src/b.php']);
    $check('uncommitted work the run did not make refuses the revert', $foreignRefusal !== null);
    $check(
        'and the refusal names the path that would have been swept up',
        str_contains((string) $foreignRefusal, 'src/b.php')
    );
    $check('a probe that goes RED without the change proves it measures the change', probeDependenceRefusal(1) === null);
    $check('a probe that stays GREEN without the change is a finding, not a pass', probeDependenceRefusal(0) !== null);
    $check('a restore that reproduces every hash is OK', restoreVerified(['a.php' => 'h1'], ['a.php' => 'h1']) === []);
    $check('a restore that does not is reported, not assumed', restoreVerified(['a.php' => 'h1'], ['a.php' => 'h2']) === ['a.php']);

    // The mechanics, for real, on a file this self-test owns. It is UNTRACKED, so no `git checkout` is
    // involved and the repository's own working tree is never touched by a self-test -- which is the only
    // way this belongs in a test at all.
    $fixture = 'storage/cache/chair-falsify-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents(CHAIR_ROOT . '/' . $fixture, "<?php // falsify self-test\n");
    $backup = falsifyBackup([$fixture]);
    falsifyRevert($backup);
    $check('reverting a file the run created takes it out of the tree', !is_file(CHAIR_ROOT . '/' . $fixture));
    $restored = falsifyRestore($backup);
    $check(
        'the restore puts it back byte-identical, and says so',
        $restored['ok']
        && is_file(CHAIR_ROOT . '/' . $fixture)
        && hash('sha256', (string) file_get_contents(CHAIR_ROOT . '/' . $fixture)) === $backup['hashes'][$fixture]
    );
    // The other direction: a restore that CANNOT reproduce the bytes must be reported, not assumed.
    $second = falsifyBackup([$fixture]);
    falsifyRevert($second);
    file_put_contents($second['aside'] . '/' . falsifyAsideName($fixture), 'tampered');
    $broken = falsifyRestore($second);
    $check(
        'a restore that cannot reproduce the bytes is reported as FAILED',
        !$broken['ok'] && $broken['mismatches'] === [$fixture]
    );
    unlink(CHAIR_ROOT . '/' . $fixture);
    @rmdir($second['aside']);

    // The director channel. A delivery check that says "sent" too easily is worse than no check at all,
    // because the whole invariant is that an unacknowledged decision is NOT delivered.
    say('the director channel:');
    $check('an accepted submission counts as delivered', deliveryAcknowledged('{"ok": true, "decision_key": "t-1"}', 0));
    $check('a bare ok:true counts as delivered', deliveryAcknowledged('{"ok": true}', 0));
    // The trap, and the reason this check exists: suppression answers ok=true and notifies nobody.
    $check('a SUPPRESSED submission is NOT delivery, even though ok is true', !deliveryAcknowledged('{"ok": true, "suppressed": true, "reason": "testing/quiet mode"}', 0));
    $check('ok:false is not delivery', !deliveryAcknowledged('{"ok": false, "error": "nope"}', 0));
    $check('output that is not JSON is not delivery', !deliveryAcknowledged('DEC-0002 submitted', 0));
    $check('empty output is not delivery', !deliveryAcknowledged('', 0));
    $check('a failed exit is not delivery even when the body says ok', !deliveryAcknowledged('{"ok": true}', 1));

    say('the deferred-decision artifact:');
    $parsed = decisionOptions('a|A|effect A|cheap|local|reversible; b|B|effect B|dear|wide|no');
    $check('options parse into the six named fields', count($parsed) === 2 && $parsed[1]['id'] === 'b');
    $check('the six fields are all carried through', $parsed[0]['reversibility'] === 'reversible');
    $rejected = false;
    try {
        decisionOptions('a|A|effect|cheap|local');
    } catch (RuntimeException $error) {
        $rejected = true;
    }
    $check('a five-field option is rejected rather than silently accepted', $rejected);

    // Acceptance coverage. Both directions, because a coverage report that flags everything is as
    // useless as one that flags nothing -- it would be ignored, and it exists to be acted on.
    say('acceptance coverage:');
    $covered = acceptanceCoverage(
        "A drop reaches the player's row (probe @p15).\n"
        . "The suite passes: `npx playwright test tests/browser/x.spec.ts`\n"
        . 'The HUD names the weapon currently held.'
    );
    $check('a criterion naming a probe tag counts as measured', in_array(
        "A drop reaches the player's row (probe @p15).",
        $covered['measured'],
        true
    ));
    $check('a criterion naming a runnable command counts as measured', in_array(
        'The suite passes: `npx playwright test tests/browser/x.spec.ts`',
        $covered['measured'],
        true
    ));
    // The one that matters: the prose criterion is exactly what went unenforced on 2026-09-19.
    $check('a criterion that names no instrument is reported as unprobed', in_array(
        'The HUD names the weapon currently held.',
        $covered['unprobed'],
        true
    ));
    $check('the two counts partition the criteria', count($covered['measured']) === 2 && count($covered['unprobed']) === 1);
    $empty = acceptanceCoverage('');
    $check('an empty acceptance section reports nothing rather than inventing a criterion', $empty['measured'] === [] && $empty['unprobed'] === []);

    // DEFECT, measured 2026-09-19: the unit is the BULLET, not the line. A contract with SIX criteria
    // reported `measured=4 unprobed=5`, because two wrapped bullets were counted as five criteria and four
    // of the five "unprobed" items were fragments. A coverage report that flags fragments gets ignored,
    // which is the opposite of what it is for -- so the wrapped case is asserted, and so is the direction
    // that catches an over-eager fix.
    $wrappedBullet = acceptanceCoverage(
        "- The HUD names the weapon currently held, and it changes when a new pickup\n"
        . "  is taken. **No probe covers this; the chair verifies it with a screenshot.**\n"
        . "  It is left in the contract deliberately unprobed.\n"
        . "- A drop reaches the player's row (probe @p15).\n"
    );
    $check(
        'a bullet wrapped over three lines is ONE criterion, not three',
        $wrappedBullet['unprobed'] === [
            'The HUD names the weapon currently held, and it changes when a new pickup is taken. '
            . '**No probe covers this; the chair verifies it with a screenshot.** '
            . 'It is left in the contract deliberately unprobed.',
        ]
        && count($wrappedBullet['measured']) === 1
    );
    // The other direction. "Join everything" would pass the check above and swallow real structure.
    $separateBullets = acceptanceCoverage(
        "- A drop reaches the player's row (probe @p15).\n"
        . "- A weapon persists for the rest of the run (probe @p17).\n"
        . "- The HUD names the weapon held.\n"
    );
    $check(
        'separate bullets are still separate criteria -- the join does not swallow real structure',
        count($separateBullets['measured']) === 2
        && $separateBullets['unprobed'] === ['The HUD names the weapon held.']
    );
    // The path `plan` actually takes: the section out of the markdown, bullets intact. The stored
    // revision cannot carry this check, which is exactly why the reader was changed.
    $fromMarkdown = acceptanceCoverage(markdownSection(
        "# CONTRACT — fixture\n## Acceptance criteria\n"
        . "- A drop reaches the player's row (probe @p15).\n"
        . "  ...and it is still legible at the end of its life.\n"
        . "- The HUD names the weapon held.\n"
        . "## Required tests\n- `php tests/thing_test.php`\n",
        'Acceptance criteria'
    ));
    $check(
        'the markdown section reader hands the criteria over with their bullets intact',
        count($fromMarkdown['measured']) === 1
        && $fromMarkdown['unprobed'] === ['The HUD names the weapon held.']
    );

    // The lock and the ledger, which are what make "never commit during a live run" askable.
    say('the lock and the ledger:');
    $held = lockAcquire(false);
    $check('the first holder gets the lock', $held !== null);
    $check('a second run is refused while one holds the lock', lockAcquire(false) === null);
    if ($held !== null) {
        lockRelease($held);
    }
    $reacquired = lockAcquire(false);
    $check('the lock is available again once released', $reacquired !== null);
    if ($reacquired !== null) {
        lockRelease($reacquired);
    }

    $ledgerProbe = 'self-test-' . bin2hex(random_bytes(4));
    ledgerAppend(['phase' => 'start', 'run' => $ledgerProbe]);
    $written = false;
    foreach (ledgerRead() as $entry) {
        if (($entry['run'] ?? '') === $ledgerProbe) {
            $written = true;
        }
    }
    $check('a run is recorded in the ledger', $written);
    // Direction 1 of the redirect: the record this test just wrote went to the SCRATCH ledger. Without
    // this, the check below could pass by the test writing nothing anywhere at all.
    $check(
        'the self-test ledger record landed in the scratch ledger, not the production one',
        $scratchLedger !== $productionLedger
        && str_contains((string) @file_get_contents($scratchLedger), $ledgerProbe)
        && !str_contains((string) @file_get_contents($productionLedger), $ledgerProbe)
    );
    $check('an unfinished run reads as abandoned while the lock is free', commandCommitCheck([]) === 0);
    $check('and --strict makes that abandoned run block a commit', commandCommitCheck(['strict']) === 3);
    ledgerAppend(['phase' => 'finish', 'run' => $ledgerProbe, 'exit' => 0]);

    // Direction 2, and the reason the redirect MOVES the path instead of disabling the helpers: with the
    // redirect lifted they must write the real ledger again. Proved by writing one marker record there,
    // confirming it landed, and removing exactly that line -- then asserting the file is byte-identical to
    // what it was, so the proof leaves no noise of its own.
    $marker = 'selftest-ledger-probe-' . bin2hex(random_bytes(4));
    ledgerOverride(null, true);
    ledgerAppend(['phase' => 'start', 'run' => $marker]);
    $landed = str_contains((string) @file_get_contents($productionLedger), $marker);
    ledgerStripLine($productionLedger, $marker);
    ledgerOverride($scratchLedger, true);
    $check('with the redirect lifted, the ledger helpers write the production ledger again', $landed);
    $check(
        'and that marker line is removed again, byte-for-byte',
        (is_file($productionLedger) ? (string) file_get_contents($productionLedger) : '') === $productionBefore
    );

    // The repair itself, measured against the file rather than against the intent: a whole self-test ran,
    // and the production ledger -- the record `commit-check` reads to decide whether a run is live -- is
    // exactly what it was before the test started.
    $check(
        'running the self-test does not change the production ledger',
        (is_file($productionLedger) ? (string) file_get_contents($productionLedger) : '') === $productionBefore
    );

    say('');
    say(sprintf('  => %d passed, %d failed', $pass, $fail));

    return $fail === 0 ? 0 : 1;
}

// ─────────────────────────────────────────────────────────────────────────────────────────────────

// The shutdown net for a falsification in flight. `try/finally` does not run when PHP exits, and
// runProbe() exits when it cannot start the probe -- without this, that exit would leave the tree
// reverted, which is the one outcome the whole backup exists to prevent. It returns immediately when
// nothing is in flight, so it costs a no-op on every other invocation.
register_shutdown_function(static function (): void {
    $backup = falsifyInFlight();
    if ($backup !== null) {
        falsifyRestore($backup);
        say('  chair: the process exited mid-falsification; the files were restored from the backup.');
    }
});

$cli = cli($argv);
$cli['options']['_flags'] = implode(',', $cli['flags']);

if (in_array('self-test', $cli['flags'], true)) {
    // WRAPPED, not called bare: the self-test records runs of its own, and those records belong in a
    // scratch ledger rather than in the production one that commit-check reads to decide if a run is live.
    exit(ledgerRedirectedSelfTest());
}

if ($cli['command'] === '') {
    say('usage: php tools/chair.php <plan|run|advance|probe|decide|status|lanes|commit-check> [options]');
    say('       php tools/chair.php run --task=<id> [--attempts=2] [--lane=<model>:<thinking>,...] [--falsify] [--dry]');
    say('       php tools/chair.php advance --task=<id> [--max=3] [--kind=mechanical|visual|reasoning] [--falsify]');
    say('       php tools/chair.php decide --task=<id> --decision=<text>');
    say('       php tools/chair.php decide --escalate --task=<id> --title=<t>');
    say('            --options="id|label|effect|cost|blast_radius|reversibility; ..." --recommend=<id>');
    say('       php tools/chair.php --self-test');
    exit(0);
}

exit(match ($cli['command']) {
    'plan' => commandPlan($cli['options']),
    // Wrapped, not inlined: a run holds the lock for its whole life and leaves a record of it. The
    // ledger decides commit eligibility, not the state of the tree.
    'run' => withRunLock(
        $cli['options'] + ['mode' => 'run'],
        static fn (string $run): int => commandRun($cli['options'], $cli['flags'], $run)
    ),
    // `advance` takes a DIFFERENT lane per attempt and ends in a recorded blocked decision, but it takes
    // the same lock: two writers on one tree is what the lock exists to prevent, whatever the command is.
    'advance' => withRunLock(
        $cli['options'] + ['mode' => 'advance'],
        static fn (string $run): int => commandAdvance($cli['options'], $cli['flags'], $run)
    ),
    'probe' => commandProbe($cli['options']),
    'lanes' => commandLanes(),
    'decide' => commandDecide($cli['options'], $cli['flags']),
    'status' => commandStatus($cli['options']),
    'commit-check' => commandCommitCheck($cli['flags']),
    default => fail('unknown command: ' . $cli['command']),
});
