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
$routes = require $root . '/routes.php';
$handlers = (string) file_get_contents($root . '/handlers.php');
$contributions = [];
foreach (($manifest['admin_contributions'] ?? []) as $contribution) {
    if (is_array($contribution)) {
        $contributions[(string) ($contribution['id'] ?? '')] = $contribution;
    }
}
$roles = ['admin', 'administrator', 'superadmin'];

$check(
    ($manifest['capabilities']['routes']['GET /cms-akira-shell/provenance'] ?? '') === 'kernel.provenance.list@1'
    && ($routes['GET']['/cms-akira-shell/provenance'] ?? '') === 'cms-akira-shell:akiraShellProvenance',
    'read-only GET route is declared and routed through the provenance capability'
);
$check(
    str_contains((string) file_get_contents($root . '/helpers.php'), "app()->cap()->call('kernel.provenance.list@1'")
    && str_contains($handlers, 'akiraShellProvenanceSnapshot('),
    'surface calls its declared capability through the canonical bus'
);
$check(
    ($contributions['cms-akira-shell.provenance']['roles'] ?? []) === $roles,
    'sidebar contribution mirrors the Kernel provenance administrator allowlist'
);
$check(
    ($manifest['reads_tables'] ?? null) === []
    && in_array('kernel.provenance.list@1', $manifest['capabilities']['depends'] ?? [], true),
    'shell is table-free and declares the Kernel provenance dependency'
);

$kernel = akiraShellProvenanceActorHtml([
    'actor_user_id' => 7, 'actor_module_user_id' => null, 'actor_source' => 'kernel', 'username' => 'alice',
]);
$module = akiraShellProvenanceActorHtml([
    'actor_user_id' => null, 'actor_module_user_id' => 91, 'actor_source' => 'cli:admin', 'username' => null,
]);
$unattributed = akiraShellProvenanceActorHtml([
    'actor_user_id' => null, 'actor_module_user_id' => null, 'actor_source' => 'unauthenticated', 'username' => null,
]);
$legacy = akiraShellProvenanceActorHtml([
    'actor_user_id' => null, 'actor_module_user_id' => null, 'actor_source' => null, 'username' => null,
]);
$check(str_contains($kernel, 'alice') && str_contains($kernel, 'kernel · user #7'), 'Kernel actor renders username and identification source');
$check(str_contains($module, 'cli:admin') && str_contains($module, 'module user #91'), 'module actor renders source label and module user id');
$check(str_contains($unattributed, 'Unattributed') && str_contains($unattributed, 'unauthenticated'), 'unattributed actor is explicit and retains its source');
$check(str_contains($legacy, 'Unattributed') && str_contains($legacy, 'source missing (legacy row)'), 'legacy anonymous actor remains explicitly unattributed');

$html = akiraShellProvenanceHtml([
    'rows' => [[
        'id' => 1, 'module' => 'cms-akira-core', 'actor_user_id' => null,
        'actor_module_user_id' => null, 'actor_source' => 'cli:unattributed',
        'action' => 'akira.post.publish', 'entity_type' => 'post', 'entity_id' => '42',
        'created_at' => '2026-09-13 10:00:00', 'username' => null,
    ]],
    'modules' => ['cms-akira-core'], 'actions' => ['akira.post.publish'],
    'module' => '', 'action' => '', 'page' => 1, 'pages' => 1, 'total' => 1,
    'unattributed_count' => 7,
]);
$check(
    str_contains($html, 'data-unattributed-count') && str_contains($html, '>7</strong>')
    && str_contains($html, 'cli:unattributed'),
    'surface visibly counts and truthfully labels unattributed rows'
);
$check(
    str_contains($html, 'name="module"') && str_contains($html, 'name="action"')
    && str_contains($html, 'akira.post.publish') && str_contains($html, 'post · 42'),
    'surface filters by module/action and details action/entity'
);
$check(!str_contains($html, '<form method="post"') && !str_contains($html, '>System<'), 'surface has no mutation form and never labels an actor System');
$check(($routes['POST']['/cms-akira-shell/provenance'] ?? null) === null, 'surface adds no POST route');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
