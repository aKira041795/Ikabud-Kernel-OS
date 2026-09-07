<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** @param array<string, string> $params */
function akiraMediaHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'module' => 'cms-akira-media',
        'version' => '1.0.0',
        'authority' => 'native',
    ], JSON_UNESCAPED_SLASHES);
}

/**
 * GET /api/v1/cms-akira-media/stream/{media_key}
 * Tenant-scoped, server-derived streaming of a stored media file. Fails closed
 * (404) for missing keys, cross-tenant reads, missing files, or unsafe paths.
 *
 * @param array<string, string> $params
 */
function akiraMediaStream(array $params = []): void
{
    $mediaKey = (string) ($params['media_key'] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/D', $mediaKey) !== 1) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Media not found'], JSON_UNESCAPED_SLASHES);
        return;
    }
    try {
        $row = camMediaFind($mediaKey);
        if ($row === null) {
            throw new RuntimeException('Media not found.');
        }
        $absolute = camMediaAbsolutePath(camMediaTenantId(), (string) $row['storage_path']);
        if (!is_file($absolute)) {
            throw new RuntimeException('Media file not found.');
        }
        $mime = (string) $row['mime_type'];
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($absolute));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=86400');
        readfile($absolute);
    } catch (Throwable) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Media not found'], JSON_UNESCAPED_SLASHES);
    }
}
