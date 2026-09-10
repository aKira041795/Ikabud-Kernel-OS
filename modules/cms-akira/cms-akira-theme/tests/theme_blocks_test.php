<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers.php';

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    echo ($condition ? "  ✓ " : "  ✗ ") . $label . "\n";
    $condition ? $passed++ : $failed++;
};

$real = realpath(dirname(__DIR__, 4) . '/storage/cms-themes/akira-ark');
$check(is_string($real) && catThemeBlockErrors($real) === [], 'akira-ark block catalogue satisfies the canonical contract');
$check(is_string($real) && count(catThemeBlocks($real)) >= 5, 'akira-ark provides at least five typed blocks');

$tmp = sys_get_temp_dir() . '/akira-blocks-' . bin2hex(random_bytes(5));
mkdir($tmp . '/blocks', 0700, true);
file_put_contents($tmp . '/blocks/good.disyl', '<p>{props.title | esc_html}</p>');
$base = [
    'id' => 'same', 'label' => 'Same', 'category' => 'content',
    'schema' => ['props' => ['title' => ['type' => 'string', 'label' => 'Title', 'default' => '']]],
    'defaults' => ['title' => ''], 'slots' => [],
    'renderer' => ['template' => 'blocks/good.disyl', 'context_keys' => ['props']],
    'contract_version' => '1.0.0',
];
$bad = $base;
$bad['schema']['props']['title']['type'] = 'html';
$bad['renderer']['template'] = '../missing.php';
file_put_contents($tmp . '/block-definitions.json', json_encode(['contract_version' => '1.0.0', 'blocks' => [$base, $bad]], JSON_THROW_ON_ERROR));
$errors = catThemeBlockErrors($tmp);
$text = implode("\n", $errors);
$check(str_contains($text, "duplicate id 'same'") && str_contains($text, "type is not allowed") && str_contains($text, "../missing.php"), 'hostile duplicate, prop type, and escaping renderer are rejected with paths');

@unlink($tmp . '/blocks/good.disyl');
@rmdir($tmp . '/blocks');
@unlink($tmp . '/block-definitions.json');
@rmdir($tmp);
echo "\nTheme blocks: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
