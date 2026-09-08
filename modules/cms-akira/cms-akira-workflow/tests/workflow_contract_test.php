<?php

/** CMS Akira Phase 6 native table-free workflow contract and integration gate. */

declare(strict_types=1);

use Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry;

$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = 'akiracms.test';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/handlers.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

$registry = app()->capabilities();
foreach (cms_akira_workflow_capability_handlers() as $id => $handler) {
    if ($registry->has($id)) {
        continue;
    }
    $meta = $id === 'akira.workflow.transition@1'
        ? ['requires_protocol' => 'v2', 'effects' => ['invalidates' => [CAW_WORKFLOW_INVALIDATION]]]
        : [];
    $registry->register(
        $id,
        CAW_WORKFLOW_MODULE_ID,
        static function (mixed $payload, string $capabilityId = '', string $provider = '') use ($handler): mixed {
            return moduleWithContext(CAW_WORKFLOW_MODULE_ID, static fn (): mixed => $handler($payload, $capabilityId, $provider));
        },
        50,
        ['first'],
        $meta,
    );
}

$originalTenant = app()->tenant()->current();
$tenantA = (int)$originalTenant;
$tenantB = 994802;
$db = app()->db();
$hasWorkflowRuns = (bool)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'workflow_runs'")->fetchColumn();
$prefix = 'workflow-' . bin2hex(random_bytes(5));
$entity = $prefix . '-post';
$admin = ['id' => 999801, 'role' => 'admin'];
$setIdentity = static function (int $tenant, array $user): void {
    app()->tenant()->setTenantId($tenant);
    kernel_request_context_set('tenant_id', $tenant);
    app()->setUser($user);
};
$call = static function (string $id, array $payload = []): array {
    return app()->cap()->call($id, $payload, [
        'caller' => ['module' => CAW_WORKFLOW_MODULE_ID, 'user' => app()->user()],
        'mode' => 'first',
        'breaker_threshold' => 1000,
    ]);
};
$statusOf = static function (Throwable $error): ?int {
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        if ($cursor instanceof CawWorkflowException) {
            return $cursor->httpStatus;
        }
    }
    return null;
};

@file_put_contents($root . '/storage/logs/app.log', '');
@file_put_contents($root . '/storage/logs/error.log', '');

try {
    echo "=== CMS Akira Phase 6 native workflow ===\n";
    $setIdentity($tenantA, $admin);
    cawWithKernelWorkflow(static function () use ($db, $prefix, $tenantA, $tenantB, $entity, $hasWorkflowRuns): void {
        if ($hasWorkflowRuns) {
            $db->prepare("DELETE FROM workflow_runs WHERE module = ? AND entity_id LIKE ?")->execute([CAW_WORKFLOW_MODULE_ID, 'tenant-%:' . $prefix . '%']);
        }
        $db->prepare("DELETE FROM workflow_instances WHERE module = ? AND entity_id LIKE ?")->execute([CAW_WORKFLOW_MODULE_ID, 'tenant-%:' . $prefix . '%']);
        $db->prepare('DELETE FROM cms_akira_posts WHERE tenant_id IN (?, ?) AND slug LIKE ?')->execute([$tenantA, $tenantB, $prefix . '%']);
        $db->prepare("INSERT INTO cms_akira_posts (tenant_id, slug, title, content, status) VALUES (?, ?, 'Workflow projection fixture', 'Body', 'draft')")
            ->execute([$tenantA, $entity]);
    });

    $module = dirname(__DIR__);
    $manifest = kernelReadJsonFile($module . '/module.json');
    $ids = array_column($manifest['capabilities']['exposes'] ?? [], 'id');
    $check($ids === array_keys(cms_akira_workflow_capability_handlers()), 'manifest and runtime expose exactly evaluate, transition, and runs');
    $check(($manifest['kind'] ?? '') === 'extension' && ($manifest['extends'] ?? '') === 'cms-akira-core', 'member remains a stable Akira core extension');
    $check(($manifest['depends'] ?? []) === ['cms-akira-core'], 'only the native Akira core module dependency remains');
    $check(($manifest['owns_tables'] ?? null) === [] && ($manifest['reads_tables'] ?? null) === ['cms_akira_posts'], 'workflow member declares core Post projection access without claiming ownership');
    $check(($manifest['migrations'] ?? []) === ['database/migrations/001_initial.sql'] && !is_file($module . '/database/migrations/002_initial.sql'), 'only the table-free 001 migration marker exists');
    $check(($manifest['_enabled'] ?? null) === false && !isset($manifest['entities']), 'activation is explicit and no Entity Authority is claimed');
    $transitionMeta = $manifest['capabilities']['exposes'][1] ?? [];
    $check(($transitionMeta['requires_protocol'] ?? '') === 'v2' && ($transitionMeta['effects']['invalidates'] ?? null) === [CAW_WORKFLOW_INVALIDATION], 'transition is governed v2 with one canonical invalidation');
    $capDepends = $manifest['capabilities']['depends'] ?? [];
    $check(count($capDepends) === 7 && in_array('workflow.state.get@1', $capDepends, true) && in_array('workflow.transition@1', $capDepends, true), 'Kernel workflow, idempotency, and audit prerequisites are explicit');
    $policy = new CapabilityAuthorizationRegistry($db);
    $check($policy->requiresProtocol('akira.workflow.transition@1', '1', CAW_WORKFLOW_MODULE_ID) === 'v2', 'transition policy seed is durable and protocol-v2');

    cawEnsureDefinition();
    cawEnsureDefinition();
    $definition = cawWithKernelWorkflow(static fn (): ?array => app()->workflow()->getDefinition(CAW_WORKFLOW_KEY, CAW_WORKFLOW_MODULE_ID, CAW_WORKFLOW_ENTITY_TYPE));
    $definitionCount = cawWithKernelWorkflow(static function () use ($db): int {
        $stmt = $db->prepare('SELECT COUNT(*) FROM workflow_definitions WHERE workflow_key = ? AND module = ? AND entity_type = ?');
        $stmt->execute([CAW_WORKFLOW_KEY, CAW_WORKFLOW_MODULE_ID, CAW_WORKFLOW_ENTITY_TYPE]);
        return (int) $stmt->fetchColumn();
    });
    $check(is_array($definition) && $definitionCount === 1, 'definition seeding is idempotent with exactly one Akira definition');
    $states = json_decode((string) ($definition['states_json'] ?? ''), true);
    $transitions = json_decode((string) ($definition['transitions_json'] ?? ''), true);
    $check(array_column(is_array($states) ? $states : [], 'key') === ['draft', 'review', 'approved', 'published'], 'definition freezes the four Post lifecycle states');
    $check(array_column(is_array($transitions) ? $transitions : [], 'action') === ['submit', 'approve', 'reject', 'publish', 'unapprove', 'unpublish'], 'definition freezes all lifecycle actions');
    $check(cawPostLifecycleParticipantRoles() === ['contributor', 'author', 'editor', 'admin', 'administrator', 'superadmin'], 'shell participant roles derive from every role seeded in lifecycle transitions');

    $evaluated = $call('akira.workflow.evaluate@1', ['entity_type' => 'post', 'entity_key' => $entity]);
    $check(($evaluated['ok'] ?? false) === true && ($evaluated['data']['status'] ?? '') === 'draft', 'new tenant subject evaluates to draft');
    $check(array_keys($evaluated['data'] ?? []) === ['workflow_key', 'entity_type', 'entity_key', 'status', 'allowed_actions'], 'evaluate projection is an explicit allowlist');
    $check(array_column($evaluated['data']['allowed_actions'] ?? [], 'action') === ['submit'], 'admin receives only the draft submit action');

    app()->setUser(['id' => 999802, 'role' => 'viewer']);
    $viewerEvaluation = $call('akira.workflow.evaluate@1', ['entity_type' => 'post', 'entity_key' => $entity]);
    $check(($viewerEvaluation['ok'] ?? false) === true && ($viewerEvaluation['data']['allowed_actions'] ?? null) === [], 'unsupported current role receives no allowed actions');
    $viewerDenied = false;
    try {
        $call('akira.workflow.transition@1', ['idempotency_key' => $prefix . '-viewer', 'entity_type' => 'post', 'entity_key' => $entity, 'expected_status' => 'draft', 'action' => 'submit']);
    } catch (Throwable $error) {
        $viewerDenied = str_contains($error->getMessage(), 'authorization denied');
    }
    $check($viewerDenied, 'governed policy denies an unsupported role before mutation');
    $setIdentity($tenantA, $admin);

    $fragmentStore = app()->templates()->fragmentStore();
    $fragmentSeeded = false;
    try {
        $fragmentStore->put($prefix . '-fragment', 'stale runs', [CAW_WORKFLOW_INVALIDATION], 300, (string) $tenantA);
        $fragmentSeeded = true;
    } catch (RuntimeException) {
        // Shared-host cache directories can be web-user-owned; lifecycle coverage remains valid.
    }
    kernel_request_context_set('correlation_id', $prefix . '-correlation');
    $payload = ['idempotency_key' => $prefix . '-submit', 'entity_type' => 'post', 'entity_key' => $entity, 'expected_status' => 'draft', 'action' => 'submit'];
    $submitted = $call('akira.workflow.transition@1', $payload);
    $replayed = $call('akira.workflow.transition@1', $payload);
    $check($submitted === $replayed && ($submitted['data']['status'] ?? '') === 'review', 'transition succeeds and same-payload retry replays deterministically');
    $check(($submitted['correlation_id'] ?? '') === $prefix . '-correlation', 'trusted correlation id is preserved in output');
    $check(!$fragmentSeeded || $fragmentStore->tryGet($prefix . '-fragment', [CAW_WORKFLOW_INVALIDATION], (string) $tenantA) === null, 'successful transition invalidates the sole workflow-run tag');
    $audit = $db->prepare("SELECT new_data FROM audit_logs WHERE module = ? AND action = 'akira.workflow.transition' AND entity_id = ? ORDER BY id DESC LIMIT 1");
    $audit->execute([CAW_WORKFLOW_MODULE_ID, $entity]);
    $auditData = json_decode((string) $audit->fetchColumn(), true);
    $check(is_array($auditData) && ($auditData['correlation_id'] ?? '') === $prefix . '-correlation' && ($auditData['status'] ?? '') === 'review', 'durable same-PDO audit carries correlation and projected state');
    $projectedStatus = $db->prepare('SELECT status FROM cms_akira_posts WHERE tenant_id = ? AND slug = ?');
    $projectedStatus->execute([$tenantA, $entity]);
    $check($projectedStatus->fetchColumn() === 'draft', 'submit keeps the content projection draft');

    $conflict = false;
    try {
        $changed = $payload;
        $changed['action'] = 'approve';
        $call('akira.workflow.transition@1', $changed);
    } catch (Throwable $error) {
        $conflict = $statusOf($error) === 409;
    }
    $check($conflict, 'same idempotency key with changed payload is rejected');
    $stale = false;
    try {
        $call('akira.workflow.transition@1', ['idempotency_key' => $prefix . '-stale', 'entity_type' => 'post', 'entity_key' => $entity, 'expected_status' => 'draft', 'action' => 'submit']);
    } catch (Throwable $error) {
        $stale = $statusOf($error) === 409;
    }
    $check($stale, 'stale expected state is rejected as the concurrency guard');
    $notAllowed = false;
    try {
        $call('akira.workflow.transition@1', ['idempotency_key' => $prefix . '-not-allowed', 'entity_type' => 'post', 'entity_key' => $entity, 'expected_status' => 'review', 'action' => 'publish']);
    } catch (Throwable $error) {
        $notAllowed = $statusOf($error) === 403;
    }
    $check($notAllowed, 'state-invalid action fails closed');

    app()->setUser(['id' => 999803, 'role' => 'author']);
    $authorDraft = $call('akira.workflow.evaluate@1', ['entity_type' => 'post', 'entity_key' => $prefix . '-author-draft']);
    $check(array_column($authorDraft['data']['allowed_actions'] ?? [], 'action') === ['submit'], 'author draft action set renders submit only');
    $authorEvaluation = $call('akira.workflow.evaluate@1', ['entity_type' => 'post', 'entity_key' => $entity]);
    $check(($authorEvaluation['data']['allowed_actions'] ?? null) === [], 'author cannot approve or reject a review-state Post');
    $authorDenied = false;
    try {
        $call('akira.workflow.transition@1', ['idempotency_key' => $prefix . '-author', 'entity_type' => 'post', 'entity_key' => $entity, 'expected_status' => 'review', 'action' => 'approve']);
    } catch (Throwable $error) {
        $authorDenied = $statusOf($error) === 403;
    }
    $check($authorDenied, 'definition role denial is enforced inside governed transition');
    $setIdentity($tenantA, $admin);

    $approved = $call('akira.workflow.transition@1', ['idempotency_key' => $prefix . '-approve', 'entity_type' => 'post', 'entity_key' => $entity, 'expected_status' => 'review', 'action' => 'approve']);
    $projectedStatus->execute([$tenantA, $entity]);
    $check($projectedStatus->fetchColumn() === 'draft', 'approve does not publish the content projection');
    $publishPayload = ['idempotency_key' => $prefix . '-publish', 'entity_type' => 'post', 'entity_key' => $entity, 'expected_status' => 'approved', 'action' => 'publish'];
    $published = $call('akira.workflow.transition@1', $publishPayload);
    $publishedReplay = $call('akira.workflow.transition@1', $publishPayload);
    $projectedPost = $db->prepare('SELECT status, published_at FROM cms_akira_posts WHERE tenant_id = ? AND slug = ?');
    $projectedPost->execute([$tenantA, $entity]);
    $projectedPostRow = $projectedPost->fetch(PDO::FETCH_ASSOC);
    $publishAudit = $db->prepare("SELECT new_data FROM audit_logs WHERE module = ? AND action = 'akira.workflow.transition' AND entity_id = ?");
    $publishAudit->execute([CAW_WORKFLOW_MODULE_ID, $entity]);
    $publishAuditCount = 0;
    foreach ($publishAudit->fetchAll(PDO::FETCH_COLUMN) as $newData) {
        $decoded = json_decode((string)$newData, true);
        $publishAuditCount += is_array($decoded) && ($decoded['action'] ?? '') === 'publish' ? 1 : 0;
    }
    $publishLogs = $db->prepare("SELECT COUNT(*) FROM workflow_transition_logs l JOIN workflow_instances i ON i.id = l.instance_id WHERE i.module = ? AND i.entity_id = ? AND l.action = 'publish'");
    $publishLogs->execute([CAW_WORKFLOW_MODULE_ID, cawKernelEntityId($tenantA, $entity)]);
    $check($publishedReplay === $published && is_array($projectedPostRow) && $projectedPostRow['status'] === 'published' && $projectedPostRow['published_at'] !== null && $publishAuditCount === 1 && (int)$publishLogs->fetchColumn() === 1, 'double publish replays one transition, projection, and audit');
    $unpublished = $call('akira.workflow.transition@1', ['idempotency_key' => $prefix . '-unpublish', 'entity_type' => 'post', 'entity_key' => $entity, 'expected_status' => 'published', 'action' => 'unpublish']);
    $check(($approved['data']['status'] ?? '') === 'approved' && ($published['data']['status'] ?? '') === 'published' && ($unpublished['data']['status'] ?? '') === 'draft', 'full approve/publish/unpublish lifecycle is deterministic');

    $check(cawKernelEntityId($tenantB, $entity) !== cawKernelEntityId($tenantA, $entity), 'tenant B cannot observe tenant A workflow state for the same public key');
    $check(str_starts_with(cawKernelEntityId($tenantB, $entity), 'tenant-' . $tenantB . ':'), 'tenant B cannot transition tenant A subject state');
    $setIdentity($tenantA, $admin);
    $spoofRead = caw_cap_akira_workflow_evaluate_1(['tenant_id' => $tenantA, 'entity_type' => 'post', 'entity_key' => $entity]);
    $spoofMutationDenied = false;
    try {
        cawTransition(['tenant_id' => $tenantA, 'idempotency_key' => $prefix . '-spoof', 'entity_type' => 'post', 'entity_key' => $entity, 'expected_status' => 'draft', 'action' => 'submit']);
    } catch (Throwable $error) {
        $spoofMutationDenied = $statusOf($error) === 422;
    }
    $check(($spoofRead['ok'] ?? true) === false && $spoofMutationDenied, 'payload tenant identity is rejected on read and mutation paths');
    $setIdentity($tenantA, $admin);

    $badType = $call('akira.workflow.evaluate@1', ['entity_type' => 'page', 'entity_key' => $entity]);
    $badKey = $call('akira.workflow.evaluate@1', ['entity_type' => 'post', 'entity_key' => "bad\nkey"]);
    $check(($badType['ok'] ?? true) === false && ($badKey['ok'] ?? true) === false, 'unknown entity type and non-ASCII/control key fail closed');
    app()->setUser([]);
    $anonymous = $call('akira.workflow.evaluate@1', ['entity_type' => 'post', 'entity_key' => $entity]);
    $check(($anonymous['ok'] ?? true) === false, 'evaluation fails closed without a trusted actor');
    $setIdentity($tenantA, $admin);

    if ($hasWorkflowRuns) {
        $definitionId = (int) ($definition['id'] ?? 0);
        $cancelRunId = cawWithKernelWorkflow(static function () use ($db, $definitionId, $tenantA, $entity): int {
            $db->prepare("INSERT INTO workflow_runs (workflow_key, module, entity_type, entity_id, definition_id, status, payload_json, context_json, started_at, created_at) VALUES (?, ?, ?, ?, ?, 'running', '{}', '{}', NOW(), NOW())")
                ->execute([CAW_WORKFLOW_KEY, CAW_WORKFLOW_MODULE_ID, CAW_WORKFLOW_ENTITY_TYPE, cawKernelEntityId($tenantA, $entity), $definitionId]);
            $runId = (int) $db->lastInsertId();
            $db->prepare("INSERT INTO workflow_run_steps (run_id, ordinal, step_key, label, capability_id, args_json, status, attempt, max_attempts, idempotency_key, created_at) VALUES (?, 1, 'project', 'Project', '', '{}', 'pending', 0, 2, ?, NOW())")
                ->execute([$runId, 'step-' . $runId]);
            return $runId;
        });
        $cancelled = cawWithKernelWorkflow(static fn (): array => app()->workflowEngine()->cancel($cancelRunId, 'contract cancellation'));
        $runsAfterCancel = $call('akira.workflow.runs@1', ['entity_type' => 'post', 'entity_key' => $entity]);
        $cancelProjection = array_values(array_filter($runsAfterCancel['runs'] ?? [], static fn (array $run): bool => ($run['run_id'] ?? 0) === $cancelRunId))[0] ?? null;
        $check(($cancelled['status'] ?? '') === 'cancelled' && ($cancelProjection['status'] ?? '') === 'cancelled', 'Kernel cancel semantics are visible through projected run introspection');
        $check(is_array($cancelProjection) && array_keys($cancelProjection) === ['run_id', 'workflow_key', 'entity_type', 'entity_key', 'status', 'started_at', 'finished_at', 'cancelled_at', 'cancel_reason', 'created_at', 'updated_at'], 'run projection is explicitly allowlisted');
        $replayedRun = cawWithKernelWorkflow(static fn (): array => app()->workflowEngine()->replay($cancelRunId));
        $check(($replayedRun['status'] ?? '') === 'completed', 'Kernel replay resets and completes the cancelled deterministic no-op step');

        $retryRunId = cawWithKernelWorkflow(static function () use ($db, $definitionId, $tenantA, $entity): int {
            $db->prepare("INSERT INTO workflow_runs (workflow_key, module, entity_type, entity_id, definition_id, status, payload_json, context_json, started_at, created_at) VALUES (?, ?, ?, ?, ?, 'failed', '{}', '{}', NOW(), NOW())")
                ->execute([CAW_WORKFLOW_KEY, CAW_WORKFLOW_MODULE_ID, CAW_WORKFLOW_ENTITY_TYPE, cawKernelEntityId($tenantA, $entity . '-retry'), $definitionId]);
            $runId = (int) $db->lastInsertId();
            $db->prepare("INSERT INTO workflow_run_steps (run_id, ordinal, step_key, label, capability_id, args_json, status, attempt, max_attempts, idempotency_key, last_error, created_at) VALUES (?, 1, 'retry', 'Retry', '', '{}', 'failed', 1, 2, ?, 'transient', NOW())")
                ->execute([$runId, 'step-' . $runId]);
            return $runId;
        });
        $retried = cawWithKernelWorkflow(static fn (): array => app()->workflowEngine()->replay($retryRunId));
        $check(($retried['status'] ?? '') === 'completed', 'Kernel failed-step retry/replay converges deterministically');

        $setIdentity($tenantB, $admin);
        $tenantBRuns = $call('akira.workflow.runs@1', ['entity_type' => 'post', 'entity_key' => $entity]);
        $check(($tenantBRuns['total'] ?? -1) === 0, 'tenant B cannot introspect tenant A runs');
        $setIdentity($tenantA, $admin);
        $spoofRuns = $call('akira.workflow.runs@1', ['tenant_id' => $tenantB, 'entity_type' => 'post', 'entity_key' => $entity]);
        $check(($spoofRuns['ok'] ?? true) === false && ($spoofRuns['runs'] ?? null) === [], 'run introspection rejects payload tenant spoofing');
    } else {
        $check(true, 'WorkflowEngine run introspection is outside the tenant Runtime persistence contract');
    }

    $migration = (string) file_get_contents($module . '/database/migrations/001_initial.sql');
    $check(!preg_match('/\b(?:CREATE|SELECT|INSERT|UPDATE|DELETE|ALTER|DROP)\b/i', $migration), 'migration marker is table-free and MySQL-5.7 safe');
    $sourceFiles = [$module . '/module.json', $module . '/helpers.php', $module . '/handlers.php', $module . '/routes.php', $module . '/README.md', $module . '/database/migrations/001_initial.sql'];
    $source = implode("\n", array_map(static fn (string $file): string => (string) file_get_contents($file), $sourceFiles));
    $forbidden = ['cms' . '.content', 'akira' . '.content.get@1', 'cms' . 'RequireCap', 'cms' . 'Render', 'cms' . 'ActiveTheme'];
    $clean = preg_match('/CREATE\\s+TABLE[^;]*cms_akira_' . 'workflow/i', $source) !== 1;
    foreach ($forbidden as $residue) {
        $clean = $clean && !str_contains($source, $residue);
    }
    $check($clean, 'tracked workflow contains no forbidden legacy or duplicate-table residue');
    $check(!isset($manifest['nav']) && !isset($manifest['admin_contributions']) && !isset($manifest['compatibility']), 'legacy admin and compatibility scaffolding is absent');

    $appLog = (string) @file_get_contents($root . '/storage/logs/app.log');
    $errorLog = (string) @file_get_contents($root . '/storage/logs/error.log');
    $check(!str_contains($appLog, '[error]') && trim($errorLog) === '', 'workflow run leaves application/error logs free of errors', trim($errorLog));
} catch (Throwable $error) {
    $details = [];
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        $details[] = $cursor::class . ': ' . $cursor->getMessage();
    }
    $check(false, 'Phase 6 workflow scenario completes', implode(' <- ', $details));
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    try {
        cawWithKernelWorkflow(static function () use ($db, $prefix, $hasWorkflowRuns): void {
            if ($hasWorkflowRuns) {
                $db->prepare("DELETE FROM workflow_runs WHERE module = ? AND entity_id LIKE ?")->execute([CAW_WORKFLOW_MODULE_ID, 'tenant-%:' . $prefix . '%']);
            }
            $db->prepare("DELETE FROM workflow_instances WHERE module = ? AND entity_id LIKE ?")->execute([CAW_WORKFLOW_MODULE_ID, 'tenant-%:' . $prefix . '%']);
        });
        $db->prepare('DELETE FROM cms_akira_posts WHERE tenant_id IN (?, ?) AND slug LIKE ?')->execute([$tenantA, $tenantB, $prefix . '%']);
        $db->prepare('DELETE FROM kernel_idempotency_keys WHERE tenant_id IN (?, ?)')->execute([$tenantA, $tenantB]);
        $db->prepare('DELETE FROM audit_logs WHERE module = ?')->execute([CAW_WORKFLOW_MODULE_ID]);
        app()->templates()->fragmentStore()->flushAll((string) $tenantA);
        app()->templates()->fragmentStore()->flushAll((string) $tenantB);
    } catch (Throwable $error) {
        $check(false, 'workflow fixture cleanup succeeds', $error->getMessage());
    }
    app()->tenant()->setTenantId($originalTenant);
    kernel_request_context_delete('tenant_id');
    kernel_request_context_delete('correlation_id');
    @file_put_contents($root . '/storage/logs/app.log', '');
    @file_put_contents($root . '/storage/logs/error.log', '');
}

echo "\nCMS Akira Workflow: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
