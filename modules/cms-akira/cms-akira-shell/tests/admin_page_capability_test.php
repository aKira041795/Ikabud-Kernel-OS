<?php

/**
 * CMS Akira shell admin-page capability contract.
 *
 * The one shell chrome (akiraShellPage) is exposed as
 * akira.shell.admin_page@1 and consumed by the module-owned admin surfaces so
 * they render inside the shared sidebar/palette/layout instead of shipping a
 * second HTML document. This gate proves the capability is a thin wrapper, that
 * its policy is active at the tenant's ACTIVE policy version (never a literal),
 * that its caller allowlist admits every real consumer, and that it stays
 * refused to roles and callers outside the admin tier.
 *
 * Phase 1 is pure file inspection and never touches a database. The live phase
 * requires module helpers and therefore calls requireNotLiveTenantDatabase()
 * first: on a provisioned tenant this SKIPs, which means phase 1 ran and the
 * live policy/bus half still needs a real non-live tenant check.
 */

declare(strict_types=1);

use Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry;

$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = 'akiracms.test';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
require_once $root . '/tests/_support/env_guard.php';

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$pass, &$fail): void {
    $ok ? ++$pass : ++$fail;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

$bodyOf = static function (string $source, string $function): string {
    $start = strpos($source, 'function ' . $function . '(');
    if ($start === false) {
        return '';
    }
    $rest = substr($source, $start);
    $end = strpos($rest, "\n}");
    return $end === false ? $rest : substr($rest, 0, $end);
};

echo "=== CMS Akira shell admin-page capability ===\n";

// ── Phase 1: static, database-free ───────────────────────────────────────
$manifest = json_decode((string) file_get_contents(dirname(__DIR__) . '/module.json'), true, 512, JSON_THROW_ON_ERROR);
$exposes = [];
foreach ((array) ($manifest['capabilities']['exposes'] ?? []) as $expose) {
    if (is_array($expose) && ($expose['id'] ?? '') !== '') {
        $exposes[(string) $expose['id']] = $expose;
    }
}
$check(isset($exposes['akira.shell.admin_page@1']), 'shell module.json exposes akira.shell.admin_page@1');
$check(
    in_array('first', (array) ($exposes['akira.shell.admin_page@1']['modes'] ?? []), true),
    'the exposed chrome capability declares first-wins dispatch'
);

$helpersSource = (string) file_get_contents(dirname(__DIR__) . '/helpers.php');
$check(
    str_contains($helpersSource, "'akira.shell.admin_page@1' => 'akiraShellCapAdminPage'"),
    'the shell capability handler map points at the thin wrapper handler'
);
$handlerBody = $bodyOf($helpersSource, 'akiraShellCapAdminPage');
$check(
    str_contains($handlerBody, 'akiraShellPage('),
    'the capability handler delegates to akiraShellPage()'
);
$check(
    !str_contains($handlerBody, '<!doctype')
    && !str_contains($handlerBody, 'kernelContributionsForHostLocation')
    && !str_contains($handlerBody, 'cdn.tailwindcss.com')
    && !str_contains($handlerBody, '<nav'),
    'the capability handler carries no second page builder, navigation list or palette'
);

$seedBody = $bodyOf($helpersSource, 'akiraShellSeedAdminPagePolicy');
$check(
    str_contains($seedBody, 'cacActivePolicyVersion()'),
    'the policy seed uses the tenant active policy version, not a literal'
);
$check(
    !preg_match("/'policy_version'\\s*=>\\s*\\d+/", $seedBody),
    'the policy seed pins no literal policy_version'
);
$check(
    str_contains($seedBody, "'akira.shell.admin_page@1'")
    && str_contains($seedBody, 'cms-akira-shell,cms-akira-seo,cms-akira-navigation')
    && str_contains($seedBody, "'admin,administrator,superadmin'")
    && str_contains($seedBody, "'provider' => 'cms-akira-shell'"),
    'the seed names the capability, the three callers, the admin tier and the provider'
);

foreach ([
    'cms-akira-seo' => ['seo', '/cms-akira-seo', 'akiraSeoShellChrome'],
    'cms-akira-navigation' => ['navigation', '/cms-akira-navigation', 'akiraNavigationShellChrome'],
] as $moduleId => [$activeKey, $route, $chromeFunction]) {
    $modulePath = dirname(__DIR__, 2) . '/' . $moduleId;
    $template = (string) file_get_contents($modulePath . '/templates/admin.disyl');
    $check(
        stripos($template, '<!doctype') === false
        && stripos($template, '<html') === false
        && stripos($template, '<head') === false
        && stripos($template, '<body') === false
        && stripos($template, '<header') === false
        && stripos($template, '<main') === false
        && stripos($template, 'cdn.tailwindcss.com') === false,
        $moduleId . ' template is a content fragment with no second document chrome'
    );

    $moduleManifest = json_decode((string) file_get_contents($modulePath . '/module.json'), true, 512, JSON_THROW_ON_ERROR);
    $check(
        in_array('akira.shell.admin_page@1', (array) ($moduleManifest['capabilities']['depends'] ?? []), true),
        $moduleId . ' declares the chrome capability dependency'
    );
    $sidebar = [];
    foreach ((array) ($moduleManifest['admin_contributions'] ?? []) as $contribution) {
        if (is_array($contribution) && ($contribution['location'] ?? '') === 'sidebar') {
            $sidebar = $contribution;
            break;
        }
    }
    $check(
        ($sidebar['route'] ?? '') === $route && ($sidebar['active_key'] ?? '') === $activeKey,
        $moduleId . ' sidebar contribution names the ' . $activeKey . ' active key'
    );

    $moduleHandlers = (string) file_get_contents($modulePath . '/handlers.php');
    $callBody = $bodyOf($moduleHandlers, $chromeFunction);
    $check(
        str_contains($callBody, "app()->cap()->call('akira.shell.admin_page@1'")
        && str_contains($callBody, "'module' => '" . $moduleId . "'")
        && str_contains($callBody, "'active' => \$active"),
        $moduleId . ' render path calls the chrome capability with its own caller identity'
    );
}

// ── Phase 2: refuse a provisioned tenant before loading module helpers ────
requireNotLiveTenantDatabase();

// ── Phase 3: live policy, authorization and bus reachability ─────────────
require_once dirname(__DIR__, 2) . '/cms-akira-core/helpers.php';
require_once dirname(__DIR__) . '/helpers.php';

$originalUser = app()->user();
$admin = ['id' => 994811, 'role' => 'administrator'];
$author = ['id' => 994812, 'role' => 'author'];

try {
    $handlers = cms_akira_shell_capability_handlers();
    $check(
        ($handlers['akira.shell.admin_page@1'] ?? '') === 'akiraShellCapAdminPage'
        && function_exists('akiraShellCapAdminPage'),
        'runtime capability handler map resolves to the callable wrapper'
    );

    app()->setUser($admin);
    $expected = akiraShellPage('Thin wrapper probe', '<p id="probe">body</p>', ['active' => 'seo']);
    $actual = akiraShellCapAdminPage([
        'title' => 'Thin wrapper probe',
        'body' => '<p id="probe">body</p>',
        'active' => 'seo',
    ]);
    $check(
        is_array($actual) && ($actual['html'] ?? '') === $expected,
        'capability output is byte-identical to akiraShellPage() for the same inputs'
    );
    $check(
        str_contains($expected, 'aria-label="Akira administration"')
        && str_contains($expected, 'href="/cms-akira-shell/posts"')
        && str_contains($expected, 'id="probe"'),
        'the shared chrome carries the sidebar and the supplied body'
    );

    loadModuleRoutes(['GET' => [], 'POST' => [], 'PUT' => [], 'DELETE' => []]);
    CapabilityAuthorizationRegistry::invalidate();
    $check(app()->capabilities()->has('akira.shell.admin_page@1'), 'runtime capability registry exposes akira.shell.admin_page@1');

    akiraShellSeedAdminPagePolicy();
    CapabilityAuthorizationRegistry::invalidate();
    $activeVersion = cacActivePolicyVersion();

    $db = app()->db();
    $statement = $db->prepare(
        'SELECT policy_version, caller_module, allowed_roles, is_active '
        . 'FROM capability_authorization_policies WHERE capability_id = ? AND provider = ? AND policy_version = ? AND is_active = 1'
    );
    $statement->execute(['akira.shell.admin_page@1', 'cms-akira-shell', $activeVersion]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    $check(is_array($row) && (int) ($row['policy_version'] ?? 0) === $activeVersion, 'a live policy row exists at the tenant active version ' . $activeVersion, json_encode($row));
    if (is_array($row)) {
        $callers = array_map('trim', explode(',', (string) ($row['caller_module'] ?? '')));
        $roles = array_map('trim', explode(',', (string) ($row['allowed_roles'] ?? '')));
        $check((int) ($row['is_active'] ?? 0) === 1, 'the live row is active');
        $check(
            in_array('cms-akira-shell', $callers, true)
            && in_array('cms-akira-seo', $callers, true)
            && in_array('cms-akira-navigation', $callers, true),
            'the live row admits every real consumer module'
        );
        $check($roles === ['admin', 'administrator', 'superadmin'], 'the live row admits exactly the shell admin tier');
    }

    $registry = new CapabilityAuthorizationRegistry($db);
    $base = [
        'capability_id' => 'akira.shell.admin_page@1',
        'capability_version' => '1',
        'provider' => 'cms-akira-shell',
        'provider_activation' => true,
        'dispatch_protocol' => 'v1',
        'policy_version' => $activeVersion,
    ];
    foreach (['cms-akira-shell', 'cms-akira-seo', 'cms-akira-navigation'] as $caller) {
        $decision = $registry->authorize($base + ['caller_module' => $caller, 'actor_role' => 'administrator']);
        $check(($decision['allowed'] ?? false) === true, $caller . ' is admitted by the live policy');
    }
    $deniedCaller = $registry->authorize($base + ['caller_module' => 'cms-akira-theme', 'actor_role' => 'administrator']);
    $check(
        ($deniedCaller['allowed'] ?? true) === false && ($deniedCaller['reason'] ?? '') === 'disabled_caller',
        'an undeclared caller is refused with disabled_caller'
    );
    $deniedRole = $registry->authorize($base + ['caller_module' => 'cms-akira-navigation', 'actor_role' => 'author']);
    $check(
        ($deniedRole['allowed'] ?? true) === false && ($deniedRole['reason'] ?? '') === 'role_not_allowed',
        'a non-admin role is refused with role_not_allowed'
    );

    $result = app()->cap()->call('akira.shell.admin_page@1', [
        'title' => 'Bus probe',
        'body' => '<p id="bus-probe">bus</p>',
        'active' => 'seo',
    ], [
        'caller' => ['module' => 'cms-akira-seo', 'user' => $admin],
        'mode' => 'first',
    ]);
    $html = is_array($result) ? (string) ($result['html'] ?? '') : '';
    $check(
        str_contains($html, 'aria-label="Akira administration"')
        && str_contains($html, 'id="bus-probe"')
        && substr_count(strtolower($html), '<!doctype') === 1,
        'the cms-akira-seo bus call returns exactly one shell document carrying the fragment'
    );
} catch (Throwable $error) {
    $details = [];
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        $details[] = $cursor::class . ': ' . $cursor->getMessage();
    }
    $check(false, 'admin-page capability scenario completes', implode(' <- ', $details));
} finally {
    app()->setUser(is_array($originalUser) ? $originalUser : []);
}

echo "\nCMS Akira shell admin-page capability: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
