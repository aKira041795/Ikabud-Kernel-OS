<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** @param array<string, string> $params */
function akiraBuilderHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'module' => CAB_BUILDER_MODULE_ID, 'version' => '1.0.0', 'authority' => 'native'], JSON_UNESCAPED_SLASHES);
}
