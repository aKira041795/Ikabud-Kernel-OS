<?php

/**
 * CMS Akira P6 · governed bundle recovery — the plan and refusal contract behind
 * `akira.bundle.diff@1` and `akira.bundle.apply@1`.
 *
 * CHAIR-OWNED ACCEPTANCE, written BEFORE the feature exists. That order is the point: this file is the
 * probe, so a red baseline has to mean "the feature is absent" rather than "the test has not been
 * written yet". A test created by the same lane that creates the feature proves nothing about either.
 *
 * It asserts the parts that are decidable without a tenant, a session, or a filesystem write:
 *
 *   1. the two capabilities are DECLARED, and the mutation carries the protocol and invalidation
 *      metadata the kernel requires of a mutation;
 *   2. both are reachable through the module's capability-handler map, so a declaration exists that
 *      actually resolves;
 *   3. the planner is pure and classifies add / update / skip / remove deterministically;
 *   4. a plan that would REMOVE anything is refused by name, because this slice is additive: a
 *      recovery path that deletes is a different, destructive feature and is out of scope.
 *
 * Deliberately no database access and no `requireNotLiveTenantDatabase()`: this suite reads PHP files
 * and calls pure functions, so it has no skip path and cannot pass vacuously. The live behaviour
 * (dry-run writes nothing, a replayed apply changes nothing) belongs to the contract's acceptance
 * evidence, where it is checked against a real tenant.
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

echo "=== 1. the capabilities are declared, as capabilities ===\n";

$manifestPath = dirname(__DIR__) . '/module.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true);
$exposes = [];
foreach ((array) ($manifest['capabilities']['exposes'] ?? []) as $entry) {
    if (is_array($entry) && isset($entry['id'])) {
        $exposes[(string) $entry['id']] = $entry;
    }
}

$check(
    'akira.bundle.diff@1 is declared in capabilities.exposes',
    isset($exposes['akira.bundle.diff@1']),
    'declared: ' . implode(', ', array_filter(array_keys($exposes), static fn (string $id): bool => str_starts_with($id, 'akira.bundle.')))
);
$check(
    'akira.bundle.apply@1 is declared in capabilities.exposes',
    isset($exposes['akira.bundle.apply@1']),
    'it is the one that writes, so it must exist as a capability and not only as a route'
);

// A mutation that does not say what it invalidates is how a cache keeps serving a pre-apply page, and a
// mutation without v2 metadata is how an idempotency key goes unenforced. Both are asserted rather than
// assumed, because the declaration is the part that is easy to write and easy to leave inert.
$apply = (array) ($exposes['akira.bundle.apply@1'] ?? []);
$check(
    'apply declares requires_protocol v2, so its idempotency key is enforced',
    ($apply['requires_protocol'] ?? '') === 'v2',
    'requires_protocol=' . var_export($apply['requires_protocol'] ?? null, true)
);
$check(
    'apply declares what it invalidates',
    !empty($apply['effects']['invalidates']),
    'effects.invalidates=' . json_encode($apply['effects']['invalidates'] ?? null)
);
$check(
    'diff is declared as a read and claims no invalidation',
    // The capability must EXIST for this to mean anything: `!isset(...)` alone passes when the whole
    // declaration is missing, which is the vacuous pass this suite exists to avoid.
    isset($exposes['akira.bundle.diff@1'])
        && !isset($exposes['akira.bundle.diff@1']['effects']['invalidates']),
    'a dry run that invalidates a cache is not a dry run'
);

echo "\n=== 2. the declarations resolve to handlers ===\n";

$handlers = function_exists('cms_akira_core_capability_handlers') ? cms_akira_core_capability_handlers() : [];
$check(
    'akira.bundle.diff@1 has a handler in the module capability map',
    isset($handlers['akira.bundle.diff@1']) && is_callable($handlers['akira.bundle.diff@1'])
);
$check(
    'akira.bundle.apply@1 has a handler in the module capability map',
    isset($handlers['akira.bundle.apply@1']) && is_callable($handlers['akira.bundle.apply@1'])
);

echo "\n=== 3. the planner is pure and deterministic ===\n";

$bundle = [
    'entries' => [
        ['kind' => 'post', 'key' => 'alpha', 'hash' => 'h-alpha-2'],   // present, changed  -> update
        ['kind' => 'post', 'key' => 'beta', 'hash' => 'h-beta-1'],     // present, same     -> skip
        ['kind' => 'post', 'key' => 'gamma', 'hash' => 'h-gamma-1'],   // absent            -> add
    ],
];
$current = [
    ['kind' => 'post', 'key' => 'alpha', 'hash' => 'h-alpha-1'],
    ['kind' => 'post', 'key' => 'beta', 'hash' => 'h-beta-1'],
    ['kind' => 'post', 'key' => 'delta', 'hash' => 'h-delta-1'],       // in tenant, not in bundle -> remove
];

if (!function_exists('cacBundlePlan')) {
    $check('cacBundlePlan() exists', false, 'not implemented yet — this is the red baseline');
} else {
    $plan = cacBundlePlan($bundle, $current);
    $check(
        'an entry the tenant does not have is an ADD',
        ($plan['add'] ?? []) === ['post:gamma'],
        'add=' . json_encode($plan['add'] ?? null)
    );
    $check(
        'an entry whose payload hash differs is an UPDATE',
        ($plan['update'] ?? []) === ['post:alpha'],
        'update=' . json_encode($plan['update'] ?? null)
    );
    $check(
        'an entry whose payload hash matches is a SKIP',
        ($plan['skip'] ?? []) === ['post:beta'],
        'skip=' . json_encode($plan['skip'] ?? null)
    );
    $check(
        'an entry the tenant has and the bundle does not is a REMOVE, reported rather than performed',
        ($plan['remove'] ?? []) === ['post:delta'],
        'remove=' . json_encode($plan['remove'] ?? null)
    );
    $check(
        'the classification is deterministic — the same inputs give the same plan',
        cacBundlePlan($bundle, $current) === $plan
    );
    $check(
        'planning writes nothing — the inputs are unchanged after the call',
        $current === [
            ['kind' => 'post', 'key' => 'alpha', 'hash' => 'h-alpha-1'],
            ['kind' => 'post', 'key' => 'beta', 'hash' => 'h-beta-1'],
            ['kind' => 'post', 'key' => 'delta', 'hash' => 'h-delta-1'],
        ]
    );
}

echo "\n=== 4. a plan that would delete is refused, by name ===\n";

if (!function_exists('cacBundleRefusal')) {
    $check('cacBundleRefusal() exists', false, 'not implemented yet — this is the red baseline');
} else {
    $check(
        'an additive-only plan is allowed',
        cacBundleRefusal(['add' => ['post:gamma'], 'update' => ['post:alpha'], 'skip' => [], 'remove' => []]) === null,
        'a refusal here would block the feature entirely'
    );
    $refusal = cacBundleRefusal(['add' => [], 'update' => [], 'skip' => [], 'remove' => ['post:delta']]);
    $check(
        'a plan containing a removal is refused, and the reason names the removal',
        is_string($refusal) && $refusal !== '' && stripos($refusal, 'remov') !== false,
        'refusal=' . var_export($refusal, true)
    );
    $check(
        'the refusal is about removal, not about the plan being additive',
        cacBundleRefusal(['add' => ['a'], 'update' => [], 'skip' => [], 'remove' => ['b']]) !== null
        && cacBundleRefusal(['add' => ['a'], 'update' => [], 'skip' => [], 'remove' => []]) === null
    );
}

echo "\n=== summary ===\n";
printf("  %d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
