<?php

/**
 * CMS Akira P6 · bundle content identity — the plan must decide by CONTENT, not by a transported hash.
 *
 * CHAIR-OWNED ACCEPTANCE, written BEFORE the change, so a red baseline means the behaviour is absent
 * rather than that the test is missing.
 *
 * `cacBundlePlan()` classifies a bundle against the tenant's current entries. It compares the bundle
 * entry's **`hash` field** with the current entry's hash. But a bundle entry ALSO carries the `payload`
 * it was hashed from, and the plan never looks at it. So the identity of an entry is decided by a
 * caller-supplied string that is never verified against the content travelling beside it.
 *
 * `bundle.php` states the intended contract in its own comment: "Both bundle entries and tenant entries
 * hash through this exact function so a no-op replay classifies as a skip." A transported hash that is
 * absent, stale, or was computed over a different projection breaks that promise:
 *
 *   * no `hash` field      -> `'' !== <current hash>` -> every entry classifies `update`
 *   * a foreign projection -> every entry classifies `update`
 *
 * And that is not merely cosmetic. `cac_cap_akira_bundle_apply_1()` detects a replayed apply with
 * `$plan['add'] === [] && $plan['update'] === [] && $plan['skip'] !== []`. Phantom updates make that
 * guard unreachable, so a no-op replay claims an idempotency key and rewrites every post instead of
 * writing nothing.
 *
 * The projection is NOT the defect and must not be widened. `{title, subtitle, content, image}` is a
 * deliberate, documented change-detection projection; an entry whose only difference lies outside it is
 * a `skip` by design. This suite asserts that, so an over-eager "fix" that hashes whole rows fails.
 *
 * Pure on purpose: no database, no tenant, no session, no skip path. It cannot pass vacuously.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = 'akiracms.test';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
require_once dirname(__DIR__) . '/helpers.php';

$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

/** @param array<string,mixed> $entry */
$bundleOf = static fn(array $entry): array => ['entries' => [$entry]];
$classification = static function (array $plan, string $key): string {
    foreach (['add', 'update', 'skip', 'remove'] as $bucket) {
        if (in_array($key, $plan[$bucket] ?? [], true)) {
            return $bucket;
        }
    }
    return 'none';
};

$row = [
    'slug' => 'hello-world',
    'title' => 'Hello World',
    'subtitle' => 'a subtitle',
    'content' => '<p>hello</p>',
    'image' => null,
    'status' => 'draft',
    'updated_at' => '2026-01-01 00:00:00',
];
$current = cacBundleCurrentFromPosts([$row]);
$hash = cacBundlePostHash($row);
$payload = cacBundlePostPayload($row);

echo "=== the promise the planner already makes (guard: the probe is not vacuous) ===\n";
$plan = cacBundlePlan(
    $bundleOf(['kind' => 'post', 'key' => 'hello-world', 'hash' => $hash, 'payload' => $payload]),
    $current
);
$check(
    'a transported hash that matches the tenant classifies as a skip',
    $classification($plan, 'post:hello-world') === 'skip',
    $classification($plan, 'post:hello-world')
);

echo "\n=== the defect: identity must come from the CONTENT, not the transported hash ===\n";
$plan = cacBundlePlan(
    $bundleOf(['kind' => 'post', 'key' => 'hello-world', 'payload' => $payload]),
    $current
);
$check(
    'a payload that matches the tenant SKIPS even when no hash was transported',
    $classification($plan, 'post:hello-world') === 'skip',
    'got ' . $classification($plan, 'post:hello-world') . ' — a no-op replay would rewrite the post'
);

$plan = cacBundlePlan(
    $bundleOf(['kind' => 'post', 'key' => 'hello-world', 'hash' => str_repeat('0', 64), 'payload' => $payload]),
    $current
);
$check(
    'a STALE transported hash cannot override a matching payload',
    $classification($plan, 'post:hello-world') === 'skip',
    'got ' . $classification($plan, 'post:hello-world')
);

echo "\n=== and genuine change is still detected (the fix must not swallow updates) ===\n";
$changed = $row;
$changed['content'] = '<p>hello, edited</p>';
$changedPayload = cacBundlePostPayload($changed);
$plan = cacBundlePlan(
    $bundleOf([
        'kind' => 'post',
        'key' => 'hello-world',
        'hash' => cacBundlePostHash($changed),
        'payload' => $changedPayload,
    ]),
    $current
);
$check(
    'a DIFFERENT payload classifies as an update',
    $classification($plan, 'post:hello-world') === 'update',
    $classification($plan, 'post:hello-world')
);

$plan = cacBundlePlan(
    $bundleOf([
        'kind' => 'post',
        'key' => 'hello-world',
        'hash' => str_repeat('a', 64),
        'payload' => $changedPayload,
    ]),
    $current
);
$check(
    'a different payload updates even when the transported hash is nonsense',
    $classification($plan, 'post:hello-world') === 'update',
    $classification($plan, 'post:hello-world')
);

echo "\n=== an entry carrying no payload keeps the transported hash as its identity ===\n";
$plan = cacBundlePlan($bundleOf(['kind' => 'post', 'key' => 'hello-world', 'hash' => $hash]), $current);
$check(
    'no payload + matching hash is a skip',
    $classification($plan, 'post:hello-world') === 'skip',
    $classification($plan, 'post:hello-world')
);
$plan = cacBundlePlan($bundleOf(['kind' => 'post', 'key' => 'hello-world', 'hash' => str_repeat('b', 64)]), $current);
$check(
    'no payload + differing hash is an update',
    $classification($plan, 'post:hello-world') === 'update',
    $classification($plan, 'post:hello-world')
);

echo "\n=== the projection stays deliberately narrow (guard against widening it) ===\n";
$restatus = $row;
$restatus['status'] = 'published';
$restatus['updated_at'] = '2026-06-06 06:06:06';
$plan = cacBundlePlan(
    $bundleOf(['kind' => 'post', 'key' => 'hello-world', 'hash' => $hash, 'payload' => $payload]),
    cacBundleCurrentFromPosts([$restatus])
);
$check(
    'a change OUTSIDE {title, subtitle, content, image} is a skip, by design',
    $classification($plan, 'post:hello-world') === 'skip',
    'got ' . $classification($plan, 'post:hello-world') . ' — the projection was widened'
);

echo "\n=== the other three classes are untouched ===\n";
$plan = cacBundlePlan(
    ['entries' => [
        ['kind' => 'post', 'key' => 'hello-world', 'payload' => $payload],
        ['kind' => 'post', 'key' => 'brand-new', 'payload' => $payload],
    ]],
    $current
);
$check(
    'an unknown key is still an add',
    $classification($plan, 'post:brand-new') === 'add',
    $classification($plan, 'post:brand-new')
);

$tenantOnly = array_merge($current, cacBundleCurrentFromPosts([[
    'slug' => 'tenant-only',
    'title' => 'Tenant only',
    'content' => '<p>t</p>',
]]));
$plan = cacBundlePlan($bundleOf(['kind' => 'post', 'key' => 'hello-world', 'payload' => $payload]), $tenantOnly);
$check(
    'a tenant-only key is still a remove',
    $classification($plan, 'post:tenant-only') === 'remove',
    $classification($plan, 'post:tenant-only')
);
$check(
    'a plan that would remove is still refused by name (additive recovery never deletes)',
    is_string(cacBundleRefusal($plan)) && cacBundleRefusal($plan) !== ''
);
$plan = cacBundlePlan($bundleOf(['kind' => 'post', 'key' => 'hello-world', 'payload' => $payload]), $current);
$check(
    'and a purely additive plan raises no refusal',
    cacBundleRefusal($plan) === null
);

echo "\n=== deterministic: identical inputs, identical plan ===\n";
$first = cacBundlePlan(
    $bundleOf(['kind' => 'post', 'key' => 'hello-world', 'payload' => $payload]),
    $current
);
$second = cacBundlePlan(
    $bundleOf(['kind' => 'post', 'key' => 'hello-world', 'payload' => $payload]),
    $current
);
$check('identical inputs give a byte-identical plan', $first === $second);
$check(
    'and the classification is stable across calls',
    $classification($second, 'post:hello-world') === 'skip'
    && $classification($first, 'post:hello-world') === 'skip'
);

echo "\n=== summary ===\n";
printf("  %d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
