<?php

/**
 * CMS Akira P2.4 search operations console contract.
 *
 * Proves the console reads the real tenant index through the owning
 * akira.search.query@1 capability (never a parallel model or SQL in the shell)
 * and that its rebuild path refuses a role outside the governance policy. Runs
 * against the isolated base store with a synthetic tenant; it never binds a
 * live tenant database.
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
require_once dirname(__DIR__, 2) . '/cms-akira-core/helpers.php';
require_once dirname(__DIR__, 2) . '/cms-akira-search/helpers.php';
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/handlers.php';

requireNotLiveTenantDatabase();
// The real-state read and rebuild path need the member-owned tables. CI migrates
// them; a bare kernel checkout skips rather than reporting a fabricated pass.
requireDatabaseTables(app()->db(), ['cms_akira_search_documents', 'cms_akira_posts']);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$pass, &$fail): void {
    $ok ? ++$pass : ++$fail;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

$tenantId = 0;
for ($attempt = 0; $attempt < 40 && $tenantId === 0; $attempt++) {
    $candidate = random_int(8100000, 8199999);
    $exists = app()->controlDb()->prepare('SELECT 1 FROM kernel_tenants WHERE id = ?');
    $exists->execute([$candidate]);
    if ($exists->fetchColumn() === false) {
        $tenantId = $candidate;
    }
}
if ($tenantId === 0) {
    testEnvironmentSkip('could not allocate an unused tenant fixture id for the search console');
}

$originalTenant = (int) app()->tenant()->current();
$originalUser = app()->user();
$prefix = 'search-console-' . bin2hex(random_bytes(5));
$policyVersion = random_int(900000, 999999);
$seededPolicy = false;
$admin = ['id' => 994601, 'role' => 'administrator'];
$viewer = ['id' => 994602, 'role' => 'viewer'];
$db = null;

try {
    echo "=== CMS Akira P2.4 search operations console ===\n";

    ensureTestTenant($tenantId, 'cms-akira-core');
    ensureTestTenant($tenantId, CAS_SEARCH_MODULE_ID);
    $db = app()->db();

    // Register the real search and core handlers on the in-process bus so the
    // console helper exercises the same capability path the HTTP handler does.
    $registry = app()->capabilities();
    $register = static function (string $moduleId, array $handlers, array $mutations = [], string $invalidation = '') use ($registry): void {
        foreach ($handlers as $id => $handler) {
            if ($registry->has($id)) {
                continue;
            }
            $meta = in_array($id, $mutations, true)
                ? ['requires_protocol' => 'v2', 'effects' => ['invalidates' => [$invalidation]]]
                : [];
            $registry->register($id, $moduleId, static function (mixed $payload, string $capabilityId = '', string $provider = '') use ($moduleId, $handler): mixed {
                return moduleWithContext($moduleId, static fn (): mixed => $handler($payload, $capabilityId, $provider));
            }, 50, ['first'], $meta);
        }
    };
    $register('cms-akira-core', cms_akira_core_capability_handlers(), [
        'akira.post.create@1', 'akira.post.update@1', 'akira.post.publish@1', 'akira.post.unpublish@1', 'akira.post.delete@1',
    ], 'entity.list.post');
    $register(CAS_SEARCH_MODULE_ID, cms_akira_search_capability_handlers(), [
        'akira.search.upsert@1', 'akira.search.delete@1', 'akira.search.rebuild@1',
    ], CAS_SEARCH_INVALIDATION);
    app()->entityAuthority()->registerAuthority('post', 'cms-akira-core', ['authority' => true]);

    app()->tenant()->setTenantId($tenantId);
    kernel_request_context_set('tenant_id', $tenantId);
    app()->setUser($admin);

    // Own the active policy version so the governed bus calls below are decided
    // by this fixture's role/caller allowlists, not ambient rows.
    (new CapabilityAuthorizationRegistry($db))->seedPolicy([
        [
            'policy_version' => $policyVersion, 'capability_id' => 'akira.search.upsert@1', 'capability_version' => '1',
            'provider' => CAS_SEARCH_MODULE_ID, 'caller_module' => CAS_SEARCH_MODULE_ID . ',cms-akira-shell',
            'allowed_roles' => 'admin,administrator,superadmin', 'provider_activation_required' => true,
            'requires_protocol' => 'v2', 'is_active' => true,
        ],
        [
            'policy_version' => $policyVersion, 'capability_id' => 'akira.search.query@1', 'capability_version' => '1',
            'provider' => CAS_SEARCH_MODULE_ID, 'caller_module' => CAS_SEARCH_MODULE_ID . ',cms-akira-shell',
            'allowed_roles' => 'admin,administrator,superadmin', 'provider_activation_required' => true,
            'requires_protocol' => 'v1', 'is_active' => true,
        ],
        [
            'policy_version' => $policyVersion, 'capability_id' => 'akira.search.rebuild@1', 'capability_version' => '1',
            'provider' => CAS_SEARCH_MODULE_ID, 'caller_module' => CAS_SEARCH_MODULE_ID . ',cms-akira-shell',
            'allowed_roles' => 'admin,administrator,superadmin', 'provider_activation_required' => true,
            'requires_protocol' => 'v2', 'is_active' => true,
        ],
    ]);
    $seededPolicy = true;
    CapabilityAuthorizationRegistry::invalidate();

    // ── Read path: real indexed state through akira.search.query@1 ────────
    $db->prepare('DELETE FROM cms_akira_search_documents WHERE tenant_id = ?')->execute([$tenantId]);
    $db->prepare('DELETE FROM cms_akira_posts WHERE tenant_id = ?')->execute([$tenantId]);

    $upsert = app()->cap()->call('akira.search.upsert@1', [
        'idempotency_key' => $prefix . '-upsert',
        'document' => [
            'entity_type' => 'post',
            'document_key' => 'console-read-fixture',
            'fields' => [
                'title' => 'Console Native Fixture',
                'body' => 'Console searchable body',
                'summary' => 'Console summary',
                'status' => 'published',
                'meta' => ['url' => '/posts/console-read-fixture'],
            ],
        ],
    ], ['caller' => ['module' => CAS_SEARCH_MODULE_ID, 'user' => $admin], 'mode' => 'first', 'breaker_threshold' => 1000]);
    $check(($upsert['ok'] ?? false) === true, 'governed upsert places a real document in the tenant index', json_encode($upsert));

    $console = akiraShellSearchData('Console Native', 1);
    $rows = is_array($console['results']['rows'] ?? null) ? $console['results']['rows'] : [];
    $check(($console['population_ok'] ?? false) === true && (int) ($console['indexed_total'] ?? 0) === 1, 'console population read returns the real index count through the capability');
    $check(($console['results']['ok'] ?? false) === true && count($rows) === 1 && ($rows[0]['document_key'] ?? '') === 'console-read-fixture' && ($rows[0]['title'] ?? '') === 'Console Native Fixture', 'console term query returns the real indexed document', json_encode($console));

    $html = akiraShellSearchHtml($console);
    $check(str_contains($html, 'data-akira-search-row="console-read-fixture"') && str_contains($html, 'Console Native Fixture'), 'console renders the real document returned by akira.search.query@1');
    $check(str_contains($html, '1 document indexed'), 'console states the observed index population honestly');
    $check(str_contains($html, '/cms-akira-shell/search/rebuild') && str_contains($html, 'Rebuild index now'), 'console exposes an explicit rebuild form');
    $check(!str_contains($html, 'cms_akira_search_documents'), 'console output never names or queries the member table');

    $empty = akiraShellSearchHtml([
        'indexed_total' => 0, 'population_ok' => true, 'population_error' => '', 'term' => '', 'page' => 1, 'results' => null,
    ]);
    $check(str_contains($empty, 'data-akira-search-empty') && str_contains($empty, '0 documents indexed'), 'console renders an explicit empty state for an empty index');

    $noResults = akiraShellSearchHtml([
        'indexed_total' => 1, 'population_ok' => true, 'population_error' => '', 'term' => 'no-such-document', 'page' => 1,
        'results' => ['ok' => true, 'rows' => [], 'total' => 0],
    ]);
    $check(str_contains($noResults, 'data-akira-search-no-results'), 'console distinguishes a no-match query from an empty index');

    // ── Rebuild path: real mutation through akira.search.rebuild@1 ────────
    $db->prepare("INSERT INTO cms_akira_posts (tenant_id, slug, title, subtitle, content, status, published_at) VALUES (?, 'console-rebuild-post', 'Console Rebuild Post', 'Rebuild summary', 'Rebuild searchable content', 'published', NOW())")
        ->execute([$tenantId]);
    $rebuilt = app()->cap()->call('akira.search.rebuild@1', ['idempotency_key' => $prefix . '-rebuild'], [
        'caller' => ['module' => 'cms-akira-shell', 'user' => $admin], 'mode' => 'first', 'breaker_threshold' => 1000,
    ]);
    $after = akiraShellSearchData('Console Rebuild Post', 1);
    $check(($rebuilt['ok'] ?? false) === true && (int) ($rebuilt['data']['indexed'] ?? 0) === 1, 'rebuild reports the real indexed count through akira.search.rebuild@1', json_encode($rebuilt));
    $check((int) ($after['indexed_total'] ?? 0) === 1 && count($after['results']['rows'] ?? []) === 1, 'rebuild replaces the current-tenant index and a re-read sees the new document');

    // Same idempotency key must replay, not double-apply.
    $replay = app()->cap()->call('akira.search.rebuild@1', ['idempotency_key' => $prefix . '-rebuild'], [
        'caller' => ['module' => 'cms-akira-shell', 'user' => $admin], 'mode' => 'first', 'breaker_threshold' => 1000,
    ]);
    $check($replay === $rebuilt && (int) ($after['indexed_total'] ?? 0) === 1, 'identical rebuild under one idempotency key replays without double-applying');

    // ── Governance policy: role and caller allowlists ────────────────────
    $policy = new CapabilityAuthorizationRegistry($db);
    $queryBase = [
        'capability_version' => '1', 'provider' => CAS_SEARCH_MODULE_ID, 'provider_activation' => true,
        'dispatch_protocol' => 'v1', 'policy_version' => $policyVersion, 'tenant_id' => (string) $tenantId,
    ];
    $rebuildBase = $queryBase;
    $rebuildBase['dispatch_protocol'] = 'v2';
    $queryAdmin = $policy->authorize($queryBase + ['capability_id' => 'akira.search.query@1', 'caller_module' => 'cms-akira-shell', 'actor_role' => 'administrator']);
    $queryViewer = $policy->authorize($queryBase + ['capability_id' => 'akira.search.query@1', 'caller_module' => 'cms-akira-shell', 'actor_role' => 'viewer']);
    $queryOtherCaller = $policy->authorize($queryBase + ['capability_id' => 'akira.search.query@1', 'caller_module' => 'cms-akira-other', 'actor_role' => 'administrator']);
    $check(($queryAdmin['allowed'] ?? false) === true, 'query policy admits the administrator role');
    $check(($queryViewer['allowed'] ?? true) === false && ($queryViewer['reason'] ?? '') === 'role_not_allowed', 'query policy fails closed for an unauthorized role');
    $check(($queryOtherCaller['allowed'] ?? true) === false && ($queryOtherCaller['reason'] ?? '') === 'disabled_caller', 'query policy fails closed for an undeclared caller');

    $rebuildViewer = $policy->authorize($rebuildBase + ['capability_id' => 'akira.search.rebuild@1', 'caller_module' => 'cms-akira-shell', 'actor_role' => 'viewer']);
    $rebuildAdmin = $policy->authorize($rebuildBase + ['capability_id' => 'akira.search.rebuild@1', 'caller_module' => 'cms-akira-shell', 'actor_role' => 'administrator']);
    $check(($rebuildViewer['allowed'] ?? true) === false && ($rebuildViewer['reason'] ?? '') === 'role_not_allowed', 'rebuild policy refuses an unauthorized role fail-closed');
    $check(($rebuildAdmin['allowed'] ?? false) === true && ($rebuildAdmin['reason'] ?? '') === 'allowed', 'rebuild policy admits the administrator role the console renders for');

    $policyRow = $db->prepare(
        'SELECT caller_module FROM capability_authorization_policies WHERE policy_version = ? AND capability_id = ? AND provider = ? AND is_active = 1'
    );
    $policyRow->execute([$policyVersion, 'akira.search.query@1', CAS_SEARCH_MODULE_ID]);
    $callers = array_map('trim', explode(',', (string) $policyRow->fetchColumn()));
    $check(in_array('cms-akira-shell', $callers, true) && !in_array('cms-akira-other', $callers, true), 'query policy caller allowlist names the shell and excludes undeclared callers');

    // ── Console mutation gate: fail closed before the capability ─────────
    $handlersSource = (string) file_get_contents(dirname(__DIR__) . '/handlers.php');
    $gateBody = explode('function akiraShellSearchRebuild', $handlersSource, 2)[1] ?? '';
    $gateBody = explode("akiraShellCall('akira.search.rebuild@1'", $gateBody, 2)[0];
    $check(str_contains($gateBody, 'akiraShellAuthorizeAdmin()') && !str_contains($gateBody, 'akiraShellCall('), 'rebuild handler gates on the administrator authority before any capability dispatch');

    // ── Declaration test: every new route is declared and mounted ────────
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__) . '/module.json'), true);
    $routes = require dirname(__DIR__) . '/routes.php';
    $check(
        ($manifest['capabilities']['routes']['POST /cms-akira-shell/search/rebuild'] ?? '') === 'akira.search.rebuild@1'
        && ($routes['POST']['/cms-akira-shell/search/rebuild'] ?? '') === 'cms-akira-shell:akiraShellSearchRebuild',
        'search rebuild POST route is declared in capabilities.routes and mounted'
    );
    $check(
        ($manifest['capabilities']['routes']['GET /cms-akira-shell/search'] ?? '') === 'akira.search.query@1'
        && in_array('akira.search.query@1', $manifest['capabilities']['depends'] ?? [], true)
        && in_array('akira.search.rebuild@1', $manifest['capabilities']['depends'] ?? [], true),
        'search read route declares and depends on the query and rebuild capabilities'
    );
    $check(
        str_contains($handlersSource, 'akiraShellAuthorizeAdmin()') && str_contains($handlersSource, "akiraShellCall('akira.search.rebuild@1'"),
        'search rebuild handler gates locally and dispatches the same declared capability'
    );
    $contribution = null;
    foreach ($manifest['admin_contributions'] ?? [] as $candidate) {
        if (($candidate['id'] ?? '') === 'cms-akira-shell.search') {
            $contribution = $candidate;
        }
    }
    $check(
        is_array($contribution) && ($contribution['route'] ?? '') === '/cms-akira-shell/search'
        && ($contribution['roles'] ?? []) === ['admin', 'administrator', 'superadmin'],
        'search sidebar contribution points at the console and matches the policy role set'
    );
    $helpersSource = (string) file_get_contents(dirname(__DIR__) . '/helpers.php');
    $check(
        !str_contains($helpersSource . $handlersSource, 'cms_akira_search_documents') && !str_contains($helpersSource . $handlersSource, 'SELECT '),
        'shell search console stays table-free and SQL-free'
    );
} catch (Throwable $error) {
    $details = [];
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        $details[] = $cursor::class . ': ' . $cursor->getMessage();
    }
    $check(false, 'search console scenario completes', implode(' <- ', $details));
} finally {
    if (is_object($db) && method_exists($db, 'inTransaction') && $db->inTransaction()) {
        $db->rollBack();
    }
    try {
        if ($db instanceof PDO) {
            $db->prepare('DELETE FROM cms_akira_search_documents WHERE tenant_id = ?')->execute([$tenantId]);
            $db->prepare('DELETE FROM cms_akira_posts WHERE tenant_id = ?')->execute([$tenantId]);
            $db->prepare('DELETE FROM kernel_idempotency_keys WHERE tenant_id = ?')->execute([$tenantId]);
            $db->prepare('DELETE FROM audit_logs WHERE module = ?')->execute([CAS_SEARCH_MODULE_ID]);
        }
        if ($db instanceof PDO && $seededPolicy) {
            $db->prepare(
                "DELETE FROM capability_authorization_policies WHERE policy_version = ? AND provider = ? AND capability_id IN ('akira.search.upsert@1', 'akira.search.query@1', 'akira.search.rebuild@1')"
            )->execute([$policyVersion, CAS_SEARCH_MODULE_ID]);
            CapabilityAuthorizationRegistry::invalidate();
        }
    } catch (Throwable $cleanupError) {
        $check(false, 'search console fixture cleanup succeeds', $cleanupError->getMessage());
    }
    cleanupTestTenant($tenantId);
    app()->tenant()->setTenantId($originalTenant);
    kernel_request_context_delete('tenant_id');
    if (is_array($originalUser)) {
        app()->setUser($originalUser);
    }
}

echo "\nCMS Akira search console: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
