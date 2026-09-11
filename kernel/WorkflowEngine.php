<?php

declare(strict_types=1);

namespace Ikabud\Kernel;

use Ikabud\Kernel\Capabilities\AuthorityScopeResolver;
use Ikabud\Kernel\Http\Idempotency;
use PDO;
use Throwable;

// This distribution does not currently ship the retention helper. Keep payload-
// hash recording inert until the upstream sync gap restores it, rather than
// making WorkflowEngine fatal during bootstrap.
$workflowRetentionHelper = __DIR__ . '/../src/helpers/workflow-retention.php';
if (is_file($workflowRetentionHelper)) {
    require_once $workflowRetentionHelper;
}

/**
 * Multi-step workflow engine — runs ordered capability steps with retry,
 * idempotency, event-triggered auto-start, and cancellation.
 *
 * Complements WorkflowRuntime (single-transition state machine) by adding
 * run-scoped step execution and lifecycle management.
 *
 * @package Ikabud\Kernel
 */
final class WorkflowEngine
{
    private const MAX_CONCURRENT_STEPS = 50;

    /** Runtime-only defense for test/CI; disabled to preserve production behavior. */
    private static bool $runtimeGuardEnabled = false;

    /** @var (\Closure(PDO): void)|null Test seam used to simulate a dispatch-time transaction regression. */
    private static ?\Closure $runtimeGuardBeforeDispatch = null;

    /** @var array<string, true>|null Registered event->workflow subscriptions (in-memory cache) */
    private ?array $subscriptionsCache = null;

    /** Bypass transport re-entry when scope establishment itself fails. */
    private bool $authorityScopeFallback = false;

    public function __construct(private readonly App $app)
    {
    }

    /**
     * Enable the dispatch transaction invariant in tests/CI.
     *
     * The optional callback is a test-only fault-injection seam. It runs only
     * while the guard is enabled and immediately before the transaction check.
     */
    public static function setRuntimeGuardEnabled(bool $enabled, ?callable $beforeDispatch = null): void
    {
        self::$runtimeGuardEnabled = $enabled;
        self::$runtimeGuardBeforeDispatch = $enabled && $beforeDispatch !== null
            ? \Closure::fromCallable($beforeDispatch)
            : null;
    }

    // ── YAML Definition Loading ──────────────────────────────────────

    /**
     * Scan a module directory for workflow YAML definitions and sync to DB.
     *
     * Expects files matching: modules/<id>/workflows/*.yaml
     * Each YAML file defines one workflow with steps, transitions, and triggers.
     *
     * @return list<string> Loaded workflow keys
     */
    public function loadDefinitions(string $moduleDir, string $moduleId): array
    {
        $workflowDir = rtrim($moduleDir, '/') . '/workflows';
        if (!is_dir($workflowDir)) {
            return [];
        }

        $loaded = [];
        $files = glob($workflowDir . '/*.yaml') ?: [];

        foreach ($files as $file) {
            $yaml = @file_get_contents($file);
            if ($yaml === false || trim($yaml) === '') {
                continue;
            }

            $definition = $this->parseYamlDefinition($yaml, basename($file));
            if ($definition === null) {
                write_log("WorkflowEngine: failed to parse definition in {$file}", 'warning');
                continue;
            }

            $this->syncDefinition($definition, $moduleId);
            $loaded[] = $definition['key'];

            // Register event subscription if trigger is defined
            if (isset($definition['trigger']['event'])) {
                $this->subscribe(
                    $moduleId,
                    $definition['trigger']['event'],
                    $definition['key'],
                    $definition['trigger']['filter'] ?? null,
                    $definition['entity_type'] ?? null,
                );
            }
        }

        return $loaded;
    }

    /**
     * Parse a workflow YAML string into a structured definition array.
     *
     * Prefers the Symfony Yaml library (pure PHP — works on shared hosting such
     * as Bluehost where the PECL `yaml` extension is unavailable). Falls back
     * to the legacy line-based parser when the library is not installed (e.g. a
     * deploy that could not run composer) or when a file is not parseable.
     */
    private function parseYamlDefinition(string $yaml, string $filename): ?array
    {
        $def = $this->parseYamlWithLibrary($yaml, $filename);
        if ($def === null) {
            $def = $this->parseYamlDefinitionLegacy($yaml, $filename);
        }
        return $def;
    }

    /**
     * Parse workflow YAML with the Symfony Yaml library and normalize the
     * generic nested structure into the definition shape the engine expects.
     * Returns null if the library is unavailable or parsing fails (caller
     * falls back to the legacy parser).
     */
    private function parseYamlWithLibrary(string $yaml, string $filename): ?array
    {
        if (!class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            return null; // library not installed — signal legacy fallback
        }
        try {
            $parsed = \Symfony\Component\Yaml\Yaml::parse($yaml);
        } catch (\Throwable $e) {
            write_log("WorkflowEngine: Symfony Yaml parse failed for {$filename}: " . $e->getMessage(), 'warning');
            return null;
        }
        if (!is_array($parsed)) {
            return null;
        }

        $def = [
            'key' => '',
            'label' => '',
            'entity_type' => '',
            'initial_state' => 'pending',
            'states' => [],
            'transitions' => [],
            'steps' => [],
            'trigger' => null,
        ];

        foreach (['key', 'label', 'entity_type', 'initial_state'] as $k) {
            if (isset($parsed[$k]) && is_scalar($parsed[$k])) {
                $def[$k] = (string)$parsed[$k];
            }
        }

        if (isset($parsed['trigger']) && is_array($parsed['trigger'])) {
            $trigger = [
                'event' => (string)($parsed['trigger']['event'] ?? ''),
                'filter' => isset($parsed['trigger']['filter']) && is_array($parsed['trigger']['filter'])
                    ? $parsed['trigger']['filter']
                    : null,
            ];
            if ($trigger['event'] !== '' || $trigger['filter'] !== null) {
                $def['trigger'] = $trigger;
            }
        }

        if (isset($parsed['states']) && is_array($parsed['states'])) {
            foreach ($parsed['states'] as $s) {
                if (!is_array($s) || !isset($s['key'])) {
                    continue;
                }
                $def['states'][] = [
                    'key' => (string)$s['key'],
                    'label' => isset($s['label']) ? (string)$s['label'] : ucfirst((string)$s['key']),
                ];
            }
        }

        if (isset($parsed['transitions']) && is_array($parsed['transitions'])) {
            foreach ($parsed['transitions'] as $t) {
                if (!is_array($t) || !isset($t['from'], $t['action'], $t['to'])) {
                    write_log("WorkflowEngine: malformed transition in {$filename}: " . json_encode($t), 'warning');
                    continue;
                }
                $norm = [
                    'from' => (string)$t['from'],
                    'action' => (string)$t['action'],
                    'to' => (string)$t['to'],
                ];
                if (isset($t['roles'])) {
                    // Symfony yields a real array; the legacy parser produced a
                    // raw string that the runtime treated as empty (roles were
                    // never enforced). The array form is the intended behavior.
                    $norm['roles'] = is_array($t['roles'])
                        ? array_values(array_map('strval', $t['roles']))
                        : [(string)$t['roles']];
                }
                $def['transitions'][] = $norm;
            }
        }

        if (isset($parsed['steps']) && is_array($parsed['steps'])) {
            foreach ($parsed['steps'] as $st) {
                if (!is_array($st) || !isset($st['key'])) {
                    continue;
                }
                $step = [
                    'key' => (string)$st['key'],
                    'capability_id' => (string)($st['capability'] ?? $st['capability_id'] ?? ''),
                    'args' => isset($st['args']) && is_array($st['args']) ? $st['args'] : [],
                    'max_attempts' => isset($st['max_attempts']) ? max(1, (int)$st['max_attempts']) : 1,
                ];
                if (isset($st['label'])) {
                    $step['label'] = (string)$st['label'];
                }
                $def['steps'][] = $step;
            }
        }

        if ($def['key'] === '') {
            write_log("WorkflowEngine: definition in {$filename} missing 'key'", 'warning');
            return null;
        }

        return $def;
    }

    /**
     * Legacy line-based workflow YAML parser (fallback). Retained so installs
     * that cannot run composer (no Symfony Yaml) still load workflow
     * definitions. Handles a minimal schema — see parseYamlWithLibrary for the
     * full-featured parser.
     */
    private function parseYamlDefinitionLegacy(string $yaml, string $filename): ?array
    {
        // Simple line-based YAML parser for the expected schema.
        // Uses a minimal state machine — not a full YAML parser.
        $lines = explode("\n", $yaml);
        $def = [
            'key' => '',
            'label' => '',
            'entity_type' => '',
            'initial_state' => 'pending',
            'states' => [],
            'transitions' => [],
            'steps' => [],
            'trigger' => null,
        ];

        $section = null;
        $inTrigger = false;
        $inStates = false;
        $inTransitions = false;
        $inSteps = false;
        $currentStep = null;
        $currentTransition = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip empty and comment lines
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            // Section headers
            if (preg_match('/^(\w+):$/', $trimmed, $m)) {
                $section = $m[1];
                $inTrigger = $section === 'trigger';
                $inStates = $section === 'states';
                $inTransitions = $section === 'transitions';
                $inSteps = $section === 'steps';
                continue;
            }

            // Top-level scalar keys
            if (preg_match('/^(\w+):\s*(.*)$/', $trimmed, $m) && !$inTrigger && !$inStates && !$inTransitions && !$inSteps) {
                $key = $m[1];
                $val = trim($m[2]);
                if (in_array($key, ['key', 'label', 'entity_type', 'initial_state'], true)) {
                    $def[$key] = $val;
                }
                continue;
            }

            // Trigger section
            if ($inTrigger) {
                if (preg_match('/^(\w+):\s*(.*)$/', $trimmed, $m)) {
                    $tKey = $m[1];
                    $tVal = trim($m[2]);
                    if ($tKey === 'event') {
                        $def['trigger']['event'] = $tVal;
                    }
                }
                // Filter sub-keys (indented under filter:)
                if (preg_match('/^\s{4,}(\w+):\s*(.*)$/', $trimmed, $m)) {
                    $def['trigger']['filter'][$m[1]] = trim($m[2]);
                }
                continue;
            }

            // States section — list items
            if ($inStates) {
                if (preg_match('/^\s*-\s*key:\s*(\S+)\s*$/', $trimmed, $m)) {
                    $def['states'][] = ['key' => $m[1], 'label' => ucfirst($m[1])];
                } elseif (preg_match('/^\s*-\s*(\S+)\s*$/', $trimmed, $m)) {
                    $def['states'][] = ['key' => $m[1], 'label' => ucfirst($m[1])];
                }
                continue;
            }

            // Transitions section
            if ($inTransitions) {
                if (preg_match('/^\s*-\s*from:\s*(\S+)\s*$/', $trimmed, $m)) {
                    if ($currentTransition !== null) {
                        // A new transition item started before the previous one
                        // completed — the previous item is malformed.
                        write_log("WorkflowEngine: malformed transition in {$filename} (missing to/action): " . json_encode($currentTransition), 'warning');
                    }
                    $currentTransition = ['from' => $m[1]];
                } elseif ($currentTransition !== null && preg_match('/^\s{4,}(\w+):\s*(.*)$/', $trimmed, $m)) {
                    $currentTransition[$m[1]] = trim($m[2]);
                    // If we have from+action+to, emit
                    if (isset($currentTransition['from'], $currentTransition['action'], $currentTransition['to'])) {
                        $def['transitions'][] = $currentTransition;
                        $currentTransition = null;
                    }
                }
                continue;
            }

            // Steps section
            if ($inSteps) {
                if (preg_match('/^\s*-\s*key:\s*(\S+)\s*$/', $trimmed, $m)) {
                    if ($currentStep !== null) {
                        $def['steps'][] = $currentStep;
                    }
                    $currentStep = ['key' => $m[1], 'capability_id' => '', 'args' => [], 'max_attempts' => 1];
                } elseif ($currentStep !== null && preg_match('/^\s{4,}(\w+):\s*(.*)$/', $trimmed, $m)) {
                    $sk = $m[1];
                    $sv = trim($m[2]);
                    if (in_array($sk, ['label', 'capability_id', 'capability'], true)) {
                        if ($sk === 'capability') {
                            $currentStep['capability_id'] = $sv;
                        } else {
                            $currentStep[$sk] = $sv;
                        }
                    } elseif ($sk === 'max_attempts') {
                        $currentStep['max_attempts'] = max(1, (int)$sv);
                    }
                }
                // Step args (indented under args:)
                if ($currentStep !== null && preg_match('/^\s{8,}(\w+):\s*(.*)$/', $trimmed, $m)) {
                    $currentStep['args'][$m[1]] = trim($m[2]);
                }
                continue;
            }
        }

        // Flush last step
        if ($currentStep !== null) {
            $def['steps'][] = $currentStep;
        }

        // A dangling transition item never completed (missing action/to) —
        // log it instead of silently dropping it.
        if ($currentTransition !== null) {
            write_log("WorkflowEngine: incomplete transition in {$filename}: " . json_encode($currentTransition), 'warning');
        }

        // Validate required fields
        if ($def['key'] === '') {
            write_log("WorkflowEngine: definition in {$filename} missing 'key'", 'warning');
            return null;
        }

        return $def;
    }

    /**
     * Sync a parsed definition to the workflow_definitions table.
     */
    private function syncDefinition(array $definition, string $moduleId): void
    {
        $entityType = $definition['entity_type'] ?? '';

        // Build states array from definition states or steps
        $states = $definition['states'] ?: [];
        if ($states === [] && $definition['steps'] !== []) {
            $states = [['key' => 'pending', 'label' => 'Pending']];
            foreach ($definition['steps'] as $step) {
                $states[] = ['key' => $step['key'], 'label' => $step['label'] ?? ucfirst($step['key'])];
            }
            $states[] = ['key' => 'completed', 'label' => 'Completed'];
            $states[] = ['key' => 'failed', 'label' => 'Failed'];
        }

        // Build transitions from steps
        $transitions = $definition['transitions'] ?: [];
        if ($transitions === [] && $definition['steps'] !== []) {
            $prev = $definition['initial_state'] ?? 'pending';
            foreach ($definition['steps'] as $step) {
                $transitions[] = [
                    'from' => $prev,
                    'action' => 'advance_' . $step['key'],
                    'to' => $step['key'],
                    'roles' => ['administrator', 'superadmin'],
                ];
                $prev = $step['key'];
            }
            $transitions[] = [
                'from' => $prev,
                'action' => 'complete',
                'to' => 'completed',
                'roles' => ['administrator', 'superadmin'],
            ];
            // Error recovery: allow retry from failed steps
            $transitions[] = [
                'from' => 'failed',
                'action' => 'retry',
                'to' => 'pending',
                'roles' => ['administrator', 'superadmin'],
            ];
        }

        try {
            $this->app->workflow()->ensureDefinition(
                $definition['key'],
                $moduleId,
                $entityType,
                $definition['initial_state'] ?? 'pending',
                $states,
                $transitions,
            );
        } catch (Throwable $e) {
            write_log("WorkflowEngine: failed to sync definition '{$definition['key']}': " . $e->getMessage(), 'error');
        }
    }

    // ── Event Subscriptions ──────────────────────────────────────────

    /**
     * Register an event subscription that auto-starts a workflow run.
     */
    public function subscribe(string $module, string $eventId, string $workflowKey, ?array $filter = null, ?string $entityType = null): void
    {
        try {
            $db = $this->app->db();
            $stmt = $db->prepare(
                'INSERT INTO workflow_subscriptions (module, event_id, workflow_key, entity_type, filter_json, is_active, created_at) '
                . 'VALUES (:mod, :evt, :wk, :et, :fj, 1, NOW()) '
                . 'ON DUPLICATE KEY UPDATE entity_type = VALUES(entity_type), filter_json = VALUES(filter_json), is_active = 1, updated_at = NOW()'
            );
            $stmt->execute([
                ':mod' => $module,
                ':evt' => $eventId,
                ':wk'  => $workflowKey,
                ':et'  => $entityType ?? '',
                ':fj'  => $filter !== null ? json_encode($filter) : 'null',
            ]);
        } catch (Throwable $e) {
            write_log("WorkflowEngine: subscribe failed for {$workflowKey} @ {$eventId}: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Handle a kernel event — auto-start workflows subscribed to this event.
     */
    public function handleEvent(string $eventId, array $payload = []): void
    {
        $subscriptions = $this->getActiveSubscriptions();
        $matched = [];

        foreach ($subscriptions as $sub) {
            if ($sub['event_id'] !== $eventId) {
                continue;
            }

            // Apply filter if present
            $filter = json_decode((string)($sub['filter_json'] ?? 'null'), true);
            if (is_array($filter) && $filter !== []) {
                $matched_filter = true;
                foreach ($filter as $fk => $fv) {
                    $pv = $payload[$fk] ?? null;
                    if ((string)$pv !== (string)$fv) {
                        $matched_filter = false;
                        break;
                    }
                }
                if (!$matched_filter) {
                    continue;
                }
            }

            $matched[] = $sub;
        }

        foreach ($matched as $sub) {
            try {
                $this->start(
                    $sub['workflow_key'],
                    $sub['module'],
                    $payload,
                    $sub['entity_type'] ?: null,
                    $payload['id'] ?? $payload['entity_id'] ?? null,
                );
            } catch (Throwable $e) {
                write_log("WorkflowEngine: auto-start failed for {$sub['workflow_key']}: " . $e->getMessage(), 'error');
            }
        }
    }

    /**
     * Get active event subscriptions from DB (cached per request).
     */
    private function getActiveSubscriptions(): array
    {
        if ($this->subscriptionsCache !== null) {
            return $this->subscriptionsCache;
        }

        try {
            $db = $this->app->db();
            $stmt = $db->query('SELECT * FROM workflow_subscriptions WHERE is_active = 1');
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->subscriptionsCache = is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            $this->subscriptionsCache = [];
        }

        return $this->subscriptionsCache;
    }

    // ── Run Lifecycle ────────────────────────────────────────────────

    /**
     * Start a workflow run, optionally under a durable external idempotency key.
     *
     * Keyless calls retain the original active-run behavior. A keyed call is
     * tenant-scoped and hashes the canonical invocation (workflow identity,
     * subject, and recursively key-sorted payload) before claiming the shared
     * kernel idempotency primitive.
     *
     * @return array{ok: bool, run_id?: int, status?: string, deduplicated?: bool, conflict?: bool, result?: mixed, error?: string}
     */
    public function start(
        string $workflowKey,
        string $module,
        array $payload = [],
        ?string $entityType = null,
        ?string $entityId = null,
        ?string $externalKey = null,
    ): array {
        if (!$this->authorityScopeFallback && AuthorityScopeResolver::currentEntryPoint() !== AuthorityScopeResolver::SERVICE) {
            $scopeTenantId = $this->app->tenant()->current();
            if (is_int($scopeTenantId) && $scopeTenantId > 0) {
                $scoped = false;
                try {
                    return AuthorityScopeResolver::withScope(
                        $scopeTenantId,
                        AuthorityScopeResolver::SERVICE,
                        function () use (&$scoped, $workflowKey, $module, $payload, $entityType, $entityId, $externalKey): array {
                            $scoped = true;
                            return $this->start($workflowKey, $module, $payload, $entityType, $entityId, $externalKey);
                        },
                    );
                } catch (Throwable $e) {
                    // @phpstan-ignore if.alwaysFalse (closure mutates the by-reference execution guard)
                    if ($scoped) {
                        throw $e;
                    }
                    write_log('WorkflowEngine: start could not establish a service authority scope; continuing without one', 'warning', [
                        'reason' => 'tenant_authority_store_unavailable',
                        'tenant_id' => $scopeTenantId,
                        'error' => $e->getMessage(),
                    ]);
                    $this->authorityScopeFallback = true;
                    try {
                        return $this->start($workflowKey, $module, $payload, $entityType, $entityId, $externalKey);
                    } finally {
                        $this->authorityScopeFallback = false;
                    }
                }
            }
        }

        if ($externalKey === '') {
            return ['ok' => false, 'error' => 'idempotency_key_required'];
        }

        $tenantId = null;
        if ($externalKey !== null) {
            $tenantId = $this->app->tenant()->current();
            if ($tenantId === null || $tenantId <= 0) {
                return ['ok' => false, 'error' => 'idempotency_tenant_required'];
            }
        }

        try {
            $db = $this->app->db();
            $normalizedEntityType = $entityType ?? '';
            $normalizedEntityId = $entityId ?? '';

            // Resolve the definition before opening the run-creation transaction.
            $definition = null;
            if ($normalizedEntityType !== '') {
                $definition = $this->app->workflow()->getDefinition($workflowKey, $module, $normalizedEntityType);
            }

            $steps = [];
            if ($definition !== null) {
                $states = json_decode((string)($definition['states_json'] ?? '[]'), true);
                $steps = $this->extractStepsFromStates(is_array($states) ? $states : []);
            }

            $lockName = $this->startLockName(
                $workflowKey,
                $module,
                $normalizedEntityType,
                $normalizedEntityId,
            );

            try {
                if ($externalKey !== null) {
                    // The external claim follows an active-run observation, and
                    // the transactional check below remains the insert guard.
                    $this->findActiveRun(
                        $db,
                        $workflowKey,
                        $module,
                        $normalizedEntityType,
                        $normalizedEntityId,
                    );

                    try {
                        $payloadHash = $this->externalPayloadHash(
                            $workflowKey,
                            $module,
                            $payload,
                            $normalizedEntityType,
                            $normalizedEntityId,
                        );
                        $claim = Idempotency::claim($externalKey, $tenantId, $payloadHash, $db);
                    } catch (Throwable $e) {
                        write_log("WorkflowEngine: idempotency claim failed for {$workflowKey}: " . $e->getMessage(), 'error');
                        return ['ok' => false, 'error' => 'idempotency_claim_failed'];
                    }

                    if ($claim['status'] === 'conflict') {
                        return [
                            'ok' => false,
                            'conflict' => true,
                            'error' => 'idempotency_payload_conflict',
                        ];
                    }
                    if ($claim['status'] === 'duplicate') {
                        $outcome = is_array($claim['outcome'] ?? null) ? $claim['outcome'] : [];
                        $outcome['deduplicated'] = true;
                        return $outcome;
                    }
                    if ($claim['status'] === 'in_progress') {
                        return [
                            'ok' => false,
                            'in_progress' => true,
                            'error' => 'idempotency_in_progress',
                        ];
                    }
                }

                $lockStmt = $db->prepare('SELECT GET_LOCK(:lock_name, 10)');
                $lockStmt->execute([':lock_name' => $lockName]);
                $hasStartLock = (int)$lockStmt->fetchColumn() === 1;
                $created = false;

                if (!$hasStartLock) {
                    // A timed-out contender may still observe the committed winner.
                    $activeRun = $this->findActiveRun(
                        $db,
                        $workflowKey,
                        $module,
                        $normalizedEntityType,
                        $normalizedEntityId,
                    );
                    if (!is_array($activeRun)) {
                        return ['ok' => false, 'error' => 'run_creation_busy'];
                    }
                    $started = $this->deduplicatedStartResult($activeRun);
                } else {
                    $db->beginTransaction();
                    try {
                        $activeRun = $this->findActiveRun(
                            $db,
                            $workflowKey,
                            $module,
                            $normalizedEntityType,
                            $normalizedEntityId,
                            true,
                        );

                        if (is_array($activeRun)) {
                            $db->commit();
                            $started = $this->deduplicatedStartResult($activeRun);
                        } else {
                            $payloadJson = json_encode($payload);
                            $stmt = $db->prepare(
                                'INSERT INTO workflow_runs (workflow_key, module, entity_type, entity_id, definition_id, status, payload_json, context_json, started_at, created_at) '
                                . 'VALUES (:wk, :mod, :et, :eid, :did, :status, :pj, :cj, NOW(), NOW())'
                            );
                            $stmt->execute([
                                ':wk'  => $workflowKey,
                                ':mod' => $module,
                                ':et'  => $normalizedEntityType,
                                ':eid' => $normalizedEntityId,
                                ':did' => $definition ? (int)($definition['id'] ?? 0) : null,
                                ':status' => $steps === [] ? 'completed' : 'running',
                                ':pj'  => $payloadJson,
                                ':cj'  => 'null',
                            ]);
                            $runId = (int)$db->lastInsertId();
                            if (is_string($payloadJson) && function_exists('workflowRecordRunPayloadHash')) {
                                \workflowRecordRunPayloadHash($db, $runId, $payloadJson);
                            }

                            foreach ($steps as $i => $step) {
                                $idKey = 'step_' . $step['key'] . '_' . $runId . '_' . ($i + 1);
                                $stmt2 = $db->prepare(
                                    'INSERT INTO workflow_run_steps (run_id, ordinal, step_key, label, capability_id, args_json, status, attempt, max_attempts, idempotency_key, created_at) '
                                    . 'VALUES (:rid, :ord, :sk, :lab, :cap, :args, :st, 0, :ma, :ik, NOW())'
                                );
                                $stmt2->execute([
                                    ':rid' => $runId,
                                    ':ord' => $i + 1,
                                    ':sk'  => $step['key'],
                                    ':lab' => $step['label'] ?? ucfirst($step['key']),
                                    ':cap' => $step['capability_id'] ?? '',
                                    ':args' => json_encode($step['args'] ?? []),
                                    ':st'  => 'pending',
                                    ':ma'  => $step['max_attempts'] ?? 1,
                                    ':ik'  => $idKey,
                                ]);
                            }

                            $db->commit();
                            $created = true;
                            $started = ['ok' => true, 'run_id' => $runId];
                        }
                    } catch (Throwable $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        throw $e;
                    }

                    // Preserve the Phase-1 lock scope: dispatch runs only after
                    // the tuple lock is released. The finally remains the
                    // unconditional all-path cleanup for the same lock value.
                    $this->releaseStartLock($db, $lockName);
                }

                if ($created && $steps !== []) {
                    $this->advance($started['run_id']);
                }

                if ($created) {
                    write_log("WorkflowEngine: started run {$started['run_id']} for {$workflowKey}", 'info', [
                        'workflow_key' => $workflowKey,
                        'module' => $module,
                        'run_id' => $started['run_id'],
                        'steps' => count($steps),
                    ]);
                }

                if ($externalKey === null) {
                    return $started;
                }

                $runId = $started['run_id'];
                $outcome = [
                    'ok' => true,
                    'run_id' => $runId,
                    'deduplicated' => false,
                    'result' => $this->getRun($runId),
                ];

                try {
                    if (!Idempotency::commit($externalKey, $tenantId, $outcome, $db)) {
                        throw new \RuntimeException('Idempotency commit connection does not own the claim');
                    }
                } catch (Throwable $e) {
                    // Dispatch may already have produced side effects. Keep the
                    // processing row fail-closed rather than releasing it for replay.
                    write_log("WorkflowEngine: idempotency commit failed for {$workflowKey}: " . $e->getMessage(), 'error');
                    return [
                        'ok' => false,
                        'run_id' => $runId,
                        'error' => 'idempotency_commit_failed',
                    ];
                }

                return $outcome;
            } finally {
                if ($externalKey !== null) {
                    // No-op for duplicate/conflict and after commit(); for a
                    // failed new start this removes the owned processing claim.
                    try {
                        Idempotency::release($externalKey, $tenantId, $db);
                    } catch (Throwable $releaseError) {
                        write_log("WorkflowEngine: idempotency release failed for {$workflowKey}: " . $releaseError->getMessage(), 'error');
                    }
                }
                $releaseStmt = $db->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $releaseStmt->execute([':lock_name' => $lockName]);
            }
        } catch (Throwable $e) {
            write_log("WorkflowEngine: start failed for {$workflowKey}: " . $e->getMessage(), 'error');
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Release the tuple lock before capability dispatch without weakening finally cleanup. */
    private function releaseStartLock(PDO $db, string $lockName): void
    {
        $releaseStmt = $db->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $releaseStmt->execute([':lock_name' => $lockName]);
    }

    /**
     * Advance a run — atomically claim and execute the next pending step.
     *
     * The run row is locked only while selecting and claiming a step. The
     * transaction commits before capability dispatch, so long-running side
     * effects never hold an InnoDB row lock.
     *
     * @return array{ok: bool, run_id: int, step_id?: int, status?: string, run_busy?: bool, error?: string}
     */
    public function advance(int $runId): array
    {
        if (!$this->authorityScopeFallback && AuthorityScopeResolver::currentEntryPoint() !== AuthorityScopeResolver::SERVICE) {
            $tenantId = $this->app->tenant()->current();
            if (is_int($tenantId) && $tenantId > 0) {
                $scoped = false;
                try {
                    return AuthorityScopeResolver::withScope(
                        $tenantId,
                        AuthorityScopeResolver::SERVICE,
                        function () use (&$scoped, $runId): array {
                            $scoped = true;
                            return $this->advance($runId);
                        },
                    );
                } catch (Throwable $e) {
                    // @phpstan-ignore if.alwaysFalse (closure mutates the by-reference execution guard)
                    if ($scoped) {
                        throw $e;
                    }
                    write_log('WorkflowEngine: advance could not establish a service authority scope; continuing without one', 'warning', [
                        'reason' => 'tenant_authority_store_unavailable',
                        'tenant_id' => $tenantId,
                        'run_id' => $runId,
                        'error' => $e->getMessage(),
                    ]);
                    $this->authorityScopeFallback = true;
                    try {
                        return $this->advance($runId);
                    } finally {
                        $this->authorityScopeFallback = false;
                    }
                }
            }
        }

        try {
            $db = $this->app->db();
            $db->beginTransaction();

            try {
                $stmt = $db->prepare('SELECT * FROM workflow_runs WHERE id = :id LIMIT 1 FOR UPDATE');
                $stmt->execute([':id' => $runId]);
                $run = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!is_array($run)) {
                    $db->rollBack();
                    return ['ok' => false, 'run_id' => $runId, 'error' => 'Run not found'];
                }

                if (in_array($run['status'], ['completed', 'cancelled', 'failed'], true)) {
                    $db->commit();
                    return ['ok' => false, 'run_id' => $runId, 'error' => "Run already {$run['status']}"];
                }

                $blockedStep = $this->findBlockedStep($db, $runId);
                if (is_array($blockedStep)) {
                    $db->commit();
                    if ($blockedStep['status'] === 'running') {
                        return $this->runBusyResult($runId);
                    }
                    return $this->interruptedStepResult($runId, (int)$blockedStep['id']);
                }

                $stepStmt = $db->prepare(
                    "SELECT * FROM workflow_run_steps WHERE run_id = :rid AND status IN ('pending', 'failed') ORDER BY ordinal ASC LIMIT 1"
                );
                $stepStmt->execute([':rid' => $runId]);
                $step = $stepStmt->fetch(PDO::FETCH_ASSOC);

                if (!is_array($step)) {
                    $updateStmt = $db->prepare(
                        "UPDATE workflow_runs SET status = 'completed', finished_at = NOW(), updated_at = NOW() "
                        . "WHERE id = :id AND status IN ('pending', 'running')"
                    );
                    $updateStmt->execute([':id' => $runId]);
                    $db->commit();
                    write_log("WorkflowEngine: run {$runId} completed", 'info');
                    return ['ok' => true, 'run_id' => $runId, 'status' => 'completed'];
                }

                $stepId = (int)$step['id'];
                $claimStmt = $db->prepare(
                    "UPDATE workflow_run_steps SET status = 'running', attempt = attempt + 1, started_at = NOW(), updated_at = NOW() "
                    . "WHERE id = :id AND run_id = :rid AND status IN ('pending', 'failed')"
                );
                $claimStmt->execute([':id' => $stepId, ':rid' => $runId]);
                if ($claimStmt->rowCount() !== 1) {
                    $db->rollBack();
                    return $this->runBusyResult($runId);
                }

                $attempt = (int)($step['attempt'] ?? 0) + 1;
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                if ($this->isRunLockContention($e)) {
                    return $this->runBusyResult($runId);
                }
                throw $e;
            }

            $capabilityId = (string)($step['capability_id'] ?? '');
            $args = json_decode((string)($step['args_json'] ?? '{}'), true);
            $payload = json_decode((string)($run['payload_json'] ?? '{}'), true);
            $resolvedArgs = $this->resolveStepArgs(
                is_array($args) ? $args : [],
                is_array($payload) ? $payload : [],
                $run,
            );
            $maxAttempts = (int)($step['max_attempts'] ?? 1);
            $correlationId = "wf:run:{$runId}:step:{$stepId}";

            // CapabilityBus is the canonical dispatch path. CapabilityRegistry
            // intentionally has no call() method; App::cap() supplies the bus.
            try {
                if ($capabilityId !== '') {
                    $guardFailure = $this->runtimeDispatchGuardFailure($db, $runId, $stepId);
                    if ($guardFailure !== null) {
                        return $guardFailure;
                    }
                    $result = $this->app->cap()->call($capabilityId, $resolvedArgs, [
                        'correlation_id' => $correlationId,
                    ]);
                } else {
                    $result = ['ok' => true, 'data' => null];
                }
            } catch (Throwable $e) {
                $isLastAttempt = $attempt >= $maxAttempts;

                if ($isLastAttempt) {
                    $db->prepare(
                        "UPDATE workflow_run_steps SET status = 'failed', last_error = :err, finished_at = NOW(), updated_at = NOW() "
                        . "WHERE id = :id AND status = 'running'"
                    )->execute([':err' => $e->getMessage(), ':id' => $stepId]);
                    $db->prepare(
                        "UPDATE workflow_runs SET status = 'failed', finished_at = NOW(), updated_at = NOW() WHERE id = :id"
                    )->execute([':id' => $runId]);

                    write_log("WorkflowEngine: run {$runId} step {$stepId} failed after {$maxAttempts} attempts: " . $e->getMessage(), 'error', [
                        'run_id' => $runId,
                        'step_key' => $step['step_key'],
                        'error' => $e->getMessage(),
                        'correlation_id' => $correlationId,
                    ]);

                    return ['ok' => false, 'run_id' => $runId, 'step_id' => $stepId, 'error' => $e->getMessage(), 'status' => 'failed'];
                }

                $db->prepare(
                    "UPDATE workflow_run_steps SET status = 'failed', last_error = :err, updated_at = NOW() "
                    . "WHERE id = :id AND status = 'running'"
                )->execute([':err' => $e->getMessage(), ':id' => $stepId]);

                write_log("WorkflowEngine: run {$runId} step {$stepId} attempt {$attempt}/{$maxAttempts} failed, will retry: " . $e->getMessage(), 'warning', [
                    'run_id' => $runId,
                    'step_key' => $step['step_key'],
                    'error' => $e->getMessage(),
                    'correlation_id' => $correlationId,
                ]);

                return ['ok' => false, 'run_id' => $runId, 'step_id' => $stepId, 'error' => $e->getMessage(), 'status' => 'retry_pending'];
            }

            // Dispatch returned, so its side effects may have happened. Any
            // completion-persistence failure must fail closed and must never
            // put this logical execution back into the retryable pool.
            try {
                $completeStmt = $db->prepare(
                    "UPDATE workflow_run_steps SET status = 'completed', result_json = :rj, finished_at = NOW(), updated_at = NOW() "
                    . "WHERE id = :id AND status = 'running'"
                );
                $completeStmt->execute([':rj' => json_encode($result), ':id' => $stepId]);
                if ($completeStmt->rowCount() !== 1) {
                    throw new \RuntimeException('Step state changed during completion persistence');
                }
            } catch (Throwable $e) {
                $status = $this->interruptStepAfterDispatch($db, $stepId, $e);
                return [
                    'ok' => false,
                    'run_id' => $runId,
                    'step_id' => $stepId,
                    'status' => $status,
                    'error' => 'Step completion persistence failed after dispatch: ' . $e->getMessage(),
                ];
            }

            write_log("WorkflowEngine: run {$runId} step {$stepId} ({$step['step_key']}) completed", 'info', [
                'run_id' => $runId,
                'step_key' => $step['step_key'],
                'capability' => $capabilityId,
                'correlation_id' => $correlationId,
            ]);

            return $this->advance($runId);
        } catch (Throwable $e) {
            write_log("WorkflowEngine: advance failed for run {$runId}: " . $e->getMessage(), 'error');
            return ['ok' => false, 'run_id' => $runId, 'error' => $e->getMessage()];
        }
    }

    /**
     * Cancel a workflow run.
     *
     * Cancellation is refused while a capability is in flight. This minimal
     * safe semantic preserves the running marker until dispatch settles.
     */
    public function cancel(int $runId, string $reason = 'Cancelled by operator'): array
    {
        try {
            $db = $this->app->db();
            $db->beginTransaction();

            try {
                $stmt = $db->prepare('SELECT * FROM workflow_runs WHERE id = :id LIMIT 1 FOR UPDATE');
                $stmt->execute([':id' => $runId]);
                $run = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!is_array($run)) {
                    $db->rollBack();
                    return ['ok' => false, 'run_id' => $runId, 'error' => 'Run not found'];
                }

                if (in_array($run['status'], ['completed', 'cancelled'], true)) {
                    $db->commit();
                    return ['ok' => false, 'run_id' => $runId, 'error' => "Run already {$run['status']}"];
                }

                $blockedStep = $this->findBlockedStep($db, $runId);
                if (is_array($blockedStep)) {
                    $db->commit();
                    if ($blockedStep['status'] === 'running') {
                        return $this->runBusyResult($runId);
                    }
                    return $this->interruptedStepResult($runId, (int)$blockedStep['id']);
                }

                $db->prepare(
                    "UPDATE workflow_run_steps SET status = 'cancelled', last_error = :err, updated_at = NOW() "
                    . "WHERE run_id = :rid AND status IN ('pending', 'failed')"
                )->execute([':err' => $reason, ':rid' => $runId]);
                $db->prepare(
                    "UPDATE workflow_runs SET status = 'cancelled', cancelled_at = NOW(), cancel_reason = :cr, finished_at = NOW(), updated_at = NOW() WHERE id = :id"
                )->execute([':cr' => $reason, ':id' => $runId]);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                if ($this->isRunLockContention($e)) {
                    return $this->runBusyResult($runId);
                }
                throw $e;
            }

            write_log("WorkflowEngine: run {$runId} cancelled: {$reason}", 'info');
            return ['ok' => true, 'run_id' => $runId, 'status' => 'cancelled'];
        } catch (Throwable $e) {
            write_log("WorkflowEngine: cancel failed for run {$runId}: " . $e->getMessage(), 'error');
            return ['ok' => false, 'run_id' => $runId, 'error' => $e->getMessage()];
        }
    }

    /**
     * Replay a run from its first failed step (or from a specific step).
     *
     * Resets the target step and all subsequent steps to 'pending',
     * then re-advances.
     */
    public function replay(int $runId, ?string $fromStepKey = null): array
    {
        try {
            $db = $this->app->db();
            $db->beginTransaction();

            try {
                $stmt = $db->prepare('SELECT * FROM workflow_runs WHERE id = :id LIMIT 1 FOR UPDATE');
                $stmt->execute([':id' => $runId]);
                $run = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!is_array($run)) {
                    $db->rollBack();
                    return ['ok' => false, 'run_id' => $runId, 'error' => 'Run not found'];
                }

                $blockedStep = $this->findBlockedStep($db, $runId);
                if (is_array($blockedStep)) {
                    $db->commit();
                    if ($blockedStep['status'] === 'running') {
                        return $this->runBusyResult($runId);
                    }
                    return $this->interruptedStepResult($runId, (int)$blockedStep['id']);
                }

                if ($fromStepKey !== null) {
                    $resetStmt = $db->prepare(
                        "UPDATE workflow_run_steps SET status = 'pending', attempt = 0, last_error = NULL, result_json = NULL, started_at = NULL, finished_at = NULL, updated_at = NOW() "
                        . 'WHERE run_id = :rid AND ordinal >= (SELECT MIN(ordinal) FROM workflow_run_steps WHERE run_id = :rid2 AND step_key = :sk)'
                    );
                    $resetStmt->execute([':rid' => $runId, ':rid2' => $runId, ':sk' => $fromStepKey]);
                } else {
                    $db->prepare(
                        "UPDATE workflow_run_steps SET status = 'pending', attempt = 0, last_error = NULL, result_json = NULL, started_at = NULL, finished_at = NULL, updated_at = NOW() "
                        . "WHERE run_id = :rid AND status IN ('failed', 'cancelled')"
                    )->execute([':rid' => $runId]);
                }

                $db->prepare(
                    "UPDATE workflow_runs SET status = 'running', finished_at = NULL, cancelled_at = NULL, updated_at = NOW() WHERE id = :id"
                )->execute([':id' => $runId]);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                if ($this->isRunLockContention($e)) {
                    return $this->runBusyResult($runId);
                }
                throw $e;
            }

            write_log("WorkflowEngine: replaying run {$runId}" . ($fromStepKey ? " from step {$fromStepKey}" : ''), 'info');
            return $this->advance($runId);
        } catch (Throwable $e) {
            write_log("WorkflowEngine: replay failed for run {$runId}: " . $e->getMessage(), 'error');
            return ['ok' => false, 'run_id' => $runId, 'error' => $e->getMessage()];
        }
    }

    // ── Query Methods ────────────────────────────────────────────────

    /**
     * Get run details including steps.
     */
    public function getRun(int $runId): ?array
    {
        try {
            $db = $this->app->db();
            $stmt = $db->prepare('SELECT * FROM workflow_runs WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $runId]);
            $run = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($run)) {
                return null;
            }

            $stepStmt = $db->prepare('SELECT * FROM workflow_run_steps WHERE run_id = :rid ORDER BY ordinal ASC');
            $stepStmt->execute([':rid' => $runId]);
            $steps = $stepStmt->fetchAll(PDO::FETCH_ASSOC);

            $run['steps'] = is_array($steps) ? $steps : [];
            return $run;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Get all runs for a specific entity.
     */
    public function getRuns(string $workflowKey, string $entityType, string $entityId): array
    {
        try {
            $db = $this->app->db();
            $stmt = $db->prepare(
                'SELECT * FROM workflow_runs WHERE workflow_key = :wk AND entity_type = :et AND entity_id = :eid ORDER BY created_at DESC'
            );
            $stmt->execute([':wk' => $workflowKey, ':et' => $entityType, ':eid' => $entityId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * List recent runs with optional status filter.
     */
    public function listRuns(int $limit = 50, ?string $status = null, ?string $module = null): array
    {
        try {
            $db = $this->app->db();
            $where = [];
            $params = [];

            if ($status !== null) {
                $where[] = 'status = :st';
                $params[':st'] = $status;
            }
            if ($module !== null) {
                $where[] = 'module = :mod';
                $params[':mod'] = $module;
            }

            $whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
            $sql = "SELECT * FROM workflow_runs {$whereClause} ORDER BY created_at DESC LIMIT " . max(1, min(500, $limit));

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /** @param array<string, mixed> $payload */
    private function externalPayloadHash(
        string $workflowKey,
        string $module,
        array $payload,
        string $entityType,
        string $entityId,
    ): string {
        return Idempotency::canonicalPayloadHash([
            'workflow_key' => $workflowKey,
            'module' => $module,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload' => $payload,
        ]);
    }

    private function startLockName(string $workflowKey, string $module, string $entityType, string $entityId): string
    {
        $tuple = json_encode([$workflowKey, $module, $entityType, $entityId]);
        return 'workflow:start:' . substr(hash('sha256', (string)$tuple), 0, 48);
    }

    /**
     * @return array{ok: false, run_id: int, step_id: int, status: string, error: string}|null
     */
    private function runtimeDispatchGuardFailure(PDO $db, int $runId, int $stepId): ?array
    {
        if (!self::$runtimeGuardEnabled) {
            return null;
        }

        if (self::$runtimeGuardBeforeDispatch !== null) {
            (self::$runtimeGuardBeforeDispatch)($db);
        }

        if (!$db->inTransaction()) {
            return null;
        }

        write_log(
            "WorkflowEngine: runtime dispatch guard blocked capability for run {$runId} step {$stepId}: transaction is open",
            'error',
            ['run_id' => $runId, 'step_id' => $stepId],
        );

        // The already-committed running claim remains non-retryable. Roll back
        // only the unexpected transaction opened after that claim so the
        // connection is not leaked in a transactional state.
        $db->rollBack();

        return [
            'ok' => false,
            'run_id' => $runId,
            'step_id' => $stepId,
            'status' => 'running',
            'error' => 'runtime_dispatch_guard_violation',
        ];
    }

    /** @return array<string, mixed>|false */
    private function findActiveRun(
        PDO $db,
        string $workflowKey,
        string $module,
        string $entityType,
        string $entityId,
        bool $forUpdate = false,
    ): array|false {
        $sql = 'SELECT id, status FROM workflow_runs '
            . 'WHERE workflow_key = :wk AND module = :mod AND entity_type = :et AND entity_id = :eid '
            . "AND status IN ('pending', 'running') ORDER BY id ASC LIMIT 1";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $db->prepare($sql);
        $stmt->execute([':wk' => $workflowKey, ':mod' => $module, ':et' => $entityType, ':eid' => $entityId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $run
     * @return array{ok: true, run_id: int, status: string, deduplicated: true}
     */
    private function deduplicatedStartResult(array $run): array
    {
        return [
            'ok' => true,
            'run_id' => (int)$run['id'],
            'status' => (string)$run['status'],
            'deduplicated' => true,
        ];
    }

    /** @return array{id: mixed, status: mixed}|false */
    private function findBlockedStep(PDO $db, int $runId): array|false
    {
        $stmt = $db->prepare(
            "SELECT id, status FROM workflow_run_steps WHERE run_id = :rid "
            . "AND status IN ('running', 'interrupted') ORDER BY ordinal ASC LIMIT 1"
        );
        $stmt->execute([':rid' => $runId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function interruptStepAfterDispatch(PDO $db, int $stepId, Throwable $cause): string
    {
        try {
            $stmt = $db->prepare(
                "UPDATE workflow_run_steps SET status = 'interrupted', last_error = :err, updated_at = NOW() "
                . "WHERE id = :id AND status = 'running'"
            );
            $stmt->execute([':err' => $cause->getMessage(), ':id' => $stepId]);
            if ($stmt->rowCount() === 1) {
                write_log("WorkflowEngine: step {$stepId} interrupted after dispatch: " . $cause->getMessage(), 'error');
                return 'interrupted';
            }

            $statusStmt = $db->prepare('SELECT status FROM workflow_run_steps WHERE id = :id LIMIT 1');
            $statusStmt->execute([':id' => $stepId]);
            $currentStatus = $statusStmt->fetchColumn();
            if ($currentStatus === 'interrupted') {
                return 'interrupted';
            }
        } catch (Throwable $markError) {
            write_log("WorkflowEngine: could not persist interrupted state for step {$stepId}: " . $markError->getMessage(), 'error');
        }

        // Leaving the claim as running is also fail-closed: no engine path can
        // reclaim it automatically.
        return 'running';
    }

    /** @return array{ok: false, run_id: int, step_id: int, status: string, error: string} */
    private function interruptedStepResult(int $runId, int $stepId): array
    {
        return [
            'ok' => false,
            'run_id' => $runId,
            'step_id' => $stepId,
            'status' => 'interrupted',
            'error' => 'step_interrupted',
        ];
    }

    /**
     * @return array{ok: false, run_id: int, run_busy: true, error: string}
     */
    private function runBusyResult(int $runId): array
    {
        return ['ok' => false, 'run_id' => $runId, 'run_busy' => true, 'error' => 'run_busy'];
    }

    private function isRunLockContention(Throwable $e): bool
    {
        $driverCode = $e instanceof \PDOException ? (int)($e->errorInfo[1] ?? 0) : 0;
        if (in_array($driverCode, [1205, 1213, 3572], true)) {
            return true;
        }

        $message = strtolower($e->getMessage());
        return str_contains($message, 'lock wait timeout')
            || str_contains($message, 'deadlock found')
            || str_contains($message, 'could not obtain lock');
    }

    /**
     * Extract step definitions from workflow definition states.
     * Steps are serialized as state metadata.
     */
    private function extractStepsFromStates(array $states): array
    {
        $steps = [];
        foreach ($states as $state) {
            if (isset($state['step']) && is_array($state['step'])) {
                $steps[] = $state['step'];
            }
        }
        return $steps;
    }

    /**
     * Resolve {payload.*} and {run.*} references in step arguments.
     */
    private function resolveStepArgs(array $args, array $payload, array $run): array
    {
        $resolved = [];
        foreach ($args as $key => $value) {
            if (is_string($value)) {
                // Replace {payload.field} references
                $value = preg_replace_callback('/\{payload\.([a-zA-Z_][\w.]*)\}/', function ($m) use ($payload) {
                    $parts = explode('.', $m[1]);
                    $v = $payload;
                    foreach ($parts as $p) {
                        if (is_array($v) && array_key_exists($p, $v)) {
                            $v = $v[$p];
                        } else {
                            return $m[0];
                        }
                    }
                    return is_scalar($v) ? (string)$v : $m[0];
                }, $value);

                // Replace {run.field} references
                $value = preg_replace_callback('/\{run\.([a-zA-Z_]\w*)\}/', function ($m) use ($run) {
                    return (string)($run[$m[1]] ?? $m[0]);
                }, $value);
            }
            $resolved[$key] = $value;
        }
        return $resolved;
    }
}
