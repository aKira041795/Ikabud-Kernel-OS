<?php

declare(strict_types=1);

const CAN_NAVIGATION_MAX_DEPTH = 8;
const CAN_NAVIGATION_INVALIDATION = 'entity.list.navigation-menu';

/** @return array<string, string> */
function cms_akira_navigation_capability_handlers(): array
{
    return [
        'akira.navigation.menus@1' => 'can_cap_akira_navigation_menus_1',
        'akira.navigation.tree@1' => 'can_cap_akira_navigation_tree_1',
        'akira.navigation.resolve@1' => 'can_cap_akira_navigation_resolve_1',
        'akira.navigation.menu.create@1' => 'can_cap_akira_navigation_menu_create_1',
        'akira.navigation.menu.update@1' => 'can_cap_akira_navigation_menu_update_1',
        'akira.navigation.menu.delete@1' => 'can_cap_akira_navigation_menu_delete_1',
        'akira.navigation.item.create@1' => 'can_cap_akira_navigation_item_create_1',
        'akira.navigation.item.update@1' => 'can_cap_akira_navigation_item_update_1',
        'akira.navigation.item.delete@1' => 'can_cap_akira_navigation_item_delete_1',
    ];
}

/** Seed only the six mutation policies; public reads remain policy-free. */
function canSeedNavigationMutationPolicies(): void
{
    if (!function_exists('app')) {
        return;
    }
    $rows = [];
    foreach ([
        'akira.navigation.menu.create@1',
        'akira.navigation.menu.update@1',
        'akira.navigation.menu.delete@1',
        'akira.navigation.item.create@1',
        'akira.navigation.item.update@1',
        'akira.navigation.item.delete@1',
    ] as $capabilityId) {
        $rows[] = [
            'policy_version' => 1,
            'capability_id' => $capabilityId,
            'capability_version' => '1',
            'provider' => 'cms-akira-navigation',
            'caller_module' => null,
            'allowed_roles' => function_exists('cacAkiraAdminRoleCsv') ? cacAkiraAdminRoleCsv() : 'admin,administrator,superadmin',
            'provider_activation_required' => true,
            'requires_protocol' => 'v2',
            'is_active' => true,
        ];
    }
    \Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry::seedPolicyForCurrentScope($rows);
}

canSeedNavigationMutationPolicies();

function canCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('cms-akira-navigation');
    if (!$ctx) {
        throw new RuntimeException('CMS Akira Navigation module context unavailable.');
    }
    return $ctx;
}

function canDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    /** @var \Ikabud\Kernel\Contracts\ModuleDB $db */
    $db = canCtx()->db();
    return $db;
}

function canNavigationTenantId(): int
{
    $tenantId = (int) app()->tenant()->current();
    if ($tenantId <= 0) {
        throw new RuntimeException('A trusted tenant context is required.');
    }
    return $tenantId;
}

final class CanNavigationMutationException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}

function canNavigationSlug(mixed $value, string $field = 'slug'): string
{
    $value = is_string($value) ? trim($value) : '';
    if ($value === '' || strlen($value) > 190 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $value) !== 1) {
        throw new CanNavigationMutationException("{$field} must be a canonical lowercase slug.");
    }
    return $value;
}

function canNavigationKey(mixed $value, string $field): string
{
    $value = is_string($value) ? trim($value) : '';
    if (preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
        throw new CanNavigationMutationException("{$field} is invalid.");
    }
    return $value;
}

function canNavigationText(mixed $value, string $field, int $max, bool $required = true): string
{
    if (!is_string($value)) {
        throw new CanNavigationMutationException("{$field} must be a string.");
    }
    $value = trim($value);
    if (($required && $value === '') || strlen($value) > $max) {
        throw new CanNavigationMutationException("{$field} is invalid.");
    }
    return $value;
}

function canNavigationUrl(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    $url = canNavigationText($value, 'url', 2048);
    if (preg_match('/[\x00-\x20\x7f]/', $url) === 1 || str_contains($url, '\\')) {
        throw new CanNavigationMutationException('url contains unsafe characters.');
    }
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        return $url;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
        throw new CanNavigationMutationException('url must be a local, HTTP, or HTTPS URL.');
    }
    return $url;
}

/**
 * @param array<string, mixed> $payload
 * @param array<string, mixed>|null $existing
 * @return array{url: ?string, reference_type: ?string, reference_key: ?string}
 */
function canNavigationLinkFields(array $payload, ?array $existing = null): array
{
    $hasLinkInput = array_key_exists('url', $payload)
        || array_key_exists('reference_type', $payload)
        || array_key_exists('reference_key', $payload);
    if (!$hasLinkInput && $existing !== null) {
        return [
            'url' => $existing['url'] !== null ? (string) $existing['url'] : null,
            'reference_type' => $existing['reference_type'] !== null ? (string) $existing['reference_type'] : null,
            'reference_key' => $existing['reference_key'] !== null ? (string) $existing['reference_key'] : null,
        ];
    }

    $url = canNavigationUrl($payload['url'] ?? null);
    $type = $payload['reference_type'] ?? null;
    $key = $payload['reference_key'] ?? null;
    if ($url !== null && ($type !== null || $key !== null)) {
        throw new CanNavigationMutationException('Use either url or an Akira content reference, not both.');
    }
    if ($url !== null) {
        return ['url' => $url, 'reference_type' => null, 'reference_key' => null];
    }
    if ($type !== 'post') {
        throw new CanNavigationMutationException('reference_type must be post.');
    }
    return ['url' => null, 'reference_type' => 'post', 'reference_key' => canNavigationSlug($key, 'reference_key')];
}

function canNavigationWeight(mixed $value): int
{
    if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/D', $value) === 1)) {
        throw new CanNavigationMutationException('weight must be an integer.');
    }
    $weight = (int) $value;
    if ($weight < -1000000 || $weight > 1000000) {
        throw new CanNavigationMutationException('weight is outside the supported range.');
    }
    return $weight;
}

function canNavigationExpectedVersion(mixed $value): string
{
    if (!is_string($value)) {
        throw new CanNavigationMutationException('expected_updated_at is required.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d H:i:s') !== $value) {
        throw new CanNavigationMutationException('expected_updated_at must use Y-m-d H:i:s.');
    }
    return $value;
}

/**
 * @param array<string, mixed> $row
 * @return array{key: string, slug: string, location: string, title: string}
 */
function canNavigationProjectMenu(array $row): array
{
    return [
        'key' => (string) $row['menu_key'],
        'slug' => (string) $row['slug'],
        'location' => (string) $row['location'],
        'title' => (string) $row['title'],
    ];
}

/**
 * @param array<string, mixed> $row
 * @param array<int, array<string, mixed>> $children
 * @return array{key: string, parent_key: ?string, label: string, href: string, reference: ?array{type: string, key: string}, weight: int, depth: int, children: array<int, array<string, mixed>>}
 */
function canNavigationProjectItem(array $row, array $children): array
{
    $type = $row['reference_type'] !== null ? (string) $row['reference_type'] : null;
    $key = $row['reference_key'] !== null ? (string) $row['reference_key'] : null;
    $href = $row['url'] !== null ? (string) $row['url'] : ($type === 'post' && $key !== null ? '/posts/' . rawurlencode($key) : '');
    return [
        'key' => (string) $row['item_key'],
        'parent_key' => $row['parent_key'] !== null ? (string) $row['parent_key'] : null,
        'label' => (string) $row['label'],
        'href' => $href,
        'reference' => $type !== null && $key !== null ? ['type' => $type, 'key' => $key] : null,
        'weight' => (int) $row['weight'],
        'depth' => (int) $row['depth'],
        'children' => $children,
    ];
}

/**
 * Builds a deterministic tree and fails closed for orphan, cycle, duplicate-key,
 * or persisted-depth corruption rather than returning a partial navigation.
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function canNavigationBuildTree(array $rows): array
{
    $byKey = [];
    $children = [];
    foreach ($rows as $row) {
        $key = (string) ($row['item_key'] ?? '');
        if ($key === '' || isset($byKey[$key])) {
            throw new RuntimeException('Navigation tree contains a duplicate or empty key.');
        }
        $byKey[$key] = $row;
        $parent = $row['parent_key'] !== null ? (string) $row['parent_key'] : '';
        $children[$parent][] = $key;
    }
    foreach ($children as $parent => $keys) {
        if ($parent !== '' && !isset($byKey[$parent])) {
            throw new RuntimeException('Navigation tree contains an orphan.');
        }
    }

    $visiting = [];
    $visited = [];
    $build = static function (string $key, int $depth) use (&$build, &$visiting, &$visited, $byKey, $children): array {
        if ($depth > CAN_NAVIGATION_MAX_DEPTH || isset($visiting[$key])) {
            throw new RuntimeException('Navigation tree exceeds its depth guard or contains a cycle.');
        }
        if ((int) $byKey[$key]['depth'] !== $depth) {
            throw new RuntimeException('Navigation tree depth is inconsistent.');
        }
        $visiting[$key] = true;
        $nested = [];
        foreach ($children[$key] ?? [] as $child) {
            $nested[] = $build($child, $depth + 1);
        }
        unset($visiting[$key]);
        $visited[$key] = true;
        return canNavigationProjectItem($byKey[$key], $nested);
    };

    $tree = [];
    foreach ($children[''] ?? [] as $root) {
        $tree[] = $build($root, 0);
    }
    if (count($visited) !== count($byKey)) {
        throw new RuntimeException('Navigation tree contains an unreachable cycle.');
    }
    return $tree;
}

/** @return array<string, mixed>|null */
function canNavigationFindMenu(string $column, string $value, bool $forUpdate = false): ?array
{
    if (!in_array($column, ['slug', 'location', 'menu_key'], true)) {
        throw new InvalidArgumentException('Unsupported menu lookup.');
    }
    $sql = 'SELECT id, tenant_id, menu_key, slug, location, title, created_at, updated_at '
        . "FROM cms_akira_menus WHERE tenant_id = :tenant AND {$column} = :value LIMIT 1"
        . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = canDb()->prepare($sql);
    $stmt->execute([':tenant' => canNavigationTenantId(), ':value' => $value]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/** @return array<int, array<string, mixed>> */
function canNavigationItems(string $menuKey, bool $forUpdate = false): array
{
    $sql = 'SELECT id, tenant_id, menu_key, item_key, parent_key, label, url, reference_type, reference_key, weight, depth, created_at, updated_at '
        . 'FROM cms_akira_menu_items WHERE tenant_id = :tenant AND menu_key = :menu '
        . 'ORDER BY parent_key ASC, weight ASC, item_key ASC'
        . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = canDb()->prepare($sql);
    $stmt->execute([':tenant' => canNavigationTenantId(), ':menu' => $menuKey]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array<string, mixed> */
function can_cap_akira_navigation_menus_1(mixed $payload, string $capabilityId = 'akira.navigation.menus@1', string $caller = 'unknown'): array
{
    if ($payload !== null && !is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }
    if (is_array($payload) && array_key_exists('tenant_id', $payload)) {
        return ['ok' => false, 'error' => 'tenant_id is supplied by kernel context'];
    }
    $limit = max(1, min(100, (int) ($payload['limit'] ?? 50)));
    $offset = max(0, (int) ($payload['offset'] ?? 0));
    try {
        $stmt = canDb()->prepare(
            "SELECT menu_key, slug, location, title FROM cms_akira_menus
             WHERE tenant_id = :tenant ORDER BY title ASC, slug ASC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute([':tenant' => canNavigationTenantId()]);
        $rows = array_map('canNavigationProjectMenu', $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['ok' => true, 'rows' => $rows, 'total' => count($rows)];
    } catch (Throwable) {
        return ['ok' => false, 'rows' => [], 'total' => 0, 'error' => 'Navigation storage unavailable'];
    }
}

/**
 * @param array<string, mixed> $menu
 * @return array<string, mixed>
 */
function canNavigationTreeResult(array $menu): array
{
    try {
        return ['ok' => true, 'data' => ['menu' => canNavigationProjectMenu($menu), 'items' => canNavigationBuildTree(canNavigationItems((string) $menu['menu_key']))]];
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'Navigation tree is invalid'];
    }
}

/** @return array<string, mixed> */
function can_cap_akira_navigation_tree_1(mixed $payload, string $capabilityId = 'akira.navigation.tree@1', string $caller = 'unknown'): array
{
    if (!is_array($payload) || array_key_exists('tenant_id', $payload)) {
        return ['ok' => false, 'error' => 'A tenant-context menu slug is required'];
    }
    try {
        $menu = canNavigationFindMenu('slug', canNavigationSlug($payload['slug'] ?? null));
        return $menu !== null ? canNavigationTreeResult($menu) : ['ok' => false, 'error' => 'Menu not found'];
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'Menu not found'];
    }
}

/** @return array<string, mixed> */
function can_cap_akira_navigation_resolve_1(mixed $payload, string $capabilityId = 'akira.navigation.resolve@1', string $caller = 'unknown'): array
{
    if (!is_array($payload) || array_key_exists('tenant_id', $payload)) {
        return ['ok' => false, 'error' => 'A tenant-context location is required'];
    }
    try {
        $location = canNavigationSlug($payload['location'] ?? null, 'location');
        $menu = canNavigationFindMenu('location', $location);
        if ($menu === null) {
            return ['ok' => false, 'error' => 'Navigation location not found'];
        }
        $result = canNavigationTreeResult($menu);
        if (($result['ok'] ?? false) === true) {
            $result['data'] = ['location' => $location] + $result['data'];
        }
        return $result;
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'Navigation location not found'];
    }
}

/** @return array{id: int, role: string, source?: string} */
function canNavigationActor(): array
{
    $actor = app()->user();
    if (!is_array($actor) || (int) ($actor['id'] ?? $actor['sub'] ?? 0) <= 0) {
        throw new CanNavigationMutationException('Authentication required.', 401);
    }
    if ((string) ($actor['role'] ?? '') !== 'admin') {
        throw new CanNavigationMutationException('Administrator role required.', 403);
    }
    return $actor;
}

function canNavigationCorrelationId(): string
{
    $context = function_exists('kernel_request_context_get') ? trim((string) kernel_request_context_get('correlation_id', '')) : '';
    return $context !== '' ? $context : bin2hex(random_bytes(16));
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function canNavigationMutate(string $entity, string $operation, array $payload): array
{
    $actor = canNavigationActor();
    $tenantId = canNavigationTenantId();
    if (array_key_exists('tenant_id', $payload)) {
        throw new CanNavigationMutationException('tenant_id is supplied by kernel context.');
    }
    $idempotencyKey = is_string($payload['idempotency_key'] ?? null) ? trim($payload['idempotency_key']) : '';
    if ($idempotencyKey === '' || strlen($idempotencyKey) > 255) {
        throw new CanNavigationMutationException('A valid idempotency_key is required.');
    }

    $allowed = $entity === 'menu'
        ? ['slug', 'location', 'title', 'expected_updated_at']
        : ['menu_slug', 'item_key', 'parent_key', 'label', 'url', 'reference_type', 'reference_key', 'weight', 'expected_updated_at'];
    $input = array_intersect_key($payload, array_flip($allowed));
    $envelope = ['operation' => "{$entity}.{$operation}", $entity => $input];
    $hash = app()->cap()->call('kernel.idempotency.hash@1', ['payload' => $envelope], [
        'caller' => ['module' => 'cms-akira-navigation', 'user' => $actor],
        'mode' => 'first',
    ]);
    if (!is_string($hash)) {
        throw new CanNavigationMutationException('Idempotency hashing unavailable.', 503);
    }

    $pdo = app()->db();
    $claimed = false;
    $publicationUncertain = false;
    try {
        $pdo->beginTransaction();
        $claim = app()->cap()->call('kernel.idempotency.claim@1', [
            'key' => $idempotencyKey,
            'tenant_id' => $tenantId,
            'payload_hash' => $hash,
            'db' => $pdo,
        ], ['caller' => ['module' => 'cms-akira-navigation', 'user' => $actor], 'mode' => 'first']);
        $status = is_array($claim) ? (string) ($claim['status'] ?? '') : '';
        if ($status === 'duplicate') {
            $pdo->rollBack();
            return is_array($claim['outcome'] ?? null) ? $claim['outcome'] : ['ok' => true, 'replayed' => true];
        }
        if ($status === 'conflict') {
            $pdo->rollBack();
            throw new CanNavigationMutationException('Idempotency key payload conflict.', 409);
        }
        if ($status === 'in_progress') {
            $pdo->rollBack();
            throw new CanNavigationMutationException('Idempotent mutation is still processing.', 425, 2);
        }
        if ($status !== 'new') {
            throw new RuntimeException('Unexpected idempotency claim result.');
        }
        $claimed = true;

        $change = $entity === 'menu'
            ? canNavigationMutateMenu($operation, $input, $tenantId)
            : canNavigationMutateItem($operation, $input, $tenantId);
        $correlationId = canNavigationCorrelationId();
        $audit = app()->cap()->call('kernel.audit.record@1', [
            'module' => 'cms-akira-navigation',
            'action' => "akira.navigation.{$entity}.{$operation}",
            'entity_type' => 'navigation-' . $entity,
            'entity_id' => (string) $change['key'],
            'old_data' => $change['old'],
            'new_data' => ['correlation_id' => $correlationId, 'tenant_id' => $tenantId] + $change['new'],
        ], ['caller' => ['module' => 'cms-akira-navigation', 'user' => $actor], 'mode' => 'first']);
        if (!is_array($audit) || ($audit['ok'] ?? false) !== true) {
            throw new RuntimeException('Durable navigation audit failed.');
        }
        $outcome = [
            'ok' => true,
            'operation' => "{$entity}.{$operation}",
            $entity => $change['projection'],
            'correlation_id' => $correlationId,
        ];
        $committed = app()->cap()->call('kernel.idempotency.commit@1', [
            'key' => $idempotencyKey,
            'tenant_id' => $tenantId,
            'outcome' => $outcome,
            'db' => $pdo,
        ], ['caller' => ['module' => 'cms-akira-navigation', 'user' => $actor], 'mode' => 'first']);
        if ($committed !== true) {
            throw new RuntimeException('Idempotency outcome commit failed.');
        }
        $publicationUncertain = true;
        $pdo->commit();
        $publicationUncertain = false;
        return $outcome;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($claimed && !$publicationUncertain) {
            try {
                app()->cap()->call('kernel.idempotency.release@1', [
                    'key' => $idempotencyKey,
                    'tenant_id' => $tenantId,
                    'db' => $pdo,
                ], ['caller' => ['module' => 'cms-akira-navigation', 'user' => $actor], 'mode' => 'first']);
            } catch (Throwable) {
                // A failed release stays processing and therefore fails closed.
            }
        }
        throw $error;
    }
}

/**
 * @param array<string, mixed> $input
 * @return array{key: string, old: mixed, new: array<string, mixed>, projection: array<string, mixed>}
 */
function canNavigationMutateMenu(string $operation, array $input, int $tenantId): array
{
    $slug = canNavigationSlug($input['slug'] ?? null);
    $old = canNavigationFindMenu('slug', $slug, true);
    if ($operation === 'create') {
        if ($old !== null) {
            throw new CanNavigationMutationException('Menu slug already exists.', 409);
        }
        $menuKey = bin2hex(random_bytes(16));
        $location = canNavigationSlug($input['location'] ?? null, 'location');
        $title = canNavigationText($input['title'] ?? null, 'title', 255);
        $stmt = canDb()->prepare(
            'INSERT INTO cms_akira_menus (tenant_id, menu_key, slug, location, title) '
            . 'VALUES (:tenant, :menu_key, :slug, :location, :title)'
        );
        $stmt->execute([':tenant' => $tenantId, ':menu_key' => $menuKey, ':slug' => $slug, ':location' => $location, ':title' => $title]);
        $row = ['menu_key' => $menuKey, 'slug' => $slug, 'location' => $location, 'title' => $title];
        return ['key' => $menuKey, 'old' => null, 'new' => $row, 'projection' => canNavigationProjectMenu($row)];
    }
    if ($old === null) {
        throw new CanNavigationMutationException('Menu not found.', 404);
    }
    $expected = canNavigationExpectedVersion($input['expected_updated_at'] ?? null);
    if ($expected !== (string) $old['updated_at']) {
        throw new CanNavigationMutationException('Menu was modified; refresh and retry.', 409);
    }
    if ($operation === 'delete') {
        $stmt = canDb()->prepare('DELETE FROM cms_akira_menus WHERE tenant_id = :tenant AND menu_key = :menu AND updated_at = :expected');
        $stmt->execute([':tenant' => $tenantId, ':menu' => $old['menu_key'], ':expected' => $expected]);
        if ($stmt->rowCount() !== 1) {
            throw new CanNavigationMutationException('Menu was modified; refresh and retry.', 409);
        }
        return ['key' => (string) $old['menu_key'], 'old' => $old, 'new' => ['deleted' => true], 'projection' => canNavigationProjectMenu($old)];
    }
    if ($operation !== 'update') {
        throw new InvalidArgumentException('Unsupported menu mutation.');
    }
    $location = array_key_exists('location', $input) ? canNavigationSlug($input['location'], 'location') : (string) $old['location'];
    $title = array_key_exists('title', $input) ? canNavigationText($input['title'], 'title', 255) : (string) $old['title'];
    $stmt = canDb()->prepare(
        'UPDATE cms_akira_menus SET location = :location, title = :title '
        . 'WHERE tenant_id = :tenant AND menu_key = :menu AND updated_at = :expected'
    );
    $stmt->execute([':location' => $location, ':title' => $title, ':tenant' => $tenantId, ':menu' => $old['menu_key'], ':expected' => $expected]);
    if ($stmt->rowCount() !== 1) {
        throw new CanNavigationMutationException('Menu was not changed or is stale.', 409);
    }
    $row = ['menu_key' => $old['menu_key'], 'slug' => $slug, 'location' => $location, 'title' => $title];
    return ['key' => (string) $old['menu_key'], 'old' => $old, 'new' => $row, 'projection' => canNavigationProjectMenu($row)];
}

/** @return array<string, mixed>|null */
function canNavigationFindItem(string $menuKey, string $itemKey, bool $forUpdate = false): ?array
{
    $sql = 'SELECT id, tenant_id, menu_key, item_key, parent_key, label, url, reference_type, reference_key, weight, depth, created_at, updated_at '
        . 'FROM cms_akira_menu_items WHERE tenant_id = :tenant AND menu_key = :menu AND item_key = :item LIMIT 1'
        . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = canDb()->prepare($sql);
    $stmt->execute([':tenant' => canNavigationTenantId(), ':menu' => $menuKey, ':item' => $itemKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/**
 * @param array<string, mixed> $input
 * @return array{key: string, old: mixed, new: array<string, mixed>, projection: array<string, mixed>}
 */
function canNavigationMutateItem(string $operation, array $input, int $tenantId): array
{
    $menuSlug = canNavigationSlug($input['menu_slug'] ?? null, 'menu_slug');
    $menu = canNavigationFindMenu('slug', $menuSlug, true);
    if ($menu === null) {
        throw new CanNavigationMutationException('Menu not found.', 404);
    }
    $menuKey = (string) $menu['menu_key'];
    $itemKey = $operation === 'create' ? bin2hex(random_bytes(16)) : canNavigationKey($input['item_key'] ?? null, 'item_key');
    $old = $operation === 'create' ? null : canNavigationFindItem($menuKey, $itemKey, true);
    if ($operation !== 'create' && $old === null) {
        throw new CanNavigationMutationException('Menu item not found.', 404);
    }

    if ($operation === 'delete') {
        $expected = canNavigationExpectedVersion($input['expected_updated_at'] ?? null);
        if ($expected !== (string) $old['updated_at']) {
            throw new CanNavigationMutationException('Menu item was modified; refresh and retry.', 409);
        }
        $children = canDb()->prepare(
            'SELECT COUNT(*) FROM cms_akira_menu_items WHERE tenant_id = :tenant AND menu_key = :menu AND parent_key = :item'
        );
        $children->execute([':tenant' => $tenantId, ':menu' => $menuKey, ':item' => $itemKey]);
        if ((int) $children->fetchColumn() > 0) {
            throw new CanNavigationMutationException('Delete child items before their parent.', 409);
        }
        $stmt = canDb()->prepare(
            'DELETE FROM cms_akira_menu_items WHERE tenant_id = :tenant AND menu_key = :menu AND item_key = :item AND updated_at = :expected'
        );
        $stmt->execute([':tenant' => $tenantId, ':menu' => $menuKey, ':item' => $itemKey, ':expected' => $expected]);
        if ($stmt->rowCount() !== 1) {
            throw new CanNavigationMutationException('Menu item was modified; refresh and retry.', 409);
        }
        return ['key' => $itemKey, 'old' => $old, 'new' => ['deleted' => true], 'projection' => canNavigationProjectItem($old, [])];
    }

    $label = array_key_exists('label', $input)
        ? canNavigationText($input['label'], 'label', 255)
        : (string) ($old['label'] ?? '');
    $links = canNavigationLinkFields($input, $old);
    $weight = array_key_exists('weight', $input) ? canNavigationWeight($input['weight']) : (int) ($old['weight'] ?? 0);
    if ($operation === 'create') {
        $parentKey = null;
        $depth = 0;
        if (($input['parent_key'] ?? null) !== null && $input['parent_key'] !== '') {
            $parentKey = canNavigationKey($input['parent_key'], 'parent_key');
            $parent = canNavigationFindItem($menuKey, $parentKey, true);
            if ($parent === null) {
                throw new CanNavigationMutationException('Parent menu item not found.', 422);
            }
            $depth = (int) $parent['depth'] + 1;
            if ($depth > CAN_NAVIGATION_MAX_DEPTH) {
                throw new CanNavigationMutationException('Menu depth guard exceeded.', 422);
            }
        }
        $stmt = canDb()->prepare(
            'INSERT INTO cms_akira_menu_items '
            . '(tenant_id, menu_key, item_key, parent_key, label, url, reference_type, reference_key, weight, depth) '
            . 'VALUES (:tenant, :menu, :item, :parent, :label, :url, :reference_type, :reference_key, :weight, :depth)'
        );
        $stmt->execute([
            ':tenant' => $tenantId, ':menu' => $menuKey, ':item' => $itemKey, ':parent' => $parentKey,
            ':label' => $label, ':url' => $links['url'], ':reference_type' => $links['reference_type'],
            ':reference_key' => $links['reference_key'], ':weight' => $weight, ':depth' => $depth,
        ]);
        $row = ['item_key' => $itemKey, 'parent_key' => $parentKey, 'label' => $label, 'weight' => $weight, 'depth' => $depth] + $links;
        return ['key' => $itemKey, 'old' => null, 'new' => $row, 'projection' => canNavigationProjectItem($row, [])];
    }
    if ($operation !== 'update') {
        throw new InvalidArgumentException('Unsupported item mutation.');
    }
    if (array_key_exists('parent_key', $input)) {
        throw new CanNavigationMutationException('Item reparenting requires a separately governed operation.');
    }
    $expected = canNavigationExpectedVersion($input['expected_updated_at'] ?? null);
    if ($expected !== (string) $old['updated_at']) {
        throw new CanNavigationMutationException('Menu item was modified; refresh and retry.', 409);
    }
    $stmt = canDb()->prepare(
        'UPDATE cms_akira_menu_items SET label = :label, url = :url, reference_type = :reference_type, '
        . 'reference_key = :reference_key, weight = :weight '
        . 'WHERE tenant_id = :tenant AND menu_key = :menu AND item_key = :item AND updated_at = :expected'
    );
    $stmt->execute([
        ':label' => $label, ':url' => $links['url'], ':reference_type' => $links['reference_type'],
        ':reference_key' => $links['reference_key'], ':weight' => $weight, ':tenant' => $tenantId,
        ':menu' => $menuKey, ':item' => $itemKey, ':expected' => $expected,
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new CanNavigationMutationException('Menu item was not changed or is stale.', 409);
    }
    $row = ['item_key' => $itemKey, 'parent_key' => $old['parent_key'], 'label' => $label, 'weight' => $weight, 'depth' => $old['depth']] + $links;
    return ['key' => $itemKey, 'old' => $old, 'new' => $row, 'projection' => canNavigationProjectItem($row, [])];
}

/** @return array<string, mixed> */
function can_cap_akira_navigation_menu_create_1(mixed $payload, string $capabilityId = 'akira.navigation.menu.create@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CanNavigationMutationException('payload must be an object.');
    }
    return canNavigationMutate('menu', 'create', $payload);
}

/** @return array<string, mixed> */
function can_cap_akira_navigation_menu_update_1(mixed $payload, string $capabilityId = 'akira.navigation.menu.update@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CanNavigationMutationException('payload must be an object.');
    }
    return canNavigationMutate('menu', 'update', $payload);
}

/** @return array<string, mixed> */
function can_cap_akira_navigation_menu_delete_1(mixed $payload, string $capabilityId = 'akira.navigation.menu.delete@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CanNavigationMutationException('payload must be an object.');
    }
    return canNavigationMutate('menu', 'delete', $payload);
}

/** @return array<string, mixed> */
function can_cap_akira_navigation_item_create_1(mixed $payload, string $capabilityId = 'akira.navigation.item.create@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CanNavigationMutationException('payload must be an object.');
    }
    return canNavigationMutate('item', 'create', $payload);
}

/** @return array<string, mixed> */
function can_cap_akira_navigation_item_update_1(mixed $payload, string $capabilityId = 'akira.navigation.item.update@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CanNavigationMutationException('payload must be an object.');
    }
    return canNavigationMutate('item', 'update', $payload);
}

/** @return array<string, mixed> */
function can_cap_akira_navigation_item_delete_1(mixed $payload, string $capabilityId = 'akira.navigation.item.delete@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CanNavigationMutationException('payload must be an object.');
    }
    return canNavigationMutate('item', 'delete', $payload);
}
