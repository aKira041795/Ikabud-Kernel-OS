<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** @param array<string, string> $params */
function akiraNavigationHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'module' => 'cms-akira-navigation',
        'version' => '1.0.0',
        'authority' => 'native',
    ], JSON_UNESCAPED_SLASHES);
}
