<?php

declare(strict_types=1);

/** @param array<string, string> $params */
function akiraEditorHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'module' => 'cms-akira-editor',
        'version' => '1.0.0',
        'network_provider' => false,
    ], JSON_UNESCAPED_SLASHES);
}
