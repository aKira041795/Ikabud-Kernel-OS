<?php

/**
 * CMS Akira P3.3 user session revocation contract.
 *
 * Proves the primitive that did not exist before this slice: revoking a user's
 * live sessions as a governed action of its own, without changing their role or
 * their activation.
 *  - revocation increments token_version and leaves role and is_active
 *    byte-identical (read from the stored row before and after);
 *  - the acting user's own sessions are refused against the acting identity,
 *    never a client-supplied actor id;
 *  - the same idempotency key never increments token_version twice;
 *  - the capability is seeded at the exact tier of akira.user.set_active@1;
 *  - the shell route, handler and manifest declaration resolve to it.
 *
 * Runs against the isolated base store with a synthetic tenant; it never binds
 * a live tenant database.
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
    $candidate = random_int(8400000, 8499999);
    $exists = app()->controlDb()->prepare('SELECT 1 FROM kernel_tenants WHERE id = ?');
    $exists->execute([$candidate]);
    if ($exists->fetchColumn() === false) {
        $tenantId = $candidate;
    }
}
if ($tenantId === 0) {
    testEnvironmentSkip('could not allocate an unused tenant fixture id for session revocation');
}

$originalTenant = app()->tenant()->current();
$originalUser = app()->user();
$actor = ['id' => 994801, 'role' => 'administrator'];
$db = null;
$createdUser = false;
$targetId = 0;
$key = 'revoke-sessions-test-' . bin2hex(random_bytes(8));
$username = 'revoke-probe-' . bin2hex(random_bytes(4));

try {
    echo "=== CMS Akira P3.3 session revocation ===\n";

    ensureTestTenant($tenantId, 'cms-akira-core');
    $db = app()->db();
    app()->tenant()->setTenantId($tenantId);
    kernel_request_context_set('tenant_id', $tenantId);
    app()->setUser($actor);

    // ── The real seeded policy tier, compared with its neighbour ─────────
    cacSeedGovernancePolicies();
    $readPolicy = static function (PDO $db, string $capability): ?array {
        $stmt = $db->prepare(
            "SELECT policy_version, caller_module, allowed_roles, requires_protocol, provider_activation_required, is_active "
            . "FROM capability_authorization_policies WHERE capability_id = ? AND capability_version = '1' "
            . "AND provider = 'cms-akira-core' AND is_active = 1 ORDER BY policy_version DESC LIMIT 1"
        );
        $stmt->execute([$capability]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    };
    $newTier = $readPolicy($db, 'akira.user.revoke_sessions@1');
    $neighbourTier = $readPolicy($db, 'akira.user.set_active@1');
    $check(is_array($newTier), 'the seed produced an active policy row for akira.user.revoke_sessions@1');
    $check(
        is_array($newTier) && is_array($neighbourTier)
        && $newTier['caller_module'] === $neighbourTier['caller_module']
        && $newTier['allowed_roles'] === $neighbourTier['allowed_roles']
        && $newTier['requires_protocol'] === $neighbourTier['requires_protocol']
        && (int)$newTier['provider_activation_required'] === (int)$neighbourTier['provider_activation_required'],
        'the new capability is seeded at the exact tier of akira.user.set_active@1'
    );
    $check(
        is_array($newTier) && $newTier['allowed_roles'] === 'admin,administrator,superadmin'
        && str_contains((string)$newTier['caller_module'], 'cms-akira-shell')
        && str_contains((string)$newTier['caller_module'], 'cms-akira-core'),
        'the policy row admits shell and core at the canonical admin tier'
    );

    // ── Policy fails closed for a role outside the allowlist ─────────────
    CapabilityAuthorizationRegistry::invalidate();
    $policy = new CapabilityAuthorizationRegistry($db);
    $authority = [
        'capability_id' => 'akira.user.revoke_sessions@1', 'capability_version' => '1',
        'provider' => 'cms-akira-core', 'caller_module' => 'cms-akira-shell',
        'provider_activation' => true, 'dispatch_protocol' => 'v2',
        'policy_version' => (int)($newTier['policy_version'] ?? 1), 'tenant_id' => (string)$tenantId,
    ];
    $denied = $policy->authorize($authority + ['actor_role' => 'editor']);
    $allowed = $policy->authorize($authority + ['actor_role' => 'administrator']);
    $check(($denied['allowed'] ?? true) === false && ($denied['reason'] ?? '') === 'role_not_allowed', 'the policy refuses an unauthorized role fail-closed');
    $check(($allowed['allowed'] ?? false) === true, 'the policy admits the administrator role');

    // ── A synthetic target user in the isolated store ────────────────────
    $insert = $db->prepare(
        'INSERT INTO users (username, email, password_hash, full_name, role, is_active, token_version) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([$username, $username . '@example.test', password_hash('probe', PASSWORD_DEFAULT), 'Revoke Probe', 'admin', 1, 0]);
    $targetId = (int)$db->lastInsertId();
    $createdUser = $targetId > 0;
    $check($createdUser, 'a synthetic target user exists in the isolated store');

    // ── Revocation: token_version moves, role and is_active do not ───────
    $before = kernelUserForGovernanceUpdate($tenantId, $targetId);
    $result = cac_cap_akira_user_revoke_sessions_1(['user_id' => $targetId, 'idempotency_key' => $key]);
    $after = kernelUserForGovernanceUpdate($tenantId, $targetId);
    $check(is_array($before) && is_array($after), 'the stored user row is readable before and after revocation');
    $check(($result['ok'] ?? false) === true && ($result['operation'] ?? '') === 'akira.user.revoke_sessions', 'the revocation capability reports a governed success');
    $check(is_array($before) && is_array($after) && (int)$after['token_version'] === (int)$before['token_version'] + 1, 'revocation increments token_version by exactly one');
    $check(is_array($before) && is_array($after) && (string)$after['role'] === (string)$before['role'], 'the stored role is byte-identical after revocation');
    $check(is_array($before) && is_array($after) && (int)$after['is_active'] === (int)$before['is_active'], 'the stored is_active is byte-identical after revocation');

    // ── Audit envelope ───────────────────────────────────────────────────
    $audit = $db->prepare("SELECT action, entity_type FROM audit_logs WHERE module = 'cms-akira-core' AND action = 'akira.user.revoke_sessions' AND entity_id = ? ORDER BY id DESC LIMIT 1");
    $audit->execute([(string)$targetId]);
    $auditRow = $audit->fetch(PDO::FETCH_ASSOC);
    $check(is_array($auditRow) && $auditRow['action'] === 'akira.user.revoke_sessions' && $auditRow['entity_type'] === 'user', 'revocation writes the standard governance audit envelope');

    // ── Idempotency: the same key never increments twice ─────────────────
    $replay = cac_cap_akira_user_revoke_sessions_1(['user_id' => $targetId, 'idempotency_key' => $key]);
    $afterReplay = kernelUserForGovernanceUpdate($tenantId, $targetId);
    $check(($replay['ok'] ?? false) === true, 'a repeated idempotency key replays the governed outcome');
    $check(is_array($afterReplay) && (int)$afterReplay['token_version'] === (int)$after['token_version'], 'replaying the same key does not increment token_version twice');

    // ── Self-revocation is refused against the acting identity ───────────
    app()->setUser(['id' => $targetId, 'role' => 'administrator']);
    $refused = false;
    $message = '';
    try {
        // A client-supplied actor_id must not be able to pose as someone else.
        cac_cap_akira_user_revoke_sessions_1(['user_id' => $targetId, 'actor_id' => 1, 'idempotency_key' => $key . '-self']);
    } catch (CacGovernanceException $error) {
        $refused = true;
        $message = $error->getMessage();
    }
    $check($refused && $message === 'You cannot revoke your own sessions.', 'self-revocation is refused against the acting identity, not a payload field', $message);
    $afterSelf = kernelUserForGovernanceUpdate($tenantId, $targetId);
    $check(is_array($afterSelf) && (int)$afterSelf['token_version'] === (int)$after['token_version'], 'a refused self-revocation leaves token_version untouched');
    app()->setUser($actor);

    // ── The read surface exposes the session generation ──────────────────
    $list = cac_cap_akira_user_list_1([]);
    $listed = null;
    foreach (($list['rows'] ?? []) as $row) {
        if (is_array($row) && (int)($row['id'] ?? 0) === $targetId) {
            $listed = $row;
        }
    }
    $check(is_array($listed) && array_key_exists('token_version', $listed) && (int)$listed['token_version'] === (int)$after['token_version'], 'the user list exposes the stored token_version');

    // ── Declaration and handler wiring ───────────────────────────────────
    $handlers = cms_akira_core_capability_handlers();
    $check(($handlers['akira.user.revoke_sessions@1'] ?? '') === 'cac_cap_akira_user_revoke_sessions_1', 'runtime exports the revocation capability handler');
    $coreManifest = json_decode((string)file_get_contents(dirname(__DIR__) . '/module.json'), true);
    $exposed = [];
    foreach (($coreManifest['capabilities']['exposes'] ?? []) as $entry) {
        if (is_array($entry) && isset($entry['id'])) {
            $exposed[(string)$entry['id']] = $entry;
        }
    }
    $check(isset($exposed['akira.user.revoke_sessions@1']) && ($exposed['akira.user.revoke_sessions@1']['requires_protocol'] ?? '') === 'v2', 'the core manifest exposes the v2 revocation capability');
    $shellRoutes = require dirname(__DIR__, 2) . '/cms-akira-shell/routes.php';
    $check(($shellRoutes['POST']['/cms-akira-shell/users/{id}/revoke'] ?? '') === 'cms-akira-shell:akiraShellUserRevokeSessions', 'shell routes resolve the revoke path to its handler');
    $shellManifest = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/cms-akira-shell/module.json'), true);
    $check(($shellManifest['capabilities']['routes']['POST /cms-akira-shell/users/{id}/revoke'] ?? '') === 'akira.user.revoke_sessions@1', 'the shell manifest declares the revocation route authority');
    $check(in_array('akira.user.revoke_sessions@1', $shellManifest['capabilities']['depends'] ?? [], true), 'the shell manifest declares the revocation dependency');
    $shellHandlers = (string)file_get_contents(dirname(__DIR__, 2) . '/cms-akira-shell/handlers.php');
    $check(str_contains($shellHandlers, 'function akiraShellUserRevokeSessions') && str_contains($shellHandlers, "'akira.user.revoke_sessions@1'"), 'the shell handler delegates to the revocation capability');
    $seedSource = (string)file_get_contents(dirname(__DIR__) . '/helpers.php');
    $check(str_contains($seedSource, "'akira.user.revoke_sessions@1'"), 'the governance seed loop includes the revocation capability');
} catch (Throwable $error) {
    $details = [];
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        $details[] = $cursor::class . ': ' . $cursor->getMessage();
    }
    $check(false, 'session revocation scenario completes', implode(' <- ', $details));
} finally {
    if (is_object($db) && method_exists($db, 'inTransaction') && $db->inTransaction()) {
        $db->rollBack();
    }
    try {
        if ($db instanceof PDO) {
            if ($createdUser && $targetId > 0) {
                $db->prepare('DELETE FROM users WHERE id = ?')->execute([$targetId]);
            }
            $db->prepare("DELETE FROM audit_logs WHERE module = 'cms-akira-core' AND action = 'akira.user.revoke_sessions'")->execute();
            $db->prepare('DELETE FROM kernel_idempotency_keys WHERE tenant_id = ?')->execute([$tenantId]);
        }
    } catch (Throwable $cleanupError) {
        $check(false, 'session revocation fixture cleanup succeeds', $cleanupError->getMessage());
    }
    cleanupTestTenant($tenantId);
    app()->tenant()->setTenantId(is_numeric($originalTenant) ? (int)$originalTenant : null);
    kernel_request_context_delete('tenant_id');
    if (is_array($originalUser)) {
        app()->setUser($originalUser);
    }
}

echo "\nCMS Akira session revocation: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
