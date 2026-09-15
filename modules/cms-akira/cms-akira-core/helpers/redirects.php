<?php

declare(strict_types=1);

/**
 * CMS Akira Core — governed post-path redirects.
 *
 * A redirect maps one retired post path (/posts/<slug>) to one same-origin
 * internal target path. The table is tenant-scoped and owned by cms-akira-core;
 * the shell owns no SQL and never touches it directly. Resolution is exact-match
 * and single-hop: a matched row's target is returned verbatim and is never
 * re-resolved, so A -> B -> C can never become a chain or a loop.
 *
 * The write is administrator-tier only at the policy layer, idempotent per key,
 * audited, and transactional. Target validation is the security boundary: an
 * absolute URL, a protocol-relative URL (//host) or a scheme-qualified value
 * (javascript:, data:, mailto:, …) is refused outright — this surface must never
 * be able to emit an open redirect.
 */

const CAC_REDIRECT_MODULE_ID = 'cms-akira-core';
const CAC_REDIRECT_TABLE = 'cms_akira_redirects';

final class CacRedirectException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}

/** @return array<string,mixed> */
function cacRedirectActor(): array
{
    $actor = app()->user();
    if (!is_array($actor) || (int) ($actor['id'] ?? $actor['sub'] ?? 0) <= 0) {
        throw new CacRedirectException('Authentication required.', 401);
    }
    return ['id' => (int) ($actor['id'] ?? $actor['sub']), 'role' => (string) ($actor['role'] ?? '')];
}

/** @param array<string,mixed> $actor
 * @return array<string,mixed>
 */
function cacRedirectAuditContext(array $actor): array
{
    return ['caller' => ['module' => CAC_REDIRECT_MODULE_ID, 'user' => $actor], 'mode' => 'first'];
}

/** @return array<string,mixed> */
function cacRedirectContext(): array
{
    return ['caller' => ['module' => CAC_REDIRECT_MODULE_ID, 'user' => null], 'mode' => 'first'];
}

/**
 * A source is always a canonical retired post path. A bare slug is accepted for
 * convenience and normalised to /posts/<slug>; anything else is refused so the
 * resolution key can never be a scheme, host or arbitrary path.
 */
function cacRedirectSource(mixed $value): string
{
    $path = is_string($value) ? trim($value) : '';
    if ($path === '') {
        throw new CacRedirectException('A source post path is required.');
    }
    if (!str_starts_with($path, '/')) {
        $path = '/posts/' . ltrim($path, '/');
    }
    if (strlen($path) > 255) {
        throw new CacRedirectException('The source post path is too long.');
    }
    if (preg_match('#^/posts/[a-z0-9]+(?:-[a-z0-9]+)*$#D', $path) !== 1) {
        throw new CacRedirectException('A redirect source must be a post path such as /posts/retired-slug.');
    }
    return $path;
}

/**
 * The open-redirect guard. Only a same-origin root-relative path is accepted.
 * Rejected forms include https://host, //host, /\\host, javascript:, data:,
 * whitespace/control-character smuggling, and dot-segment traversal.
 */
function cacRedirectTarget(mixed $value): string
{
    $path = is_string($value) ? trim($value) : '';
    if ($path === '') {
        throw new CacRedirectException('A target path is required.');
    }
    if (strlen($path) > 255) {
        throw new CacRedirectException('The target path is too long.');
    }
    // No control characters, spaces or other whitespace anywhere in the value.
    if (preg_match('#[\x00-\x20\x7F]#', $path) === 1) {
        throw new CacRedirectException('A redirect target must not contain whitespace or control characters.');
    }
    // A backslash is normalised to a slash by some clients and would smuggle a
    // second leading slash past a naive check.
    if (str_contains($path, '\\')) {
        throw new CacRedirectException('A redirect target must use forward slashes only.');
    }
    // Exactly one leading slash: rejects absolute (https://), scheme-relative
    // (//evil.test), and scheme-qualified (javascript:) values, none of which
    // begin with a single root-relative slash.
    if ($path[0] !== '/' || (isset($path[1]) && $path[1] === '/')) {
        throw new CacRedirectException('Redirect targets must be same-origin internal paths; absolute, protocol-relative and scheme-qualified URLs are refused.');
    }
    // Reject dot-segment traversal so a stored target cannot be rewritten by the
    // client into a path outside its literal meaning.
    if (preg_match('#(?:^|/)\.\.(?:/|$)#', $path) === 1) {
        throw new CacRedirectException('A redirect target must not contain parent-directory segments.');
    }
    return $path;
}

/** @return list<array<string,mixed>> */
function cacRedirectList(?int $tenantId = null, ?PDO $db = null): array
{
    $tenantId ??= (int) app()->tenant()->current();
    if ($tenantId <= 0) {
        throw new CacRedirectException('A trusted tenant context is required.', 500);
    }
    $db ??= app()->db();
    $statement = $db->prepare(
        'SELECT id, source_path, target_path, created_at, updated_at FROM ' . CAC_REDIRECT_TABLE
        . ' WHERE tenant_id = ? ORDER BY source_path ASC'
    );
    $statement->execute([$tenantId]);
    $rows = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (is_array($row)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/**
 * Exact-match single-hop lookup. One prepared SELECT, no recursion: the target
 * of a matched row is returned as-is even when it equals another row's source.
 */
function cacRedirectResolve(string $path, ?int $tenantId = null, ?PDO $db = null): ?string
{
    $path = trim($path);
    if ($path === '' || strlen($path) > 255) {
        return null;
    }
    $tenantId ??= (int) app()->tenant()->current();
    if ($tenantId <= 0) {
        return null;
    }
    try {
        $db ??= app()->db();
        $statement = $db->prepare(
            'SELECT target_path FROM ' . CAC_REDIRECT_TABLE
            . ' WHERE tenant_id = ? AND source_path = ? LIMIT 1'
        );
        $statement->execute([$tenantId, $path]);
        $target = $statement->fetchColumn();
    } catch (Throwable) {
        // A missing table (migration not applied) must fail safe to the original
        // 404, never to a broken page or an open redirect.
        return null;
    }
    return is_string($target) && $target !== '' ? $target : null;
}

/**
 * Idempotent, audited, transactional create-or-update of one post-path redirect.
 *
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function cacRedirectMutate(array $payload, ?int $tenantId = null, ?PDO $db = null): array
{
    $actor = cacRedirectActor();
    $allowed = ['source_path', 'target_path', 'idempotency_key'];
    $unknown = array_diff(array_keys($payload), $allowed);
    if ($unknown !== []) {
        throw new CacRedirectException('Unknown request field: ' . (string) reset($unknown) . '.');
    }
    $source = cacRedirectSource($payload['source_path'] ?? null);
    $target = cacRedirectTarget($payload['target_path'] ?? null);
    $key = is_string($payload['idempotency_key'] ?? null) ? trim($payload['idempotency_key']) : '';
    if ($key === '' || strlen($key) > 255) {
        throw new CacRedirectException('A valid idempotency_key is required.');
    }
    $tenantId ??= (int) app()->tenant()->current();
    if ($tenantId <= 0) {
        throw new CacRedirectException('A trusted tenant context is required.', 500);
    }
    $hash = app()->cap()->call('kernel.idempotency.hash@1', [
        'payload' => ['operation' => 'akira.redirect.create', 'source_path' => $source, 'target_path' => $target],
    ], cacRedirectAuditContext($actor));
    if (!is_string($hash) || $hash === '') {
        throw new CacRedirectException('Idempotency hashing unavailable.', 503);
    }

    $db ??= app()->db();
    $claimed = false;
    $publicationUncertain = false;
    try {
        $db->beginTransaction();
        $claim = app()->cap()->call('kernel.idempotency.claim@1', [
            'key' => $key, 'tenant_id' => $tenantId, 'payload_hash' => $hash, 'db' => $db,
        ], cacRedirectAuditContext($actor));
        $status = is_array($claim) ? (string) ($claim['status'] ?? '') : '';
        if ($status === 'duplicate') {
            $db->rollBack();
            $stored = is_array($claim['outcome'] ?? null) ? $claim['outcome'] : [];
            return ['ok' => true, 'replayed' => true] + $stored;
        }
        if ($status === 'conflict') {
            $db->rollBack();
            throw new CacRedirectException('Idempotency key payload conflict.', 409);
        }
        if ($status === 'in_progress') {
            $db->rollBack();
            throw new CacRedirectException('Idempotent redirect write is still processing.', 425);
        }
        if ($status !== 'new') {
            throw new RuntimeException('Unexpected idempotency claim result.');
        }
        $claimed = true;

        $before = null;
        $existing = $db->prepare(
            'SELECT id, target_path FROM ' . CAC_REDIRECT_TABLE . ' WHERE tenant_id = ? AND source_path = ? LIMIT 1'
        );
        $existing->execute([$tenantId, $source]);
        $beforeRow = $existing->fetch(PDO::FETCH_ASSOC);
        if (is_array($beforeRow)) {
            $before = ['id' => (int) $beforeRow['id'], 'target_path' => (string) $beforeRow['target_path']];
        }

        $correlationId = function_exists('kernel_request_context_get')
            ? trim((string) kernel_request_context_get('correlation_id', '')) : '';
        $correlationId = $correlationId !== '' ? $correlationId : bin2hex(random_bytes(16));

        // The composite unique key makes the write an in-place update for an
        // existing source; the idempotency claim above already handled replay.
        $write = $db->prepare(
            'INSERT INTO ' . CAC_REDIRECT_TABLE
            . ' (tenant_id, source_path, target_path, actor_user_id, correlation_id)'
            . ' VALUES (?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE target_path = VALUES(target_path),'
            . ' actor_user_id = VALUES(actor_user_id), correlation_id = VALUES(correlation_id)'
        );
        $write->execute([$tenantId, $source, $target, $actor['id'], $correlationId]);
        if ($write->rowCount() < 1) {
            throw new CacRedirectException('Redirect write failed.', 503);
        }

        $audit = app()->cap()->call('kernel.audit.record@1', [
            'module' => CAC_REDIRECT_MODULE_ID,
            'action' => 'akira.redirect.create',
            'entity_type' => 'redirect',
            'entity_id' => $tenantId . ':' . $source,
            'old_data' => $before,
            'new_data' => [
                'tenant_id' => $tenantId,
                'source_path' => $source,
                'target_path' => $target,
                'correlation_id' => $correlationId,
                'performed_by_role' => $actor['role'],
            ],
        ], cacRedirectAuditContext($actor));
        if (!is_array($audit) || ($audit['ok'] ?? false) !== true) {
            throw new RuntimeException('Durable redirect audit failed.');
        }

        $outcome = [
            'ok' => true,
            'tenant_id' => $tenantId,
            'source_path' => $source,
            'target_path' => $target,
            'correlation_id' => $correlationId,
        ];
        $committed = app()->cap()->call('kernel.idempotency.commit@1', [
            'key' => $key, 'tenant_id' => $tenantId, 'outcome' => $outcome, 'db' => $db,
        ], cacRedirectAuditContext($actor));
        if ($committed !== true) {
            throw new RuntimeException('Idempotency outcome commit failed.');
        }
        $publicationUncertain = true;
        $db->commit();
        $publicationUncertain = false;

        return $outcome;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($claimed && !$publicationUncertain) {
            try {
                app()->cap()->call('kernel.idempotency.release@1', [
                    'key' => $key, 'tenant_id' => $tenantId, 'db' => $db,
                ], cacRedirectAuditContext($actor));
            } catch (Throwable) {
                // A failed release remains in progress and therefore fails closed.
            }
        }
        throw $error;
    }
}

/** @return array<string,mixed> */
function cac_cap_akira_redirect_list_1(mixed $payload, string $capabilityId = 'akira.redirect.list@1', string $caller = 'unknown'): array
{
    if ($payload !== null && $payload !== [] && !is_array($payload)) {
        throw new CacRedirectException('payload must be an object.');
    }
    $rows = cacRedirectList();
    return ['ok' => true, 'rows' => $rows, 'total' => count($rows)];
}

/** @return array<string,mixed> */
function cac_cap_akira_redirect_create_1(mixed $payload, string $capabilityId = 'akira.redirect.create@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CacRedirectException('payload must be an object.');
    }
    return cacRedirectMutate($payload);
}

/**
 * Public, ungoverned resolution seam used only by akiraPublicNotFound(). It
 * returns the single stored target for an exact source match, or null so the
 * caller keeps its original 404.
 *
 * @return array<string,mixed>
 */
function cac_cap_akira_redirect_resolve_1(mixed $payload, string $capabilityId = 'akira.redirect.resolve@1', string $caller = 'unknown'): array
{
    if ($payload !== null && $payload !== [] && !is_array($payload)) {
        throw new CacRedirectException('payload must be an object.');
    }
    $path = is_array($payload) && is_string($payload['path'] ?? null) ? $payload['path'] : '';
    $target = cacRedirectResolve($path);
    return ['ok' => true, 'found' => $target !== null, 'target' => $target];
}
