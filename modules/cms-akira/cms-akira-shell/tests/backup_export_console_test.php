<?php

/**
 * CMS Akira P6a backup and export console contract.
 *
 * Proves the console's read path returns real state from the kernel backup
 * service (never a parallel model or SQL in the shell), that its create path
 * refuses an unauthorized role fail-closed, and that the destructive
 * ModuleDataResetService is unreachable from the new routes. Runs against the
 * isolated base store with a synthetic tenant; it never binds a live tenant
 * database.
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
    $candidate = random_int(8200000, 8299999);
    $exists = app()->controlDb()->prepare('SELECT 1 FROM kernel_tenants WHERE id = ?');
    $exists->execute([$candidate]);
    if ($exists->fetchColumn() === false) {
        $tenantId = $candidate;
    }
}
if ($tenantId === 0) {
    testEnvironmentSkip('could not allocate an unused tenant fixture id for the backup console');
}

$originalTenant = (int) app()->tenant()->current();
$originalUser = app()->user();
$policyVersion = random_int(900000, 999999);
$seededPolicy = false;
$admin = ['id' => 994801, 'role' => 'administrator'];
$viewer = ['id' => 994802, 'role' => 'viewer'];
$db = null;
$backupDir = null;
$createdBackup = '';
$createdDir = false;

try {
    echo "=== CMS Akira P6a backup and export console ===\n";

    ensureTestTenant($tenantId, 'cms-akira-core');
    $db = app()->db();

    // Register the real core handlers on the in-process bus so the console
    // helper exercises the same capability path the HTTP handler does.
    $registry = app()->capabilities();
    $mutations = ['akira.backup.create@1', 'akira.export.create@1'];
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

    // Own the active policy version so the governed bus calls below are decided
    // by this fixture's role/caller allowlists, not ambient rows.
    (new CapabilityAuthorizationRegistry($db))->seedPolicy([
        [
            'policy_version' => $policyVersion, 'capability_id' => 'akira.backup.list@1', 'capability_version' => '1',
            'provider' => 'cms-akira-core', 'caller_module' => 'cms-akira-core,cms-akira-shell',
            'allowed_roles' => 'admin,administrator,superadmin', 'provider_activation_required' => true,
            'requires_protocol' => 'v1', 'is_active' => true,
        ],
        [
            'policy_version' => $policyVersion, 'capability_id' => 'akira.backup.create@1', 'capability_version' => '1',
            'provider' => 'cms-akira-core', 'caller_module' => 'cms-akira-core,cms-akira-shell',
            'allowed_roles' => 'admin,administrator,superadmin', 'provider_activation_required' => true,
            'requires_protocol' => 'v2', 'is_active' => true,
        ],
        [
            'policy_version' => $policyVersion, 'capability_id' => 'akira.export.create@1', 'capability_version' => '1',
            'provider' => 'cms-akira-core', 'caller_module' => 'cms-akira-core,cms-akira-shell',
            'allowed_roles' => 'admin,administrator,superadmin', 'provider_activation_required' => true,
            'requires_protocol' => 'v2', 'is_active' => true,
        ],
    ]);
    $seededPolicy = true;
    CapabilityAuthorizationRegistry::invalidate();

    // ── Read path: real state straight from ModuleBackupService ──────────
    $storageBase = defined('STORAGE_PATH') ? STORAGE_PATH : (defined('BASE_PATH') ? BASE_PATH . '/storage' : '');
    $check($storageBase !== '', 'the kernel storage root is available to the fixture');
    $backupDir = rtrim((string) $storageBase, '/\\') . '/backups/cms-akira-core';
    // The live web server owns this directory after a real backup. Only place a
    // fixture artifact when this process may write there; either way the read
    // assertion below compares the console against the real service listing.
    $backupDirWritable = is_dir($backupDir)
        ? is_writable($backupDir)
        : (is_dir(dirname($backupDir)) && is_writable(dirname($backupDir)));
    if ($backupDirWritable) {
        if (!is_dir($backupDir)) {
            $createdDir = @mkdir($backupDir, 0755, true);
        }
        $createdBackup = 'cms-akira-core-db-backup-20000101-' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT) . '.sql';
        @file_put_contents($backupDir . '/' . $createdBackup, "-- fixture\n");
    }

    $serviceFiles = array_map(
        static fn (array $row): string => (string) $row['file_name'],
        \Ikabud\Kernel\Services\ModuleBackupService::list('cms-akira-core', '/cms-akira-shell/backups')
    );
    $console = akiraShellBackupData();
    $files = array_map(static fn (array $row): string => (string) ($row['file_name'] ?? ''), $console['backups'] ?? []);
    $check(($console['ok'] ?? false) === true, 'console population read succeeds against the real service', json_encode($console));
    $check($files === $serviceFiles, 'console read path mirrors the real ModuleBackupService listing exactly', json_encode(['console' => $files, 'service' => $serviceFiles]));
    if ($createdBackup !== '') {
        $check(in_array($createdBackup, $files, true), 'console read path lists the fixture artifact placed in the store', json_encode($files));
    }

    $html = akiraShellBackupHtml($console);
    if ($files !== []) {
        $check(str_contains($html, 'data-akira-backup-row="' . $files[0] . '"'), 'console renders the real artifact returned by akira.backup.list@1');
    } else {
        $check(str_contains($html, 'data-akira-backup-empty'), 'console renders an explicit empty state for the real empty store');
    }
    $check(str_contains($html, '/cms-akira-shell/backups') && str_contains($html, 'Create backup now'), 'console exposes an explicit backup form');
    $check(str_contains($html, '/cms-akira-shell/exports') && str_contains($html, 'Produce CSV export'), 'console exposes an explicit export form');
    $check(!str_contains($html, 'ModuleDataResetService') && !str_contains($html, 'Reset'), 'console output never names or offers the destructive reset service');

    $empty = akiraShellBackupHtml(['ok' => true, 'backups' => [], 'total' => 0, 'module_id' => 'cms-akira-core', 'error' => '']);
    $check(str_contains($empty, 'data-akira-backup-empty') && str_contains($empty, '0 backups'), 'console renders an explicit empty state when no backups exist');

    $missing = akiraShellBackupHtml(
        ['ok' => true, 'backups' => [], 'total' => 0, 'module_id' => 'cms-akira-core', 'error' => ''],
        '',
        null
    );
    $check(!str_contains($missing, 'data-akira-backup-created'), 'console does not claim a created artifact without one');

    // ── Governance policy: role and caller allowlists ────────────────────
    $policy = new CapabilityAuthorizationRegistry($db);
    $base = [
        'capability_version' => '1', 'provider' => 'cms-akira-core', 'provider_activation' => true,
        'policy_version' => $policyVersion, 'tenant_id' => (string) $tenantId,
    ];
    $listAdmin = $policy->authorize($base + [
        'capability_id' => 'akira.backup.list@1', 'caller_module' => 'cms-akira-shell', 'actor_role' => 'administrator',
        'dispatch_protocol' => 'v1',
    ]);
    $listViewer = $policy->authorize($base + [
        'capability_id' => 'akira.backup.list@1', 'caller_module' => 'cms-akira-shell', 'actor_role' => 'viewer',
        'dispatch_protocol' => 'v1',
    ]);
    $check(($listAdmin['allowed'] ?? false) === true, 'backup list policy admits the administrator role');
    $check(($listViewer['allowed'] ?? true) === false && ($listViewer['reason'] ?? '') === 'role_not_allowed', 'backup list policy fails closed for an unauthorized role');

    $createViewer = $policy->authorize($base + [
        'capability_id' => 'akira.backup.create@1', 'caller_module' => 'cms-akira-shell', 'actor_role' => 'viewer',
        'dispatch_protocol' => 'v2',
    ]);
    $createAdmin = $policy->authorize($base + [
        'capability_id' => 'akira.backup.create@1', 'caller_module' => 'cms-akira-shell', 'actor_role' => 'administrator',
        'dispatch_protocol' => 'v2',
    ]);
    $check(($createViewer['allowed'] ?? true) === false && ($createViewer['reason'] ?? '') === 'role_not_allowed', 'backup create policy refuses an unauthorized role fail-closed');
    $check(($createAdmin['allowed'] ?? false) === true && ($createAdmin['reason'] ?? '') === 'allowed', 'backup create policy admits the administrator role the console renders for');

    $policyRow = $db->prepare(
        'SELECT caller_module FROM capability_authorization_policies WHERE policy_version = ? AND capability_id = ? AND provider = ? AND is_active = 1'
    );
    $policyRow->execute([$policyVersion, 'akira.backup.create@1', 'cms-akira-core']);
    $callers = array_map('trim', explode(',', (string) $policyRow->fetchColumn()));
    $check(in_array('cms-akira-shell', $callers, true) && !in_array('cms-akira-other', $callers, true), 'backup create policy caller allowlist names the shell and excludes undeclared callers');

    // ── Console mutation gate: fail closed before the capability ─────────
    app()->setUser($viewer);
    $check(akiraShellIsAdmin() === false, 'console local gate classifies a non-administrator as unauthorized');
    app()->setUser($admin);
    $handlersSource = (string) file_get_contents(dirname(__DIR__) . '/handlers.php');
    $gateBody = explode('function akiraShellBackupCreate', $handlersSource, 2)[1] ?? '';
    $gateBody = explode("akiraShellCall('akira.backup.create@1'", $gateBody, 2)[0];
    $check(str_contains($gateBody, 'akiraShellAuthorizeAdmin()') && !str_contains($gateBody, 'akiraShellCall('), 'backup create handler gates on the administrator authority before any capability dispatch');
    $exportGateBody = explode('function akiraShellExportCreate', $handlersSource, 2)[1] ?? '';
    $exportGateBody = explode("akiraShellCall('akira.export.create@1'", $exportGateBody, 2)[0];
    $check(str_contains($exportGateBody, 'akiraShellAuthorizeAdmin()') && !str_contains($exportGateBody, 'akiraShellCall('), 'export handler gates on the administrator authority before any capability dispatch');

    // ── Declaration test: every new route is declared and mounted ────────
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__) . '/module.json'), true);
    $routes = require dirname(__DIR__) . '/routes.php';
    $check(
        ($manifest['capabilities']['routes']['GET /cms-akira-shell/backups'] ?? '') === 'akira.backup.list@1'
        && ($routes['GET']['/cms-akira-shell/backups'] ?? '') === 'cms-akira-shell:akiraShellBackups',
        'backups GET route is declared in capabilities.routes and mounted'
    );
    $check(
        ($manifest['capabilities']['routes']['POST /cms-akira-shell/backups'] ?? '') === 'akira.backup.create@1'
        && ($routes['POST']['/cms-akira-shell/backups'] ?? '') === 'cms-akira-shell:akiraShellBackupCreate',
        'backups POST route is declared in capabilities.routes and mounted'
    );
    $check(
        ($manifest['capabilities']['routes']['POST /cms-akira-shell/exports'] ?? '') === 'akira.export.create@1'
        && ($routes['POST']['/cms-akira-shell/exports'] ?? '') === 'cms-akira-shell:akiraShellExportCreate',
        'exports POST route is declared in capabilities.routes and mounted'
    );
    foreach (['akira.backup.list@1', 'akira.backup.create@1', 'akira.export.create@1'] as $capability) {
        $check(in_array($capability, $manifest['capabilities']['depends'] ?? [], true), "shell declares {$capability} dependency");
    }
    $contribution = null;
    foreach ($manifest['admin_contributions'] ?? [] as $candidate) {
        if (($candidate['id'] ?? '') === 'cms-akira-shell.backups') {
            $contribution = $candidate;
        }
    }
    $check(
        is_array($contribution) && ($contribution['route'] ?? '') === '/cms-akira-shell/backups'
        && ($contribution['roles'] ?? []) === ['admin', 'administrator', 'superadmin'],
        'backup sidebar contribution points at the console and matches the policy role set'
    );

    // ── Destructive service is unreachable from every new surface ───────
    $coreBackupSource = (string) file_get_contents(dirname(__DIR__, 2) . '/cms-akira-core/helpers/backup.php');
    $shellHelpersSource = (string) file_get_contents(dirname(__DIR__) . '/helpers.php');
    $combined = $coreBackupSource . $shellHelpersSource . $handlersSource;
    $check(
        !preg_match('/ModuleDataResetService\s*::/', $combined)
        && !str_contains($combined, '->reset(')
        && !preg_match('/\btruncate\b/i', $combined),
        'ModuleDataResetService and any truncate/reset operation are unreachable from the new surface'
    );
    $backupCreateBody = explode('function cac_cap_akira_backup_create_1', $coreBackupSource, 2)[1] ?? '';
    $backupCreateBody = explode('function cacExportCollectPostRows', $backupCreateBody, 2)[0];
    $check(
        !str_contains($backupCreateBody, 'unlink') && !str_contains($backupCreateBody, 'ModuleDataResetService'),
        'the backup create path never deletes a backup artifact or resets data'
    );

    // ── Shell remains table-free and SQL-free ────────────────────────────
    $check(
        !str_contains($shellHelpersSource . $handlersSource, 'cms_akira_posts')
        && !str_contains($shellHelpersSource . $handlersSource, 'SELECT '),
        'shell backup console stays table-free and SQL-free'
    );
} catch (Throwable $error) {
    $details = [];
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        $details[] = $cursor::class . ': ' . $cursor->getMessage();
    }
    $check(false, 'backup console scenario completes', implode(' <- ', $details));
} finally {
    if (is_object($db) && method_exists($db, 'inTransaction') && $db->inTransaction()) {
        $db->rollBack();
    }
    try {
        if ($createdBackup !== '' && is_string($backupDir)) {
            $artifact = $backupDir . '/' . $createdBackup;
            if (is_file($artifact)) {
                @unlink($artifact);
            }
        }
        if ($createdDir && is_string($backupDir) && is_dir($backupDir)) {
            $remaining = array_diff(scandir($backupDir) ?: [], ['.', '..']);
            if ($remaining === []) {
                @rmdir($backupDir);
            }
        }
        if ($db instanceof PDO && $seededPolicy) {
            $db->prepare(
                "DELETE FROM capability_authorization_policies WHERE policy_version = ? AND provider = ? AND capability_id IN ('akira.backup.list@1', 'akira.backup.create@1', 'akira.export.create@1')"
            )->execute([$policyVersion, 'cms-akira-core']);
            CapabilityAuthorizationRegistry::invalidate();
        }
    } catch (Throwable $cleanupError) {
        $check(false, 'backup console fixture cleanup succeeds', $cleanupError->getMessage());
    }
    cleanupTestTenant($tenantId);
    app()->tenant()->setTenantId($originalTenant);
    kernel_request_context_delete('tenant_id');
    if (is_array($originalUser)) {
        app()->setUser($originalUser);
    }
}

echo "\nCMS Akira backup console: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
