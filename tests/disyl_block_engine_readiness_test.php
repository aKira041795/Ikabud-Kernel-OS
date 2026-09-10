<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

/** @var list<string> $failures */
$failures = [];

function readinessCheck(string $label, bool $condition, string $detail = ''): void
{
    global $failures;
    echo ($condition ? '  ✓ ' : '  ✗ ') . $label . "\n";
    if (!$condition) {
        $failures[] = $label . ($detail === '' ? '' : ': ' . $detail);
    }
}

$theme = __DIR__ . '/../storage/cms-themes/akira-ark';
$definitions = json_decode((string) file_get_contents($theme . '/block-definitions.json'), true, 512, JSON_THROW_ON_ERROR);
$templates = [];
foreach ($definitions['blocks'] as $definition) {
    $templates[$definition['id']] = $definition['renderer']['template'];
}

$tmp = sys_get_temp_dir() . '/disyl_block_readiness_' . getmypid();
mkdir($tmp . '/blocks', 0755, true);
foreach (glob($theme . '/blocks/*.disyl') ?: [] as $block) {
    copy($block, $tmp . '/blocks/' . basename($block));
}

$composition = <<<'DISYL'
{foreach sections as section}
{include section.template props=section.props}
{if section.children}{include "composition.disyl" sections=section.children}{/if}
{/foreach}
DISYL;
file_put_contents($tmp . '/composition.disyl', $composition);

$sections = [
    [
        'template' => $templates['hero'],
        'props' => [
            'eyebrow' => 'Proof', 'title' => '<Ready>', 'subtitle' => 'Dynamic',
            'cta_label' => 'Go', 'cta_href' => '/go',
        ],
        'children' => [[
            'template' => $templates['quote'],
            'props' => ['quote' => 'Nested', 'cite' => 'Child'],
        ]],
    ],
    [
        'template' => $templates['card-grid'],
        'props' => [
            'title' => 'Cards',
            'items' => [
                ['title' => 'One', 'text' => 'First', 'href' => '/one'],
                ['title' => 'Two', 'text' => 'Second', 'href' => ''],
            ],
        ],
    ],
];

$expectedFragments = [
    '<h1>&lt;Ready&gt;</h1>',
    '<blockquote>“Nested”</blockquote>',
    '<h2>Cards</h2>',
    '<h3>One</h3><p>First</p><a href="/one">Learn more →</a>',
    '<h3>Two</h3><p>Second</p>',
];

foreach ([false, true] as $compiled) {
    $engine = new TemplateEngine($tmp, $tmp . '/cache-' . ($compiled ? 'compiled' : 'interpreted'), false);
    $engine->enableCompiledMode($compiled);
    $output = $engine->render('composition.disyl', ['sections' => $sections]);
    foreach ($expectedFragments as $fragment) {
        readinessCheck(($compiled ? 'compiled' : 'interpreted') . ' output contains ' . $fragment, str_contains($output, $fragment), $output);
    }
    readinessCheck(($compiled ? 'compiled' : 'interpreted') . ' dynamic recursion has no errors', $engine->getErrors() === [], implode(' | ', $engine->getErrors()));
}

$deep = ['template' => $templates['quote'], 'props' => ['quote' => 'leaf', 'cite' => '']];
for ($i = 0; $i < 25; $i++) {
    $deep = ['template' => $templates['quote'], 'props' => ['quote' => (string) $i, 'cite' => ''], 'children' => [$deep]];
}
$guardEngine = new TemplateEngine($tmp, $tmp . '/cache-guard', false);
$guardEngine->enableCompiledMode(true);
$guardOutput = $guardEngine->render('composition.disyl', ['sections' => [$deep]]);
readinessCheck(
    'recursive composition is stopped by the compiled include-depth guard',
    substr_count($guardOutput, '<blockquote>') <= 20,
    $guardOutput
);

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($iterator as $path) {
    $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
}
rmdir($tmp);

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "DiSyL block engine readiness: PASS\n";
