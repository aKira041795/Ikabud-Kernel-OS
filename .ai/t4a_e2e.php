<?php
/** T4a E2E — create/validate/publish a composition on tenant 54 via governed capabilities. */
declare(strict_types=1);
$root = dirname(__DIR__);
$_SERVER['HTTP_HOST'] = 'akiracms.test';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
require_once $root . '/modules/cms-akira/cms-akira-builder/helpers.php';
require_once $root . '/modules/cms-akira/cms-akira-theme/helpers.php';
require_once $root . '/modules/cms-akira/cms-akira-core/helpers/capabilities.php';

// CLI bootstrap does not auto-register module capability providers — mirror the module test pattern.
$registry = app()->capabilities();
$coreMutations = [];
foreach (cms_akira_core_capability_handlers() as $id => $handler) {
    if ($registry->has($id)) {
        continue;
    }
    $meta = in_array($id, $coreMutations, true) ? ['requires_protocol' => 'v2'] : [];
    $registry->register($id, 'cms-akira-core', static fn (mixed $p, string $c = '', string $pr = ''): mixed => moduleWithContext('cms-akira-core', static fn (): mixed => $handler($p, $c, $pr)), 50, ['first'], $meta);
}

// CLI bootstrap does not auto-register module capability providers — mirror the module test pattern.
$registry = app()->capabilities();
$builderMutations = ['akira.builder.create@1', 'akira.builder.update@1', 'akira.builder.publish@1', 'akira.builder.unpublish@1', 'akira.builder.delete@1', 'akira.builder.validate@1'];
foreach (cms_akira_builder_capability_handlers() as $id => $handler) {
    if ($registry->has($id)) {
        continue;
    }
    $meta = in_array($id, $builderMutations, true) ? ['requires_protocol' => 'v2', 'effects' => ['invalidates' => [CAB_BUILDER_INVALIDATION]]] : [];
    $registry->register($id, 'cms-akira-builder', static fn (mixed $p, string $c = '', string $pr = ''): mixed => moduleWithContext('cms-akira-builder', static fn (): mixed => $handler($p, $c, $pr)), 50, ['first'], $meta);
}
foreach (cms_akira_theme_capability_handlers() as $id => $handler) {
    if ($registry->has($id)) {
        continue;
    }
    $meta = in_array($id, ['akira.theme.activate@1', 'akira.theme.customize@1'], true) ? ['requires_protocol' => 'v2', 'effects' => ['invalidates' => [CAT_THEME_INVALIDATION]]] : [];
    $registry->register($id, 'cms-akira-theme', static fn (mixed $p, string $c = '', string $pr = ''): mixed => moduleWithContext('cms-akira-theme', static fn (): mixed => $handler($p, $c, $pr)), 50, ['first'], $meta);
}


app()->tenant()->setTenantId(54);
kernel_request_context_set('tenant_id', 54);
kernel_request_context_delete('_tenant_module_settings_cache');
app()->setUser(['id' => 1, 'role' => 'admin', 'username' => 'charlienacario884']);

$call = static function (string $id, array $payload): array {
    try {
        $r = app()->cap()->call($id, $payload, [
            'caller' => ['module' => 'cms-akira-builder', 'user' => app()->user()],
            'mode' => 'first',
        ]);
        return ['ok' => true, 'result' => $r];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e::class . ': ' . $e->getMessage()];
    }
};

$tree = ['version' => 1, 'blocks' => [
    ['block' => 'hero', 'props' => ['eyebrow' => 'CMS Akira', 'title' => 'Composed Page', 'subtitle' => 'Rendered from theme blocks', 'cta_label' => 'Learn more', 'cta_href' => '/']],
    ['block' => 'card-grid', 'props' => ['title' => 'Cards', 'items' => [
        ['title' => 'One', 'text' => 'First', 'href' => '/one'],
        ['title' => 'Two', 'text' => 'Second', 'href' => '/two'],
    ]]],
]];

$create = $call('akira.builder.create@1', [
    'idempotency_key' => 't4a-e2e-create-1', 'entity_type' => 'post', 'entity_key' => 't4a-demo',
    'title' => 'T4a Demo', 'tree' => $tree, 'change_note' => 't4a e2e',
]);
echo "CREATE: " . json_encode($create) . "\n";

// Diagnostics: call handlers DIRECTLY to surface raw exception chains.
$diag = static function (string $capId, array $payload, string $label): void {
    try {
        $r = moduleWithContext('cms-akira-builder', static fn (): mixed => cms_akira_builder_capability_handlers()[$capId]($payload, $capId, 'cms-akira-builder'));
        echo "DIRECT_$label: " . json_encode($r) . "\n";
    } catch (Throwable $e) {
        $chain = [];
        for ($c = $e; $c !== null; $c = $c->getPrevious()) {
            $chain[] = $c::class . ': ' . $c->getMessage();
        }
        echo "DIRECT_{$label}_FAIL: " . implode(' <- ', $chain) . "\n";
    }
};
$diag('akira.builder.publish@1', ['idempotency_key' => 't4a-dp-1', 'entity_type' => 'post', 'entity_key' => 't4a-demo'], 'PUBLISH');
$diag('akira.builder.validate@1', ['idempotency_key' => 't4a-dv-1', 'tree' => ['version' => 1, 'blocks' => [['block' => 'no-such-block', 'props' => []]]]], 'VALIDATE_UNKNOWN');
$diag('akira.builder.validate@1', ['idempotency_key' => 't4a-dv-2', 'tree' => ['version' => 1, 'blocks' => [['block' => 'card-grid', 'props' => ['items' => 'not-an-array']]]]], 'VALIDATE_BADPROP');

$publish = $call('akira.builder.publish@1', [
    'idempotency_key' => 't4a-e2e-pub-1', 'entity_type' => 'post', 'entity_key' => 't4a-demo',
]);
echo "PUBLISH: " . json_encode($publish) . "\n";

$dup = $call('akira.builder.publish@1', [
    'idempotency_key' => 't4a-e2e-pub-1', 'entity_type' => 'post', 'entity_key' => 't4a-demo',
]);
echo "PUBLISH_DUP: " . json_encode($dup) . "\n";

$bad = $call('akira.builder.validate@1', [
    'idempotency_key' => 't4a-e2e-val-bad', 'tree' => ['version' => 1, 'blocks' => [['block' => 'no-such-block', 'props' => []]]],
]);
echo "VALIDATE_BAD: " . json_encode($bad) . "\n";

$badprop = $call('akira.builder.validate@1', [
    'idempotency_key' => 't4a-e2e-val-badprop',
    'tree' => ['version' => 1, 'blocks' => [['block' => 'card-grid', 'props' => ['items' => 'not-an-array']]]],
]);
echo "VALIDATE_BADPROP: " . json_encode($badprop) . "\n";
