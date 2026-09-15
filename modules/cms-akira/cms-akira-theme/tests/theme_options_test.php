<?php

/** Pure contract checks for the akira-editorial design surface. */

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/kernel/Contracts/ControlDefinition.php';
require_once dirname(__DIR__) . '/helpers.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

$theme = $root . '/storage/cms-themes/akira-editorial';
$tokens = json_decode((string)file_get_contents($theme . '/tokens.json'), true, 512, JSON_THROW_ON_ERROR);
$schema = json_decode((string)file_get_contents($theme . '/customizer.schema.json'), true, 512, JSON_THROW_ON_ERROR);
$layout = (string)file_get_contents($theme . '/layouts/public.disyl');
$handlers = (string)file_get_contents(dirname(__DIR__) . '/handlers.php');
$shell = (string)file_get_contents(dirname(__DIR__, 2) . '/cms-akira-shell/helpers.php');

$controls = [];
foreach ($schema['sections'] ?? [] as $sectionId => $section) {
    foreach ($section['controls'] ?? [] as $controlId => $control) {
        $controls[$sectionId . '.' . $controlId] = $control;
    }
}
$check(count($controls) === 43, 'customizer exposes 43 reachable controls', 'count=' . count($controls));

$newIds = [
    'font_size_base', 'line_height_body', 'font_size_display_max', 'font_size_headline_lg_max',
    'font_size_headline_md', 'font_size_headline_sm', 'heading_letter_spacing_adjust',
    'color_link', 'color_heading', 'color_button_background', 'color_button_text',
    'color_button_hover_background', 'color_field_border', 'color_field_focus', 'color_code_surface',
    'button_radius', 'button_padding_block', 'button_padding_inline', 'card_shadow', 'header_height',
];
$missingDefaults = [];
$missingConsumers = [];
foreach ($newIds as $id) {
    $token = '--' . str_replace('_', '-', $id);
    $control = null;
    foreach ($schema['sections'] as $section) {
        if (isset($section['controls'][$id])) {
            $control = $section['controls'][$id];
            break;
        }
    }
    if (!is_array($control) || !array_key_exists($token, $tokens) || ($control['default'] ?? null) !== $tokens[$token]) {
        $missingDefaults[] = $id;
    }
    if (!str_contains($layout, $token . ':') || !str_contains($layout, 'var(' . $token . ')')) {
        $missingConsumers[] = $id;
    }
}
$check($missingDefaults === [], 'every added control has a matching token default', implode(', ', $missingDefaults));
$check($missingConsumers === [], 'every added token is declared and consumed by public CSS', implode(', ', $missingConsumers));

$numericIds = [
    'font_size_base', 'line_height_body', 'font_size_display_max', 'font_size_headline_lg_max',
    'font_size_headline_md', 'font_size_headline_sm', 'heading_letter_spacing_adjust',
    'button_radius', 'button_padding_block', 'button_padding_inline', 'header_height',
];
$badNumericSchemas = [];
foreach ($numericIds as $id) {
    $control = null;
    foreach ($schema['sections'] as $section) {
        if (isset($section['controls'][$id])) {
            $control = $section['controls'][$id];
            break;
        }
    }
    if (($control['type'] ?? null) !== 'number'
        || !isset($control['constraints']['min'], $control['constraints']['max'], $control['constraints']['step'])) {
        $badNumericSchemas[] = $id;
    }
}
$check($badNumericSchemas === [], 'all metric controls are bounded number controls', implode(', ', $badNumericSchemas));
$check(
    str_contains($handlers, "['color', 'number']")
    && str_contains($handlers, 'if ($type === \'select\')')
    && str_contains($handlers, "['min', 'max', 'step']"),
    'Theme Studio renders number constraints and select controls'
);
$check(
    str_contains($shell, "'--' . str_replace('_', '-', (string) \$key)")
    && str_contains($shell, "\$tokens[\$tokenKey]['default'] = \$value"),
    'public shell merge maps control ids to token ids and applies saved values'
);

$number = new \Ikabud\Kernel\Contracts\ControlDefinition(
    id: 'font_size_base',
    label: 'Base font size',
    type: 'number',
    default: 15,
    constraints: ['min' => 12, 'max' => 24, 'step' => 1],
);
$validAccepted = true;
try {
    catThemeValidateNumericControlValue('18', $number, 'design_typography.font_size_base');
} catch (Throwable) {
    $validAccepted = false;
}
$check($validAccepted, 'server numeric validation accepts an in-range number');

foreach (['not-a-number', '11', '25'] as $badValue) {
    $rejected = false;
    try {
        catThemeValidateNumericControlValue($badValue, $number, 'design_typography.font_size_base');
    } catch (CatThemeException $error) {
        $rejected = $error->httpStatus === 422;
    }
    $check($rejected, "server numeric validation rejects {$badValue} with 422");
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
