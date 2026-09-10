<?php

declare(strict_types=1);

const CAB_BUILDER_MODULE_ID = 'cms-akira-builder';
const CAB_BUILDER_INVALIDATION = 'entity.list.composition';
const CAB_BUILDER_VIEW = 'entity.detail.composition';
const CAB_BUILDER_MAX_TREE_BYTES = 262144;
const CAB_BUILDER_MAX_DEPTH = 8;
const CAB_BUILDER_MAX_BLOCKS = 500;

/** @return array<string, string> */
function cms_akira_builder_capability_handlers(): array
{
    return [
        'akira.builder.compositions@1' => 'cab_builder_cap_compositions_1',
        'akira.builder.get@1' => 'cab_builder_cap_get_1',
        'akira.builder.revisions@1' => 'cab_builder_cap_revisions_1',
        'akira.builder.render@1' => 'cab_builder_cap_render_1',
        'akira.builder.create@1' => 'cab_builder_cap_create_1',
        'akira.builder.update@1' => 'cab_builder_cap_update_1',
        'akira.builder.publish@1' => 'cab_builder_cap_publish_1',
        'akira.builder.unpublish@1' => 'cab_builder_cap_unpublish_1',
        'akira.builder.delete@1' => 'cab_builder_cap_delete_1',
        'akira.builder.validate@1' => 'cab_builder_cap_validate_1',
    ];
}

function cabBuilderSeedMutationPolicies(): void
{
    if (!function_exists('app')) {
        return;
    }
    $rows = [];
    foreach (array_slice(array_keys(cms_akira_builder_capability_handlers()), 4) as $id) {
        $rows[] = [
            'policy_version' => 1, 'capability_id' => $id, 'capability_version' => '1',
            'provider' => CAB_BUILDER_MODULE_ID, 'caller_module' => null, 'allowed_roles' => 'admin',
            'provider_activation_required' => true, 'requires_protocol' => 'v2', 'is_active' => true,
        ];
    }
    (new \Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry(app()->db()))->seedPolicy($rows);
}

function cabBuilderCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $context = module(CAB_BUILDER_MODULE_ID);
    if (!$context) {
        throw new RuntimeException('CMS Akira Builder module context unavailable.');
    }
    return $context;
}

function cabBuilderDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    /** @var \Ikabud\Kernel\Contracts\ModuleDB $database */
    $database = cabBuilderCtx()->db();
    return $database;
}

function cabBuilderTenantId(): int
{
    $tenant = (int) app()->tenant()->current();
    if ($tenant <= 0) {
        throw new CabBuilderException('A trusted tenant context is required.', 500);
    }
    return $tenant;
}

final class CabBuilderException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}

/**
 * @param array<string, mixed> $value
 * @param list<string> $allowed
 */
function cabBuilderExactKeys(array $value, array $allowed, string $subject): void
{
    $unknown = array_diff(array_keys($value), $allowed);
    if ($unknown !== []) {
        throw new CabBuilderException($subject . ' contains unknown fields: ' . implode(', ', $unknown) . '.');
    }
}

function cabBuilderEntityType(mixed $value): string
{
    if ($value !== 'post') {
        throw new CabBuilderException('entity_type must be post.');
    }
    return 'post';
}

function cabBuilderEntityKey(mixed $value): string
{
    $key = is_string($value) ? trim($value) : '';
    if ($key === '' || strlen($key) > 190 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $key) !== 1) {
        throw new CabBuilderException('entity_key must be a canonical Akira Post slug.');
    }
    return $key;
}

function cabBuilderTitle(mixed $value): string
{
    $title = is_string($value) ? trim($value) : '';
    if ($title === '' || strlen($title) > 255 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $title) === 1) {
        throw new CabBuilderException('title is invalid.');
    }
    return $title;
}

function cabBuilderNote(mixed $value): string
{
    $note = $value === null ? '' : (is_string($value) ? trim($value) : '');
    if (strlen($note) > 500 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $note) === 1) {
        throw new CabBuilderException('change_note is invalid.');
    }
    return $note;
}

function cabBuilderPositiveId(mixed $value, string $field): int
{
    if (!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1)) {
        throw new CabBuilderException("{$field} must be a positive integer.");
    }
    $id = (int) $value;
    if ($id <= 0) {
        throw new CabBuilderException("{$field} must be a positive integer.");
    }
    return $id;
}

/** @return array{id:int,role:string,source?:string} */
function cabBuilderActor(): array
{
    $actor = app()->user();
    if (!is_array($actor) || (int) ($actor['id'] ?? $actor['sub'] ?? 0) <= 0) {
        throw new CabBuilderException('Authentication required.', 401);
    }
    if (($actor['role'] ?? '') !== 'admin') {
        throw new CabBuilderException('Administrator role required.', 403);
    }
    return $actor;
}

function cabBuilderUnsafeString(string $value): bool
{
    return preg_match('~<\s*script\b|javascript\s*:|data\s*:|<\?(?:php|=)|\{\s*(?:include|extends|require)\b|\b(?:union\s+select|drop\s+table|insert\s+into|delete\s+from)\b~iu', $value) === 1
        || str_contains($value, "\0");
}

function cabBuilderText(mixed $value, string $field, int $max, bool $required = true): string
{
    if (!is_string($value)) {
        throw new CabBuilderException("{$field} must be a string.");
    }
    $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
    if (($required && $value === '') || strlen($value) > $max || cabBuilderUnsafeString($value)) {
        throw new CabBuilderException("{$field} is unsafe or invalid.");
    }
    return $value;
}

function cabBuilderUrl(mixed $value, string $field): string
{
    $url = cabBuilderText($value, $field, 2048);
    if (preg_match('/[\x00-\x20\x7f]/', $url) === 1 || str_contains($url, '\\')) {
        throw new CabBuilderException("{$field} contains unsafe URL characters.");
    }
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        return $url;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
        throw new CabBuilderException("{$field} must be a local, HTTP, or HTTPS URL.");
    }
    return $url;
}

/** @return array<string, array<string, mixed>> */
function cabBuilderBlockCatalogue(): array
{
    $result = app()->cap()->call('akira.theme.blocks@1', [], [
        'caller' => ['module' => CAB_BUILDER_MODULE_ID, 'user' => app()->user()], 'mode' => 'first',
    ]);
    if (!is_array($result) || ($result['ok'] ?? false) !== true || !is_array($result['blocks'] ?? null)) {
        throw new CabBuilderException('Active theme block catalogue unavailable.', 503);
    }
    $catalogue = [];
    foreach ($result['blocks'] as $definition) {
        if (is_array($definition) && is_string($definition['id'] ?? null)) {
            $catalogue[$definition['id']] = $definition;
        }
    }
    return $catalogue;
}

/** @param array<string, mixed> $schema */
function cabBuilderValidatePropValue(mixed $value, array $schema, string $field): mixed
{
    return match ($schema['type'] ?? '') {
        'string' => cabBuilderText($value, $field, 65535, false),
        'url' => cabBuilderUrl($value, $field),
        'boolean' => is_bool($value) ? $value : throw new CabBuilderException("{$field} must be a boolean."),
        'integer' => is_int($value) ? $value : throw new CabBuilderException("{$field} must be an integer."),
        'array' => cabBuilderValidateArrayProp($value, $schema, $field),
        'object' => cabBuilderValidateObjectProp($value, $schema, $field),
        default => throw new CabBuilderException("{$field} has an unsupported schema type."),
    };
}

/**
 * @param array<string, mixed> $schema
 * @return list<mixed>
 */
function cabBuilderValidateArrayProp(mixed $value, array $schema, string $field): array
{
    if (!is_array($value) || !array_is_list($value) || !is_array($schema['items'] ?? null)) {
        throw new CabBuilderException("{$field} must be an array.");
    }
    return array_map(static fn (mixed $item): mixed => cabBuilderValidatePropValue($item, $schema['items'], "{$field}[]"), $value);
}

/**
 * @param array<string, mixed> $schema
 * @return array<string, mixed>
 */
function cabBuilderValidateObjectProp(mixed $value, array $schema, string $field): array
{
    if (!is_array($value) || array_is_list($value) || !is_array($schema['props'] ?? null)) {
        throw new CabBuilderException("{$field} must be an object.");
    }
    cabBuilderExactKeys($value, array_keys($schema['props']), $field);
    $normalized = [];
    foreach ($value as $name => $item) {
        $propSchema = $schema['props'][$name] ?? null;
        if (is_array($propSchema)) {
            $normalized[$name] = cabBuilderValidatePropValue($item, $propSchema, "{$field}.{$name}");
        }
    }
    return $normalized;
}

/**
 * Unknown properties are rejected, consistently, rather than silently changing authored content.
 * @param array<string, mixed> $props
 * @param array<string, mixed> $definition
 * @return array<string, mixed>
 */
function cabBuilderValidateProps(string $blockId, array $props, array $definition): array
{
    $schemas = $definition['schema']['props'] ?? null;
    if (!is_array($schemas)) {
        throw new CabBuilderException("Block {$blockId} has no valid property schema.");
    }
    cabBuilderExactKeys($props, array_keys($schemas), "{$blockId}.props");
    $normalized = [];
    foreach ($props as $name => $value) {
        $normalized[$name] = cabBuilderValidatePropValue($value, $schemas[$name], "{$blockId}.{$name}");
    }
    return $normalized;
}

/**
 * @param array<string, mixed> $block
 * @param array<string, array<string, mixed>> $catalogue
 * @return array<string, mixed>
 */
function cabBuilderValidateBlock(array $block, int $depth, int &$count, array $catalogue): array
{
    if ($depth > CAB_BUILDER_MAX_DEPTH || ++$count > CAB_BUILDER_MAX_BLOCKS) {
        throw new CabBuilderException('Composition depth or block-count guard exceeded.');
    }
    cabBuilderExactKeys($block, ['block', 'props', 'children'], 'block');
    $blockId = is_string($block['block'] ?? null) ? $block['block'] : '';
    $props = $block['props'] ?? null;
    $children = $block['children'] ?? [];
    if (!isset($catalogue[$blockId])) {
        throw new CabBuilderException("Unknown theme block: {$blockId}.");
    }
    if (!is_array($props) || !is_array($children) || !array_is_list($children)) {
        throw new CabBuilderException('Block props must be an object and children must be a list.');
    }
    $normalizedChildren = [];
    foreach ($children as $child) {
        if (!is_array($child)) {
            throw new CabBuilderException('Every child must be a block object.');
        }
        $normalizedChildren[] = cabBuilderValidateBlock($child, $depth + 1, $count, $catalogue);
    }
    return ['block' => $blockId, 'props' => cabBuilderValidateProps($blockId, $props, $catalogue[$blockId]), 'children' => $normalizedChildren];
}

/** @return array{version:int,blocks:list<array<string,mixed>>} */
function cabBuilderValidateTree(mixed $tree): array
{
    if (!is_array($tree)) {
        throw new CabBuilderException('tree must be an object.');
    }
    cabBuilderExactKeys($tree, ['version', 'blocks'], 'tree');
    if (($tree['version'] ?? null) !== 1 || !is_array($tree['blocks'] ?? null) || !array_is_list($tree['blocks'])) {
        throw new CabBuilderException('tree.version must be 1 and tree.blocks must be a list.');
    }
    $encoded = json_encode($tree, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (strlen($encoded) > CAB_BUILDER_MAX_TREE_BYTES) {
        throw new CabBuilderException('tree exceeds the encoded size guard.');
    }
    $count = 0;
    $blocks = [];
    $catalogue = cabBuilderBlockCatalogue();
    foreach ($tree['blocks'] as $block) {
        if (!is_array($block)) {
            throw new CabBuilderException('Every tree entry must be a block object.');
        }
        $blocks[] = cabBuilderValidateBlock($block, 1, $count, $catalogue);
    }
    return ['version' => 1, 'blocks' => $blocks];
}

/** @param array<string, mixed> $tree */
function cabBuilderEncodeTree(array $tree): string
{
    return json_encode($tree, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** @return array<string, mixed> */
function cabBuilderDecodeTree(string $json): array
{
    try {
        $tree = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        throw new CabBuilderException('Persisted composition tree is invalid.', 500);
    }
    return cabBuilderValidateTree($tree);
}

/** @return array<string, mixed>|null */
function cabBuilderFind(string $type, string $key, bool $lock = false): ?array
{
    $sql = 'SELECT id, entity_type, entity_key, title, tree, status, published_revision_id, version, created_at, updated_at '
        . 'FROM cms_akira_compositions WHERE tenant_id = :tenant AND entity_type = :type AND entity_key = :key LIMIT 1'
        . ($lock ? ' FOR UPDATE' : '');
    $stmt = cabBuilderDb()->prepare($sql);
    $stmt->execute([':tenant' => cabBuilderTenantId(), ':type' => $type, ':key' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function cabBuilderCurrentRevisionId(int $compositionId, bool $lock = false): ?int
{
    $sql = 'SELECT id FROM cms_akira_composition_revisions WHERE tenant_id = :tenant AND composition_id = :composition ORDER BY id DESC LIMIT 1'
        . ($lock ? ' FOR UPDATE' : '');
    $stmt = cabBuilderDb()->prepare($sql);
    $stmt->execute([':tenant' => cabBuilderTenantId(), ':composition' => $compositionId]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function cabBuilderProject(array $row, bool $withTree = false): array
{
    $projection = [
        'entity_type' => (string) $row['entity_type'], 'entity_key' => (string) $row['entity_key'],
        'title' => (string) $row['title'], 'status' => (string) $row['status'],
        'current_revision_id' => cabBuilderCurrentRevisionId((int) $row['id']),
        'published_revision_id' => $row['published_revision_id'] === null ? null : (int) $row['published_revision_id'],
        'version' => (int) $row['version'], 'created_at' => (string) $row['created_at'], 'updated_at' => (string) $row['updated_at'],
    ];
    if ($withTree) {
        $projection['tree'] = cabBuilderDecodeTree((string) $row['tree']);
    }
    return $projection;
}

/** @return array<string, mixed> */
function cab_builder_cap_compositions_1(mixed $payload, string $capabilityId = 'akira.builder.compositions@1', string $caller = 'unknown'): array
{
    if ($payload !== null && !is_array($payload)) {
        return ['ok' => false, 'rows' => [], 'total' => 0, 'error' => 'payload must be an object'];
    }
    $payload = is_array($payload) ? $payload : [];
    if (array_key_exists('tenant_id', $payload) || array_diff(array_keys($payload), ['limit', 'offset']) !== []) {
        return ['ok' => false, 'rows' => [], 'total' => 0, 'error' => 'invalid projection request'];
    }
    try {
        $limit = max(1, min(100, (int) ($payload['limit'] ?? 50)));
        $offset = max(0, (int) ($payload['offset'] ?? 0));
        $count = cabBuilderDb()->prepare('SELECT COUNT(*) FROM cms_akira_compositions WHERE tenant_id = :tenant');
        $count->execute([':tenant' => cabBuilderTenantId()]);
        $stmt = cabBuilderDb()->prepare("SELECT id, entity_type, entity_key, title, tree, status, published_revision_id, version, created_at, updated_at FROM cms_akira_compositions WHERE tenant_id = :tenant ORDER BY updated_at DESC, id DESC LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute([':tenant' => cabBuilderTenantId()]);
        return ['ok' => true, 'rows' => array_map(static fn (array $row): array => cabBuilderProject($row), $stmt->fetchAll(PDO::FETCH_ASSOC)), 'total' => (int) $count->fetchColumn()];
    } catch (Throwable) {
        return ['ok' => false, 'rows' => [], 'total' => 0, 'error' => 'Composition storage unavailable'];
    }
}

/** @return array<string, mixed> */
function cab_builder_cap_get_1(mixed $payload, string $capabilityId = 'akira.builder.get@1', string $caller = 'unknown'): array
{
    if (!is_array($payload) || array_key_exists('tenant_id', $payload)) {
        return ['ok' => false, 'error' => 'A tenant-context entity reference is required'];
    }
    try {
        cabBuilderExactKeys($payload, ['entity_type', 'entity_key'], 'payload');
        $row = cabBuilderFind(cabBuilderEntityType($payload['entity_type'] ?? null), cabBuilderEntityKey($payload['entity_key'] ?? null));
        return $row === null ? ['ok' => false, 'error' => 'Composition not found'] : ['ok' => true, 'data' => cabBuilderProject($row, true)];
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'Composition not found'];
    }
}

/** @return array<string, mixed> */
function cab_builder_cap_revisions_1(mixed $payload, string $capabilityId = 'akira.builder.revisions@1', string $caller = 'unknown'): array
{
    if (!is_array($payload) || array_key_exists('tenant_id', $payload)) {
        return ['ok' => false, 'rows' => [], 'total' => 0, 'error' => 'A tenant-context entity reference is required'];
    }
    try {
        cabBuilderExactKeys($payload, ['entity_type', 'entity_key', 'limit', 'offset'], 'payload');
        $row = cabBuilderFind(cabBuilderEntityType($payload['entity_type'] ?? null), cabBuilderEntityKey($payload['entity_key'] ?? null));
        if ($row === null) {
            return ['ok' => false, 'rows' => [], 'total' => 0, 'error' => 'Composition not found'];
        }
        $limit = max(1, min(100, (int) ($payload['limit'] ?? 50)));
        $offset = max(0, (int) ($payload['offset'] ?? 0));
        $stmt = cabBuilderDb()->prepare("SELECT id, base, author_id, change_note, created_at FROM cms_akira_composition_revisions WHERE tenant_id = :tenant AND composition_id = :composition ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute([':tenant' => cabBuilderTenantId(), ':composition' => $row['id']]);
        $rows = array_map(static fn (array $revision): array => [
            'revision_id' => (int) $revision['id'], 'base_revision_id' => $revision['base'] === null ? null : (int) $revision['base'],
            'author_id' => (int) $revision['author_id'], 'change_note' => (string) $revision['change_note'], 'created_at' => (string) $revision['created_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['ok' => true, 'rows' => $rows, 'total' => count($rows)];
    } catch (Throwable) {
        return ['ok' => false, 'rows' => [], 'total' => 0, 'error' => 'Revision storage unavailable'];
    }
}

/**
 * Resolve structured sections to active-theme templates. Invalid persisted entries are skipped atomically.
 * @param list<mixed> $blocks
 * @param array<string, array<string, mixed>> $catalogue
 * @param list<string> $warnings
 * @return list<array{template:string,props:array<string,mixed>}>
 */
function cabBuilderResolveSections(array $blocks, array $catalogue, array &$warnings): array
{
    $sections = [];
    foreach ($blocks as $index => $block) {
        $id = is_array($block) && is_string($block['block'] ?? null) ? $block['block'] : '';
        $definition = $catalogue[$id] ?? null;
        $template = is_array($definition) ? ($definition['renderer']['template'] ?? null) : null;
        if (!is_string($template) || !is_array($block['props'] ?? null)) {
            $warnings[] = "Skipped unknown or invalid block at index {$index}.";
            continue;
        }
        try {
            $props = cabBuilderValidateProps($id, $block['props'], $definition);
        } catch (Throwable) {
            $warnings[] = "Skipped invalid block '{$id}' at index {$index}.";
            continue;
        }
        $sections[] = ['template' => $template, 'props' => $props];
        if (is_array($block['children'] ?? null)) {
            $sections = array_merge($sections, cabBuilderResolveSections($block['children'], $catalogue, $warnings));
        }
    }
    return $sections;
}

/** @return array<string, mixed> */
function cab_builder_cap_render_1(mixed $payload, string $capabilityId = 'akira.builder.render@1', string $caller = 'unknown'): array
{
    if (!is_array($payload) || array_key_exists('tenant_id', $payload)) {
        return ['ok' => false, 'error' => 'A tenant-context render request is required'];
    }
    try {
        cabBuilderExactKeys($payload, ['entity_type', 'entity_key', 'source', 'view_id'], 'payload');
        $type = cabBuilderEntityType($payload['entity_type'] ?? null);
        $key = cabBuilderEntityKey($payload['entity_key'] ?? null);
        $source = $payload['source'] ?? 'published';
        $view = $payload['view_id'] ?? CAB_BUILDER_VIEW;
        if (!in_array($source, ['preview', 'published'], true) || !is_string($view) || preg_match('/^entity\.(?:list|detail)\.[a-zA-Z0-9][a-zA-Z0-9_-]*$/D', $view) !== 1) {
            throw new CabBuilderException('Render source or view is invalid.');
        }
        $row = cabBuilderFind($type, $key);
        if ($row === null) {
            throw new CabBuilderException('Composition not found.', 404);
        }
        $treeJson = (string) $row['tree'];
        $revisionId = cabBuilderCurrentRevisionId((int) $row['id']);
        if ($source === 'published') {
            if ($row['published_revision_id'] === null || $row['status'] !== 'published') {
                throw new CabBuilderException('Composition is not published.', 404);
            }
            $revision = cabBuilderDb()->prepare('SELECT tree FROM cms_akira_composition_revisions WHERE tenant_id = :tenant AND composition_id = :composition AND id = :revision LIMIT 1');
            $revision->execute([':tenant' => cabBuilderTenantId(), ':composition' => $row['id'], ':revision' => $row['published_revision_id']]);
            $treeJson = (string) $revision->fetchColumn();
            $revisionId = (int) $row['published_revision_id'];
        }
        try {
            $tree = json_decode($treeJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new CabBuilderException('Persisted composition tree is invalid.', 500);
        }
        if (!is_array($tree) || !is_array($tree['blocks'] ?? null) || !array_is_list($tree['blocks'])) {
            throw new CabBuilderException('Persisted composition tree is invalid.', 500);
        }
        $theme = app()->cap()->call('akira.theme.resolve@1', [], ['caller' => ['module' => CAB_BUILDER_MODULE_ID, 'user' => app()->user()], 'mode' => 'first']);
        $slug = is_array($theme) && ($theme['ok'] ?? false) === true && ($theme['validated'] ?? false) === true ? ($theme['theme_slug'] ?? null) : null;
        if (!is_string($slug) || $slug === '') {
            throw new CabBuilderException('Validated Akira theme resolution is unavailable.', 503);
        }
        $warnings = [];
        $sections = cabBuilderResolveSections($tree['blocks'], cabBuilderBlockCatalogue(), $warnings);
        $html = app()->arkRenderers()->render($view, ['composition' => [
            'entity_type' => $type, 'entity_key' => $key, 'title' => (string) $row['title'],
            'revision_id' => $revisionId, 'source' => $source, 'sections' => $sections,
        ]], $slug);
        if (!is_string($html)) {
            throw new CabBuilderException('The Akira theme does not register an executable composition view.', 422);
        }
        return ['ok' => true, 'data' => ['html' => $html, 'source' => $source, 'revision_id' => $revisionId, 'theme_slug' => $slug, 'view_id' => $view, 'warnings' => $warnings]];
    } catch (Throwable $error) {
        return ['ok' => false, 'error' => $error instanceof CabBuilderException ? $error->getMessage() : 'Composition render failed'];
    }
}

function cabBuilderInvalidatePublicCache(?string $key = null): void
{
    try {
        app()->templates()->fragmentStore()->invalidate([CAB_BUILDER_INVALIDATION], (string) cabBuilderTenantId());
        // This module owns the public /p/{key} route and caches it under its own
        // module tag, so its tag must always be cleared — not only as a fallback.
        if (function_exists('pageCacheInvalidateModule')) {
            pageCacheInvalidateModule(CAB_BUILDER_MODULE_ID);
        }
        if (function_exists('akiraShellInvalidatePublicCache')) {
            akiraShellInvalidatePublicCache($key !== null && $key !== '' ? $key : null);
        }
        if ($key !== null && $key !== '' && function_exists('pageCacheInvalidateUrl')) {
            pageCacheInvalidateUrl('/p/' . $key);
        }
    } catch (Throwable) {
        // A committed publication must not be rolled back by cache infrastructure.
    }
}

function cabBuilderCorrelationId(): string
{
    $id = function_exists('kernel_request_context_get') ? trim((string) kernel_request_context_get('correlation_id', '')) : '';
    return $id !== '' ? $id : bin2hex(random_bytes(16));
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function cabBuilderMutate(string $operation, array $payload): array
{
    $actor = cabBuilderActor();
    $tenant = cabBuilderTenantId();
    if (array_key_exists('tenant_id', $payload)) {
        throw new CabBuilderException('tenant_id is supplied by Kernel context.');
    }
    $allowed = match ($operation) {
        'create' => ['idempotency_key', 'entity_type', 'entity_key', 'title', 'tree', 'change_note'],
        'update' => ['idempotency_key', 'entity_type', 'entity_key', 'title', 'tree', 'base_revision_id', 'change_note'],
        'publish', 'unpublish', 'delete' => ['idempotency_key', 'entity_type', 'entity_key'],
        'validate' => ['idempotency_key', 'tree'],
        default => throw new InvalidArgumentException('Unknown builder mutation.'),
    };
    cabBuilderExactKeys($payload, $allowed, 'payload');
    $key = is_string($payload['idempotency_key'] ?? null) ? trim($payload['idempotency_key']) : '';
    if ($key === '' || strlen($key) > 255) {
        throw new CabBuilderException('A valid idempotency_key is required.');
    }
    $input = array_diff_key($payload, ['idempotency_key' => true]);
    $hash = app()->cap()->call('kernel.idempotency.hash@1', ['payload' => ['operation' => 'builder.' . $operation, 'input' => $input]], [
        'caller' => ['module' => CAB_BUILDER_MODULE_ID, 'user' => $actor], 'mode' => 'first',
    ]);
    if (!is_string($hash)) {
        throw new CabBuilderException('Idempotency hashing unavailable.', 503);
    }
    $pdo = app()->db();
    $claimed = false;
    $publicationUncertain = false;
    try {
        $pdo->beginTransaction();
        $claim = app()->cap()->call('kernel.idempotency.claim@1', ['key' => $key, 'tenant_id' => $tenant, 'payload_hash' => $hash, 'db' => $pdo], [
            'caller' => ['module' => CAB_BUILDER_MODULE_ID, 'user' => $actor], 'mode' => 'first',
        ]);
        $status = is_array($claim) ? (string) ($claim['status'] ?? '') : '';
        if ($status === 'duplicate') {
            $pdo->rollBack();
            return is_array($claim['outcome'] ?? null) ? $claim['outcome'] : ['ok' => true, 'replayed' => true];
        }
        if ($status === 'conflict') {
            $pdo->rollBack();
            throw new CabBuilderException('Idempotency key payload conflict.', 409);
        }
        if ($status === 'in_progress') {
            $pdo->rollBack();
            throw new CabBuilderException('Idempotent builder mutation is still processing.', 425, 2);
        }
        if ($status !== 'new') {
            throw new RuntimeException('Unexpected idempotency claim result.');
        }
        $claimed = true;
        $change = cabBuilderApplyMutation($operation, $input, $tenant, $actor['id']);
        $correlation = cabBuilderCorrelationId();
        $auditEntityId = (string) $change['key'];
        if (strlen($auditEntityId) > 50) {
            $auditEntityId = 'sha256:' . substr(hash('sha256', $auditEntityId), 0, 43);
        }
        $audit = app()->cap()->call('kernel.audit.record@1', [
            'module' => CAB_BUILDER_MODULE_ID, 'action' => 'akira.builder.' . $operation,
            'entity_type' => 'composition', 'entity_id' => $auditEntityId,
            'old_data' => $change['old'], 'new_data' => ['tenant_id' => $tenant, 'correlation_id' => $correlation] + $change['new'],
        ], ['caller' => ['module' => CAB_BUILDER_MODULE_ID, 'user' => $actor], 'mode' => 'first']);
        if (!is_array($audit) || ($audit['ok'] ?? false) !== true) {
            throw new RuntimeException('Durable builder audit failed.');
        }
        $outcome = ['ok' => true, 'operation' => 'builder.' . $operation, 'data' => $change['projection'], 'correlation_id' => $correlation];
        $committed = app()->cap()->call('kernel.idempotency.commit@1', ['key' => $key, 'tenant_id' => $tenant, 'outcome' => $outcome, 'db' => $pdo], [
            'caller' => ['module' => CAB_BUILDER_MODULE_ID, 'user' => $actor], 'mode' => 'first',
        ]);
        if ($committed !== true) {
            throw new RuntimeException('Idempotency outcome commit failed.');
        }
        $publicationUncertain = true;
        $pdo->commit();
        $publicationUncertain = false;
        if (in_array($operation, ['publish', 'unpublish'], true)) {
            cabBuilderInvalidatePublicCache(is_string($input['entity_key'] ?? null) ? trim((string) $input['entity_key']) : null);
        }
        return $outcome;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($claimed && !$publicationUncertain) {
            try {
                app()->cap()->call('kernel.idempotency.release@1', ['key' => $key, 'tenant_id' => $tenant, 'db' => $pdo], [
                    'caller' => ['module' => CAB_BUILDER_MODULE_ID, 'user' => $actor], 'mode' => 'first',
                ]);
            } catch (Throwable) {
                // A failed release remains processing and therefore fails closed.
            }
        }
        throw $error;
    }
}

/**
 * @param array<string, mixed> $input
 * @return array{key:string,old:mixed,new:array<string,mixed>,projection:array<string,mixed>}
 */
function cabBuilderApplyMutation(string $operation, array $input, int $tenant, int $author): array
{
    if ($operation === 'validate') {
        $tree = cabBuilderValidateTree($input['tree'] ?? null);
        return ['key' => hash('sha256', cabBuilderEncodeTree($tree)), 'old' => null, 'new' => ['valid' => true], 'projection' => ['valid' => true, 'tree' => $tree, 'errors' => []]];
    }
    $type = cabBuilderEntityType($input['entity_type'] ?? null);
    $key = cabBuilderEntityKey($input['entity_key'] ?? null);
    $row = cabBuilderFind($type, $key, true);
    if ($operation === 'create') {
        if ($row !== null) {
            throw new CabBuilderException('Composition already exists.', 409);
        }
        $tree = cabBuilderValidateTree($input['tree'] ?? null);
        $title = cabBuilderTitle($input['title'] ?? null);
        $note = cabBuilderNote($input['change_note'] ?? null);
        $treeJson = cabBuilderEncodeTree($tree);
        $stmt = cabBuilderDb()->prepare('INSERT INTO cms_akira_compositions (tenant_id, entity_type, entity_key, title, tree, status, version) VALUES (:tenant, :type, :key, :title, :tree, \'draft\', 1)');
        $stmt->execute([':tenant' => $tenant, ':type' => $type, ':key' => $key, ':title' => $title, ':tree' => $treeJson]);
        $composition = (int) cabBuilderDb()->lastInsertId();
        $revision = cabBuilderDb()->prepare('INSERT INTO cms_akira_composition_revisions (tenant_id, composition_id, tree, base, author_id, change_note) VALUES (:tenant, :composition, :tree, NULL, :author, :note)');
        $revision->execute([':tenant' => $tenant, ':composition' => $composition, ':tree' => $treeJson, ':author' => $author, ':note' => $note]);
        $projection = ['entity_type' => $type, 'entity_key' => $key, 'title' => $title, 'status' => 'draft', 'current_revision_id' => (int) cabBuilderDb()->lastInsertId(), 'published_revision_id' => null, 'version' => 1];
        return ['key' => $type . ':' . $key, 'old' => null, 'new' => $projection, 'projection' => $projection];
    }
    if ($row === null) {
        throw new CabBuilderException('Composition not found.', 404);
    }
    $old = cabBuilderProject($row, true);
    if ($operation === 'update') {
        $base = cabBuilderPositiveId($input['base_revision_id'] ?? null, 'base_revision_id');
        $current = cabBuilderCurrentRevisionId((int) $row['id'], true);
        if ($current === null || $base !== $current) {
            throw new CabBuilderException('Composition revision conflict; refresh and retry.', 409);
        }
        $tree = cabBuilderValidateTree($input['tree'] ?? null);
        $treeJson = cabBuilderEncodeTree($tree);
        $title = array_key_exists('title', $input) ? cabBuilderTitle($input['title']) : (string) $row['title'];
        $revision = cabBuilderDb()->prepare('INSERT INTO cms_akira_composition_revisions (tenant_id, composition_id, tree, base, author_id, change_note) VALUES (:tenant, :composition, :tree, :base, :author, :note)');
        $revision->execute([':tenant' => $tenant, ':composition' => $row['id'], ':tree' => $treeJson, ':base' => $base, ':author' => $author, ':note' => cabBuilderNote($input['change_note'] ?? null)]);
        $revisionId = (int) cabBuilderDb()->lastInsertId();
        cabBuilderDb()->prepare('UPDATE cms_akira_compositions SET title = :title, tree = :tree, version = version + 1 WHERE tenant_id = :tenant AND id = :id')->execute([':title' => $title, ':tree' => $treeJson, ':tenant' => $tenant, ':id' => $row['id']]);
        $projection = ['entity_type' => $type, 'entity_key' => $key, 'title' => $title, 'status' => $row['status'], 'current_revision_id' => $revisionId, 'published_revision_id' => $old['published_revision_id'], 'version' => (int) $row['version'] + 1];
        return ['key' => $type . ':' . $key, 'old' => $old, 'new' => $projection, 'projection' => $projection];
    }
    if ($operation === 'publish') {
        cabBuilderValidateTree(json_decode((string) $row['tree'], true, 512, JSON_THROW_ON_ERROR));
        $entity = app()->cap()->call('akira.post.get@1', ['slug' => $key], ['caller' => ['module' => CAB_BUILDER_MODULE_ID, 'user' => app()->user()], 'mode' => 'first']);
        if (!is_array($entity) || ($entity['ok'] ?? false) !== true) {
            throw new CabBuilderException('A published-capable Akira Post reference is required.', 422);
        }
        $revision = cabBuilderCurrentRevisionId((int) $row['id'], true);
        if ($revision === null) {
            throw new CabBuilderException('Composition has no preview revision.', 409);
        }
        cabBuilderDb()->prepare("UPDATE cms_akira_compositions SET status = 'published', published_revision_id = :revision, version = version + 1 WHERE tenant_id = :tenant AND id = :id")->execute([':revision' => $revision, ':tenant' => $tenant, ':id' => $row['id']]);
        $projection = $old;
        unset($projection['tree']);
        $projection['status'] = 'published';
        $projection['published_revision_id'] = $revision;
        $projection['version'] = (int) $row['version'] + 1;
        return ['key' => $type . ':' . $key, 'old' => $old, 'new' => $projection, 'projection' => $projection];
    }
    if ($operation === 'unpublish') {
        cabBuilderDb()->prepare("UPDATE cms_akira_compositions SET status = 'draft', published_revision_id = NULL, version = version + 1 WHERE tenant_id = :tenant AND id = :id")->execute([':tenant' => $tenant, ':id' => $row['id']]);
        $projection = $old;
        unset($projection['tree']);
        $projection['status'] = 'draft';
        $projection['published_revision_id'] = null;
        $projection['version'] = (int) $row['version'] + 1;
        return ['key' => $type . ':' . $key, 'old' => $old, 'new' => $projection, 'projection' => $projection];
    }
    if ($operation === 'delete') {
        cabBuilderDb()->prepare('DELETE FROM cms_akira_compositions WHERE tenant_id = :tenant AND id = :id')->execute([':tenant' => $tenant, ':id' => $row['id']]);
        return ['key' => $type . ':' . $key, 'old' => $old, 'new' => ['deleted' => true], 'projection' => ['entity_type' => $type, 'entity_key' => $key, 'deleted' => true]];
    }
    throw new InvalidArgumentException('Unsupported builder mutation.');
}

/** @return array<string, mixed> */
function cab_builder_cap_create_1(mixed $payload, string $capabilityId = 'akira.builder.create@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CabBuilderException('payload must be an object.');
    } return cabBuilderMutate('create', $payload);
}
/** @return array<string, mixed> */
function cab_builder_cap_update_1(mixed $payload, string $capabilityId = 'akira.builder.update@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CabBuilderException('payload must be an object.');
    } return cabBuilderMutate('update', $payload);
}
/** @return array<string, mixed> */
function cab_builder_cap_publish_1(mixed $payload, string $capabilityId = 'akira.builder.publish@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CabBuilderException('payload must be an object.');
    } return cabBuilderMutate('publish', $payload);
}
/** @return array<string, mixed> */
function cab_builder_cap_unpublish_1(mixed $payload, string $capabilityId = 'akira.builder.unpublish@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CabBuilderException('payload must be an object.');
    } return cabBuilderMutate('unpublish', $payload);
}
/** @return array<string, mixed> */
function cab_builder_cap_delete_1(mixed $payload, string $capabilityId = 'akira.builder.delete@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CabBuilderException('payload must be an object.');
    } return cabBuilderMutate('delete', $payload);
}
/** @return array<string, mixed> */
function cab_builder_cap_validate_1(mixed $payload, string $capabilityId = 'akira.builder.validate@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CabBuilderException('payload must be an object.');
    } return cabBuilderMutate('validate', $payload);
}

cabBuilderSeedMutationPolicies();
