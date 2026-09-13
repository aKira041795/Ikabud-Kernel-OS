<?php

declare(strict_types=1);

/** Surface the exception the Ark renderer swallows when a theme view throws. */

$root = dirname(__DIR__);
$_SERVER['HTTP_HOST'] = 'akiracms.test';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/bootstrap.php';

$tenant = 54;
app()->tenant()->setTenantId($tenant);
kernel_request_context_set('tenant_id', $tenant);
app()->setUser(['id' => 1, 'role' => 'admin']);

$context = ['composition' => [
    'entity_type' => 'post',
    'entity_key' => 'probe',
    'title' => 'Probe',
    'revision_id' => 1,
    'source' => 'preview',
    'sections' => [['template' => 'blocks/hero.disyl', 'props' => ['title' => 'Probe Hero', 'eyebrow' => 'E']]],
]];

// Show the real exception: the app's handler replaces uncaught output with a page.
set_exception_handler(null);
ini_set('display_errors', '1');
error_reporting(E_ALL);

try {
    $resolver = app()->arkRenderers();
    $selection = $resolver->resolve('entity.detail.composition', 'akira-ark');
    echo 'selection: ' . json_encode($selection) . PHP_EOL;

    $out = app()->templates()->renderWithin((string) $selection['path'], $context, dirname((string) $selection['path'], 2));
    echo 'direct renderWithin len=' . strlen($out) . ' hasHeroText=' . var_export(str_contains($out, 'Probe Hero'), true) . PHP_EOL;
} catch (Throwable $e) {
    echo 'EXCEPTION: ' . $e::class . ': ' . $e->getMessage() . PHP_EOL;
    echo 'at ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
    foreach (array_slice($e->getTrace(), 0, 6) as $frame) {
        echo '  ' . ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '') . ' @ ' . ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? '?') . PHP_EOL;
    }
}
