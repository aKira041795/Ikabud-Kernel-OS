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

/**
 * The verifier trust surface: the enumerated files whose modification could silently change what
 * the harness proves. This list is the single source of truth for the trust-surface prohibition and
 * for the integrity hash recorded by the run ledger (tools/ai-run.php mirrors it; the two are pinned
 * together by tests/ai_autonomy_test.php and tests/ai_run_test.php).
 *
 * CONSTITUTING CHANGE — owner directive 2026-09-14 ("a strict guardrail that you as chair cannot
 * break"). This matcher was added ONCE, by the Chair, under that explicit directive, to close the
 * verified fail-open in which a valid contract could list the verifier in `allowed_scope` and thereby
 * legitimise editing it (CD-23: `check "widen the command allowlist…" --path=tools/ai-run.php`
 * returned RECORD/L2/exit 0, while the same action aimed at `phpstan.neon` returned ESCALATE/L4).
 *
 * This exception is NOT a precedent and cannot be cited as one. Once this matcher is mechanically
 * effective, any further change to the trust surface requires the director: the Chair may propose,
 * it may not perform. Amendments to this list are director-only.
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
 * Does one normalized path touch the trust surface?
 *
 * Ambiguity resolves toward protection, never toward the executor's preferred reading:
 *   - an exact trust-surface file matches;
 *   - a directory that covers a trust-surface file matches (`tools/` covers `tools/ai-run.php`);
 *   - a glob that could match a trust-surface file matches (`tools/*.php`, `tools/*`);
 *   - an unresolved or trust-surface-resolving symlink matches.
 */
function isTrustSurfacePath(string $path): bool
{
    return trustSurfaceCoverage($path) !== [];
}

/**
 * Trust-surface files reached by a scope claim. This is deliberately a coverage test rather than an
 * exact-path test: directories and globs are capabilities over every path they can denote. A broken
 * symlink cannot be resolved and therefore fails closed as reaching the whole protected surface.
 *
 * @return list<string>
 */
function trustSurfaceCoverage(string $path): array
{
    $path = trim($path, " \t\n\r\0\x0B`\"'");
    $path = preg_replace('#^\./+#', '', $path) ?? $path;
    $path = rtrim($path, '/');
    if ($path === '' || $path === '.') { return []; }

    $reached = [];
    foreach (trustSurfacePaths() as $trust) {
        if (preg_match('/[*?\[\]{}]/', $path) === 1) {
            // The unflagged form is intentionally conservative: `*` may cross `/`, so a broad
            // claim such as `**/*.php` cannot evade the protection through matcher semantics.
            if (fnmatch($path, $trust) || fnmatch($path, $trust, defined('FNM_PATHNAME') ? FNM_PATHNAME : 0)) {
                $reached[] = $trust;
            }
        } elseif ($path === $trust || str_starts_with($trust, $path . '/')) {
            $reached[] = $trust;
        }
    }

    $root = dirname(__DIR__);
    $absolute = $root . '/' . $path;
    if (is_link($absolute)) {
        $target = realpath($absolute);
        if ($target === false) {
            return trustSurfacePaths();
        }
        foreach (trustSurfacePaths() as $trust) {
            $resolved = realpath($root . '/' . $trust);
            if ($resolved !== false && ($target === $resolved || str_starts_with($resolved, rtrim($target, '/') . '/'))) {
                $reached[] = $trust;
            }
        }
    }

    return array_values(array_unique($reached));
}

/**
 * The single source of truth for L4 policy. Each entry is either a contract-relative trigger
 * (groundable in the contract) or an absolute prohibition (unauthorisable by any contract; no
 * escalation can obtain permission). Path-decidable entries carry matcher keys that enforcement
 * derives from; judgement-based entries are listed too, so the printed policy and the enforced
 * policy cannot drift apart — the gap is visible here rather than discovered by probe.
 *
 * @return list<array{reason:string,class:string,decidable:bool,matchers:list<string>}>
 */
function l4Taxonomy(): array
{
    return [
        ['reason' => 'any path outside allowed_scope', 'class' => 'contract_relative', 'decidable' => false, 'matchers' => []],
        ['reason' => 'anything in forbidden_scope', 'class' => 'contract_relative', 'decidable' => false, 'matchers' => []],
        ['reason' => 'schema or DDL change', 'class' => 'contract_relative', 'decidable' => true, 'matchers' => ['ddl']],
        ['reason' => 'new runtime dependency', 'class' => 'contract_relative', 'decidable' => true, 'matchers' => ['dependency']],
        ['reason' => 'public API/capability contract change', 'class' => 'contract_relative', 'decidable' => true, 'matchers' => ['module_manifest']],
        ['reason' => 'cross-module coupling or ownership change', 'class' => 'contract_relative', 'decidable' => false, 'matchers' => []],
        ['reason' => 'data deletion or irreversible migration', 'class' => 'contract_relative', 'decidable' => false, 'matchers' => []],
        ['reason' => 'any acceptance criterion that cannot be met without widening scope', 'class' => 'contract_relative', 'decidable' => false, 'matchers' => []],
        ['reason' => 'evidence that falsifies a foundational contract assumption', 'class' => 'contract_relative', 'decidable' => false, 'matchers' => []],
        ['reason' => 'a required external dependency that no longer exists', 'class' => 'contract_relative', 'decidable' => false, 'matchers' => []],
        ['reason' => 'auth, authorisation, policy or security weakening', 'class' => 'absolute', 'decidable' => true, 'matchers' => ['authority']],
        ['reason' => 'disabling, skipping, deleting or weakening an existing test or gate to get a pass', 'class' => 'absolute', 'decidable' => true, 'matchers' => ['existing_test', 'gate_config']],
        ['reason' => 'editing a quality-gate baseline', 'class' => 'absolute', 'decidable' => true, 'matchers' => ['gate_baseline']],
        // CONSTITUTING CHANGE — owner directive 2026-09-14 ("a strict guardrail that you as chair cannot
        // break"). Added ONCE, by the Chair, to make the verifier trust surface contract-unreachable.
        // Not a precedent: subsequent amendments are director-only (see trustSurfacePaths()).
        ['reason' => 'modifying the verifier trust surface', 'class' => 'absolute', 'decidable' => true, 'matchers' => ['trust_surface']],
        ['reason' => 'deleting audit data or falsifying provenance', 'class' => 'absolute', 'decidable' => false, 'matchers' => []],
        ['reason' => 'silent non-delivery to the director', 'class' => 'absolute', 'decidable' => false, 'matchers' => []],
    ];
}

/**
 * Project the taxonomy for one class, preserving order.
 *
 * @return list<string>
 */
function taxonomyReasons(string $class): array
{
    $reasons = [];
    foreach (l4Taxonomy() as $entry) {
        if ($entry['class'] === $class) { $reasons[] = $entry['reason']; }
    }
    return $reasons;
}

/**
 * Absolute prohibitions: no contract can authorise these, and no escalation can obtain permission.
 *
 * @return list<string>
 */
function absoluteProhibitions(): array { return taxonomyReasons('absolute'); }

/**
 * Contract-relative L4 triggers: escalate only when the approved contract does NOT authorise the
 * action. A sensitive change grounded in contract acceptance/constraints is L3 (record and proceed).
 *
 * @return list<string>
 */
function contractRelativeTriggers(): array { return taxonomyReasons('contract_relative'); }

/**
 * Chair decisions: resolve, record the rationale, and continue. These are NEVER a stop.
 *
 * @return list<string>
 */
function chairDecisions(): array
{
    return [
        'choosing among multiple valid in-scope implementations',
        'a failed tactic, a red phase requiring replanning, or a discarded implementation',
        'what to work on next, ordering, decomposition, or agent/model assignment',
        'a second failed repair attempt on the same failure (promote to the next repair level)',
        "one executor's repair or budget exhaustion (reallocate the executor)",
        'ambiguity resolvable from the contract, ADRs and prior decisions',
    ];
}

/**
 * The full trigger list, in the order the three conditions apply.
 *
 * @return list<string>
 */
function l4Triggers(): array
{
    return array_merge(contractRelativeTriggers(), absoluteProhibitions());
}

/**
 * Model-cost tiers.
 *
 * NOT the same axis as L0–L4. **L is authority** — may this action proceed without the owner?
 * **T is intelligence cost** — what is the cheapest adequate model for this work? They are
 * independent: a T0 check can gate an L4 escalation, and an L2 action may be T1 work.
 *
 * @return array<string,string>
 */
function modelTiers(): array
{
    return [
        'T0' => 'No AI. Deterministic tools: tests, lint, static analysis, grep/AST, Playwright, contracts, Workbench. Prefer whenever software can determine the answer.',
        'T1' => 'Low-cost model. Classification, extraction, summarisation, routine bounded decisions, high-frequency latency-sensitive work.',
        'T2' => 'Primary executor. The affordable coding model that does most implementation, repair and bounded debugging.',
        'T3' => 'Strong architect/reviewer. Architecture, adjudication, review of high-consequence changes.',
        'T4' => 'Premium exceptional escalation. Only when expected value justifies the cost.',
    ];
}

/**
 * Questions software answers. These MUST NOT be routed to a model — asking one wastes money and
 * introduces a source of error where a deterministic check already exists.
 *
 * @return list<string>
 */
function deterministicFirst(): array
{
    return [
        'did the tests pass',
        'did lint / static analysis pass',
        'did the change exceed allowed_scope or touch forbidden_scope',
        'which files changed',
        'is a route declared for every write handler',
        'does a declared capability have an active policy row',
        'is the shell still table-free',
        'which contract revision / ADR is current',
        'did the release gate pass',
        'what is in the logs or cache',
    ];
}

/**
 * The Chair's cost policy: spend on the cheapest adequate intelligence for this decision.
 *
 * A premium model is a specialist hired temporarily, not the platform.
 *
 * @return array<string,mixed>
 */
function modelPolicy(): array
{
    return [
        'prefer' => ['deterministic_first' => true],
        // Guiding principle. A ceiling halts a slice mid-way: the tokens already spent become
        // waste and are paid for again on resume. Efficiency finishes the same work for less.
        // Design the workflow so the cheapest adequate lane is the NATURAL path, and reserve
        // numeric caps for the rare case where nothing else prevents catastrophic spend.
        'guiding_principle' => 'efficiency produces savings; cost ceilings do not',
        // Lanes differ in cost SHAPE, not merely price, and the shape decides the workflow.
        //   variable      every token is charged   -> minimise context; Lean-CTX pays for itself
        //   fixed         spend already committed  -> the marginal token is free; the scarce
        //                 resource is the rate limit, so spend it where judgement matters
        //   metered_burst free but capped          -> capacity is scarce; fill it with bounded
        //                 work and keep headroom for the slice that still needs it
        'cost_shape' => [
            'deepseek-v4-flash' => 'variable',
            'openai-codex/gpt-5.6-sol' => 'fixed',
            'groq/openai/gpt-oss-120b' => 'metered_burst',
            'groq/qwen/qwen3.8-27b' => 'metered_burst',
        ],
        'lane_notes' => [
            'deepseek-v4-flash' => 'primary implementation lane; metered, so context is the bill',
            'openai-codex/gpt-5.6-sol' => 'fixed monthly cost, rate-limited with a 5h reset: do NOT waste the cap on mechanical edits, and do not hoard it to "save" spend already committed',
            'groq/openai/gpt-oss-120b' => 'free burst capacity, 5M tokens/day (~618 turns at the measured 8.1K tok/turn): reserve headroom for slices rather than spending a day of it on questions a test answers',
            'groq/qwen/qwen3.8-27b' => '750K tokens/day and the ONLY vision-capable lane: keep its capacity for screenshot triage',
        ],
        'routine_reasoning' => ['max_cost_usd' => 0.02],
        // Groq lanes, measured from this workspace on 2026-09-14.
        // gpt-oss-120b is the CHEAPEST usable lane: $0.15/M in, $0.60/M out — 3x cheaper on input and
        // 5.3x cheaper on output than qwen3.8-27b ($0.45/$3.20), AND more accurate (3/3 vs 2/3 on an
        // independently verified 3-fact probe). Prefer gpt-oss for ALL text work; qwen is for vision only.
        // Rate limits are 250K tokens/min and 5M tokens/day => ~400-600 turns/day at the measured
        // ~8.1K tokens/turn, i.e. roughly 7 slices/day. Groq is a viable T2 fallback, not a curiosity.
        // Measured monthly cost if gpt-oss absorbed this repo's whole observed workload: ~$10.32
        // (35.1M in / 8.4M out over 15.6 days); for the implementation lane alone, ~$3.94.
        // REQUIRES a local providers.groq entry in ~/.pi/agent/models.json. Without it pi sends no tool
        // schemas and the model dies with `attempted to call tool 'grep' which was not in request.tools`.
        // Per-model maxTokens MUST respect the provider cap: qwen rejects anything above 16384 with
        // `400 max_completion_tokens must be <= 16384` (a wrong value makes every qwen call fail);
        // gpt-oss accepts 32768. contextWindow must exceed 16384 or large reads die with stopReason=length.
        'lanes' => [
            'T1' => ['groq/openai/gpt-oss-120b', 'groq/qwen/qwen3.8-27b', 'deepseek-v4-flash'],
            'T2' => ['deepseek-v4-flash', 'groq/openai/gpt-oss-120b'],
            'T3' => ['openai-codex/gpt-5.6-sol'],
        ],
        'implementation' => [
            'preferred' => 'deepseek-v4-flash',
            'fallback' => ['groq/openai/gpt-oss-120b', 'groq/qwen/qwen3.8-27b'],
        ],
        'architecture' => ['preferred' => 'openai-codex/gpt-5.6-sol'],
        // Playwright is a first-class verification tool, but only one lane among them can LOOK at a
        // screenshot. gpt-oss-120b declares input: [text] and cannot see images at all; qwen3.8-27b
        // declares input: [text, image] and was verified reading a controlled image correctly (text,
        // background colour and shapes all right). So visual triage is qwen's one genuine advantage.
        // Tiers follow the directive's PW-1/PW-2/PW-3 split: running the specs is deterministic (T0);
        // reading a screenshot or trace needs vision (qwen); diagnosing from DOM/console/network text
        // is ordinary bounded reasoning and belongs on the cheaper gpt-oss lane.
        'playwright' => [
            'pw0_run_specs' => 'T0 — deterministic, no model. Run the specs and read the exit code.',
            'pw1_visual_triage' => 'groq/qwen/qwen3.8-27b — the ONLY vision-capable lane in this repo',
            'pw2_failure_diagnosis' => 'groq/openai/gpt-oss-120b — DOM/console/network text evidence',
            'pw3_release_journeys' => 'T0 — deterministic gate, no model discretion',
        ],
        'premium_escalation' => [
            'allowed' => true,
            'conditions' => [
                'repeated_strategy_failure',
                'unresolved_architecture_contradiction',
                'high_risk_review',
                'contract_blocker',
            ],
        ],
        'executor_exhaustion' => 'reallocate to another lane; only a hard owner-defined project budget forces a stop',
        'project_budget_usd' => null,
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
  php tools/ai-autonomy.php trust-surface amend --reason=TEXT --director-decision=REF
                                  Validate and record an authorised amendment and current hash;
                                  never edits a trust-surface file. Missing/unrecorded decision exits 3.
  php tools/ai-autonomy.php notify --type=PROGRESS|DECISION_REQUIRED|BLOCKED|RELEASE_READY|FAILED --body=TEXT
                                  [--conversation=N] [--title=TEXT]
  php tools/ai-autonomy.php models [--json]
                                  Deterministic-first list, T0-T4 intelligence-cost tiers, and the
                                  Chair cost policy. T (intelligence cost) is independent of L (authority).
  php tools/ai-autonomy.php stop-report --remaining=N [--stop-reason=TYPE] [--json]
                                  Check the stop invariant. Exit 0 when the stop is legitimate
                                  (remaining=0 with any reason, or remaining>0 with CONTRACT_BLOCKED,
                                  RESOURCE_EXHAUSTED, EXTERNAL_DEPENDENCY_BLOCKED or SAFETY_BLOCKED);
                                  exit 3 when obligations remain under any other reason.
Exit codes: 0 ok; 2 malformed input or contract; 3 L4 escalation or illegitimate stop; 4 local decision/message not delivered.
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

/** @return array{hash:?string,files:array<string,?string>} */
function trustSurfaceDigest(): array
{
    $paths = trustSurfacePaths();
    sort($paths, SORT_STRING);
    $files = [];
    $contents = '';
    $complete = true;
    foreach ($paths as $path) {
        $content = @file_get_contents(dirname(__DIR__) . '/' . $path);
        if ($content === false) {
            $files[$path] = null;
            $complete = false;
            continue;
        }
        $files[$path] = hash('sha256', $content);
        $contents .= $content;
    }
    return ['hash' => $complete ? hash('sha256', $contents) : null, 'files' => $files];
}

/** Resolve a named decision against the two repository decision records. */
function directorDecisionExists(string $reference, string $decisionsDir, string $chairDecisions): bool
{
    $reference = trim($reference);
    if ($reference === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $reference) !== 1) { return false; }
    $chair = @file_get_contents($chairDecisions);
    if ($chair !== false && preg_match('/^##\s+' . preg_quote($reference, '/') . '\b/m', $chair) === 1) { return true; }

    $json = @file_get_contents(rtrim($decisionsDir, '/') . '/' . $reference . '.json');
    $decision = $json === false ? null : json_decode($json, true);
    return is_array($decision)
        && ($decision['decision_id'] ?? null) === $reference
        && ($decision['schema'] ?? null) === 'ark.workbench-development-decision-request.v1';
}

/** @return list<string> */
function supersededTrustSurfaceHashes(string $runsDir, string $amendmentsFile): array
{
    $hashes = [];
    foreach (glob(rtrim($runsDir, '/') . '/*.json') ?: [] as $file) {
        $record = json_decode((string) @file_get_contents($file), true);
        $hash = is_array($record) ? ($record['trust_surface_hash'] ?? null) : null;
        if (is_string($hash) && preg_match('/^[0-9a-f]{64}$/', $hash) === 1) { $hashes[] = $hash; }
    }
    $prior = json_decode((string) @file_get_contents($amendmentsFile), true);
    foreach (is_array($prior) && is_array($prior['amendments'] ?? null) ? $prior['amendments'] : [] as $item) {
        $hash = is_array($item) ? ($item['trust_surface_hash'] ?? null) : null;
        if (is_string($hash) && preg_match('/^[0-9a-f]{64}$/', $hash) === 1) { $hashes[] = $hash; }
    }
    sort($hashes, SORT_STRING);
    return array_values(array_unique($hashes));
}

/**
 * Record a director-attributed trust-surface amendment and its resulting digest.
 *
 * Honest limit: this cannot prove that an authorisation is genuine; no code can. It guarantees that
 * every accepted amendment is visible, attributed, and tied to a named recorded decision. The route
 * records and re-hashes only. It never edits a trust-surface file.
 *
 * @param array<string,list<string>> $options
 */
function commandTrustSurfaceAmend(array $options): int
{
    $reference = trim((string) option($options, 'director-decision', ''));
    $decisionsDir = option($options, 'decisions-dir', DEFAULT_DECISIONS_DIR) ?? DEFAULT_DECISIONS_DIR;
    $chairDecisions = option($options, 'chair-decisions', '.ai/chair-decisions.md') ?? '.ai/chair-decisions.md';
    if (!directorDecisionExists($reference, $decisionsDir, $chairDecisions)) {
        fwrite(STDOUT, "REFUSED: --director-decision must name a recorded decision in {$decisionsDir}/ or a ## CD-<n> heading in {$chairDecisions}\n");
        fwrite(STDOUT, "Obtain and record the director's decision, then retry trust-surface amend. No trust-surface file was changed.\n");
        return EXIT_ESCALATE;
    }
    $reason = requiredValue(option($options, 'reason'), 'reason');
    $amendmentsFile = option($options, 'amendments-file', '.ai/trust-surface-amendments.json') ?? '.ai/trust-surface-amendments.json';
    $runsDir = option($options, 'runs-dir', '.ai/runs') ?? '.ai/runs';
    $digest = trustSurfaceDigest();
    if ($digest['hash'] === null) {
        throw new InvalidArgumentException('trust-surface amendment refused: one or more trust-surface files cannot be read');
    }

    $document = ['schema' => 'ikabud.trust-surface-amendments.v1', 'amendments' => []];
    if (is_file($amendmentsFile)) {
        $loaded = json_decode((string) @file_get_contents($amendmentsFile), true);
        if (!is_array($loaded) || ($loaded['schema'] ?? null) !== $document['schema'] || !is_array($loaded['amendments'] ?? null)) {
            throw new InvalidArgumentException("amendment record '{$amendmentsFile}' is malformed");
        }
        $document = $loaded;
    }
    $sequence = count($document['amendments']) + 1;
    $record = [
        'id' => 'TSA-' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
        'reason' => trim($reason),
        'director_decision' => $reference,
        'acting_context' => [
            'provider' => getenv('PI_PROVIDER') ?: null,
            'model' => getenv('PI_MODEL') ?: null,
            'session_id' => getenv('PI_SESSION_ID') ?: null,
            'user' => getenv('USER') ?: null,
            'working_directory' => getcwd() ?: null,
        ],
        'recorded_at' => date(DATE_ATOM),
        'trust_surface_hash' => $digest['hash'],
        'trust_surface_files' => $digest['files'],
        'supersedes_hashes' => supersededTrustSurfaceHashes($runsDir, $amendmentsFile),
        'trust_surface_files_changed_by_route' => false,
    ];
    $document['amendments'][] = $record;
    $dir = dirname($amendmentsFile);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new InvalidArgumentException("amendment record directory '{$dir}' cannot be created");
    }
    if (@file_put_contents($amendmentsFile, encodeJson($document, true) . "\n", LOCK_EX) === false) {
        throw new InvalidArgumentException("amendment record '{$amendmentsFile}' cannot be written");
    }

    fwrite(STDOUT, "TRUST-SURFACE AMENDMENT RECORDED {$record['id']}\n");
    fwrite(STDOUT, "  decision: {$reference}\n  reason:   {$record['reason']}\n  recorded: {$record['recorded_at']}\n  hash:     {$record['trust_surface_hash']}\n");
    fwrite(STDOUT, "  record:   {$amendmentsFile}\nNO TRUST-SURFACE FILE WAS CHANGED BY THIS ROUTE; it only validated, recorded and re-hashed.\n");
    return EXIT_OK;
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
    if (isset($value['data']) && is_array($value['data'])) { $value = $value['data']; }
    foreach (['decisions', 'items'] as $key) {
        if (isset($value[$key]) && is_array($value[$key])) { $value = $value[$key]; break; }
    }
    $isRow = static fn (mixed $row): bool => is_array($row)
        && (array_key_exists('decision_key', $row) || (isset($row['id']) && is_int($row['id'])));
    if (array_is_list($value)) { return array_values(array_filter($value, $isRow)); }
    return $isRow($value) ? [$value] : [];
}

/** The standing-contract reference that makes a slice inherit the autonomy envelope. */
function contractHasHarnessReference(string $markdown): bool
{
    if (preg_match('/^harness[ \t]*:[ \t]*\n(.*?)(?=^\S|\z)/ms', $markdown, $block) === 1) {
        return str_contains($block[1], 'ai-autonomy-harness.contract.md');
    }
    return preg_match('/^harness[ \t]*:.*ai-autonomy-harness\.contract\.md/m', $markdown) === 1;
}

/**
 * Forbidden bullets the parser could not bind to a path. After the parser fix
 * every bullet is represented exactly once: as a scope path in `forbidden_scope`
 * or, when its first token is not path-like, verbatim in `forbidden_rules`.
 * The rules bucket is therefore the exact set of prohibitions that cannot be
 * enforced as path scope; it is surfaced here so a prohibition is never silent.
 * The D8 warning is a backstop, not a routine message.
 *
 * @param array<string,mixed> $contract
 * @return list<string>
 */
function droppedForbiddenRules(array $contract): array
{
    return array_values(array_map('strval', (array) ($contract['forbidden_rules'] ?? [])));
}

/**
 * Allowed and forbidden scope that overlap. Fail-closed precedence is unchanged; this only makes
 * the overlap visible at plan time.
 *
 * @param array<string,mixed> $contract
 * @return list<string>
 */
function scopeIntersections(array $contract): array
{
    $warnings = [];
    foreach ((array) ($contract['allowed_scope'] ?? []) as $allowed) {
        foreach ((array) ($contract['forbidden_scope'] ?? []) as $forbidden) {
            if (!is_array($allowed) || !is_array($forbidden)) { continue; }
            if (pathMatches((string) ($allowed['path'] ?? ''), $forbidden) || pathMatches((string) ($forbidden['path'] ?? ''), $allowed)) {
                $warnings[] = "allowed '{$allowed['path']}' ({$allowed['kind']}) intersects forbidden '{$forbidden['path']}' ({$forbidden['kind']}); check applies fail-closed precedence to the forbidden entry";
            }
        }
    }
    return $warnings;
}

/**
 * Envelope defects that must be visible without changing the exit code: a present-but-defective
 * envelope is still a runnable envelope, but a silently unenforceable prohibition is not.
 *
 * @param array<string,mixed> $contract
 * @return list<string>
 */
function planWarnings(array $contract, string $markdown): array
{
    $warnings = [];
    foreach ((array) ($contract['forbidden_scope'] ?? []) as $entry) {
        $path = (string) ($entry['path'] ?? '');
        if (preg_match('/^[A-Za-z0-9_-]+$/', $path) === 1 && !file_exists($path)) {
            $warnings[] = "suspicious scope entry (prose?): {$path}";
        }
    }
    if (!contractHasHarnessReference($markdown)) {
        $warnings[] = 'no harness: block references the standing contract (.ai/ai-autonomy-harness.contract.md); the slice adopts the syntax but inherits no autonomy envelope';
    }
    foreach (droppedForbiddenRules($contract) as $dropped) {
        $warnings[] = "forbidden bullet is not a path and is not enforced as scope (restate it in Architectural constraints to bind): {$dropped}";
    }
    foreach (scopeIntersections($contract) as $intersection) {
        $warnings[] = $intersection;
    }
    return $warnings;
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

/**
 * Allowed or baseline scope claims that cover the verifier. Rule 3 is about consequence, not syntax:
 * naming `tools/`, `tools/ai-*.php`, or an even broader glob grants the same capability as naming the
 * protected file exactly, so all are refused. The reached path is retained for an actionable error.
 *
 * @param array<string,mixed> $contract
 * @return list<array{entry:string,path:string}>
 */
function planTrustSurfaceViolations(array $contract): array
{
    $violations = [];
    $scope = array_merge((array) ($contract['allowed_scope'] ?? []), (array) ($contract['baseline_scope'] ?? []));
    foreach ($scope as $entry) {
        if (!is_array($entry)) { continue; }
        $claim = (string) ($entry['path'] ?? '');
        // Derive this prohibition through the taxonomy rather than maintaining a second policy list.
        if (trustSurfaceMatches([$claim]) === []) { continue; }
        foreach (trustSurfaceCoverage($claim) as $reached) {
            $violations[$claim . '|' . $reached] = ['entry' => $claim, 'path' => $reached];
        }
    }
    return array_values($violations);
}

/**
 * Trust-surface paths explicitly named by free-text action prose. Only path-shaped tokens are
 * considered — a token containing a directory separator, or a filename that exactly matches a
 * trust-surface basename. A bare word is not promoted into a path claim.
 *
 * @return list<string>
 */
function trustSurfaceMentions(string $action): array
{
    $mentions = [];
    if (preg_match_all('~[A-Za-z0-9_.\-]+(?:/[A-Za-z0-9_.*?\[\]{}\-]+)+~', $action, $matches) !== false) {
        foreach ($matches[0] as $token) {
            $candidate = rtrim(trim($token, "`\"',;:"), '/');
            if ($candidate !== '' && isTrustSurfacePath($candidate)) {
                $mentions[] = $candidate;
            }
        }
    }
    foreach (trustSurfacePaths() as $trust) {
        $base = basename($trust);
        if (preg_match('~(?<![A-Za-z0-9_.\-])' . preg_quote($base, '~') . '(?![A-Za-z0-9_.\-])~', $action) === 1) {
            $mentions[] = $trust;
        }
    }
    return array_values(array_unique($mentions));
}

/**
 * Absolute trust-surface matches for candidates derived from action text. The reason and class come
 * from l4Taxonomy(), so the action-text path cannot drift from the single policy list.
 *
 * @param list<string> $candidates
 * @return list<array{reason:string,class:string,path:string,matcher:string}>
 */
function trustSurfaceMatches(array $candidates): array
{
    $matches = [];
    foreach (l4Taxonomy() as $entry) {
        if (($entry['class'] ?? '') !== 'absolute' || !in_array('trust_surface', (array) ($entry['matchers'] ?? []), true)) {
            continue;
        }
        foreach ($candidates as $candidate) {
            if (isTrustSurfacePath($candidate)) {
                $matches[] = ['reason' => (string) $entry['reason'], 'class' => 'absolute', 'path' => $candidate, 'matcher' => 'trust_surface'];
            }
        }
    }
    return $matches;
}

/** @param array<string,mixed> $contract */
function commandPlan(array $contract, string $contractPath, string $directory, bool $json, ?string $manifestPath, bool $manifestStdout): int
{
    $trustViolations = planTrustSurfaceViolations($contract);
    if ($trustViolations !== []) {
        $details = array_map(static fn (array $item): string => "entry '{$item['entry']}' reaches '{$item['path']}'", $trustViolations);
        throw new InvalidArgumentException('contract names the verifier trust surface in its scope and is refused: ' . implode('; ', $details) . ' — the trust surface is not contract-authorisable (owner directive 2026-09-14)');
    }
    $pending = count(array_filter(decisions($directory), static fn (array $i): bool => !isset($i['resolution'])));
    $allowed = (array) $contract['allowed_scope']; $forbidden = (array) $contract['forbidden_scope'];
    $forbiddenRules = array_values(array_map('strval', (array) ($contract['forbidden_rules'] ?? [])));
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
    $markdown = @file_get_contents($contractPath);
    $warnings = planWarnings($contract, $markdown === false ? '' : $markdown);
    if ($json) {
        fwrite(STDOUT, encodeJson(['envelope' => ['objective' => $contract['objective'], 'contract_revision' => DevelopmentTaskContract::revisionId($contract), 'allowed_scope' => $allowed, 'forbidden_scope' => $forbidden, 'forbidden_rules' => $forbiddenRules], 'phases' => $phases, 'absolute_prohibitions' => absoluteProhibitions(), 'contract_relative_l4' => contractRelativeTriggers(), 'chair_decisions' => chairDecisions(), 'deterministic_first' => deterministicFirst(), 'model_policy' => modelPolicy(), 'model_tiers' => modelTiers(), 'decisions_dir' => $directory, 'pending' => $pending, 'warnings' => $warnings, 'l4_taxonomy' => l4Taxonomy()]) . "\n");
        return EXIT_OK;
    }
    foreach ($warnings as $warning) { fwrite(STDOUT, "WARN {$warning}\n"); }
    fwrite(STDOUT, "AUTONOMY ENVELOPE — {$contractPath}\nobjective: {$contract['objective']}\ncontract revision: " . DevelopmentTaskContract::revisionId($contract) . "\nallowed scope (" . count($allowed) . "):\n");
    foreach ($allowed as $entry) { fwrite(STDOUT, "  - {$entry['path']} ({$entry['kind']})\n"); }
    fwrite(STDOUT, 'forbidden scope (' . count($forbidden) . "):\n");
    foreach ($forbidden as $entry) { fwrite(STDOUT, "  - {$entry['path']} ({$entry['kind']})\n"); }
    fwrite(STDOUT, 'forbidden rules (' . count($forbiddenRules) . ") — not enforceable as path scope; restate in Architectural constraints to bind:\n");
    foreach ($forbiddenRules as $rule) { fwrite(STDOUT, "  - {$rule}\n"); }
    fwrite(STDOUT, "phases (run unattended; only a contract-relative L4 stops the run):\n"); foreach ($phases as $phase) { fwrite(STDOUT, "  {$phase}\n"); }
    fwrite(STDOUT, "ABSOLUTE prohibitions (no contract can authorise; no escalation can obtain permission):\n"); foreach (absoluteProhibitions() as $trigger) { fwrite(STDOUT, "  - {$trigger}\n"); }
    fwrite(STDOUT, "contract-relative L4 (escalate ONLY if the contract does not authorise it):\n"); foreach (contractRelativeTriggers() as $trigger) { fwrite(STDOUT, "  - {$trigger}\n"); }
    fwrite(STDOUT, "chair decisions — resolve, record, continue; NEVER a stop:\n"); foreach (chairDecisions() as $decision) { fwrite(STDOUT, "  - {$decision}\n"); }
    fwrite(STDOUT, "\nDETERMINISTIC FIRST — software decides these; never pay a model:\n"); foreach (deterministicFirst() as $question) { fwrite(STDOUT, "  - {$question}\n"); }
    fwrite(STDOUT, "\nMODEL TIERS (T = intelligence cost; independent of L = authority):\n"); foreach (modelTiers() as $tier => $meaning) { fwrite(STDOUT, "  {$tier}  {$meaning}\n"); }
    fwrite(STDOUT, "decisions dir: {$directory}\npending decisions: {$pending}\n");
    return EXIT_OK;
}

/** @param array{path:string,kind:string} $entry */
function pathMatches(string $path, array $entry): bool
{
    // A glob is matched as a glob against the normalised path. `kind: glob`
    // never collapses to its parent directory, so a file-pattern prohibition
    // binds exactly the files it names and not the whole tree. FNM_PATHNAME
    // keeps `*` from crossing a directory separator when the platform defines it.
    if ($entry['kind'] === 'glob') {
        return fnmatch($entry['path'], $path, defined('FNM_PATHNAME') ? FNM_PATHNAME : 0);
    }

    return $entry['kind'] === 'file' ? $path === $entry['path'] : $path === $entry['path'] || str_starts_with($path, $entry['path'] . '/');
}

/** A test path that already exists is a verification artefact whose edit weakens verification. */
function isExistingTestPath(string $path): bool
{
    $isTest = preg_match('#^tests/.*_test\.php$#', $path) === 1
        || preg_match('#^modules/[^/]+/tests/.*\.php$#', $path) === 1;
    return $isTest && file_exists($path);
}

/**
 * Gate and verification configuration. Editing any of these weakens a gate. A new test file is an
 * addition, not a weakening, and is handled by isExistingTestPath()'s existence check.
 */
function isGateConfigPath(string $path): bool
{
    if (str_starts_with($path, '.github/workflows/')) { return true; }
    return preg_match('#(^|/)(phpstan\.neon|phpunit\.xml(?:\.dist)?|phpcs\.xml(?:\.dist)?|playwright\.config\.[jt]s|infection\.json5?|rector\.php|\.php-cs-fixer(?:\.dist)?\.php)$#', $path) === 1;
}

/** Decide one taxonomy matcher for one normalized path. */
function taxonomyMatcherMatches(string $matcher, string $path): bool
{
    return match ($matcher) {
        'ddl' => preg_match('#(^|/)migrations(/|$)#i', $path) === 1 || str_ends_with(strtolower($path), '.sql'),
        'dependency' => preg_match('#(^|/)(composer\.json|composer\.lock|package\.json|package-lock\.json)$#', $path) === 1,
        'module_manifest' => preg_match('#(^|/)module\.json$#', $path) === 1,
        'authority' => preg_match('#kernel/Capabilities|CapabilityAuthorization|SecurityHeaders|auth|JWT|policy#i', $path) === 1,
        'existing_test' => isExistingTestPath($path),
        'gate_config' => isGateConfigPath($path),
        'gate_baseline' => preg_match('#(^|/)phpstan-baseline\.neon$#', $path) === 1,
        'trust_surface' => isTrustSurfacePath($path),
        default => false,
    };
}

/**
 * Every path-decidable taxonomy entry that matches the supplied paths, with the class needed to
 * enforce absolute prohibitions before any justification can ground them.
 *
 * @param list<string> $paths
 * @return list<array{reason:string,class:string,path:string,matcher:string}>
 */
function sensitiveMatches(array $paths): array
{
    $matches = [];
    foreach (l4Taxonomy() as $entry) {
        if (!$entry['decidable']) { continue; }
        foreach ($paths as $path) {
            foreach ($entry['matchers'] as $matcher) {
                if (taxonomyMatcherMatches($matcher, $path)) {
                    $matches[] = ['reason' => $entry['reason'], 'class' => $entry['class'], 'path' => $path, 'matcher' => $matcher];
                }
            }
        }
    }
    return $matches;
}

/**
 * The sensitive reasons for these paths, derived from l4Taxonomy(); there is no second list.
 *
 * @param list<string> $paths
 * @return list<string>
 */
function sensitiveReasons(array $paths): array
{
    return array_values(array_unique(array_map(
        static fn (array $match): string => $match['reason'],
        sensitiveMatches($paths)
    )));
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
        $matches = array_merge(sensitiveMatches($paths), trustSurfaceMatches(trustSurfaceMentions($action)));
        $unique = [];
        foreach ($matches as $match) { $unique[$match['matcher'] . '|' . $match['path']] = $match; }
        $matches = array_values($unique);
        $absolute = array_values(array_filter($matches, static fn (array $match): bool => $match['class'] === 'absolute'));
        if ($absolute !== []) {
            $resolved = 'L4';
            foreach ($absolute as $match) {
                $reasons[] = "path '{$match['path']}' trips an absolute prohibition: {$match['reason']} (no justification can authorise it)";
            }
        } elseif ($matches !== []) {
            $reasons = array_merge($reasons, sensitiveReasons($paths));
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
        $data = is_array($payload) && is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $remoteId = is_array($payload) ? ($data['id'] ?? $data['decision_id'] ?? $payload['id'] ?? $payload['decision_id'] ?? ($payload['decision']['id'] ?? null)) : null;
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
        foreach (remoteRows($result['stdout']) as $row) { if (($row['decision_key'] ?? null) === $id && strtoupper((string) ($row['lifecycle_state'] ?? $row['state'] ?? '')) === 'DECIDED') { $match = $row; break; } }
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
            'harpp_state' => is_array($remoteRow) ? ($remoteRow['lifecycle_state'] ?? $remoteRow['state'] ?? null) : null, 'question' => (string) $item['question'], 'chosen_option_id' => $resolution['chosen_option_id'] ?? null];
        unset($remoteByKey[$id]);
    }
    foreach ($remoteByKey as $key => $row) { $rows[] = ['decision_id' => $key, 'task_id' => '', 'state' => 'REMOTE', 'harpp_state' => $row['lifecycle_state'] ?? $row['state'] ?? null, 'question' => $row['title'] ?? '', 'chosen_option_id' => null]; }
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

/**
 * The doctrine's stop invariant, made checkable: an approved contract with unsatisfied obligations
 * and no contract blocker means the harness is not idle. This makes the invariant checkable, not
 * unfalsifiable — the honest input is the Chair's own obligation count.
 */
function commandStopReport(string $remainingRaw, string $stopReasonRaw, bool $json): int
{
    if (preg_match('/^\d+$/', $remainingRaw) !== 1) {
        throw new InvalidArgumentException("offending field --remaining: '{$remainingRaw}' (expected a non-negative integer)");
    }
    $remaining = (int) $remainingRaw;
    $reason = strtoupper(trim($stopReasonRaw));
    $contractLevel = ['CONTRACT_BLOCKED', 'RESOURCE_EXHAUSTED', 'EXTERNAL_DEPENDENCY_BLOCKED', 'SAFETY_BLOCKED'];
    $legitimate = $remaining === 0 || in_array($reason, $contractLevel, true);
    $invariant = 'IF an approved contract has unsatisfied obligations AND no contract blocker THEN the harness is not idle';
    $basis = $remaining === 0
        ? 'no unsatisfied obligations remain'
        : ($legitimate
            ? "{$remaining} obligation(s) remain with contract-level reason {$reason}"
            : "{$remaining} obligation(s) remain with non-contract reason '" . ($reason === '' ? 'NONE' : $reason) . "'; no contract blocker is named, so the stop is illegitimate");
    $payload = ['remaining_obligations' => $remaining, 'stop_reason' => $reason, 'legitimate' => $legitimate,
        'verdict' => $legitimate ? 'LEGITIMATE_STOP' : 'ILLEGITIMATE_STOP', 'invariant' => $invariant, 'basis' => $basis];
    if ($json) { fwrite(STDOUT, encodeJson($payload) . "\n"); }
    else {
        fwrite(STDOUT, "STOP REPORT\nunsatisfied obligations: {$remaining}\nstop reason: " . ($reason === '' ? 'NONE' : $reason) . "\ninvariant: {$invariant}\nbasis: {$basis}\nverdict: " . $payload['verdict'] . "\n");
        if (!$legitimate) { fwrite(STDOUT, "system defect: a stop with obligations outstanding is only legitimate under a contract blocker — name one or continue\n"); }
    }
    return $legitimate ? EXIT_OK : EXIT_ESCALATE;
}

/** Dispatch and return a contractual exit status. */
function main(): int
{
    $args = $_SERVER['argv']; array_shift($args);
    if ($args === [] || in_array('--help', $args, true)) { usage(); return EXIT_OK; }
    $command = array_shift($args); if (!in_array($command, ['plan', 'check', 'defer', 'resume', 'status', 'notify', 'models', 'stop-report', 'trust-surface'], true)) { throw new InvalidArgumentException("unknown command '{$command}'"); }
    $p = parseArguments($args);
    if ($command === 'trust-surface') {
        validateArgumentNames($p, ['reason', 'director-decision', 'decisions-dir', 'chair-decisions', 'amendments-file', 'runs-dir'], []);
        if ($p['positionals'] !== ['amend']) { throw new InvalidArgumentException('trust-surface requires the subcommand amend'); }
        return commandTrustSurfaceAmend($p['options']);
    }
    if ($command === 'stop-report') {
        validateArgumentNames($p, ['remaining', 'stop-reason'], ['json']);
        if ($p['positionals'] !== []) { throw new InvalidArgumentException("offending argument: '{$p['positionals'][0]}'"); }
        return commandStopReport(requiredValue(option($p['options'], 'remaining'), 'remaining'), option($p['options'], 'stop-reason', '') ?? '', isset($p['flags']['json']));
    }
    if ($command === 'models') {
        validateArgumentNames($p, [], ['json']);
        if ($p['positionals'] !== []) { throw new InvalidArgumentException("offending argument: '{$p['positionals'][0]}'"); }
        $payload = ['model_policy' => modelPolicy(), 'model_tiers' => modelTiers(), 'deterministic_first' => deterministicFirst()];
        if (isset($p['flags']['json'])) { fwrite(STDOUT, encodeJson($payload) . "\n"); return EXIT_OK; }
        fwrite(STDOUT, "DETERMINISTIC FIRST — software decides these; never pay a model:\n");
        foreach (deterministicFirst() as $question) { fwrite(STDOUT, "  - {$question}\n"); }
        fwrite(STDOUT, "\nMODEL TIERS (T = intelligence cost; independent of L = authority):\n");
        foreach (modelTiers() as $tier => $meaning) { fwrite(STDOUT, "  {$tier}  {$meaning}\n"); }
        fwrite(STDOUT, "\nCHAIR COST POLICY:\n"); fwrite(STDOUT, encodeJson(modelPolicy(), true) . "\n");
        return EXIT_OK;
    }
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
