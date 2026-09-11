<?php

declare(strict_types=1);

const CAW_WORKFLOW_MODULE_ID = 'cms-akira-workflow';
const CAW_WORKFLOW_KEY = 'akira.post';
const CAW_WORKFLOW_ENTITY_TYPE = 'post';
const CAW_WORKFLOW_INVALIDATION = 'entity.list.workflow-run';

/** @return array<string, string> */
function cms_akira_workflow_capability_handlers(): array
{
    return [
        'akira.workflow.evaluate@1' => 'caw_cap_akira_workflow_evaluate_1',
        'akira.workflow.transition@1' => 'caw_cap_akira_workflow_transition_1',
        'akira.workflow.runs@1' => 'caw_cap_akira_workflow_runs_1',
    ];
}

/**
 * Execute only the Kernel-owned workflow service outside ModuleDB ownership enforcement.
 * WorkflowRuntime predates KernelPDO's internal escalation seam, so its documented app()
 * service must run without the calling extension's table identity; restore it unconditionally.
 */
function cawWithKernelWorkflow(callable $operation): mixed
{
    $activeModule = app()->getActiveModule();
    $moduleContext = function_exists('kernel_request_context_get')
        ? kernel_request_context_get('_activeModuleContext')
        : null;
    app()->clearActiveModule();
    if (function_exists('kernel_request_context_delete')) {
        kernel_request_context_delete('_activeModuleContext');
    }
    try {
        return $operation();
    } finally {
        if (function_exists('kernel_request_context_set')) {
            kernel_request_context_set('_activeModuleContext', $moduleContext);
        }
        app()->setActiveModule($activeModule);
    }
}

/** @return list<array{from:string,action:string,to:string,roles:list<string>}> */
function cawPostLifecycleTransitions(): array
{
    return [
        ['from' => 'draft', 'action' => 'submit', 'to' => 'review', 'roles' => ['contributor', 'author', 'editor', 'admin', 'administrator', 'superadmin']],
        ['from' => 'review', 'action' => 'approve', 'to' => 'approved', 'roles' => ['editor', 'admin', 'administrator', 'superadmin']],
        ['from' => 'review', 'action' => 'reject', 'to' => 'draft', 'roles' => ['editor', 'admin', 'administrator', 'superadmin']],
        ['from' => 'approved', 'action' => 'publish', 'to' => 'published', 'roles' => ['author', 'editor', 'admin', 'administrator', 'superadmin']],
        ['from' => 'approved', 'action' => 'unapprove', 'to' => 'review', 'roles' => ['editor', 'admin', 'administrator', 'superadmin']],
        ['from' => 'published', 'action' => 'unpublish', 'to' => 'draft', 'roles' => ['author', 'editor', 'admin', 'administrator', 'superadmin']],
    ];
}

/** @return list<string> */
function cawPostLifecycleParticipantRoles(): array
{
    $roles = [];
    foreach (cawPostLifecycleTransitions() as $transition) {
        foreach ($transition['roles'] as $role) {
            $roles[$role] = true;
        }
    }
    return array_keys($roles);
}

/** The one Akira-owned definition. Extra definitions and member-owned persistence are forbidden. */
function cawEnsureDefinition(): void
{
    if (!function_exists('app')) {
        return;
    }

    app()->workflow()->registerCaller(CAW_WORKFLOW_MODULE_ID);
    cawWithKernelWorkflow(static function (): void {
        app()->workflow()->ensureDefinition(
            CAW_WORKFLOW_KEY,
            CAW_WORKFLOW_MODULE_ID,
            CAW_WORKFLOW_ENTITY_TYPE,
            'draft',
            [
                ['key' => 'draft', 'label' => 'Draft'],
                ['key' => 'review', 'label' => 'In Review'],
                ['key' => 'approved', 'label' => 'Approved'],
                ['key' => 'published', 'label' => 'Published'],
            ],
            cawPostLifecycleTransitions(),
        );
    });
}

/** Seed protocol-v2 authorization for all roles represented by the definition. */
function cawSeedTransitionPolicy(): void
{
    if (!function_exists('app')) {
        return;
    }
    \Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry::seedPolicyForCurrentScope([[
        'policy_version' => 1,
        'capability_id' => 'akira.workflow.transition@1',
        'capability_version' => '1',
        'provider' => CAW_WORKFLOW_MODULE_ID,
        'caller_module' => CAW_WORKFLOW_MODULE_ID . ',cms-akira-shell',
        'allowed_roles' => implode(',', cawPostLifecycleParticipantRoles()),
        'provider_activation_required' => true,
        'requires_protocol' => 'v2',
        'is_active' => true,
    ]]);
}

final class CawWorkflowException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}

function cawTenantId(): int
{
    $tenantId = (int) app()->tenant()->current();
    if ($tenantId <= 0) {
        throw new CawWorkflowException('A trusted tenant context is required.', 500);
    }
    return $tenantId;
}

/** Opaque printable ASCII reference; it is never interpreted as or joined to foreign storage. */
function cawEntityKey(mixed $value): string
{
    $key = is_string($value) ? trim($value) : '';
    if ($key === '' || strlen($key) > 150 || preg_match('/^[\x21-\x7E]+$/D', $key) !== 1) {
        throw new CawWorkflowException('entity_key must be an opaque printable ASCII reference of at most 150 bytes.');
    }
    return $key;
}

function cawEntityType(mixed $value): string
{
    $type = is_string($value) ? trim($value) : '';
    if ($type !== CAW_WORKFLOW_ENTITY_TYPE) {
        throw new CawWorkflowException('Only the Akira Post entity type is supported.');
    }
    return $type;
}

/** Kernel workflow rows are shared, so the trusted tenant is part of the internal subject identity. */
function cawKernelEntityId(int $tenantId, string $entityKey): string
{
    return 'tenant-' . $tenantId . ':' . $entityKey;
}

/** @return array{id: int, role: string, source?: string} */
function cawActor(): array
{
    $actor = app()->user();
    if (!is_array($actor) || (int) ($actor['id'] ?? $actor['sub'] ?? 0) <= 0) {
        throw new CawWorkflowException('Authentication required.', 401);
    }
    $role = trim((string) ($actor['role'] ?? ''));
    if ($role === '') {
        throw new CawWorkflowException('A trusted actor role is required.', 403);
    }
    $actor['id'] = (int) ($actor['id'] ?? $actor['sub']);
    $actor['role'] = $role;
    return $actor;
}

function cawCorrelationId(): string
{
    $context = function_exists('kernel_request_context_get')
        ? trim((string) kernel_request_context_get('correlation_id', ''))
        : '';
    return $context !== '' ? $context : bin2hex(random_bytes(16));
}

/**
 * @param array<string, mixed> $payload
 * @return array{0: string, 1: string, 2: int, 3: string}
 */
function cawSubject(array $payload): array
{
    if (array_key_exists('tenant_id', $payload)) {
        throw new CawWorkflowException('tenant_id is supplied by kernel context.');
    }
    $type = cawEntityType($payload['entity_type'] ?? null);
    $key = cawEntityKey($payload['entity_key'] ?? $payload['key'] ?? null);
    $tenantId = cawTenantId();
    return [$type, $key, $tenantId, cawKernelEntityId($tenantId, $key)];
}

/**
 * Runtime calls are deliberate: the Kernel capability provider's caller allowlist is frozen during
 * App bootstrap, before an extension can register itself. Direct WorkflowRuntime use preserves the
 * outer CapabilityBus actor context and lets transition participate in this member's same-PDO audit transaction.
 *
 * @return array<string, mixed>
 */
function cawRuntimeState(string $kernelEntityId): array
{
    return cawWithKernelWorkflow(static fn (): array => app()->workflow()->stateGet([
        'workflow_key' => CAW_WORKFLOW_KEY,
        'module' => CAW_WORKFLOW_MODULE_ID,
        'entity_type' => CAW_WORKFLOW_ENTITY_TYPE,
        'entity_id' => $kernelEntityId,
    ]));
}

/**
 * Materialize core's Post lifecycle projection on the caller-managed tenant transaction.
 * cms-akira-core retains table ownership; this bridge is the sole cross-member writer because
 * the authoritative workflow transition and projection must commit on the same PDO.
 */
function cawProjectPostLifecycle(PDO $db, int $tenantId, string $slug, string $workflowState): void
{
    $status = $workflowState === 'published' ? 'published' : 'draft';
    cawWithKernelWorkflow(static function () use ($db, $tenantId, $slug, $status): void {
        $statement = $db->prepare(
            'UPDATE cms_akira_posts SET status = :status, '
            . 'published_at = CASE WHEN :publication_status = \'published\' THEN CURRENT_TIMESTAMP ELSE NULL END '
            . 'WHERE tenant_id = :tenant AND slug = :slug AND deleted_at IS NULL'
        );
        $statement->execute([
            ':status' => $status,
            ':publication_status' => $status,
            ':tenant' => $tenantId,
            ':slug' => $slug,
        ]);
        if ($statement->rowCount() === 0) {
            $exists = $db->prepare(
                'SELECT 1 FROM cms_akira_posts WHERE tenant_id = :tenant AND slug = :slug AND deleted_at IS NULL LIMIT 1 FOR UPDATE'
            );
            $exists->execute([':tenant' => $tenantId, ':slug' => $slug]);
            if ($exists->fetchColumn() === false) {
                throw new CawWorkflowException('Post projection target is unavailable.', 404);
            }
        }
    });
}

/**
 * @param array<string, mixed> $workflow
 * @return array<string, mixed>
 */
function cawProjectState(array $workflow, string $entityKey): array
{
    $actions = [];
    foreach (is_array($workflow['allowed_actions'] ?? null) ? $workflow['allowed_actions'] : [] as $action) {
        if (!is_array($action)) {
            continue;
        }
        $name = trim((string) ($action['action'] ?? ''));
        $to = trim((string) ($action['to'] ?? ''));
        if ($name !== '' && $to !== '') {
            $actions[] = ['action' => $name, 'to' => $to, 'label' => (string) ($action['label'] ?? ucfirst($name))];
        }
    }
    return [
        'workflow_key' => CAW_WORKFLOW_KEY,
        'entity_type' => CAW_WORKFLOW_ENTITY_TYPE,
        'entity_key' => $entityKey,
        'status' => (string) ($workflow['state'] ?? ''),
        'allowed_actions' => $actions,
    ];
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function cawEvaluate(array $payload): array
{
    cawActor();
    [, $key, , $kernelEntityId] = cawSubject($payload);
    $state = cawRuntimeState($kernelEntityId);
    $workflow = is_array($state['workflow'] ?? null) ? $state['workflow'] : null;
    if (($state['ok'] ?? false) !== true || $workflow === null) {
        return ['ok' => false, 'error' => 'Workflow state unavailable.'];
    }
    return ['ok' => true, 'data' => cawProjectState($workflow, $key)];
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function cawTransition(array $payload): array
{
    $actor = cawActor();
    [$type, $key, $tenantId, $kernelEntityId] = cawSubject($payload);
    $action = is_string($payload['action'] ?? null) ? trim($payload['action']) : '';
    $expected = is_string($payload['expected_status'] ?? null) ? trim($payload['expected_status']) : '';
    $idempotencyKey = is_string($payload['idempotency_key'] ?? null) ? trim($payload['idempotency_key']) : '';
    if ($action === '' || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $action) !== 1) {
        throw new CawWorkflowException('A canonical action is required.');
    }
    if ($expected === '' || !in_array($expected, ['draft', 'review', 'approved', 'published'], true)) {
        throw new CawWorkflowException('expected_status is required for concurrency control.');
    }
    if ($idempotencyKey === '' || strlen($idempotencyKey) > 255) {
        throw new CawWorkflowException('A valid idempotency_key is required.');
    }

    $envelope = ['operation' => 'workflow.transition', 'entity_type' => $type, 'entity_key' => $key, 'action' => $action, 'expected_status' => $expected];
    $hash = app()->cap()->call('kernel.idempotency.hash@1', ['payload' => $envelope], [
        'caller' => ['module' => CAW_WORKFLOW_MODULE_ID, 'user' => $actor], 'mode' => 'first',
    ]);
    if (!is_string($hash)) {
        throw new CawWorkflowException('Idempotency hashing unavailable.', 503);
    }

    $db = app()->db();
    $claimed = false;
    $publicationUncertain = false;
    try {
        $db->beginTransaction();
        $claim = app()->cap()->call('kernel.idempotency.claim@1', [
            'key' => $idempotencyKey, 'tenant_id' => $tenantId, 'payload_hash' => $hash, 'db' => $db,
        ], ['caller' => ['module' => CAW_WORKFLOW_MODULE_ID, 'user' => $actor], 'mode' => 'first']);
        $claimStatus = is_array($claim) ? (string) ($claim['status'] ?? '') : '';
        if ($claimStatus === 'duplicate') {
            $db->rollBack();
            return is_array($claim['outcome'] ?? null) ? $claim['outcome'] : ['ok' => true, 'replayed' => true];
        }
        if ($claimStatus === 'conflict') {
            $db->rollBack();
            throw new CawWorkflowException('Idempotency key payload conflict.', 409);
        }
        if ($claimStatus === 'in_progress') {
            $db->rollBack();
            throw new CawWorkflowException('Idempotent transition is still processing.', 425, 2);
        }
        if ($claimStatus !== 'new') {
            throw new RuntimeException('Unexpected idempotency claim result.');
        }
        $claimed = true;

        $before = cawRuntimeState($kernelEntityId);
        $workflowBefore = is_array($before['workflow'] ?? null) ? $before['workflow'] : null;
        if (($before['ok'] ?? false) !== true || $workflowBefore === null) {
            throw new CawWorkflowException('Workflow state unavailable.', 503);
        }
        if ((string) ($workflowBefore['state'] ?? '') !== $expected) {
            throw new CawWorkflowException('Workflow state changed; refresh and retry.', 409);
        }
        $allowed = array_column(is_array($workflowBefore['allowed_actions'] ?? null) ? $workflowBefore['allowed_actions'] : [], 'action');
        if (!in_array($action, $allowed, true)) {
            throw new CawWorkflowException('Workflow action is not allowed for the current role or state.', 403);
        }

        $correlationId = cawCorrelationId();
        $transition = cawWithKernelWorkflow(static fn (): array => app()->workflow()->transition([
            'workflow_key' => CAW_WORKFLOW_KEY,
            'module' => CAW_WORKFLOW_MODULE_ID,
            'entity_type' => CAW_WORKFLOW_ENTITY_TYPE,
            'entity_id' => $kernelEntityId,
            'action' => $action,
            'actor_user_id' => (int) $actor['id'],
            'meta' => ['correlation_id' => $correlationId, 'tenant_id' => $tenantId, 'entity_key' => $key],
        ]));
        if (($transition['ok'] ?? false) !== true) {
            $message = str_contains(strtolower((string) ($transition['error'] ?? '')), 'concurrent')
                ? 'Workflow state changed concurrently; refresh and retry.'
                : 'Workflow transition failed.';
            throw new CawWorkflowException($message, 409);
        }

        $after = cawRuntimeState($kernelEntityId);
        $workflowAfter = is_array($after['workflow'] ?? null) ? $after['workflow'] : null;
        if (($after['ok'] ?? false) !== true || $workflowAfter === null) {
            throw new RuntimeException('Transition state projection failed.');
        }
        $projection = cawProjectState($workflowAfter, $key);
        cawProjectPostLifecycle($db, $tenantId, $key, (string) $projection['status']);
        $audit = app()->cap()->call('kernel.audit.record@1', [
            'module' => CAW_WORKFLOW_MODULE_ID,
            'action' => 'akira.workflow.transition',
            'entity_type' => $type,
            'entity_id' => $key,
            'old_data' => ['status' => $expected],
            'new_data' => ['tenant_id' => $tenantId, 'status' => $projection['status'], 'action' => $action, 'correlation_id' => $correlationId],
        ], ['caller' => ['module' => CAW_WORKFLOW_MODULE_ID, 'user' => $actor], 'mode' => 'first']);
        if (!is_array($audit) || ($audit['ok'] ?? false) !== true) {
            throw new RuntimeException('Durable workflow audit failed.');
        }

        $outcome = ['ok' => true, 'operation' => 'workflow.transition', 'data' => $projection, 'correlation_id' => $correlationId];
        $committed = app()->cap()->call('kernel.idempotency.commit@1', [
            'key' => $idempotencyKey, 'tenant_id' => $tenantId, 'outcome' => $outcome, 'db' => $db,
        ], ['caller' => ['module' => CAW_WORKFLOW_MODULE_ID, 'user' => $actor], 'mode' => 'first']);
        if ($committed !== true) {
            throw new RuntimeException('Idempotency outcome commit failed.');
        }
        $publicationUncertain = true;
        $db->commit();
        $publicationUncertain = false;
        return $outcome;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($claimed && !$publicationUncertain) {
            try {
                app()->cap()->call('kernel.idempotency.release@1', [
                    'key' => $idempotencyKey, 'tenant_id' => $tenantId, 'db' => $db,
                ], ['caller' => ['module' => CAW_WORKFLOW_MODULE_ID, 'user' => $actor], 'mode' => 'first']);
            } catch (Throwable) {
                // A failed release remains processing, so retries fail closed.
            }
        }
        throw $error;
    }
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function cawProjectRun(array $row, string $entityKey): array
{
    return [
        'run_id' => (int) ($row['id'] ?? 0),
        'workflow_key' => CAW_WORKFLOW_KEY,
        'entity_type' => CAW_WORKFLOW_ENTITY_TYPE,
        'entity_key' => $entityKey,
        'status' => (string) ($row['status'] ?? ''),
        'started_at' => $row['started_at'] ?? null,
        'finished_at' => $row['finished_at'] ?? null,
        'cancelled_at' => $row['cancelled_at'] ?? null,
        'cancel_reason' => $row['cancel_reason'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function cawRuns(array $payload): array
{
    cawActor();
    [, $key, , $kernelEntityId] = cawSubject($payload);
    $rows = cawWithKernelWorkflow(static fn (): array => app()->workflowEngine()->getRuns(
        CAW_WORKFLOW_KEY,
        CAW_WORKFLOW_ENTITY_TYPE,
        $kernelEntityId,
    ));
    $projected = [];
    foreach ($rows as $row) {
        if (is_array($row) && (string) ($row['module'] ?? '') === CAW_WORKFLOW_MODULE_ID
            && (string) ($row['entity_id'] ?? '') === $kernelEntityId) {
            $projected[] = cawProjectRun($row, $key);
        }
    }
    return ['ok' => true, 'runs' => $projected, 'total' => count($projected)];
}

/** @return array<string, mixed> */
function caw_cap_akira_workflow_evaluate_1(mixed $payload, string $capabilityId = 'akira.workflow.evaluate@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }
    try {
        return cawEvaluate($payload);
    } catch (CawWorkflowException $error) {
        return ['ok' => false, 'error' => $error->getMessage()];
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'Workflow evaluation unavailable.'];
    }
}

/** @return array<string, mixed> */
function caw_cap_akira_workflow_transition_1(mixed $payload, string $capabilityId = 'akira.workflow.transition@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CawWorkflowException('payload must be an object.');
    }
    return cawTransition($payload);
}

/** @return array<string, mixed> */
function caw_cap_akira_workflow_runs_1(mixed $payload, string $capabilityId = 'akira.workflow.runs@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'runs' => [], 'total' => 0, 'error' => 'payload must be an object'];
    }
    try {
        return cawRuns($payload);
    } catch (CawWorkflowException $error) {
        return ['ok' => false, 'runs' => [], 'total' => 0, 'error' => $error->getMessage()];
    } catch (Throwable) {
        return ['ok' => false, 'runs' => [], 'total' => 0, 'error' => 'Workflow run introspection unavailable.'];
    }
}

cawEnsureDefinition();
cawSeedTransitionPolicy();
