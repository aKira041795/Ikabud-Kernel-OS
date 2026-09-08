<?php

declare(strict_types=1);

/** @param array<string, string> $params */
function akiraAiHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'module' => 'cms-akira-ai',
        'version' => '1.0.0',
        'local' => true,
    ], JSON_UNESCAPED_SLASHES);
}
