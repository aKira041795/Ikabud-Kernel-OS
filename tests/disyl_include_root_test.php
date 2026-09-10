<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/**
 * Theme-rooted renders must resolve relative {include}s against the theme root.
 *
 * Regression guard for a silent production defect: block definitions expose
 * renderer.template = 'blocks/<id>.disyl' (theme-relative), and the theme's
 * composition view includes them by that name. Resolved against the application
 * templates directory those includes were never found, the compiled loader
 * logged "Template include not found", substituted an empty template, and every
 * composed page rendered an empty <article> — with no error surfaced. The proof
 * that shipped earlier missed it because it rendered through a theme-rooted
 * engine, where the include resolved by accident of templateDir.
 *
 * @var list<string> $failures
 */
$failures = [];

function includeRootCheck(string $label, bool $condition, string $detail = ''): void
{
    global $failures;
    echo ($condition ? '  ✓ ' : '  ✗ ') . $label . "\n";
    if (!$condition) {
        $failures[] = $label . ($detail === '' ? '' : ': ' . $detail);
    }
}

$resolver = app()->arkRenderers();
$selection = $resolver->resolve('entity.detail.composition', 'akira-ark');

if (!is_array($selection) || ($selection['path'] ?? null) === null || !is_file((string) $selection['path'])) {
    fwrite(STDERR, "Akira theme does not expose entity.detail.composition\n");
    exit(1);
}

$viewPath = (string) $selection['path'];
$themeRoot = dirname($viewPath, 2);
$marker = 'INCLUDE-ROOT-MARKER';
$context = ['composition' => [
    'entity_type' => 'post',
    'entity_key' => 'include-root-probe',
    'title' => 'Include root probe',
    'revision_id' => 1,
    'source' => 'preview',
    'sections' => [[
        'template' => 'blocks/hero.disyl',
        'props' => [
            'eyebrow' => 'Probe',
            'title' => $marker,
            'subtitle' => 'Sub',
            'cta_label' => 'Go',
            'cta_href' => '/go',
        ],
    ]],
]];

$engine = app()->templates();

$rooted = $engine->renderWithin($viewPath, $context, $themeRoot);
includeRootCheck(
    'theme-relative block include renders content when the render is rooted at the theme',
    str_contains($rooted, $marker),
    $rooted
);

$unrooted = $engine->render($viewPath, $context);
includeRootCheck(
    'the same include yields no block content without an include root',
    !str_contains($unrooted, $marker),
    $unrooted
);

$restored = $engine->render($viewPath, $context);
includeRootCheck(
    'include root does not leak into later renders on the shared engine',
    !str_contains($restored, $marker),
    $restored
);

$again = $engine->renderWithin($viewPath, $context, $themeRoot);
includeRootCheck(
    'rooted renders keep resolving after unrooted renders',
    str_contains($again, $marker),
    $again
);

if ($failures !== []) {
    fwrite(STDERR, "\n" . count($failures) . " failure(s):\n  - " . implode("\n  - ", $failures) . "\n");
    exit(1);
}

echo "DiSyL include root: PASS\n";
