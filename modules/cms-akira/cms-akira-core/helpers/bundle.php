<?php

declare(strict_types=1);

/**
 * CMS Akira Core — governed bundle recovery (`akira.bundle.diff@1` /
 * `akira.bundle.apply@1`).
 *
 * The export half of recovery already exists: `cacExportCollectPostRows()`
 * reads the tenant's posts through `akira.post.admin.list@1`, and
 * `akira.export.create@1` renders them through `KernelExport`. This file is the
 * import half, and it is deliberately ADDITIVE:
 *
 *   * `cacBundlePlan()` is a pure, deterministic classifier over an exported
 *     bundle and the tenant's current entries. It never writes.
 *   * `cacBundleRefusal()` refuses any plan that would REMOVE an entry, by
 *     name, because a recovery path that deletes is a different, destructive
 *     feature. `apply` never issues a delete.
 *   * `akira.bundle.diff@1` is a dry run: it computes and reports the plan,
 *     writing no row in any affected table.
 *   * `akira.bundle.apply@1` is a v2 mutation: it is idempotent, audited, and
 *     applies only the add/update half through the existing governed Post
 *     capabilities. It reuses the existing export collection; it never
 *     re-implements it, and it never reaches ModuleDataResetService.
 *
 * Change detection is by payload hash, not timestamp: an entry is a skip when
 * the tenant's hash already matches, an update when it differs, an add when the
 * tenant lacks the key, and a remove when the tenant has a key the bundle does
 * not. The plan preserves input order, so identical inputs give an identical
 * plan.
 */

const CAC_BUNDLE_FORMAT = 'cms-akira-bundle/1';
const CAC_BUNDLE_MAX_ENTRIES = CAC_EXPORT_MAX_ROWS;
const CAC_BUNDLE_IDEMPOTENCY_MAX = 255;

final class CacBundleException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}

/**
 * The stable `kind:key` identity of a bundle/tenant entry, or null when the
 * entry is not addressable. A malformed entry is ignored rather than allowed
 * to poison the classification.
 *
 * @param array<string,mixed> $entry
 */
function cacBundleEntryKey(array $entry): ?string
{
    $kind = trim((string) ($entry['kind'] ?? ''));
    $key = trim((string) ($entry['key'] ?? ''));
    if ($kind === '' || $key === '' || strlen($kind) > 64 || strlen($key) > 191) {
        return null;
    }
    return $kind . ':' . $key;
}

/**
 * Normalise a bundle declaration into its list of entries. The bundle wrapper
 * (`['entries' => [...] ]`) is the canonical form; a bare list is accepted for
 * the pure planner's convenience.
 *
 * @param mixed $bundle
 * @return list<array<string,mixed>>
 */
function cacBundleEntries(mixed $bundle): array
{
    if (!is_array($bundle)) {
        return [];
    }
    $entries = array_key_exists('entries', $bundle) ? $bundle['entries'] : $bundle;
    if (!is_array($entries)) {
        return [];
    }
    $normalised = [];
    foreach ($entries as $entry) {
        if (is_array($entry)) {
            $normalised[] = $entry;
        }
    }
    return $normalised;
}

/**
 * Classify a bundle against the tenant's current entries.
 *
 * PURE: it opens no transaction, performs no write, and leaves its inputs
 * unchanged. Classification is by payload hash; ordering is deterministic and
 * follows the input order of each side.
 *
 * @param array<string,mixed> $bundle   A bundle wrapper (`['entries' => [...]]`) or a bare entry list.
 * @param array<int,mixed>    $current  The tenant's current entry list.
 * @return array{add:list<string>,update:list<string>,skip:list<string>,remove:list<string>}
 */
function cacBundlePlan(array $bundle, array $current): array
{
    $plan = ['add' => [], 'update' => [], 'skip' => [], 'remove' => []];

    $currentByKey = [];
    foreach (array_values($current) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $key = cacBundleEntryKey($entry);
        if ($key !== null) {
            $currentByKey[$key] = $entry;
        }
    }

    $bundleKeys = [];
    foreach (cacBundleEntries($bundle) as $entry) {
        $key = cacBundleEntryKey($entry);
        if ($key === null) {
            continue;
        }
        $bundleKeys[$key] = true;
        $existing = $currentByKey[$key] ?? null;
        if ($existing === null) {
            $plan['add'][] = $key;
            continue;
        }
        if ((string) ($entry['hash'] ?? '') !== (string) ($existing['hash'] ?? '')) {
            $plan['update'][] = $key;
            continue;
        }
        $plan['skip'][] = $key;
    }

    foreach (array_values($current) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $key = cacBundleEntryKey($entry);
        if ($key === null || isset($bundleKeys[$key])) {
            continue;
        }
        $plan['remove'][] = $key;
    }

    return $plan;
}

/**
 * The additive guard. A plan that would remove anything is refused by name;
 * every additive plan is allowed (null).
 *
 * @param array<string,mixed> $plan
 */
function cacBundleRefusal(array $plan): ?string
{
    $remove = [];
    foreach (is_array($plan['remove'] ?? null) ? $plan['remove'] : [] as $key) {
        $key = trim((string) $key);
        if ($key !== '') {
            $remove[] = $key;
        }
    }
    if ($remove === []) {
        return null;
    }

    $shown = array_slice($remove, 0, 10);
    $suffix = count($remove) > count($shown) ? ', …' : '';
    return 'Refused: this bundle would remove ' . count($remove) . ' tenant entr'
        . (count($remove) === 1 ? 'y' : 'ies') . ' that the bundle does not contain ('
        . implode(', ', $shown) . $suffix . '). Additive recovery never deletes; export a complete '
        . 'bundle or reconcile the entries before applying.';
}

/** @return array<string,mixed> */
function cacBundleActor(): array
{
    $actor = app()->user();
    if (!is_array($actor) || (int) ($actor['id'] ?? $actor['sub'] ?? 0) <= 0) {
        throw new CacBundleException('Authentication required.', 401);
    }
    return $actor;
}

/**
 * The canonical payload projection used for change detection. Both bundle
 * entries and tenant entries hash through this exact function so a no-op
 * replay classifies as a skip.
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function cacBundlePostPayload(array $row): array
{
    return [
        'title' => (string) ($row['title'] ?? ''),
        'subtitle' => (string) ($row['subtitle'] ?? ''),
        'content' => (string) ($row['content'] ?? ''),
        'image' => $row['image'] ?? null,
    ];
}

/** @param array<string,mixed> $row */
function cacBundlePostHash(array $row): string
{
    return hash('sha256', (string) json_encode(cacBundlePostPayload($row), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * Build the export-side entries from real tenant Post rows.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function cacBundleEntriesFromPosts(array $rows): array
{
    $entries = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $slug = trim((string) ($row['slug'] ?? ''));
        if ($slug === '' || strlen($slug) > 191) {
            continue;
        }
        $entries[] = [
            'kind' => 'post',
            'key' => $slug,
            'hash' => cacBundlePostHash($row),
            'payload' => cacBundlePostPayload($row),
        ];
        if (count($entries) >= CAC_BUNDLE_MAX_ENTRIES) {
            break;
        }
    }
    return $entries;
}

/**
 * Build the tenant-side entries from real tenant Post rows. `expected_updated_at`
 * rides along so apply can honour optimistic concurrency on every update.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function cacBundleCurrentFromPosts(array $rows): array
{
    $entries = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $slug = trim((string) ($row['slug'] ?? ''));
        if ($slug === '' || strlen($slug) > 191) {
            continue;
        }
        $entries[] = [
            'kind' => 'post',
            'key' => $slug,
            'hash' => cacBundlePostHash($row),
            'expected_updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
    return $entries;
}

/**
 * Build a complete, honest bundle from the tenant through the existing export
 * collection. This is the only export implementation; recovery reuses it.
 *
 * @return array<string,mixed>
 */
function cacBundleFromTenant(array $actor): array
{
    $rows = cacExportCollectPostRows($actor);
    $entries = cacBundleEntriesFromPosts($rows);
    return [
        'format' => CAC_BUNDLE_FORMAT,
        'generated_at' => date('Y-m-d H:i:s'),
        'tenant_id' => cacPostTenantId(),
        'total' => count($entries),
        'entries' => $entries,
    ];
}

/** @param array<string,mixed> $actor */
function cacBundleAuditContext(array $actor): array
{
    return ['caller' => ['module' => CAC_BACKUP_MODULE_ID, 'user' => $actor], 'mode' => 'first'];
}

/**
 * Derive a bounded per-entry idempotency key from the apply key and the entry
 * identity. Deterministic, so a retried apply replays rather than duplicates.
 */
function cacBundleEntryIdempotencyKey(string $applyKey, string $entryKey): string
{
    $prefix = substr($applyKey, 0, 160);
    return $prefix . '-e' . substr(hash('sha256', $entryKey), 0, 24);
}

/**
 * Dry-run diff. Reads the tenant through the existing governed Post list (or
 * accepts an explicit tenant snapshot), classifies it, and reports the plan and
 * any refusal. It writes no row in any affected table and claims no invalidation.
 *
 * @return array<string,mixed>
 */
function cac_cap_akira_bundle_diff_1(mixed $payload, string $capabilityId = 'akira.bundle.diff@1', string $caller = 'unknown'): array
{
    if ($payload !== null && $payload !== [] && !is_array($payload)) {
        throw new CacBundleException('payload must be an object.');
    }
    $payload = is_array($payload) ? $payload : [];
    $actor = cacBundleActor();
    $bundle = is_array($payload['bundle'] ?? null) ? $payload['bundle'] : [];
    $current = is_array($payload['current'] ?? null)
        ? $payload['current']
        : cacBundleCurrentFromPosts(cacExportCollectPostRows($actor));

    $plan = cacBundlePlan($bundle, $current);
    $refusal = cacBundleRefusal($plan);

    $audit = app()->cap()->call('kernel.audit.record@1', [
        'module' => CAC_BACKUP_MODULE_ID,
        'action' => 'akira.bundle.diff',
        'entity_type' => 'bundle',
        'entity_id' => (string) ($payload['bundle_id'] ?? ''),
        'new_data' => [
            'tenant_id' => cacPostTenantId(),
            'counts' => [
                'add' => count($plan['add']),
                'update' => count($plan['update']),
                'skip' => count($plan['skip']),
                'remove' => count($plan['remove']),
            ],
            'additive' => $refusal === null,
            'refusal' => $refusal,
            'performed_by_role' => (string) ($actor['role'] ?? ''),
        ],
    ], cacBundleAuditContext($actor));
    if (!is_array($audit) || ($audit['ok'] ?? false) !== true) {
        throw new CacBundleException('Durable bundle diff audit failed.', 503);
    }

    return [
        'ok' => true,
        'operation' => 'diff',
        'dry_run' => true,
        'plan' => $plan,
        'refusal' => $refusal,
        'additive' => $refusal === null,
        'counts' => [
            'add' => count($plan['add']),
            'update' => count($plan['update']),
            'skip' => count($plan['skip']),
            'remove' => count($plan['remove']),
        ],
    ];
}

/**
 * Apply a bundle additively, under audit and idempotency. A plan containing
 * removals is refused by name and nothing is written. Otherwise the add/update
 * half is dispatched through the existing governed Post capabilities; each
 * entry carries a derived idempotency key so a retried apply replays.
 *
 * @return array<string,mixed>
 */
function cac_cap_akira_bundle_apply_1(mixed $payload, string $capabilityId = 'akira.bundle.apply@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CacBundleException('payload must be an object.');
    }
    if (array_key_exists('tenant_id', $payload)) {
        throw new CacBundleException('tenant_id is supplied by kernel context.');
    }
    $actor = cacBundleActor();
    $bundle = is_array($payload['bundle'] ?? null) ? $payload['bundle'] : [];
    $current = is_array($payload['current'] ?? null)
        ? $payload['current']
        : cacBundleCurrentFromPosts(cacExportCollectPostRows($actor));

    $plan = cacBundlePlan($bundle, $current);
    $refusal = cacBundleRefusal($plan);
    if ($refusal !== null) {
        // Refusing is the correct outcome: audit the refusal, then surface it.
        $audit = app()->cap()->call('kernel.audit.record@1', [
            'module' => CAC_BACKUP_MODULE_ID,
            'action' => 'akira.bundle.apply',
            'entity_type' => 'bundle',
            'entity_id' => (string) ($payload['bundle_id'] ?? ''),
            'new_data' => [
                'tenant_id' => cacPostTenantId(),
                'refused' => true,
                'reason' => $refusal,
                'remove' => array_values($plan['remove']),
                'performed_by_role' => (string) ($actor['role'] ?? ''),
            ],
        ], cacBundleAuditContext($actor));
        if (!is_array($audit) || ($audit['ok'] ?? false) !== true) {
            throw new CacBundleException('Durable bundle refusal audit failed.', 503);
        }
        throw new CacBundleException($refusal, 409);
    }

    $key = is_string($payload['idempotency_key'] ?? null) ? trim($payload['idempotency_key']) : '';
    if ($key === '' || strlen($key) > CAC_BUNDLE_IDEMPOTENCY_MAX) {
        throw new CacBundleException('A valid idempotency_key is required.');
    }

    $bundleByKey = [];
    foreach (cacBundleEntries($bundle) as $entry) {
        $entryKey = cacBundleEntryKey($entry);
        if ($entryKey !== null) {
            $bundleByKey[$entryKey] = $entry;
        }
    }
    $currentByKey = [];
    foreach (array_values($current) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $entryKey = cacBundleEntryKey($entry);
        if ($entryKey !== null) {
            $currentByKey[$entryKey] = $entry;
        }
    }

    // A plan with nothing left to add or update is a replay of an already
    // applied bundle: report it and write nothing.
    if ($plan['add'] === [] && $plan['update'] === [] && $plan['skip'] !== []) {
        return [
            'ok' => true,
            'operation' => 'apply',
            'replayed' => true,
            'plan' => $plan,
            'applied' => ['add' => [], 'update' => []],
            'counts' => [
                'add' => 0,
                'update' => 0,
                'skip' => count($plan['skip']),
                'remove' => 0,
            ],
        ];
    }

    $tenantId = cacPostTenantId();
    $envelope = ['operation' => 'akira.bundle.apply', 'bundle' => [
        'entries' => cacBundleEntries($bundle),
        'plan' => $plan,
    ]];
    $hash = app()->cap()->call('kernel.idempotency.hash@1', ['payload' => $envelope], cacBundleAuditContext($actor));
    if (!is_string($hash) || $hash === '') {
        throw new CacBundleException('Idempotency hashing unavailable.', 503);
    }

    $db = app()->db();
    $claimed = false;
    try {
        $claim = app()->cap()->call('kernel.idempotency.claim@1', [
            'key' => $key, 'tenant_id' => $tenantId, 'payload_hash' => $hash, 'db' => $db,
        ], cacBundleAuditContext($actor));
        $status = is_array($claim) ? (string) ($claim['status'] ?? '') : '';
        if ($status === 'duplicate') {
            $stored = is_array($claim['outcome'] ?? null) ? $claim['outcome'] : [];
            return ['ok' => true, 'operation' => 'apply', 'replayed' => true] + $stored;
        }
        if ($status === 'conflict') {
            throw new CacBundleException('Idempotency key payload conflict.', 409);
        }
        if ($status === 'in_progress') {
            throw new CacBundleException('Idempotent bundle apply is still processing.', 425, 2);
        }
        if ($status !== 'new') {
            throw new RuntimeException('Unexpected idempotency claim result.');
        }
        $claimed = true;

        $applied = ['add' => [], 'update' => []];
        foreach ($plan['update'] as $entryKey) {
            $entry = $bundleByKey[$entryKey] ?? null;
            if ($entry === null) {
                continue;
            }
            $postPayload = is_array($entry['payload'] ?? null) ? $entry['payload'] : [];
            $expected = (string) ($currentByKey[$entryKey]['expected_updated_at'] ?? '');
            $result = app()->cap()->call('akira.post.update@1', $postPayload + [
                'slug' => (string) ($entry['key'] ?? ''),
                'expected_updated_at' => $expected,
                'idempotency_key' => cacBundleEntryIdempotencyKey($key, $entryKey),
            ], cacBundleAuditContext($actor));
            if (!is_array($result) || ($result['ok'] ?? false) !== true) {
                throw new CacBundleException('Bundle update failed for ' . $entryKey . '.', 503);
            }
            $applied['update'][] = $entryKey;
        }
        foreach ($plan['add'] as $entryKey) {
            $entry = $bundleByKey[$entryKey] ?? null;
            if ($entry === null) {
                continue;
            }
            $postPayload = is_array($entry['payload'] ?? null) ? $entry['payload'] : [];
            $result = app()->cap()->call('akira.post.create@1', $postPayload + [
                'slug' => (string) ($entry['key'] ?? ''),
                'idempotency_key' => cacBundleEntryIdempotencyKey($key, $entryKey),
            ], cacBundleAuditContext($actor));
            if (!is_array($result) || ($result['ok'] ?? false) !== true) {
                throw new CacBundleException('Bundle add failed for ' . $entryKey . '.', 503);
            }
            $applied['add'][] = $entryKey;
        }

        $outcome = [
            'ok' => true,
            'operation' => 'apply',
            'replayed' => false,
            'applied' => $applied,
            'counts' => [
                'add' => count($applied['add']),
                'update' => count($applied['update']),
                'skip' => count($plan['skip']),
                'remove' => 0,
            ],
        ];

        $audit = app()->cap()->call('kernel.audit.record@1', [
            'module' => CAC_BACKUP_MODULE_ID,
            'action' => 'akira.bundle.apply',
            'entity_type' => 'bundle',
            'entity_id' => (string) ($payload['bundle_id'] ?? ''),
            'new_data' => [
                'tenant_id' => $tenantId,
                'refused' => false,
                'applied' => $applied,
                'performed_by_role' => (string) ($actor['role'] ?? ''),
            ],
        ], cacBundleAuditContext($actor));
        if (!is_array($audit) || ($audit['ok'] ?? false) !== true) {
            throw new CacBundleException('Durable bundle apply audit failed.', 503);
        }

        $committed = app()->cap()->call('kernel.idempotency.commit@1', [
            'key' => $key, 'tenant_id' => $tenantId, 'outcome' => $outcome, 'db' => $db,
        ], cacBundleAuditContext($actor));
        if ($committed !== true) {
            throw new RuntimeException('Idempotency outcome commit failed.');
        }

        return $outcome;
    } catch (Throwable $error) {
        if ($claimed) {
            try {
                app()->cap()->call('kernel.idempotency.release@1', [
                    'key' => $key, 'tenant_id' => $tenantId, 'db' => $db,
                ], cacBundleAuditContext($actor));
            } catch (Throwable) {
                // A failed release stays in progress and therefore fails closed.
            }
        }
        throw $error;
    }
}
