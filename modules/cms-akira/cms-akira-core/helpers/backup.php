<?php

declare(strict_types=1);

/**
 * CMS Akira Core — backup and export capabilities.
 *
 * The kernel already owns the machinery: ModuleBackupService produces and
 * lists data-only SQL dumps, and KernelExport produces governed document
 * exports. These capabilities are the thin, audited, administrator-governed
 * bridge from the CMS Akira admin to those services. They deliberately own no
 * dump or export logic of their own.
 *
 * ModuleDataResetService is intentionally NOT referenced here: this surface
 * creates and reads only; it never destroys.
 */

use Ikabud\Kernel\Services\KernelExport;
use Ikabud\Kernel\Services\ModuleBackupService;

const CAC_BACKUP_MODULE_ID = 'cms-akira-core';
const CAC_BACKUP_TABLE_PREFIX = 'cms_akira_';
const CAC_BACKUP_DOWNLOAD_PATH = '/cms-akira-shell/backups';
const CAC_BACKUP_RETENTION_DAYS = 90;
const CAC_EXPORT_MAX_ROWS = 5000;
const CAC_EXPORT_EXCERPT_BYTES = 4000;

final class CacBackupException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}

/** @return array<string,mixed> */
function cacBackupActor(): array
{
    $actor = app()->user();
    if (!is_array($actor) || (int) ($actor['id'] ?? $actor['sub'] ?? 0) <= 0) {
        throw new CacBackupException('Authentication required.', 401);
    }
    return $actor;
}

/** @return array<string,mixed> */
/** @param array<string,mixed> $actor
 * @return array<string,mixed>
 */
function cacBackupAuditContext(array $actor): array
{
    return ['caller' => ['module' => CAC_BACKUP_MODULE_ID, 'user' => $actor], 'mode' => 'first'];
}

/**
 * List the real backups the kernel service holds for this module. The shell
 * never touches the filesystem; this is the only read path.
 *
 * @return array<string,mixed>
 */
function cac_cap_akira_backup_list_1(mixed $payload, string $capabilityId = 'akira.backup.list@1', string $caller = 'unknown'): array
{
    if ($payload !== null && $payload !== [] && !is_array($payload)) {
        throw new CacBackupException('payload must be an object.');
    }
    $backups = [];
    foreach (ModuleBackupService::list(CAC_BACKUP_MODULE_ID, CAC_BACKUP_DOWNLOAD_PATH) as $row) {
        $backups[] = [
            'file_name' => $row['file_name'],
            'file_size_bytes' => $row['file_size_bytes'],
            'created_at' => $row['created_at'],
        ];
    }
    return [
        'ok' => true,
        'module_id' => CAC_BACKUP_MODULE_ID,
        'backups' => $backups,
        'total' => count($backups),
    ];
}

/** @param array<string,mixed> $actor */
function cacBackupPayloadHash(array $actor): string
{
    $hash = app()->cap()->call('kernel.idempotency.hash@1', [
        'payload' => ['operation' => 'akira.backup.create', 'module' => CAC_BACKUP_MODULE_ID],
    ], cacBackupAuditContext($actor));
    if (!is_string($hash) || $hash === '') {
        throw new CacBackupException('Idempotency hashing unavailable.', 503);
    }
    return $hash;
}

/**
 * Create a real backup through ModuleBackupService, idempotent for a repeated
 * key, administrator-only at the policy layer, and audited. A failed audit
 * fails the operation.
 *
 * @return array<string,mixed>
 */
function cac_cap_akira_backup_create_1(mixed $payload, string $capabilityId = 'akira.backup.create@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CacBackupException('payload must be an object.');
    }
    $actor = cacBackupActor();
    $reason = trim((string) ($payload['reason'] ?? 'Console backup'));
    if ($reason === '' || strlen($reason) > 190) {
        $reason = 'Console backup';
    }
    $key = is_string($payload['idempotency_key'] ?? null) ? trim($payload['idempotency_key']) : '';
    if ($key === '' || strlen($key) > 255) {
        throw new CacBackupException('A valid idempotency_key is required.');
    }
    $tenantId = cacPostTenantId();
    $db = app()->db();
    $hash = cacBackupPayloadHash($actor);

    $claimed = false;
    $createdFile = '';
    $publicationUncertain = false;
    try {
        $db->beginTransaction();
        $claim = app()->cap()->call('kernel.idempotency.claim@1', [
            'key' => $key, 'tenant_id' => $tenantId, 'payload_hash' => $hash, 'db' => $db,
        ], cacBackupAuditContext($actor));
        $status = is_array($claim) ? (string) ($claim['status'] ?? '') : '';
        if ($status === 'duplicate') {
            $db->rollBack();
            $stored = is_array($claim['outcome'] ?? null) ? $claim['outcome'] : [];
            return ['ok' => true, 'replayed' => true] + $stored;
        }
        if ($status === 'conflict') {
            $db->rollBack();
            throw new CacBackupException('Idempotency key payload conflict.', 409);
        }
        if ($status === 'in_progress') {
            $db->rollBack();
            throw new CacBackupException('Idempotent backup is still processing.', 425);
        }
        if ($status !== 'new') {
            throw new RuntimeException('Unexpected idempotency claim result.');
        }
        $claimed = true;

        $result = ModuleBackupService::generate(cacCtx(), CAC_BACKUP_TABLE_PREFIX, $reason, [
            'retention_days' => CAC_BACKUP_RETENTION_DAYS,
            'download_path' => CAC_BACKUP_DOWNLOAD_PATH,
            'event' => 'akira.backup.created',
            'by_user' => (int) ($actor['id'] ?? $actor['sub'] ?? 0),
        ]);
        $createdFile = $result['file_name'];

        $audit = app()->cap()->call('kernel.audit.record@1', [
            'module' => CAC_BACKUP_MODULE_ID,
            'action' => 'akira.backup.create',
            'entity_type' => 'module_backup',
            'entity_id' => $createdFile,
            'new_data' => [
                'tenant_id' => $tenantId,
                'file_name' => $createdFile,
                'file_size_bytes' => $result['file_size_bytes'],
                'total_rows' => $result['total_rows'],
                'tables' => $result['tables'],
                'reason' => $reason,
                'performed_by_role' => (string) ($actor['role'] ?? ''),
            ],
        ], cacBackupAuditContext($actor));
        if (!is_array($audit) || ($audit['ok'] ?? false) !== true) {
            throw new CacBackupException('Durable backup audit failed; the backup was not published.', 503);
        }

        $outcome = [
            'ok' => true,
            'module_id' => CAC_BACKUP_MODULE_ID,
            'file_name' => $createdFile,
            'file_size_bytes' => $result['file_size_bytes'],
            'created_at' => date('Y-m-d H:i:s'),
            'total_rows' => $result['total_rows'],
            'tables' => $result['tables'],
            'retention_days' => $result['retention_days'],
        ];
        $committed = app()->cap()->call('kernel.idempotency.commit@1', [
            'key' => $key, 'tenant_id' => $tenantId, 'outcome' => $outcome, 'db' => $db,
        ], cacBackupAuditContext($actor));
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
                ], cacBackupAuditContext($actor));
            } catch (Throwable) {
                // A failed release stays in progress and therefore fails closed.
            }
        }
        // The kernel service owns the artifact and its retention. This
        // operation never deletes a backup.
        throw $error;
    }
}

/**
 * Collect the tenant's posts through the governed administration list
 * capability. The export path never queries storage directly.
 *
 * @param array<string,mixed> $actor
 * @return list<array<string,mixed>>
 */
function cacExportCollectPostRows(array $actor): array
{
    $rows = [];
    $offset = 0;
    $limit = 50;
    $pages = 0;
    do {
        $page = app()->cap()->call('akira.post.admin.list@1', [
            'include_unpublished' => true,
            'limit' => $limit,
            'offset' => $offset,
            'sort_field' => 'created_at',
            'sort_direction' => 'asc',
        ], cacBackupAuditContext($actor));
        if (!is_array($page) || ($page['ok'] ?? false) !== true) {
            throw new CacBackupException('Post export source read failed.', 503);
        }
        $batch = is_array($page['rows'] ?? null) ? array_values(array_filter($page['rows'], 'is_array')) : [];
        foreach ($batch as $row) {
            $rows[] = $row;
        }
        $total = (int) ($page['total'] ?? count($rows));
        $offset += $limit;
        $pages++;
    } while (count($rows) < $total && $batch !== [] && $pages < 200);

    if (count($rows) > CAC_EXPORT_MAX_ROWS) {
        $rows = array_slice($rows, 0, CAC_EXPORT_MAX_ROWS);
    }
    return $rows;
}

/**
 * Read a bounded, human-readable excerpt back from the produced export file.
 *
 * @return array{text:string,truncated:bool,bytes_shown:int}
 */
function cacExportExcerpt(string $path, int $maxBytes = CAC_EXPORT_EXCERPT_BYTES): array
{
    if (!is_file($path)) {
        return ['text' => '', 'truncated' => false, 'bytes_shown' => 0];
    }
    $size = (int) @filesize($path);
    $fh = @fopen($path, 'rb');
    if (!is_resource($fh)) {
        return ['text' => '', 'truncated' => false, 'bytes_shown' => 0];
    }
    $data = (string) fread($fh, max(1, $maxBytes));
    fclose($fh);
    if (str_starts_with($data, "\xEF\xBB\xBF")) {
        $data = substr($data, 3);
    }
    return [
        'text' => $data,
        'truncated' => $size > $maxBytes,
        'bytes_shown' => strlen($data),
    ];
}

/**
 * Produce a real governed export of the tenant's posts through KernelExport,
 * audited via the kernel. Administrator-only at the policy layer.
 *
 * @return array<string,mixed>
 */
function cac_cap_akira_export_create_1(mixed $payload, string $capabilityId = 'akira.export.create@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CacBackupException('payload must be an object.');
    }
    $actor = cacBackupActor();
    $format = strtolower(trim((string) ($payload['format'] ?? 'csv')));
    if ($format !== 'csv') {
        throw new CacBackupException('Only CSV export is available for inline review.', 422);
    }
    $tenantId = cacPostTenantId();

    $rows = cacExportCollectPostRows($actor);
    if ($rows === []) {
        return ['ok' => true, 'empty' => true, 'record_count' => 0, 'format' => $format, 'message' => 'No posts exist to export.'];
    }
    $columns = ['slug', 'title', 'subtitle', 'status', 'published_at', 'created_at', 'updated_at'];
    $filename = 'cms-akira-posts-' . date('Ymd-His') . '.csv';
    $export = KernelExport::export('post', $format, $rows, [
        'title' => 'CMS Akira posts export',
        'filename' => $filename,
        'columns' => $columns,
    ]);
    if (!is_array($export) || !is_file((string) $export['path'])) {
        throw new CacBackupException('Export generation failed.', 503);
    }

    $excerpt = cacExportExcerpt($export['path']);

    $audit = app()->cap()->call('kernel.audit.record@1', [
        'module' => CAC_BACKUP_MODULE_ID,
        'action' => 'akira.export.create',
        'entity_type' => 'post_export',
        'entity_id' => $export['filename'],
        'new_data' => [
            'tenant_id' => $tenantId,
            'format' => $format,
            'filename' => $export['filename'],
            'size_bytes' => $export['size'],
            'record_count' => count($rows),
            'performed_by_role' => (string) ($actor['role'] ?? ''),
        ],
    ], cacBackupAuditContext($actor));
    if (!is_array($audit) || ($audit['ok'] ?? false) !== true) {
        @unlink($export['path']);
        throw new CacBackupException('Durable export audit failed; the export was discarded.', 503);
    }

    // The export is served inline for review; the scratch file is not retained.
    @unlink($export['path']);

    return [
        'ok' => true,
        'empty' => false,
        'format' => $format,
        'filename' => $export['filename'],
        'size_bytes' => $export['size'],
        'record_count' => count($rows),
        'columns' => $columns,
        'excerpt' => $excerpt['text'],
        'excerpt_truncated' => $excerpt['truncated'],
    ];
}
