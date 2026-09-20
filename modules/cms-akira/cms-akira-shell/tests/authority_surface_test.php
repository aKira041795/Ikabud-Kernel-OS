<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/helpers.php';

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    $ok ? ++$pass : ++$fail;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . "\n";
};

$manifest = json_decode((string) file_get_contents($root . '/module.json'), true);
$manifest['_path'] = $root;
$policy = [
    'capability_id' => 'akira.policy.list@1',
    'capability_version' => '1',
    'provider' => 'cms-akira-core',
    'caller_module' => 'cms-akira-core,cms-akira-shell',
    'allowed_roles' => 'admin,administrator,superadmin',
    'requires_protocol' => 'v1',
    'policy_version' => 30,
    'grant_state' => 'granted',
    'is_active' => 1,
];
$snapshot = akiraShellAuthoritySnapshot([$policy], ['cms-akira-shell' => $manifest]);
$authority = array_values(array_filter(
    $snapshot['deltas'],
    static fn (array $row): bool => ($row['id'] ?? '') === 'cms-akira-shell.authority'
));

$check(($manifest['capabilities']['routes']['GET /cms-akira-shell/authority'] ?? '') === 'akira.policy.list@1', 'authority GET route declares the reused policy-list capability');
$check(($manifest['admin_contributions'][0]['roles'] ?? []) === ['admin', 'administrator', 'superadmin'], 'sidebar declaration mirrors the active administrative policy role set');
$check(count($authority) === 1 && ($authority[0]['status'] ?? '') === 'agreement', 'identical declaration and policy role sets agree');
$syntheticManifest = [
    'id' => 'cms-akira-synthetic-authority-fixture',
    'suite' => 'cms-akira',
    '_path' => __DIR__ . '/fixtures/authority',
    'capabilities' => ['routes' => [
        'POST /synthetic/declared' => 'synthetic.declared@1',
    ]],
];
$syntheticSnapshot = akiraShellAuthoritySnapshot([], [
    'cms-akira-synthetic-authority-fixture' => $syntheticManifest,
]);
$syntheticUndeclared = array_column($syntheticSnapshot['undeclared_posts'], 'route');
$check(
    $syntheticUndeclared === ['/synthetic/undeclared']
    && !in_array('/synthetic/declared', $syntheticUndeclared, true),
    'POST route scanner distinguishes synthetic declared and undeclared routes'
);

$mismatchManifest = $manifest;
$mismatchManifest['_path'] = '';
$mismatchManifest['admin_contributions'][0]['roles'] = ['administrator'];
$mismatch = akiraShellAuthoritySnapshot([$policy], ['cms-akira-shell' => $mismatchManifest]);
$check(($mismatch['deltas'][0]['status'] ?? '') === 'narrower'
    && ($mismatch['deltas'][0]['policy_only'] ?? []) === ['admin', 'superadmin'], 'role-set comparison identifies a narrower declaration');

$suspended = $policy;
$suspended['grant_state'] = 'suspended';
$revoked = $policy;
$revoked['capability_id'] = 'akira.policy.other@1';
$revoked['grant_state'] = 'revoked';
$html = akiraShellAuthorityHtml(akiraShellAuthoritySnapshot([$suspended, $revoked], []));
$check(str_contains($html, 'suspended') && str_contains($html, 'ring-amber-300'), 'suspended grants receive unmistakable amber treatment');
$check(str_contains($html, 'revoked') && str_contains($html, 'ring-red-400'), 'revoked grants receive unmistakable red treatment');
$check(!str_contains($html, '<form') && !str_contains($html, '<button'), 'authority output is read-only');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
