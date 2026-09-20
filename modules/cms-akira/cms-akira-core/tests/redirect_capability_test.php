<?php

/**
 * CMS Akira P2.2a post-path redirect capability contract.
 *
 * Proves the security-critical properties of the redirect store:
 *  - exact-match resolution and single-hop (no chains, no loops);
 *  - absolute, protocol-relative and scheme-qualified targets are refused;
 *  - the write surface fails closed for a role outside the policy allowlist;
 *  - two identical writes with the same idempotency key create one row.
 *
 * Runs against the isolated base store with a synthetic tenant; it never binds
 * a live tenant database. The module table is created from the real migration
 * file for the duration of this test and dropped again when this test created
 * it, so the base schema is left as it was found.
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
require_once dirname(__DIR__) . '/helpers.php';

requireNotLiveTenantDatabase();

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$pass, &$fail): void {
    $ok ? ++$pass : ++$fail;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

$tenantId = 0;
for ($attempt = 0; $attempt < 40 && $tenantId === 0; $attempt++) {
    $candidate = random_int(8300000, 8399999);
    $exists = app()->controlDb()->prepare('SELECT 1 FROM kernel_tenants WHERE id = ?');
    $exists->execute([$candidate]);
    if ($exists->fetchColumn() === false) {
        $tenantId = $candidate;
    }
}
if ($tenantId === 0) {
    testEnvironmentSkip('could not allocate an unused tenant fixture id for the redirect capability');
}

$originalTenant = (int) app()->tenant()->current();
$originalUser = app()->user();
$admin = ['id' => 994901, 'role' => 'administrator'];
$editor = ['id' => 994902, 'role' => 'editor'];
$db = null;
$createdTable = false;
$seededPolicy = false;
$policyVersion = random_int(900000, 999999);
$sourceA = '/posts/retired-alpha-' . bin2hex(random_bytes(4));
$sourceB = '/posts/retired-beta-' . bin2hex(random_bytes(4));
$sourceC = '/posts/retired-gamma-' . bin2hex(random_bytes(4));
$key = 'redirect-test-' . bin2hex(random_bytes(8));

try {
    echo "=== CMS Akira P2.2a post-path redirect capability ===\n";

    ensureTestTenant($tenantId, 'cms-akira-core');
    $db = app()->db();

    // Bring the real module table into the isolated base store exactly as the
    // declared migration would; the CREATE TABLE IF NOT EXISTS is re-runnable.
    $migration = dirname(__DIR__) . '/database/migrations/008_create_redirects.sql';
    $check(is_file($migration), 'the declared redirect migration exists');
    $probe = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $probe->execute(['cms_akira_redirects']);
    $createdTable = (int) $probe->fetchColumn() === 0;
    if ($createdTable) {
        $sql = preg_replace('/--.*$/m', '', (string) file_get_contents($migration)) ?? '';
        $db->exec(trim($sql, " \t\n\r\0\x0B;"));
    }
    $probeAfter = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $probeAfter->execute(['cms_akira_redirects']);
    $check((int) $probeAfter->fetchColumn() === 1, 'the declared migration creates the redirect table');

    app()->tenant()->setTenantId($tenantId);
    kernel_request_context_set('tenant_id', $tenantId);
    app()->setUser($admin);

    // ── The open-redirect guard: every external form is refused ──────────
    $refusals = [
        'absolute https URL' => 'https://example.com',
        'absolute http URL' => 'http://evil.test/path',
        'protocol-relative URL' => '//evil.test',
        'protocol-relative with path' => '//evil.test/posts',
        'scheme-qualified javascript:' => 'javascript:alert(1)',
        'scheme-qualified data:' => 'data:text/html,<script>alert(1)</script>',
        'scheme-qualified mailto:' => 'mailto:attacker@evil.test',
        'backslash-smuggled slash' => '/\\evil.test',
        'whitespace smuggled URL' => "/ \t/posts/current",
        'parent-directory traversal' => '/posts/../../admin',
    ];
    foreach ($refusals as $label => $value) {
        $refused = false;
        try {
            cacRedirectTarget($value);
        } catch (CacRedirectException) {
            $refused = true;
        }
        $check($refused, "redirect target refuses {$label}", $value);
    }
    $check(cacRedirectTarget('/posts/current-slug') === '/posts/current-slug', 'redirect target admits a same-origin internal path');
    $check(cacRedirectTarget('/') === '/', 'redirect target admits the site root');

    // ── Source shape ─────────────────────────────────────────────────────
    $check(cacRedirectSource('retired-slug') === '/posts/retired-slug', 'a bare slug source normalises to /posts/<slug>');
    $check(cacRedirectSource('/posts/retired-slug') === '/posts/retired-slug', 'a full post path source is kept');
    $sourceRefused = false;
    try {
        cacRedirectSource('https://evil.test/posts/x');
    } catch (CacRedirectException) {
        $sourceRefused = true;
    }
    $check($sourceRefused, 'a scheme-qualified source is refused');

    // ── Write + exact-match resolution + single hop ──────────────────────
    $write = cacRedirectMutate([
        'source_path' => $sourceA,
        'target_path' => $sourceB,
        'idempotency_key' => $key,
    ], $tenantId, $db);
    $check(($write['ok'] ?? false) === true && ($write['target_path'] ?? '') === $sourceB, 'a governed write stores the exact target');
    cacRedirectMutate([
        'source_path' => $sourceB,
        'target_path' => $sourceC,
        'idempotency_key' => $key . '-b',
    ], $tenantId, $db);
    cacRedirectMutate([
        'source_path' => $sourceC,
        'target_path' => '/posts/final-destination',
        'idempotency_key' => $key . '-c',
    ], $tenantId, $db);

    $check(cacRedirectResolve($sourceA, $tenantId, $db) === $sourceB, 'exact-match resolution returns the stored target');
    // Single-hop: A -> B -> C -> final. Resolving A must yield B, never C or the
    // final destination, because a target is never re-resolved.
    $check(cacRedirectResolve($sourceA, $tenantId, $db) !== $sourceC && cacRedirectResolve($sourceA, $tenantId, $db) !== '/posts/final-destination', 'a chained target is not followed (no recursion)');
    $check(cacRedirectResolve($sourceB, $tenantId, $db) === $sourceC, 'a row that is itself a target resolves on its own');
    $check(cacRedirectResolve('/posts/never-stored', $tenantId, $db) === null, 'an unknown path resolves to null');

    $rows = cacRedirectList($tenantId, $db);
    $check(count($rows) === 3, 'the list reads the real stored population', (string) count($rows));
    $sources = array_map(static fn (array $row): string => (string) $row['source_path'], $rows);
    $check($sources === [$sourceA, $sourceB, $sourceC], 'the list returns rows in a deterministic order');

    // ── Idempotency: same key, same payload => one row ───────────────────
    $before = (int) $db->query('SELECT COUNT(*) FROM cms_akira_redirects WHERE tenant_id = ' . $tenantId)->fetchColumn();
    $replay = cacRedirectMutate([
        'source_path' => $sourceA,
        'target_path' => $sourceB,
        'idempotency_key' => $key,
    ], $tenantId, $db);
    $after = (int) $db->query('SELECT COUNT(*) FROM cms_akira_redirects WHERE tenant_id = ' . $tenantId)->fetchColumn();
    $check(($replay['ok'] ?? false) === true && ($replay['replayed'] ?? false) === true, 'a repeated idempotency key replays the stored outcome');
    $check($before === $after, 'two identical writes with the same key do not create a second row', "before={$before} after={$after}");

    // ── Governance: role denial is fail-closed ───────────────────────────
    (new CapabilityAuthorizationRegistry($db))->seedPolicy([[
        'policy_version' => $policyVersion,
        'capability_id' => 'akira.redirect.create@1', 'capability_version' => '1',
        'provider' => 'cms-akira-core', 'caller_module' => 'cms-akira-core,cms-akira-shell',
        'allowed_roles' => 'admin,administrator,superadmin', 'provider_activation_required' => true,
        'requires_protocol' => 'v2', 'is_active' => true,
    ]]);
    $seededPolicy = true;
    CapabilityAuthorizationRegistry::invalidate();
    $policy = new CapabilityAuthorizationRegistry($db);
    $authority = [
        'capability_version' => '1', 'provider' => 'cms-akira-core', 'provider_activation' => true,
        'policy_version' => $policyVersion, 'tenant_id' => (string) $tenantId,
        'capability_id' => 'akira.redirect.create@1', 'caller_module' => 'cms-akira-shell',
        'dispatch_protocol' => 'v2',
    ];
    $denied = $policy->authorize($authority + ['actor_role' => 'editor']);
    $allowed = $policy->authorize($authority + ['actor_role' => 'administrator']);
    $check(($denied['allowed'] ?? true) === false && ($denied['reason'] ?? '') === 'role_not_allowed', 'redirect create policy refuses an unauthorized role fail-closed');
    $check(($allowed['allowed'] ?? false) === true, 'redirect create policy admits the administrator role');

    // ── Dispatch mapping ─────────────────────────────────────────────────
    $handlers = cms_akira_core_capability_handlers();
    $check(($handlers['akira.redirect.list@1'] ?? '') === 'cac_cap_akira_redirect_list_1', 'runtime exports the redirect list capability');
    $check(($handlers['akira.redirect.create@1'] ?? '') === 'cac_cap_akira_redirect_create_1', 'runtime exports the redirect create capability');
    $check(($handlers['akira.redirect.resolve@1'] ?? '') === 'cac_cap_akira_redirect_resolve_1', 'runtime exports the redirect resolve capability');
} catch (Throwable $error) {
    $details = [];
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        $details[] = $cursor::class . ': ' . $cursor->getMessage();
    }
    $check(false, 'redirect capability scenario completes', implode(' <- ', $details));
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
                $db->prepare("DELETE FROM capability_authorization_policies WHERE policy_version = ? AND capability_id = 'akira.redirect.create@1' AND provider = 'cms-akira-core'")->execute([$policyVersion]);
            }
            if ($createdTable) {
                $db->exec('DROP TABLE IF EXISTS cms_akira_redirects');
            }
            CapabilityAuthorizationRegistry::invalidate();
        }
    } catch (Throwable $cleanupError) {
        $check(false, 'redirect capability fixture cleanup succeeds', $cleanupError->getMessage());
    }
    cleanupTestTenant($tenantId);
    app()->tenant()->setTenantId($originalTenant);
    kernel_request_context_delete('tenant_id');
    if (is_array($originalUser)) {
        app()->setUser($originalUser);
    }
}

echo "\nCMS Akira redirect capability: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
