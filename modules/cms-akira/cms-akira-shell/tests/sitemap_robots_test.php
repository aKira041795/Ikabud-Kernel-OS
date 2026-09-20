<?php

/**
 * Sitemap and robots.txt contract for cms-akira-shell.
 *
 * Pure source + builder test: it reads the manifest, routes and helper source
 * and exercises the XML/robots builders with synthetic projected rows. It boots
 * nothing and touches no database, so it runs on every checkout.
 *
 * What this proves: route wiring, the reasoned public-route authority
 * exemptions (never a capability declaration — dispatch refuses anonymous
 * callers), request-derived absolute origins, canonical `url` derivation,
 * XML escaping, lastmod presence/omission, the entry cap notice, and the
 * robots body.
 *
 * What it cannot prove and what still needs a live HTTP check: that a running
 * host answers 200 with the declared Content-Type for an anonymous request.
 * CLI cannot observe response headers (headers_list() is empty), and the
 * sitemap handler reaches tenant data through the capability bus, so the
 * end-to-end response is a live-host assertion, not a unit one.
 */

declare(strict_types=1);

$GLOBALS['p6c_test_scheme'] = 'http';
if (!function_exists('request_scheme')) {
    function request_scheme(): string
    {
        return (string) ($GLOBALS['p6c_test_scheme'] ?? 'http');
    }
}

$root = dirname(__DIR__);
require_once $root . '/helpers.php';

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    $ok ? ++$pass : ++$fail;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . "\n";
};

$manifest = json_decode((string) file_get_contents($root . '/module.json'), true);
$routes = require $root . '/routes.php';
$handlers = (string) file_get_contents($root . '/handlers.php');
$helpers = (string) file_get_contents($root . '/helpers.php');

echo "=== route wiring ===\n";
$check(($routes['GET']['/sitemap.xml'] ?? '') === 'cms-akira-shell:akiraPublicSitemap', 'GET /sitemap.xml resolves to the sitemap handler');
$check(($routes['GET']['/robots.txt'] ?? '') === 'cms-akira-shell:akiraPublicRobots', 'GET /robots.txt resolves to the robots handler');
$check(str_contains($handlers, 'function akiraPublicSitemap('), 'sitemap handler function is defined');
$check(str_contains($handlers, 'function akiraPublicRobots('), 'robots handler function is defined');
$check(str_contains($handlers, "header('Content-Type: application/xml; charset=utf-8')"), 'sitemap handler serves application/xml');
$check(str_contains($handlers, "header('Content-Type: text/plain; charset=utf-8')"), 'robots handler serves text/plain');
$check(($routes['GET']['/'] ?? '') === 'cms-akira-shell:akiraPublicHome' && ($routes['GET']['/posts'] ?? '') === 'cms-akira-shell:akiraPublicPostList' && ($routes['GET']['/posts/{slug}'] ?? '') === 'cms-akira-shell:akiraPublicPostSingle', 'existing public presentation routes are untouched');
$check(isset($routes['GET']['/cms-akira-shell/login']) && isset($routes['GET']['/cms-akira-shell/health']), 'existing entry and health routes are untouched');

echo "\n=== public-route authority (exemption, not declaration) ===\n";
$exemptions = is_array($manifest['governance']['exemptions'] ?? null) ? $manifest['governance']['exemptions'] : [];
$exemptReasons = [];
foreach ($exemptions as $item) {
    if (is_array($item)) {
        $exemptReasons[strtoupper((string) ($item['method'] ?? '')) . ' ' . (string) ($item['route'] ?? '')] = trim((string) ($item['reason'] ?? ''));
    }
}
$check(($exemptReasons['GET /sitemap.xml'] ?? '') !== '', 'GET /sitemap.xml carries a reasoned governance exemption');
$check(($exemptReasons['GET /robots.txt'] ?? '') !== '', 'GET /robots.txt carries a reasoned governance exemption');
$check(
    !isset($manifest['capabilities']['routes']['GET /sitemap.xml'])
    && !isset($manifest['capabilities']['routes']['GET /robots.txt']),
    'public crawler routes are not capability-declared (dispatch denies anonymous empty-role callers)'
);
$check(in_array('entity.list.post@1', $manifest['capabilities']['depends'] ?? [], true), 'shell depends on the existing published-post read capability');
$check(($manifest['owns_tables'] ?? null) === [] && ($manifest['reads_tables'] ?? null) === [], 'shell remains table-free');

echo "\n=== published-read reuse, no second filter, no SQL ===\n";
$postsBody = '';
if (preg_match('/function akiraPublicSitemapPosts\(\): array\s*\{(.*?)\n\}/s', $helpers, $match) === 1) {
    $postsBody = $match[1];
}
$check($postsBody !== '' && str_contains($postsBody, "akiraShellCall('entity.list.post@1'"), 'sitemap reads posts through the existing entity.list.post@1 published read');
$check($postsBody !== '' && !str_contains($postsBody, 'include_unpublished'), 'sitemap does not request unpublished posts');
$check($postsBody !== '' && preg_match('/\bstatus\b/', $postsBody) !== 1, 'sitemap adds no second status filter');
$check(stripos($helpers, 'app()->db()') === false && stripos($handlers, 'app()->db()') === false, 'shell helpers and handlers remain SQL-free');

echo "\n=== non-HTML page-cache bypass ===\n";
$_GET = [];
$_SERVER['REQUEST_URI'] = '/sitemap.xml';
akiraPublicBypassPageCacheForNonHtml();
$check(($_GET['nocache'] ?? '') === '1', 'sitemap opts out of the HTML page cache so its Content-Type survives');
$_GET = [];
$_SERVER['REQUEST_URI'] = '/robots.txt';
akiraPublicBypassPageCacheForNonHtml();
$check(($_GET['nocache'] ?? '') === '1', 'robots opts out of the HTML page cache');
$_GET = [];
$_SERVER['REQUEST_URI'] = '/posts';
akiraPublicBypassPageCacheForNonHtml();
$check(!isset($_GET['nocache']), 'ordinary public pages keep the page cache');
unset($_SERVER['REQUEST_URI']);
$_GET = [];

echo "\n=== request-derived origin ===\n";
$GLOBALS['p6c_test_scheme'] = 'https';
$_SERVER['HTTP_HOST'] = 'tenant-a.example.test';
$check(akiraPublicRequestOrigin() === 'https://tenant-a.example.test', 'origin uses the request scheme and host');
$GLOBALS['p6c_test_scheme'] = 'http';
$_SERVER['HTTP_HOST'] = 'tenant-b.example.test:8080';
$check(akiraPublicRequestOrigin() === 'http://tenant-b.example.test:8080', 'origin follows a different requesting host and port');
$_SERVER['HTTP_HOST'] = "evil.test</loc><script>alert(1)</script>";
$check(akiraPublicRequestOrigin() === '', 'an injected Host header yields no origin rather than an attacker-shaped URL');
unset($_SERVER['HTTP_HOST']);
$check(akiraPublicRequestOrigin() === '', 'a missing Host header yields no origin');

echo "\n=== sitemap document ===\n";
$posts = [
    ['url' => '/posts/hello-world', 'metadata' => '2026-01-02 03:04:05'],
    ['url' => '/posts/tricky-<&>"title', 'metadata' => ''],
    ['url' => '/posts/no-date'],
    ['url' => 'https://evil.example/post', 'metadata' => '2026-01-01 00:00:00'],
    ['url' => '//evil.example/post'],
    ['not' => 'a post'],
];
$origin = 'https://tenant-c.example.test';
$xml = akiraPublicSitemapUrlset($posts, $origin);
$check(str_starts_with($xml, '<?xml version="1.0" encoding="UTF-8"?>'), 'sitemap emits an XML declaration');
$check(str_contains($xml, 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'), 'sitemap declares the sitemap urlset namespace');

$doc = new DOMDocument();
$loaded = @$doc->loadXML($xml);
$check($loaded === true, 'sitemap parses as valid XML even with hostile post values');
$locs = [];
$lastmods = [];
if ($loaded === true) {
    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');
    foreach ($xpath->query('//sm:url/sm:loc') as $node) {
        $locs[] = $node->textContent;
    }
    foreach ($xpath->query('//sm:url/sm:lastmod') as $node) {
        $lastmods[] = $node->textContent;
    }
}
$check($locs === [
    'https://tenant-c.example.test/posts/hello-world',
    'https://tenant-c.example.test/posts/tricky-<&>"title',
    'https://tenant-c.example.test/posts/no-date',
], 'every loc is absolute on the request host and only canonical path URLs are kept');
$check(in_array('https://tenant-c.example.test/posts/tricky-<&>"title', $locs, true), 'a hostile URL value survives XML parsing unescaped at the text layer');
$check($lastmods === ['2026-01-02T03:04:05'], 'lastmod is emitted only where a real timestamp exists');

$check(akiraPublicSitemapLastmod('2026-01-02') === '2026-01-02', 'a date-only timestamp is accepted');
$check(akiraPublicSitemapLastmod('2026-02-30 00:00:00') === null, 'an impossible calendar date is omitted, not invented');
$check(akiraPublicSitemapLastmod('') === null && akiraPublicSitemapLastmod(null) === null && akiraPublicSitemapLastmod('not a date') === null, 'absent and unparseable timestamps omit lastmod');

$truncated = akiraPublicSitemapUrlset($posts, $origin, true);
$check(str_contains($truncated, '<!-- truncated at ' . akiraPublicSitemapMaxEntries() . ' entries -->'), 'a capped sitemap says so in the document rather than truncating silently');
$emptyOrigin = akiraPublicSitemapUrlset($posts, '');
$check(str_contains($emptyOrigin, '<urlset') && !str_contains($emptyOrigin, '<loc>'), 'no origin means no fabricated loc entries');

echo "\n=== robots.txt ===\n";
$robots = akiraPublicRobotsTxt('https://tenant-c.example.test');
$check(str_contains($robots, 'User-agent: *') && str_contains($robots, 'Allow: /'), 'robots allows normal crawling');
$check(str_contains($robots, 'Sitemap: https://tenant-c.example.test/sitemap.xml'), 'robots names the sitemap absolutely on the request host');
$check(!str_contains($robots, 'Disallow') && !str_contains($robots, 'Crawl-delay'), 'robots adds no disallow rules and no crawl delay');
$check(akiraPublicRobotsTxt('') === "User-agent: *\nAllow: /\n", 'robots still allows crawling when no origin resolves');

echo "\nsitemap/robots contract: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
