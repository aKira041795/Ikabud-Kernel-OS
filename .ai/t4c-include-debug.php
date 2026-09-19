<?php

declare(strict_types=1);

/** Debug why the include-root test dies inside bootstrap/harness. */

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';

try {
    require __DIR__ . '/../bootstrap.php';
    echo "bootstrap ok\n";

    $root = sys_get_temp_dir() . '/disyl_include_debug_' . getmypid();
    @mkdir($root . '/blocks', 0755, true);
    @mkdir($root . '/entity-views', 0755, true);
    file_put_contents($root . '/blocks/hero.disyl', '<div>THEME-BLOCK-CONTENT</div>');
    file_put_contents($root . '/entity-views/composition.disyl', '<article>{foreach sections as section}{include section.template props=section.props}{/foreach}</article>');

    $engine = app()->templates();
    echo "engine ok\n";

    $out = $engine->renderWithin($root . '/entity-views/composition.disyl', ['sections' => [['template' => 'blocks/hero.disyl', 'props' => []]]], $root);
    echo 'rooted len=' . strlen($out) . ' content=' . var_export(str_contains($out, 'THEME-BLOCK-CONTENT'), true) . PHP_EOL;
} catch (Throwable $error) {
    echo 'EXCEPTION: ' . $error::class . ': ' . $error->getMessage() . PHP_EOL;
    echo 'at ' . $error->getFile() . ':' . $error->getLine() . PHP_EOL;
    foreach (array_slice($error->getTrace(), 0, 8) as $frame) {
        echo '  ' . ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '') . ' @ ' . ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? '?') . PHP_EOL;
    }
}
