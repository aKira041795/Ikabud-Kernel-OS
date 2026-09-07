<?php

declare(strict_types=1);

/**
 * EntityViewResolver @1 fallback symmetry tests.
 */

$scenario = $argv[1] ?? '';
if ($scenario !== '') {
    require_once __DIR__ . '/../bootstrap.php';

    $app = app();
    $resolver = $app->entityViews();
    $resolver->reset();
    $resolver->registerView('post', 'compact', [
        'fields' => ['id', 'title'],
        'actions' => [],
    ]);
    $resolver->registerView('post', 'detailed', [
        'fields' => ['id', 'title'],
        'actions' => [],
    ]);

    $suffix = $scenario === 'versioned' ? '@1' : '';
    $app->capabilities()->register(
        'entity.list.post' . $suffix,
        '_resolver_symmetry_fixture',
        static fn (): array => [
            'rows' => [['id' => 7, 'title' => $scenario . '-list']],
            'total' => 1,
        ],
        100
    );
    $app->capabilities()->register(
        'entity.get.post' . $suffix,
        '_resolver_symmetry_fixture',
        static fn (): array => ['id' => 7, 'title' => $scenario . '-detail'],
        100
    );

    $list = $resolver->resolveAsResult('post');
    $detail = $resolver->resolveDetail('post', '7');
    echo json_encode([
        'only_expected_list_id' => $app->capabilities()->has('entity.list.post' . $suffix)
            && !$app->capabilities()->has('entity.list.post' . ($suffix === '' ? '@1' : '')),
        'only_expected_detail_id' => $app->capabilities()->has('entity.get.post' . $suffix)
            && !$app->capabilities()->has('entity.get.post' . ($suffix === '' ? '@1' : '')),
        'list_title' => $list->rows[0]['title'] ?? null,
        'detail_title' => $detail['entity']['title'] ?? null,
        'list_error' => $list->error,
        'detail_error' => $detail['error'] ?? null,
    ], JSON_THROW_ON_ERROR);
    exit(0);
}

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        ++$passed;
        echo "  PASS  {$label}\n";
        return;
    }
    ++$failed;
    echo "  FAIL  {$label}\n";
};

$runScenario = static function (string $name): array {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($name);
    $output = shell_exec($command);
    if (!is_string($output)) {
        return [];
    }
    try {
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    } catch (Throwable) {
        return [];
    }
};

echo "EntityViewResolver @1 fallback symmetry\n";
echo str_repeat('=', 64) . "\n";

$versioned = $runScenario('versioned');
$check(
    ($versioned['only_expected_list_id'] ?? false) === true
        && ($versioned['list_title'] ?? null) === 'versioned-list'
        && ($versioned['list_error'] ?? null) === null,
    'resolveAsResult uses entity.list.post@1 when it is the only registration'
);
$check(
    ($versioned['only_expected_detail_id'] ?? false) === true
        && ($versioned['detail_title'] ?? null) === 'versioned-detail'
        && ($versioned['detail_error'] ?? null) === null,
    'resolveDetail uses entity.get.post@1 when it is the only registration'
);

$unversioned = $runScenario('unversioned');
$check(
    ($unversioned['only_expected_list_id'] ?? false) === true
        && ($unversioned['list_title'] ?? null) === 'unversioned-list'
        && ($unversioned['list_error'] ?? null) === null,
    'resolveAsResult keeps an available unversioned entity.list.post registration'
);
$check(
    ($unversioned['only_expected_detail_id'] ?? false) === true
        && ($unversioned['detail_title'] ?? null) === 'unversioned-detail'
        && ($unversioned['detail_error'] ?? null) === null,
    'resolveDetail keeps an available unversioned entity.get.post registration'
);

echo "\nEntityViewResolver fallback: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
