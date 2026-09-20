<?php

declare(strict_types=1);

/**
 * CMS Akira post body sanitization contract.
 *
 * Authored post bodies are rich text rendered as markup (entity view bodies are
 * emitted with `|raw`), so the module must store inert HTML. These cases pin the
 * allowlist so a future escaping/rendering change cannot silently reopen stored XSS.
 */

$root = dirname(__DIR__, 4);
require $root . '/bootstrap.php';
require_once dirname(__DIR__) . '/helpers.php';
if (!function_exists('cacPostSanitizeHtml')) {
    require_once dirname(__DIR__) . '/helpers/capabilities.php';
}

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

/** @var list<array{0: string, 1: string, 2: string}> $exact */
$exact = [
    ['plain text is untouched', 'plain text', 'plain text'],
    ['safe markup is preserved', '<p>Hello <strong>world</strong></p>', '<p>Hello <strong>world</strong></p>'],
    ['empty body stays empty', '', ''],
    ['script is removed with its content', '<script>alert(1)</script><p>keep</p>', '<p>keep</p>'],
    ['event handler is stripped', '<img src="x" onerror="alert(1)">', '<img src="x">'],
    ['style attribute is stripped', '<p style="color:red">y</p>', '<p>y</p>'],
    ['unknown element is unwrapped but its text kept', '<div><foo>bar</foo></div>', '<div>bar</div>'],
];
foreach ($exact as [$label, $input, $expected]) {
    $actual = cacPostSanitizeHtml($input);
    $check($actual === $expected, $label, "expected '{$expected}' actual '{$actual}'");
}

/** @var list<array{0: string, 1: string, 2: string}> $absent */
$absent = [
    ['javascript: scheme is rejected', '<a href="javascript:alert(1)">x</a>', 'javascript'],
    ['obfuscated scheme is rejected', "<a href=\"java\nscript:alert(1)\">x</a>", 'script:'],
    ['data: scheme is rejected', '<img src="data:text/html;base64,PHNjcmlwdD4=">', 'data:'],
    ['iframe is removed', '<iframe src="http://evil.test"></iframe>x', '<iframe'],
    ['svg is removed', '<svg><script>x</script></svg>', '<svg'],
    ['form controls are removed', '<form action="/x"><input name="a"></form>', '<input'],
];
foreach ($absent as [$label, $input, $needle]) {
    $actual = cacPostSanitizeHtml($input);
    $check(!str_contains($actual, $needle), $label, "unexpected '{$needle}' in '{$actual}'");
}

$blankTarget = cacPostSanitizeHtml('<a href="https://ok.test" target="_blank">g</a>');
$check(str_contains($blankTarget, 'rel="noopener noreferrer"'), 'target=_blank gains rel=noopener', $blankTarget);
$check(
    str_contains(cacPostSanitizeHtml('<a href="https://ok.test">g</a>'), 'https://ok.test'),
    'https links are preserved',
    cacPostSanitizeHtml('<a href="https://ok.test">g</a>')
);

echo "\n" . ($failed === 0 ? 'PASS' : 'FAIL') . ": {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
