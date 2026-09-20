<?php

/** Page-cache Content-Type persistence and emission contract. */

declare(strict_types=1);

$root = dirname(__DIR__);
$_SERVER['HTTP_HOST'] = 'akiracms.test';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['QUERY_STRING'] = '';
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/page-cache.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

/** @param list<string> $headers */
$hasHeader = static fn (array $headers, string $expected): bool => in_array($expected, $headers, true);
$prefix = '/page-cache-content-type-test-' . bin2hex(random_bytes(6));
$uris = [];

echo "=== page-cache Content-Type contract ===\n";

try {
    $check(
        pageCacheResolveContentType(['X-Test: one', 'cOnTeNt-TyPe: application/json']) === 'application/json',
        'resolver finds Content-Type case-insensitively'
    );
    $check(
        pageCacheResolveContentType([]) === 'text/html; charset=UTF-8',
        'resolver has the deterministic historical HTML fallback'
    );

    $types = [
        'application/xml; charset=utf-8' => '<?xml version="1.0"?><root>' . str_repeat('xml payload ', 12) . '</root>',
        'text/plain; charset=utf-8' => str_repeat('plain text payload ', 12),
        'text/html; charset=UTF-8' => '<!doctype html><html><body>' . str_repeat('html payload ', 12) . '</body></html>',
    ];
    $index = 0;
    foreach ($types as $contentType => $body) {
        $uri = $prefix . '-' . $index++;
        $uris[] = $uri;
        pageCacheSet($uri, $body, 'content-type-test', 200, $contentType);
        $entry = pageCacheGet($uri);
        $check(
            is_array($entry) && ($entry['content_type'] ?? null) === $contentType,
            $contentType . ' round-trips through cache storage'
        );
        $serveHeaders = is_array($entry) ? pageCacheServeHeaders($entry) : [];
        $check(
            $hasHeader($serveHeaders, 'Content-Type: ' . $contentType),
            $contentType . ' is selected by the real serve-path header function'
        );
    }

    $legacyHeaders = pageCacheServeHeaders([
        'html' => '<legacy response>',
        'status' => 200,
        'etag' => md5('<legacy response>'),
    ]);
    $check(
        $hasHeader($legacyHeaders, 'Content-Type: text/html; charset=UTF-8'),
        'legacy entries without content_type retain the historical HTML type'
    );

    $untypedNonHtmlUri = $prefix . '-untyped-non-html';
    $uris[] = $untypedNonHtmlUri;
    pageCacheSet($untypedNonHtmlUri, str_repeat('untyped plain payload ', 10), 'content-type-test');
    $check(pageCacheGet($untypedNonHtmlUri) === null, 'untyped non-HTML bodies fail safe and are not cached');

    $legacyCallerUri = $prefix . '-legacy-caller';
    $uris[] = $legacyCallerUri;
    pageCacheSet(
        $legacyCallerUri,
        '<!doctype html><html><body>' . str_repeat('legacy caller html ', 10) . '</body></html>',
        'content-type-test'
    );
    $legacyCallerEntry = pageCacheGet($legacyCallerUri);
    $check(
        is_array($legacyCallerEntry)
            && ($legacyCallerEntry['content_type'] ?? null) === 'text/html; charset=UTF-8',
        'existing four-argument HTML callers keep working with the deterministic fallback'
    );
} finally {
    foreach ($uris as $uri) {
        pageCacheInvalidateUrl($uri);
    }
}

echo "\nPage cache Content-Type: {$passed} passed, {$failed} failed, 0 skipped\n";
exit($failed > 0 ? 1 : 0);
