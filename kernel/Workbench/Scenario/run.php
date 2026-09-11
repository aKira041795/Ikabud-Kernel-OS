<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/ScenarioEngine.php';

use Ikabud\Kernel\Workbench\Scenario\CapabilityScenarioDataProvider;
use Ikabud\Kernel\Workbench\Scenario\JsonSandboxDataProvider;
use Ikabud\Kernel\Workbench\Scenario\ScenarioCompiler;
use Ikabud\Kernel\Workbench\Scenario\ScenarioEngine;
use Ikabud\Kernel\Workbench\Scenario\ScenarioStore;

$base = dirname(__DIR__, 3);
$command = $argv[1] ?? '';
$options = [];
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
        [$key, $value] = explode('=', substr($arg, 2), 2);
        $options[$key] = $value;
    }
}
$store = new ScenarioStore($base . '/storage/private/workbench/scenarios');
$tenantId = (int)($options['tenant'] ?? 0);
$capabilityProvider = static function (string $module) use ($base, $tenantId): CapabilityScenarioDataProvider {
    if (!function_exists('app')) {
        $target = (string)(getenv('BASE_URL') ?: '');
        $host = $target !== '' ? parse_url($target, PHP_URL_HOST) : null;
        if (is_string($host) && $host !== '') {
            $_SERVER['HTTP_HOST'] = $host;
        }
        $_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
        require_once $base . '/bootstrap.php';
    }
    app();

    $build = static function () use ($base, $module, $tenantId): CapabilityScenarioDataProvider {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/', $module)) {
            throw new RuntimeException('Invalid module ID');
        }
        $modulePath = $base . '/modules/' . $module;
        $manifest = json_decode((string)file_get_contents($modulePath . '/module.json'), true, flags: JSON_THROW_ON_ERROR);
        require_once $modulePath . '/helpers.php';
        $export = preg_replace('/[^a-z0-9]+/i', '_', $module) . '_capability_handlers';
        $handlers = function_exists($export) ? $export() : [];
        $allowed = ['workbench.scenario.describe@1','workbench.scenario.seed@1','workbench.scenario.verify@1','workbench.scenario.cleanup@1'];
        $declared = array_column((array)($manifest['capabilities']['exposes'] ?? []), 'id');
        foreach ($allowed as $capabilityId) {
            if (!in_array($capabilityId, $declared, true) || !isset($handlers[$capabilityId]) || !is_callable($handlers[$capabilityId])) {
                continue;
            }
            if (!app()->capabilities()->has($capabilityId)) {
                app()->capabilities()->register($capabilityId, $module, $handlers[$capabilityId], 50, ['first'], ['origin' => ['type' => 'headless_module_activation', 'module' => $module]]);
            }
        }
        return new CapabilityScenarioDataProvider(
            static function (string $id, array $payload, array $context) use ($tenantId, $module): array {
                $work = static fn (): array => app()->cap()->call($id, $payload, $context + ['provider' => $module]);
                if ($tenantId <= 0) {
                    return $work();
                }
                $scoped = false;
                try {
                    return \Ikabud\Kernel\Capabilities\AuthorityScopeResolver::withScope(
                        $tenantId,
                        \Ikabud\Kernel\Capabilities\AuthorityScopeResolver::WORKBENCH,
                        static function () use (&$scoped, $work): array {
                            $scoped = true;
                            return $work();
                        },
                    );
                } catch (Throwable $e) {
                    // @phpstan-ignore if.alwaysFalse (closure mutates the by-reference execution guard)
                    if ($scoped) {
                        throw $e;
                    }
                    write_log('Workbench scenario could not establish an authority scope; calling without one', 'info', [
                        'reason' => 'tenant_authority_store_unavailable',
                        'tenant_id' => $tenantId,
                        'error' => $e->getMessage(),
                    ]);
                    return $work();
                }
            },
            $module,
        );
    };

    if ($tenantId <= 0) {
        return $build();
    }
    $scoped = false;
    try {
        return \Ikabud\Kernel\Capabilities\AuthorityScopeResolver::withScope(
            $tenantId,
            \Ikabud\Kernel\Capabilities\AuthorityScopeResolver::WORKBENCH,
            static function () use (&$scoped, $build): CapabilityScenarioDataProvider {
                $scoped = true;
                return $build();
            },
        );
    } catch (Throwable $e) {
        // @phpstan-ignore if.alwaysFalse (closure mutates the by-reference execution guard)
        if ($scoped) {
            throw $e;
        }
        write_log('Workbench scenario could not establish an authority scope; preparing without one', 'info', [
            'reason' => 'tenant_authority_store_unavailable',
            'tenant_id' => $tenantId,
            'error' => $e->getMessage(),
        ]);
        return $build();
    }
};

try {
    if ($command === 'propose') {
        $input = [];
        if (!empty($options['input'])) {
            $input = json_decode((string)file_get_contents($options['input']), true, flags: JSON_THROW_ON_ERROR);
        }
        $input['module'] = $options['module'] ?? $input['module'] ?? '';
        $input['title'] = $options['title'] ?? $input['title'] ?? 'Human-guided investigation';
        if (!empty($options['question'])) {
            $input['questions'][] = $options['question'];
        }
        if (!empty($options['direction'])) {
            $input['directions'][] = ['statement' => $options['direction'], 'check' => 'question'];
        }
        $scenario = (new ScenarioCompiler())->compile($input);
        $file = $store->save($scenario);
        echo json_encode(['ok' => true, 'scenario_id' => $scenario['scenario_id'], 'file' => $file], JSON_UNESCAPED_SLASHES) . "\n";
    } elseif ($command === 'resolve') {
        $scenario = $store->load((string)($options['module'] ?? ''), (string)($options['scenario'] ?? ''));
        echo json_encode($scenario, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } elseif ($command === 'prepare') {
        $module = (string)($options['module'] ?? '');
        $runId = (string)($options['run-id'] ?? '');
        $scenario = $store->load($module, (string)($options['scenario'] ?? ''));
        $providerMode = (string)($options['provider'] ?? 'capability');
        $provider = $providerMode === 'sandbox'
            ? new JsonSandboxDataProvider($base . '/storage/private/workbench/scenario-sandboxes')
            : $capabilityProvider($module);
        $engine = new ScenarioEngine($provider);
        $run = $engine->prepare($scenario, $runId);
        $dir = $base . '/test_results/scenarios/' . $runId;
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $file = $dir . '/scenario-run.json';
        file_put_contents($file, json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
        echo json_encode(['ok' => $run['status'] === 'ready', 'file' => $file, 'scenario_file' => $dir . '/scenario.json', 'status' => $run['status']], JSON_UNESCAPED_SLASHES) . "\n";
    } elseif ($command === 'finalize') {
        $runId = (string)($options['run-id'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $runId)) {
            throw new RuntimeException('Invalid run ID');
        }
        $file = $base . '/test_results/scenarios/' . $runId . '/scenario-run.json';
        $run = json_decode((string)file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $module = (string)($run['scenario']['module'] ?? '');
        $provider = ($run['seed_receipt']['provider'] ?? '') === 'json-sandbox'
            ? new JsonSandboxDataProvider($base . '/storage/private/workbench/scenario-sandboxes')
            : $capabilityProvider($module);
        $engine = new ScenarioEngine($provider);
        $final = $engine->finalize($run);
        file_put_contents($file, json_encode($final, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
        echo json_encode(['ok' => $final['status'] === 'completed', 'file' => $file, 'status' => $final['status']], JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        fwrite(STDERR, "Usage:\n  php run.php propose --module=id [--input=file.json|--question=text|--direction=text]\n  php run.php resolve --module=id --scenario=id\n  php run.php prepare --module=id --scenario=id --run-id=id --tenant=N\n  php run.php finalize --run-id=id --tenant=N\n");
        exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}
