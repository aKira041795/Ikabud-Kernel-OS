<?php

declare(strict_types=1);

return [
    'GET' => [],
    'POST' => [
        '/synthetic/declared' => 'synthetic:testDeclared',
        '/synthetic/undeclared' => 'synthetic:testUndeclared',
    ],
];
