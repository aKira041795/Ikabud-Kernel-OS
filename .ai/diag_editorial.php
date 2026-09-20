<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/modules/cms-akira/cms-akira-theme/helpers.php';

$slugs = ['akira-editorial', 'akira-ark', 'cms-akira-posts'];

foreach ($slugs as $slug) {
    echo "=== {$slug} ===\n";
    $manifest = catThemeManifest($slug);
    echo "  manifest:        " . var_export($manifest !== null, true) . "\n";
    $dir = catThemeDir($slug);
    echo "  dir:             " . var_export($dir, true) . "\n";
    $registry = $dir !== null ? catThemeRegistry($dir) : null;
    echo "  registry:        " . json_encode($registry) . "\n";
    $list = app()->arkRenderers()->resolve('entity.list.post', $slug);
    $detail = app()->arkRenderers()->resolve('entity.detail.post', $slug);
    echo "  resolve list:    " . var_export($list !== null, true) . "\n";
    echo "  resolve detail:  " . var_export($detail !== null, true) . "\n";
    echo "  ARK visible:     " . var_export(catThemeIsArkVisible($slug), true) . "\n";
    if ($dir !== null) {
        $shell = is_array($manifest) ? (string) ($manifest['shell'] ?? '') : '';
        echo "  shell:           {$shell}\n";
        echo "  shell exists:    " . var_export($shell !== '' && file_exists($dir . '/' . ltrim($shell, '/')), true) . "\n";
    }
    echo "\n";
}

echo "=== base path ===\n";
echo "  CMS_THEMES_PATH: " . (defined('CMS_THEMES_PATH') ? CMS_THEMES_PATH : '(undefined)') . "\n";
echo "  themes used:     " . (function_exists('catThemesPath') ? catThemesPath() : '(n/a)') . "\n";
