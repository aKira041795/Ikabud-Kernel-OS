<?php

declare(strict_types=1);

/**
 * Capability-declared entity-view cache invalidation contract tests.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../kernel/DiSyL/Cache/FragmentStore.php';

use Ikabud\Kernel\DiSyL\Cache\FragmentStore;

$pass = 0;
$fail = 0;
$assert = static function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  PASS  {$name}\n";
        return;
    }

    $fail++;
    echo "  FAIL  {$name}" . ($detail !== '' ? "  → {$detail}" : '') . "\n";
};

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . '/' . $entry;
        is_dir($child) ? $removeTree($child) : @unlink($child);
    }
    @rmdir($path);
};

$suffix = bin2hex(random_bytes(4));
$tmpRoot = sys_get_temp_dir() . '/phase64_effects_' . $suffix;
@mkdir($tmpRoot, 0777, true);

$app = app();
$engine = $app->templates();
$originalStore = $engine->fragmentStore();
$engine->setFragmentStore(new FragmentStore($tmpRoot));
$_ENV['APP_CAPABILITY_TRACE_LOGS'] = 'false';
$resolver = $app->entityViews();
$resolver->reset();

$registerType = static function (string $type, int &$listCalls, int &$detailCalls) use ($app, $resolver): array {
    $resolver->registerView($type, 'compact', [
        'fields' => ['id', 'name'],
        'actions' => [],
    ]);
    $resolver->registerView($type, 'detailed', [
        'fields' => ['id', 'name'],
        'actions' => [],
    ]);
    $app->capabilities()->register(
        'entity.list.' . $type,
        'kernel',
        static function () use (&$listCalls): array {
            $listCalls++;
            return ['rows' => [['id' => 1, 'name' => 'list-' . $listCalls]], 'total' => 1];
        },
        100
    );
    $app->capabilities()->register(
        'entity.get.' . $type,
        'kernel',
        static function () use (&$detailCalls): array {
            $detailCalls++;
            return ['id' => 1, 'name' => 'detail-' . $detailCalls];
        },
        100
    );

    return [
        '{ikb_entity_list source="' . $type . '.all" view="compact" cache="60" /}',
        '{ikb_entity_detail source="' . $type . '.one" id="1" view="detailed" cache="60" /}',
    ];
};

$render = static fn (string $tag): string => $engine->renderString($tag, ['current_user_role' => 'guest']);

echo "Capability effect invalidation\n";
echo str_repeat('=', 64) . "\n";

try {
    echo "\n[Successful effects and tenant scope]\n";
    $type = 'phase64_primary_' . $suffix;
    $listCalls = 0;
    $detailCalls = 0;
    [$listTag, $detailTag] = $registerType($type, $listCalls, $detailCalls);

    $app->tenant()->setTenantId(6401);
    $tenantOneList = $render($listTag);
    $tenantOneDetail = $render($detailTag);
    $assert('initial list and detail renders populate fragments', $listCalls === 1 && $detailCalls === 1);
    $assert('populated fragments serve stale HTML before a write', $render($listTag) === $tenantOneList && $render($detailTag) === $tenantOneDetail && $listCalls === 1 && $detailCalls === 1);

    $app->tenant()->setTenantId(6402);
    $tenantTwoList = $render($listTag);
    $assert('second tenant receives an independent fragment', $listCalls === 2 && $tenantTwoList !== $tenantOneList);

    $app->capabilities()->register(
        'test.phase64.write.' . $suffix . '@1',
        'kernel',
        static fn (): array => ['ok' => true],
        100,
        ['first'],
        ['effects' => ['invalidates' => ['entity.list.' . $type, 'entity.detail.' . $type]]]
    );
    $app->tenant()->setTenantId(6401);
    $app->cap()->call('test.phase64.write.' . $suffix . '@1');
    $refreshedList = $render($listTag);
    $refreshedDetail = $render($detailTag);
    $assert('successful declared write refreshes list without a manual invalidator', $listCalls === 3 && $refreshedList !== $tenantOneList, 'list_calls=' . $listCalls);
    $assert('successful declared write refreshes detail without a manual invalidator', $detailCalls === 2 && $refreshedDetail !== $tenantOneDetail, 'detail_calls=' . $detailCalls);

    $app->tenant()->setTenantId(6402);
    $assert('effect invalidation is scoped to the active tenant', $render($listTag) === $tenantTwoList && $listCalls === 3, 'list_calls=' . $listCalls);

    echo "\n[No effects and failed writes]\n";
    $noEffectType = 'phase64_no_effect_' . $suffix;
    $noEffectListCalls = 0;
    $noEffectDetailCalls = 0;
    [$noEffectListTag] = $registerType($noEffectType, $noEffectListCalls, $noEffectDetailCalls);
    $app->tenant()->setTenantId(6410);
    $noEffectCached = $render($noEffectListTag);
    $app->capabilities()->register('test.phase64.no_effect.' . $suffix . '@1', 'kernel', static fn (): bool => true, 100);
    $app->cap()->call('test.phase64.no_effect.' . $suffix . '@1');
    $assert('capability without effects does not invalidate', $render($noEffectListTag) === $noEffectCached && $noEffectListCalls === 1);

    $failedType = 'phase64_failed_' . $suffix;
    $failedListCalls = 0;
    $failedDetailCalls = 0;
    [$failedListTag] = $registerType($failedType, $failedListCalls, $failedDetailCalls);
    $failedCached = $render($failedListTag);
    $app->capabilities()->register(
        'test.phase64.failed.' . $suffix . '@1',
        'kernel',
        static function (): never {
            throw new RuntimeException('expected failed write');
        },
        100,
        ['first'],
        ['effects' => ['invalidates' => ['entity.list.' . $failedType]]]
    );
    $failed = false;
    try {
        $app->cap()->call('test.phase64.failed.' . $suffix . '@1');
    } catch (Throwable $e) {
        $failed = true;
    }
    $assert('failed write propagates its failure', $failed);
    $assert('failed write does not invalidate', $render($failedListTag) === $failedCached && $failedListCalls === 1);

    echo "\n[Multiple tags and executed-provider ownership]\n";
    $multiListType = 'phase64_multi_list_' . $suffix;
    $multiListCalls = 0;
    $multiListDetailCalls = 0;
    [$multiListTag] = $registerType($multiListType, $multiListCalls, $multiListDetailCalls);
    $multiDetailType = 'phase64_multi_detail_' . $suffix;
    $multiDetailListCalls = 0;
    $multiDetailCalls = 0;
    [, $multiDetailTag] = $registerType($multiDetailType, $multiDetailListCalls, $multiDetailCalls);
    $multiListCached = $render($multiListTag);
    $multiDetailCached = $render($multiDetailTag);
    $app->capabilities()->register(
        'test.phase64.multi.' . $suffix . '@1',
        'kernel',
        static fn (): bool => true,
        100,
        ['first'],
        ['effects' => ['invalidates' => ['entity.list.' . $multiListType, 'entity.detail.' . $multiDetailType]]]
    );
    $app->cap()->call('test.phase64.multi.' . $suffix . '@1');
    $assert('all declared tags invalidate their entity types', $render($multiListTag) !== $multiListCached && $render($multiDetailTag) !== $multiDetailCached && $multiListCalls === 2 && $multiDetailCalls === 2);

    $unexecutedType = 'phase64_unexecuted_' . $suffix;
    $unexecutedListCalls = 0;
    $unexecutedDetailCalls = 0;
    [$unexecutedListTag] = $registerType($unexecutedType, $unexecutedListCalls, $unexecutedDetailCalls);
    $unexecutedCached = $render($unexecutedListTag);
    $app->capabilities()->register('test.phase64.first.' . $suffix . '@1', 'kernel', static fn (): string => 'first', 100, ['first']);
    $app->capabilities()->register(
        'test.phase64.first.' . $suffix . '@1',
        'kernel',
        static fn (): string => 'second',
        10,
        ['first'],
        ['effects' => ['invalidates' => ['entity.list.' . $unexecutedType]]]
    );
    $app->cap()->call('test.phase64.first.' . $suffix . '@1');
    $assert('first mode ignores effects from an unexecuted provider', $render($unexecutedListTag) === $unexecutedCached && $unexecutedListCalls === 1);

    $pipelineSuccessType = 'phase64_pipeline_success_' . $suffix;
    $pipelineSuccessCalls = 0;
    $pipelineSuccessDetailCalls = 0;
    [$pipelineSuccessTag] = $registerType($pipelineSuccessType, $pipelineSuccessCalls, $pipelineSuccessDetailCalls);
    $pipelineFailedType = 'phase64_pipeline_failed_' . $suffix;
    $pipelineFailedCalls = 0;
    $pipelineFailedDetailCalls = 0;
    [$pipelineFailedTag] = $registerType($pipelineFailedType, $pipelineFailedCalls, $pipelineFailedDetailCalls);
    $pipelineSuccessCached = $render($pipelineSuccessTag);
    $pipelineFailedCached = $render($pipelineFailedTag);
    $pipelineCapability = 'test.phase64.pipeline.' . $suffix . '@1';
    $app->capabilities()->register($pipelineCapability, 'kernel', static fn (mixed $payload): mixed => $payload, 100, ['pipeline'], ['effects' => ['invalidates' => ['entity.list.' . $pipelineSuccessType]]]);
    $app->capabilities()->register($pipelineCapability, 'kernel', static function (): never {
        throw new RuntimeException('expected non-strict pipeline failure');
    }, 10, ['pipeline'], ['effects' => ['invalidates' => ['entity.list.' . $pipelineFailedType]]]);
    $app->cap()->call($pipelineCapability, ['ok' => true], ['mode' => 'pipeline']);
    $assert('pipeline invalidates only providers that completed successfully', $render($pipelineSuccessTag) !== $pipelineSuccessCached && $pipelineSuccessCalls === 2);
    $assert('non-strict pipeline failure does not apply failed-provider effects', $render($pipelineFailedTag) === $pipelineFailedCached && $pipelineFailedCalls === 1);

    $fanoutSuccessType = 'phase64_fanout_success_' . $suffix;
    $fanoutSuccessCalls = 0;
    $fanoutSuccessDetailCalls = 0;
    [$fanoutSuccessTag] = $registerType($fanoutSuccessType, $fanoutSuccessCalls, $fanoutSuccessDetailCalls);
    $fanoutFailedType = 'phase64_fanout_failed_' . $suffix;
    $fanoutFailedCalls = 0;
    $fanoutFailedDetailCalls = 0;
    [$fanoutFailedTag] = $registerType($fanoutFailedType, $fanoutFailedCalls, $fanoutFailedDetailCalls);
    $fanoutSuccessCached = $render($fanoutSuccessTag);
    $fanoutFailedCached = $render($fanoutFailedTag);
    $fanoutCapability = 'test.phase64.fanout.' . $suffix . '@1';
    $app->capabilities()->register($fanoutCapability, 'kernel', static fn (): bool => true, 100, ['fanout'], ['effects' => ['invalidates' => ['entity.list.' . $fanoutSuccessType]]]);
    $app->capabilities()->register($fanoutCapability, 'kernel', static function (): never {
        throw new RuntimeException('expected non-strict fanout failure');
    }, 10, ['fanout'], ['effects' => ['invalidates' => ['entity.list.' . $fanoutFailedType]]]);
    $app->cap()->call($fanoutCapability, null, ['mode' => 'fanout']);
    $assert('fanout invalidates only providers that completed successfully', $render($fanoutSuccessTag) !== $fanoutSuccessCached && $fanoutSuccessCalls === 2);
    $assert('non-strict fanout failure does not apply failed-provider effects', $render($fanoutFailedTag) === $fanoutFailedCached && $fanoutFailedCalls === 1);

    echo "\n[Manifest validation]\n";
    $validManifest = [
        'capabilities' => [
            'exposes' => [[
                'id' => 'test.phase64.manifest@1',
                'effects' => [
                    'invalidates' => ['entity.list.products', 'entity.detail.products'],
                    'future_field' => true,
                ],
                'future_field' => true,
            ]],
            'depends' => [],
        ],
    ];
    $assert('effects.invalidates shape is accepted additively', validateModuleCapabilities($validManifest)['ok'] === true);
    $assert('legacy schema-v1 expose remains valid', validateModuleCapabilities(['capabilities' => ['exposes' => [['id' => 'test.phase64.legacy@1']], 'depends' => []]])['ok'] === true);
    $invalidArray = $validManifest;
    $invalidArray['capabilities']['exposes'][0]['effects']['invalidates'] = 'entity.list.products';
    $assert('effects.invalidates must be an array', validateModuleCapabilities($invalidArray)['ok'] === false);
    $invalidTag = $validManifest;
    $invalidTag['capabilities']['exposes'][0]['effects']['invalidates'] = [''];
    $assert('effects.invalidates entries must be non-empty entity tags', validateModuleCapabilities($invalidTag)['ok'] === false);
} finally {
    $engine->setFragmentStore($originalStore);
    $resolver->reset();
    $removeTree($tmpRoot);
}

echo "\n" . str_repeat('=', 64) . "\n";
echo "Total: " . ($pass + $fail) . "  PASS: {$pass}  FAIL: {$fail}\n";
exit($fail === 0 ? 0 : 1);
