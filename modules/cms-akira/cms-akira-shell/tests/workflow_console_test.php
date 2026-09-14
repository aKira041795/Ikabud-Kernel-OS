<?php

/**
 * CMS Akira P3.2 workflow / approvals console contract.
 *
 * Proves the console reads real workflow-run state through the owning
 * capability (never a parallel model or SQL in the shell) and that its
 * mutation path refuses a role outside the governance policy. Runs against
 * the isolated base store with a synthetic tenant; it never binds a live
 * tenant database.
 */

declare(strict_types=1);

use Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry;

$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
require_once $root . '/tests/_support/env_guard.php';
require_once $root . '/tests/_support/tenant_fixture.php';
require_once dirname(__DIR__, 2) . '/cms-akira-workflow/helpers.php';
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/handlers.php';

requireNotLiveTenantDatabase();

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$pass, &$fail): void {
    $ok ? ++$pass : ++$fail;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

$tenantId = 0;
for ($attempt = 0; $attempt < 40 && $tenantId === 0; $attempt++) {
    $candidate = random_int(8000000, 8999999);
    $exists = app()->controlDb()->prepare('SELECT 1 FROM kernel_tenants WHERE id = ?');
    $exists->execute([$candidate]);
    if ($exists->fetchColumn() === false) {
        $tenantId = $candidate;
    }
}
if ($tenantId === 0) {
    testEnvironmentSkip('could not allocate an unused tenant fixture id for the workflow console');
}

$originalTenant = (int) app()->tenant()->current();
$originalUser = app()->user();
$key = 'workflow-console-' . bin2hex(random_bytes(5));
$kernelEntityId = cawKernelEntityId($tenantId, $key);
$runId = 0;
$policyVersion = random_int(900000, 999999);
$seededPolicy = false;
$admin = ['id' => 994701, 'role' => 'administrator'];
$viewer = ['id' => 994702, 'role' => 'viewer'];
$db = null;

try {
    echo "=== CMS Akira P3.2 workflow / approvals console ===\n";

    ensureTestTenant($tenantId, CAW_WORKFLOW_MODULE_ID);
    $db = app()->db();

    // ── Read path: real workflow-run state ───────────────────────────────
    app()->tenant()->setTenantId($tenantId);
    kernel_request_context_set('tenant_id', $tenantId);
    tenantSetModuleActivationState($db, $tenantId, [CAW_WORKFLOW_MODULE_ID], true, 'workflow-console-' . $tenantId);
    invalidateTenantModuleSettingsCache();
    app()->setUser($admin);

    $db->prepare(
        "INSERT INTO workflow_runs (workflow_key, module, entity_type, entity_id, status, payload_json, context_json, started_at, created_at) "
        . "VALUES (?, ?, 'post', ?, 'running', '{}', '{}', NOW(), NOW())"
    )->execute([CAW_WORKFLOW_KEY, CAW_WORKFLOW_MODULE_ID, $kernelEntityId]);
    $runId = (int) $db->lastInsertId();

    $read = caw_cap_akira_workflow_runs_1(['entity_type' => 'post', 'entity_key' => $key]);
    $projectedRuns = is_array($read['runs'] ?? null) ? $read['runs'] : [];
    $projected = null;
    foreach ($projectedRuns as $candidate) {
        if (is_array($candidate) && (int) ($candidate['run_id'] ?? 0) === $runId) {
            $projected = $candidate;
            break;
        }
    }
    $check(($read['ok'] ?? false) === true && (int) ($read['total'] ?? 0) === 1 && is_array($projected), 'run read returns the real store row for the synthetic subject', json_encode($read));
    $check(is_array($projected) && ($projected['status'] ?? '') === 'running' && ($projected['workflow_key'] ?? '') === CAW_WORKFLOW_KEY, 'run read projects the real status and workflow key');
    $check(is_array($projected) && array_keys($projected) === ['run_id', 'workflow_key', 'entity_type', 'entity_key', 'status', 'started_at', 'finished_at', 'cancelled_at', 'cancel_reason', 'created_at', 'updated_at'], 'run projection remains the explicit allowlist');

    // ── Console render: real capability data, empty state ────────────────
    $console = [
        'rows' => [[
            'slug' => $key,
            'title' => 'Console read fixture',
            'status' => 'draft',
            'allowed_actions' => [['action' => 'submit', 'to' => 'review', 'label' => 'Submit for review']],
            'runs' => $projectedRuns,
            'runs_error' => '',
        ]],
        'run_total' => count($projectedRuns),
        'post_total' => 1,
        'runs_available' => true,
        'error' => '',
    ];
    $html = akiraShellWorkflowConsoleHtml($console);
    $check(str_contains($html, 'data-akira-workflow-row="' . $key . '"'), 'console renders the post row from capability output');
    $check(str_contains($html, 'data-akira-workflow-run="' . $runId . '"') && str_contains($html, 'running'), 'console renders the real run id and status returned by akira.workflow.runs@1');
    $check(str_contains($html, '/cms-akira-shell/workflow/' . $key . '/transition') && str_contains($html, 'Submit for review'), 'console renders the governed transition form for an allowed action');
    $check(str_contains($html, '1 workflow run recorded'), 'console states the observed run population honestly');

    $empty = akiraShellWorkflowConsoleHtml([
        'rows' => [], 'run_total' => 0, 'post_total' => 0, 'runs_available' => true, 'error' => '',
    ]);
    $check(str_contains($empty, 'data-akira-workflow-empty') && str_contains($empty, '0 workflow runs recorded'), 'console renders an explicit empty state when there are no posts or runs');

    $emptyRuns = akiraShellWorkflowConsoleHtml([
        'rows' => [[
            'slug' => $key, 'title' => 'Console read fixture', 'status' => 'draft',
            'allowed_actions' => [], 'runs' => [], 'runs_error' => '',
        ]],
        'run_total' => 0, 'post_total' => 1, 'runs_available' => true, 'error' => '',
    ]);
    $check(str_contains($emptyRuns, 'data-akira-workflow-run-empty') && str_contains($emptyRuns, 'No runs recorded'), 'console labels a run-less subject explicitly');

    // ── Governance policy: role and caller allowlists ────────────────────
    (new CapabilityAuthorizationRegistry($db))->seedPolicy([
        [
            'policy_version' => $policyVersion, 'capability_id' => 'akira.workflow.runs@1', 'capability_version' => '1',
            'provider' => CAW_WORKFLOW_MODULE_ID, 'caller_module' => CAW_WORKFLOW_MODULE_ID . ',cms-akira-shell',
            'allowed_roles' => 'admin,administrator,superadmin', 'provider_activation_required' => true,
            'requires_protocol' => 'v1', 'is_active' => true,
        ],
        [
            'policy_version' => $policyVersion, 'capability_id' => 'akira.workflow.transition@1', 'capability_version' => '1',
            'provider' => CAW_WORKFLOW_MODULE_ID, 'caller_module' => CAW_WORKFLOW_MODULE_ID . ',cms-akira-shell',
            'allowed_roles' => 'contributor,author,editor,admin,administrator,superadmin', 'provider_activation_required' => true,
            'requires_protocol' => 'v2', 'is_active' => true,
        ],
    ]);
    $seededPolicy = true;
    CapabilityAuthorizationRegistry::invalidate();

    $registry = new CapabilityAuthorizationRegistry($db);
    $base = [
        'capability_version' => '1', 'provider' => CAW_WORKFLOW_MODULE_ID,
        'provider_activation' => true, 'dispatch_protocol' => 'v2', 'policy_version' => $policyVersion,
        'tenant_id' => (string) $tenantId,
    ];
    $runsAdmin = $registry->authorize($base + [
        'capability_id' => 'akira.workflow.runs@1', 'caller_module' => 'cms-akira-shell', 'actor_role' => 'administrator',
    ]);
    $runsViewer = $registry->authorize($base + [
        'capability_id' => 'akira.workflow.runs@1', 'caller_module' => 'cms-akira-shell', 'actor_role' => 'viewer',
    ]);
    $check(($runsAdmin['allowed'] ?? false) === true, 'runs policy admits the administrator role');
    $check(($runsViewer['allowed'] ?? true) === false && ($runsViewer['reason'] ?? '') === 'role_not_allowed', 'runs policy fails closed for an unauthorized role');
    $policyRow = $db->prepare(
        'SELECT caller_module FROM capability_authorization_policies WHERE policy_version = ? AND capability_id = ? AND provider = ? AND is_active = 1'
    );
    $policyRow->execute([$policyVersion, 'akira.workflow.runs@1', CAW_WORKFLOW_MODULE_ID]);
    $callers = array_map('trim', explode(',', (string) $policyRow->fetchColumn()));
    $check(in_array('cms-akira-shell', $callers, true) && !in_array('cms-akira-other', $callers, true), 'runs policy caller allowlist names the shell and excludes undeclared callers');

    $transitionViewer = $registry->authorize($base + [
        'capability_id' => 'akira.workflow.transition@1', 'caller_module' => 'cms-akira-shell', 'actor_role' => 'viewer',
    ]);
    $transitionAuthor = $registry->authorize($base + [
        'capability_id' => 'akira.workflow.transition@1', 'caller_module' => 'cms-akira-shell', 'actor_role' => 'author',
    ]);
    $check(($transitionViewer['allowed'] ?? true) === false, 'transition policy fails closed for an unauthorized role');
    $check(($transitionAuthor['allowed'] ?? false) === true, 'transition policy admits the declared participant role the definition can narrow further');

    // ── Console mutation gate: fail closed before the capability ─────────
    app()->setUser($viewer);
    $check(akiraShellParticipant() === [], 'console local gate classifies a non-participant as unauthorized');
    $handlersSource = (string) file_get_contents(dirname(__DIR__) . '/handlers.php');
    $gateBody = explode('function akiraShellWorkflowConsoleTransition', $handlersSource, 2)[1] ?? '';
    $gateBody = explode('akiraShellCall(\'akira.workflow.transition@1\'', $gateBody, 2)[0] ?? '';
    $check(str_contains($gateBody, 'akiraShellAuthorize()') && !str_contains($gateBody, 'akiraShellCall('), 'console mutation handler gates on the participant authority before any capability dispatch');
    app()->setUser($admin);

    // ── Declaration test: every new POST route is declared ───────────────
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__) . '/module.json'), true);
    $routes = require dirname(__DIR__) . '/routes.php';
    $check(
        ($manifest['capabilities']['routes']['POST /cms-akira-shell/workflow/{entity_key}/transition'] ?? '') === 'akira.workflow.transition@1'
        && ($routes['POST']['/cms-akira-shell/workflow/{entity_key}/transition'] ?? '') === 'cms-akira-shell:akiraShellWorkflowConsoleTransition',
        'console transition POST route is declared in capabilities.routes and mounted'
    );
    $check(
        ($manifest['capabilities']['routes']['GET /cms-akira-shell/workflow'] ?? '') === 'akira.workflow.runs@1'
        && in_array('akira.workflow.runs@1', $manifest['capabilities']['depends'] ?? [], true),
        'console read route declares and depends on akira.workflow.runs@1'
    );
    $check(
        str_contains($handlersSource, 'akiraShellAuthorize()') && str_contains($handlersSource, "akiraShellCall('akira.workflow.transition@1'"),
        'console transition handler gates locally and dispatches the same declared capability'
    );
    $helpersSource = (string) file_get_contents(dirname(__DIR__) . '/helpers.php');
    $check(
        !str_contains($helpersSource . $handlersSource, 'cms_akira_posts') && !str_contains($helpersSource . $handlersSource, 'SELECT '),
        'shell console stays table-free and SQL-free'
    );
} catch (Throwable $error) {
    $details = [];
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        $details[] = $cursor::class . ': ' . $cursor->getMessage();
    }
    $check(false, 'workflow console scenario completes', implode(' <- ', $details));
} finally {
    if (is_object($db) && method_exists($db, 'inTransaction') && $db->inTransaction()) {
        $db->rollBack();
    }
    try {
        if ($db instanceof PDO && $runId > 0) {
            $db->prepare('DELETE FROM workflow_runs WHERE id = ?')->execute([$runId]);
        }
        if ($db instanceof PDO && $seededPolicy) {
            $db->prepare(
                "DELETE FROM capability_authorization_policies WHERE policy_version = ? AND provider = ? AND capability_id IN ('akira.workflow.runs@1', 'akira.workflow.transition@1')"
            )->execute([$policyVersion, CAW_WORKFLOW_MODULE_ID]);
            CapabilityAuthorizationRegistry::invalidate();
        }
        if ($db instanceof PDO) {
            $db->prepare('DELETE FROM workflow_instances WHERE module = ? AND entity_id = ?')->execute([CAW_WORKFLOW_MODULE_ID, $kernelEntityId]);
        }
    } catch (Throwable $cleanupError) {
        $check(false, 'workflow console fixture cleanup succeeds', $cleanupError->getMessage());
    }
    cleanupTestTenant($tenantId);
    app()->tenant()->setTenantId($originalTenant);
    kernel_request_context_delete('tenant_id');
    if (is_array($originalUser)) {
        app()->setUser($originalUser);
    }
}

echo "\nCMS Akira workflow console: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
