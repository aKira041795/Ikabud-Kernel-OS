<?php

declare(strict_types=1);

return [
    'GET' => [
        '/api/v1/cms-akira-media/health' => 'cms-akira-media:akiraMediaHealth',
        '/api/v1/cms-akira-media/stream/{media_key}' => 'cms-akira-media:akiraMediaStream',
    ],
];
