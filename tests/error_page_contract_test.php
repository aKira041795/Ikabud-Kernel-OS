<?php

/**
 * Unified Error Pages Contract Test
 *
 * Verifies that pages/404, pages/500, pages/entry-module-unavailable and
 * pages/maintenance all render through the single shared error-page partial
 * (templates/pages/partials/error-page.disyl) with the standard context
 * contract and NO strict-mode / undefined-variable warnings:
 *
 *   • every page renders a Tailwind-CDN error card with its status code
 *   • caller context overrides per-status defaults (status_code, page_title,
 *     error_title, message)
 *   • image/icon render with graceful defaults (built-in glyph map, default
 *     status glyph when nothing is supplied)
 *   • detail is rendered only when show_detail is explicitly enabled (never
 *     leaks on 500-style surfaces)
 *   • back_url renders the primary action button only when present
 *   • empty context still renders a graceful card without any warning
 *
 * Run: php tests/error_page_contract_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_support/env_guard.php';

requireWritableCompiledCache();

$pass = 0;
$fail = 0;
/** @var list<string> $errors */
$errors = [];
/** @var list<string> $phpWarnings */
$phpWarnings = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }
    $fail++;
    $errors[] = $label . ($detail !== '' ? ": {$detail}" : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function heading(string $label): void
{
    echo "\n=== {$label} ===\n";
}

/**
 * Render a page and return [html, engineErrors]. Any PHP warning/notice
 * mentioning undefined variables is captured into the shared $phpWarnings
 * list so the render itself can be asserted warning-free.
 *
 * @param array<string, mixed> $context
 * @return array{string, list<string>}
 */
function renderPage(string $page, array $context): array
{
    global $phpWarnings;
    $html = '';
    $before = count($phpWarnings);
    try {
        $html = app()->templates()->render('pages/' . $page, $context);
    } catch (Throwable $e) {
        $html = '';
    }
    foreach (array_slice($phpWarnings, $before) as $warning) {
        fwrite(STDERR, "PHP warning during {$page} render: {$warning}\n");
    }

    return [$html, app()->templates()->getErrors()];
}

heading('Environment');

set_error_handler(static function (int $severity, string $message): bool {
    global $phpWarnings;
    if (str_contains($message, 'Undefined variable') || str_contains($message, 'Undefined array key')) {
        $phpWarnings[] = $message;
    }
    return false;
});
t('template engine is available', function_exists('app') && method_exists(app()->templates(), 'render'));

heading('Every error page renders the unified card with its status code');

$pages = [
    ['404', 404],
    ['500', 500],
    ['entry-module-unavailable', 503],
    ['maintenance', 503],
];
/** @var array<string, string> $rendered */
$rendered = [];
foreach ($pages as [$page, $expectedStatus]) {
    [$html, $engineErrors] = renderPage($page, ['base_url' => '']);
    $rendered[$page] = $html;
    t(
        "{$page} renders without throwing",
        $html !== '' && str_contains($html, '<'),
        'length=' . strlen($html)
    );
    t(
        "{$page} renders with zero engine warnings",
        $engineErrors === [],
        implode('; ', $engineErrors)
    );
    t(
        "{$page} emits the shared Tailwind error card",
        str_contains($html, 'https://cdn.tailwindcss.com')
            && str_contains($html, 'bg-white/95')
            && str_contains($html, 'rounded-2xl'),
        'missing tailwind card markup'
    );
    t(
        "{$page} shows HTTP {$expectedStatus}",
        str_contains($html, 'HTTP ' . $expectedStatus),
        'status code missing'
    );
}

heading('No strict-mode / undefined-variable warnings on any surface');

t('no PHP undefined-variable warnings during renders', $phpWarnings === [], implode('; ', $phpWarnings));

heading('Standard context contract overrides per-status defaults');

// Caller-supplied context wins over the shared per-status defaults.
[$html] = renderPage('404', [
    'status_code' => 403,
    'page_title' => 'Access denied',
    'error_title' => 'Permission missing',
    'message' => 'A custom contract message.',
    'back_url' => '/login',
    'action_label' => 'Sign in',
    'icon' => 'lock',
]);
t('contract overrides status headline + copy', str_contains($html, 'Permission missing') && str_contains($html, 'A custom contract message.'));
t('contract overrides status code display', str_contains($html, 'HTTP 403'));
t('back_url renders the action button only when present', str_contains($html, 'href="/login"') && str_contains($html, 'Sign in'));

[$htmlNoButton] = renderPage('404', ['base_url' => '']);
t('no action button when back_url is absent', !str_contains($htmlNoButton, 'bg-kernel-600 text-white'));

heading('image / icon provision with graceful defaults');

[$defaultGlyph] = renderPage('404', ['base_url' => '']);
t('empty context falls back to a default status glyph', str_contains($defaultGlyph, '<svg') && str_contains($defaultGlyph, 'HTTP 404'));

[$lockIcon] = renderPage('500', ['icon' => 'lock', 'status_code' => 403]);
t('named icon (lock) renders its inline SVG glyph', str_contains($lockIcon, '<rect x="4" y="10"') && !str_contains($lockIcon, 'wrench'));

[$toolIcon] = renderPage('500', ['icon' => 'tool', 'status_code' => 503]);
t('named icon (tool) renders its inline SVG glyph', str_contains($toolIcon, '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6'));

[$imageIcon] = renderPage('404', ['image' => '/assets/error-oops.svg', 'icon' => 'lock']);
t('image wins over icon when both provided', str_contains($imageIcon, 'src="/assets/error-oops.svg"') && !str_contains($imageIcon, '<rect x="4" y="10"'));

[$unknownIcon] = renderPage('404', ['icon' => 'not-a-real-icon']);
t('unknown icon name degrades to the default glyph', str_contains($unknownIcon, '<circle cx="12" cy="12" r="10"/>'));

heading('detail is gated behind the safe show_detail flag');

[$detailHidden] = renderPage('500', ['base_url' => '', 'detail' => 'TOP SECRET EXCEPTION MESSAGE', 'show_detail' => false]);
t('500-style surface never exposes detail without show_detail', !str_contains($detailHidden, 'TOP SECRET EXCEPTION MESSAGE'));

[$detailHiddenDefault] = renderPage('500', ['base_url' => '', 'detail' => 'TOP SECRET EXCEPTION MESSAGE']);
t('detail stays hidden when show_detail is not supplied', !str_contains($detailHiddenDefault, 'TOP SECRET EXCEPTION MESSAGE'));

[$detailShown] = renderPage('entry-module-unavailable', ['base_url' => '', 'detail' => 'Entry module: cms-akira-shell', 'show_detail' => true]);
t('safe detail renders when show_detail is explicitly enabled', str_contains($detailShown, 'Entry module: cms-akira-shell'));

heading('500 hardening guarantees');

$html500 = $rendered['500'] ?? '';
// @phpstan-ignore greater.alwaysFalse (render output is populated dynamically)
t('500 output is non-empty HTML', strlen($html500) > 50 && str_contains($html500, '<'));
t(
    '500 output does not leak exception/stack text',
    !str_contains($html500, 'Exception')
        && !str_contains($html500, 'Stack trace')
        && !str_contains($html500, '#0 '),
    'trace leaked'
);

heading('Shared partial is the single source for all four pages');

$partial = (string)@file_get_contents(__DIR__ . '/../templates/pages/partials/error-page.disyl');
$pagesSource = '';
foreach (['404', '500', 'entry-module-unavailable', 'maintenance'] as $page) {
    $pagesSource .= (string)@file_get_contents(__DIR__ . '/../templates/pages/' . $page . '.disyl');
}
t('every page delegates to the shared error-page partial', substr_count($pagesSource, 'pages/partials/error-page.disyl') === 4);
t('shared partial declares the Tailwind CDN in <head>', str_contains($partial, 'https://cdn.tailwindcss.com'));

restore_error_handler();

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($errors !== [] ? 1 : 0);
