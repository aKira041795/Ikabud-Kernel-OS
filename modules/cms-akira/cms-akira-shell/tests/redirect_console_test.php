<?php

/**
 * CMS Akira P2.2a redirect console contract.
 *
 * Proves the console reads the real tenant redirect store through the owning
 * akira.redirect.list@1 capability (never a parallel model or SQL in the shell),
 * that its write path dispatches the declared akira.redirect.create@1
 * capability, that the module not-found seam resolves exact-match single-hop
 * targets only, that every external target form is refused, and that an
 * unauthorized role is denied fail-closed.
 *
 * Runs against the isolated base store with a synthetic tenant; it never binds
 * a live tenant database. The module table is created from the real migration
 * file for the duration of this test and dropped again when this test created
 * it.
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
    $candidate = random_int(8400000, 8499999);
    $exists = app()->controlDb()->prepare('SELECT 1 FROM kernel_tenants WHERE id = ?');
    $exists->execute([$candidate]);
    if ($exists->fetchColumn() === false) {
        $tenantId = $candidate;
    }
}
if ($tenantId === 0) {
    testEnvironmentSkip('could not allocate an unused tenant fixture id for the redirect console');
}

$originalTenant = (int) app()->tenant()->current();
$originalUser = app()->user();
$admin = ['id' => 995001, 'role' => 'administrator'];
$editor = ['id' => 995002, 'role' => 'editor'];
$db = null;
$createdTable = false;
$seededPolicy = false;
$policyVersion = random_int(900000, 999999);
$sourceA = '/posts/console-retired-' . bin2hex(random_bytes(4));
$sourceB = '/posts/console-mid-' . bin2hex(random_bytes(4));

try {
    echo "=== CMS Akira P2.2a redirect console ===\n";

    ensureTestTenant($tenantId, 'cms-akira-core');
    $db = app()->db();

    $migration = dirname(__DIR__, 2) . '/cms-akira-core/database/migrations/008_create_redirects.sql';
    $probe = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $probe->execute(['cms_akira_redirects']);
    $createdTable = (int) $probe->fetchColumn() === 0;
    if ($createdTable) {
        $sql = preg_replace('/--.*$/m', '', (string) file_get_contents($migration)) ?? '';
        $db->exec(trim($sql, " \t\n\r\0\x0B;"));
    }

    // Register the real core handlers so the console helper exercises the same
    // capability path the HTTP handler does.
    $registry = app()->capabilities();
    $mutations = ['akira.redirect.create@1'];
    foreach (cms_akira_core_capability_handlers() as $id => $handler) {
        if ($registry->has($id)) {
            continue;
        }
        $meta = in_array($id, $mutations, true)
            ? ['requires_protocol' => 'v2', 'effects' => ['invalidates' => []]]
            : [];
        $registry->register($id, 'cms-akira-core', static function (mixed $payload, string $capabilityId = '', string $provider = '') use ($handler): mixed {
            return moduleWithContext('cms-akira-core', static fn (): mixed => $handler($payload, $capabilityId, $provider));
        }, 50, ['first'], $meta);
    }

    app()->tenant()->setTenantId($tenantId);
    kernel_request_context_set('tenant_id', $tenantId);
    app()->setUser($admin);

    (new CapabilityAuthorizationRegistry($db))->seedPolicy([
        [
            'policy_version' => $policyVersion, 'capability_id' => 'akira.redirect.list@1', 'capability_version' => '1',
            'provider' => 'cms-akira-core', 'caller_module' => 'cms-akira-core,cms-akira-shell',
            'allowed_roles' => 'admin,administrator,superadmin', 'provider_activation_required' => true,
            'requires_protocol' => 'v1', 'is_active' => true,
        ],
        [
            'policy_version' => $policyVersion, 'capability_id' => 'akira.redirect.create@1', 'capability_version' => '1',
            'provider' => 'cms-akira-core', 'caller_module' => 'cms-akira-core,cms-akira-shell',
            'allowed_roles' => 'admin,administrator,superadmin', 'provider_activation_required' => true,
            'requires_protocol' => 'v2', 'is_active' => true,
        ],
    ]);
    $seededPolicy = true;
    CapabilityAuthorizationRegistry::invalidate();

    // ── Store a real redirect through the governed capability ────────────
    $created = akiraShellCall('akira.redirect.create@1', [
        'source_path' => $sourceA,
        'target_path' => $sourceB,
        'idempotency_key' => 'redirect-console-' . bin2hex(random_bytes(6)),
    ]);
    $check(($created['ok'] ?? false) === true && ($created['source_path'] ?? '') === $sourceA, 'the console capability path stores a real redirect');
    akiraShellCall('akira.redirect.create@1', [
        'source_path' => $sourceB,
        'target_path' => '/posts/console-final',
        'idempotency_key' => 'redirect-console-' . bin2hex(random_bytes(6)),
    ]);

    // ── Not-found seam resolution: exact match, single hop ───────────────
    $check(akiraShellRedirectResolve($sourceA) === $sourceB, 'the not-found seam resolves the exact stored source');
    $check(akiraShellRedirectResolve($sourceA) !== '/posts/console-final', 'the not-found seam does not follow a chained target');
    $check(akiraShellRedirectResolve($sourceB) === '/posts/console-final', 'a target that is another source still resolves on its own');
    $check(akiraShellRedirectResolve('/posts/console-never-stored') === null, 'an unknown path returns null so the 404 is unchanged');

    // ── Open-redirect guard through the real capability boundary ─────────
    foreach ([
        'absolute URL' => 'https://example.com',
        'protocol-relative URL' => '//evil.test',
        'scheme-qualified javascript:' => 'javascript:alert(1)',
    ] as $label => $target) {
        $refused = false;
        try {
            akiraShellCall('akira.redirect.create@1', [
                'source_path' => '/posts/guard-' . bin2hex(random_bytes(3)),
                'target_path' => $target,
                'idempotency_key' => 'redirect-guard-' . bin2hex(random_bytes(6)),
            ]);
        } catch (Throwable) {
            $refused = true;
        }
        $check($refused, "the capability refuses a {$label}");
    }

    // ── Console rendering: real rows and an honest empty state ───────────
    $rows = akiraShellCall('akira.redirect.list@1');
    $list = is_array($rows['rows'] ?? null) ? array_values($rows['rows']) : [];
    $check(count($list) === 2, 'the console list capability returns the real stored rows', (string) count($list));
    $html = akiraShellRedirectsHtml($list);
    $check(str_contains($html, 'data-akira-redirect-row="' . $sourceA . '"') && str_contains($html, $sourceB), 'the console renders the real stored redirect');
    $check(str_contains($html, '/cms-akira-shell/redirects') && str_contains($html, 'Save redirect'), 'the console exposes an explicit redirect form');
    $empty = akiraShellRedirectsHtml([]);
    $check(str_contains($empty, 'data-akira-redirect-empty') && str_contains($empty, 'No post-path redirects'), 'the console renders an explicit empty state when no rows exist');

    // ── Governance policy: role allowlist is fail-closed ─────────────────
    $policy = new CapabilityAuthorizationRegistry($db);
    $base = [
        'capability_version' => '1', 'provider' => 'cms-akira-core', 'provider_activation' => true,
        'policy_version' => $policyVersion, 'tenant_id' => (string) $tenantId,
        'capability_id' => 'akira.redirect.create@1', 'caller_module' => 'cms-akira-shell',
        'dispatch_protocol' => 'v2',
    ];
    $denied = $policy->authorize($base + ['actor_role' => 'editor']);
    $allowed = $policy->authorize($base + ['actor_role' => 'administrator']);
    $check(($denied['allowed'] ?? true) === false && ($denied['reason'] ?? '') === 'role_not_allowed', 'redirect create policy refuses an unauthorized role fail-closed');
    $check(($allowed['allowed'] ?? false) === true, 'redirect create policy admits the administrator role the console renders for');

    // ── Console gate: administrator authority before any dispatch ────────
    app()->setUser($editor);
    $check(akiraShellIsAdmin() === false, 'the console local gate classifies a non-administrator as unauthorized');
    app()->setUser($admin);
    $handlersSource = (string) file_get_contents(dirname(__DIR__) . '/handlers.php');
    $gateBody = explode('function akiraShellRedirectCreate', $handlersSource, 2)[1] ?? '';
    $gateBody = explode("akiraShellCall('akira.redirect.create@1'", $gateBody, 2)[0];
    $check(str_contains($gateBody, 'akiraShellAuthorizeAdmin()') && !str_contains($gateBody, 'akiraShellCall('), 'the redirect create handler gates on administrator authority before any capability dispatch');

    // ── Declaration test: routes, dependencies, sidebar contribution ─────
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__) . '/module.json'), true);
    $routes = require dirname(__DIR__) . '/routes.php';
    // The GET console is dispatch-enforced by the same administrator-governed
    // capability it calls. The local gate remains defense in depth and still
    // runs before the capability dispatch.
    $listGateBody = explode('function akiraShellRedirects', $handlersSource, 2)[1] ?? '';
    $listGateBody = explode("akiraShellCall('akira.redirect.list@1'", $listGateBody, 2)[0];
    $check(
        ($routes['GET']['/cms-akira-shell/redirects'] ?? '') === 'cms-akira-shell:akiraShellRedirects'
        && ($manifest['capabilities']['routes']['GET /cms-akira-shell/redirects'] ?? '') === 'akira.redirect.list@1'
        && str_contains($listGateBody, 'akiraShellAuthorizeAdmin()')
        && !str_contains($listGateBody, 'akiraShellCall('),
        'redirects GET console is mounted, dispatch-declared and locally admin-gated'
    );
    $check(
        ($manifest['capabilities']['routes']['POST /cms-akira-shell/redirects'] ?? '') === 'akira.redirect.create@1'
        && ($routes['POST']['/cms-akira-shell/redirects'] ?? '') === 'cms-akira-shell:akiraShellRedirectCreate',
        'redirects POST route is declared in capabilities.routes and mounted'
    );
    foreach (['akira.redirect.list@1', 'akira.redirect.create@1', 'akira.redirect.resolve@1'] as $capability) {
        $check(in_array($capability, $manifest['capabilities']['depends'] ?? [], true), "shell declares {$capability} dependency");
    }
    $contribution = null;
    foreach ($manifest['admin_contributions'] ?? [] as $candidate) {
        if (($candidate['id'] ?? '') === 'cms-akira-shell.redirects') {
            $contribution = $candidate;
        }
    }
    $check(
        is_array($contribution) && ($contribution['route'] ?? '') === '/cms-akira-shell/redirects'
        && ($contribution['roles'] ?? []) === ['admin', 'administrator', 'superadmin'],
        'redirect sidebar contribution points at the console and matches the policy role set'
    );

    // ── The shell stays table-free and SQL-free ──────────────────────────
    $shellHelpersSource = (string) file_get_contents(dirname(__DIR__) . '/helpers.php');
    $check(
        !str_contains($shellHelpersSource . $handlersSource, 'cms_akira_redirects')
        && !str_contains($shellHelpersSource . $handlersSource, 'SELECT '),
        'shell redirect console stays table-free and SQL-free'
    );
    $coreSource = (string) file_get_contents(dirname(__DIR__, 2) . '/cms-akira-core/helpers/redirects.php');
    $check(str_contains($coreSource, 'akira.redirect.resolve@1') && substr_count($coreSource, 'SELECT target_path') === 1, 'resolution is a single exact-match SELECT with no recursion');
} catch (Throwable $error) {
    $details = [];
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        $details[] = $cursor::class . ': ' . $cursor->getMessage();
    }
    $check(false, 'redirect console scenario completes', implode(' <- ', $details));
} finally {
    if (is_object($db) && method_exists($db, 'inTransaction') && $db->inTransaction()) {
        $db->rollBack();
    }
    try {
        if ($db instanceof PDO) {
            if (!$createdTable) {
                $delete = $db->prepare('DELETE FROM cms_akira_redirects WHERE tenant_id = ?');
                $delete->execute([$tenantId]);
            }
            $db->prepare("DELETE FROM audit_logs WHERE module = 'cms-akira-core' AND action = 'akira.redirect.create'")->execute();
            $db->prepare('DELETE FROM kernel_idempotency_keys WHERE tenant_id = ?')->execute([$tenantId]);
            if ($seededPolicy) {
                $db->prepare("DELETE FROM capability_authorization_policies WHERE policy_version = ? AND provider = 'cms-akira-core' AND capability_id IN ('akira.redirect.list@1', 'akira.redirect.create@1')")->execute([$policyVersion]);
            }
            if ($createdTable) {
                $db->exec('DROP TABLE IF EXISTS cms_akira_redirects');
            }
            CapabilityAuthorizationRegistry::invalidate();
        }
    } catch (Throwable $cleanupError) {
        $check(false, 'redirect console fixture cleanup succeeds', $cleanupError->getMessage());
    }
    cleanupTestTenant($tenantId);
    app()->tenant()->setTenantId($originalTenant);
    kernel_request_context_delete('tenant_id');
    if (is_array($originalUser)) {
        app()->setUser($originalUser);
    }
}

echo "\nCMS Akira redirect console: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
