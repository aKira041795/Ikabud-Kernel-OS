<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$moduleRoot = $root . '/modules/cms-akira';
$forbidden = ['name=' . '"_csrf_token"', "name='" . '_csrf_token' . "'"];
$violations = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($moduleRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile() || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR)) {
        continue;
    }

    $source = file_get_contents($file->getPathname());
    if (!is_string($source)) {
        continue;
    }

    foreach ($forbidden as $fieldName) {
        if (str_contains($source, $fieldName)) {
            $violations[] = str_replace($root . '/', '', $file->getPathname());
            break;
        }
    }
}

$themeHelpers = (string) file_get_contents($moduleRoot . '/cms-akira-theme/helpers.php');
$shellHelpers = (string) file_get_contents($moduleRoot . '/cms-akira-shell/helpers.php');
$delegates = str_contains($themeHelpers, 'function catThemeCsrfField(): string')
    && str_contains($shellHelpers, 'function akiraShellCsrfField(): string')
    && substr_count($themeHelpers . $shellHelpers, 'return app()->csrfField();') === 2;

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

$check($violations === [], 'CMS Akira production files never emit the legacy CSRF field name', implode(', ', $violations));
$check($delegates, 'CMS Akira form helpers delegate to the Kernel CSRF field renderer');

echo "CMS Akira CSRF field contract: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
