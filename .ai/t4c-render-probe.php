<?php

declare(strict_types=1);

/** T4c diagnostic: why does the draft render resolve to zero sections? */

$root = dirname(__DIR__);
$_SERVER['HTTP_HOST'] = 'akiracms.test';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
require_once $root . '/modules/cms-akira/cms-akira-core/helpers.php';
require_once $root . '/modules/cms-akira/cms-akira-editor/helpers.php';
require_once $root . '/modules/cms-akira/cms-akira-theme/helpers.php';
require_once $root . '/modules/cms-akira/cms-akira-builder/helpers.php';

$tenant = 54;

$register = static function (string $module, array $handlers): void {
    foreach ($handlers as $id => $handler) {
        if (app()->capabilities()->has($id)) {
            continue;
        }
        app()->capabilities()->register($id, $module, static function (mixed $payload, string $capability = '', string $provider = '') use ($handler, $module): mixed {
            return moduleWithContext($module, static fn (): mixed => $handler($payload, $capability, $provider));
        }, 50, ['first'], []);
    }
};
$register('cms-akira-core', cms_akira_core_capability_handlers());
$register('cms-akira-editor', cms_akira_editor_capability_handlers());
$register('cms-akira-theme', cms_akira_theme_capability_handlers());
$register('cms-akira-builder', cms_akira_builder_capability_handlers());

$run = static function (string $label, ?array $actor) use ($tenant): void {
    app()->tenant()->setTenantId($tenant);
    kernel_request_context_set('tenant_id', $tenant);
    app()->setUser($actor);
    echo "--- {$label} ---" . PHP_EOL;

    $theme = app()->cap()->call('akira.theme.resolve@1', [], ['caller' => ['module' => 'cms-akira-builder', 'user' => app()->user()], 'mode' => 'first']);
    echo 'theme resolve: ' . json_encode(is_array($theme) ? ($theme['theme_slug'] ?? null) : null)
        . ' validated=' . var_export(is_array($theme) ? ($theme['validated'] ?? null) : null, true) . PHP_EOL;

    $blocks = app()->cap()->call('akira.theme.blocks@1', [], ['caller' => ['module' => 'cms-akira-builder', 'user' => app()->user()], 'mode' => 'first']);
    $ids = [];
    foreach ((is_array($blocks) ? ($blocks['blocks'] ?? []) : []) as $definition) {
        $ids[] = is_array($definition) ? ($definition['id'] ?? '?') : '?';
    }
    echo 'theme blocks: ok=' . var_export(is_array($blocks) ? ($blocks['ok'] ?? null) : null, true) . ' ids=' . implode(',', $ids) . PHP_EOL;

    foreach (['preview', 'published'] as $source) {
        $result = cab_builder_cap_render_1(['entity_type' => 'post', 'entity_key' => 't4a-page', 'source' => $source]);
        $html = $result['data']['html'] ?? '';
        echo "render {$source}: ok=" . var_export($result['ok'] ?? null, true)
            . ' len=' . (is_string($html) ? strlen($html) : 'n/a')
            . ' warnings=' . json_encode($result['data']['warnings'] ?? null)
            . ' error=' . var_export($result['error'] ?? null, true) . PHP_EOL;
    }
};

$run('admin actor', ['id' => 1, 'role' => 'admin']);
$run('anonymous', null);
