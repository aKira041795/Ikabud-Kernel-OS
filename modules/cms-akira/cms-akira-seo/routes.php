<?php

declare(strict_types=1);

return [
    'GET' => [
        '/api/v1/cms-akira-seo/health' => 'cms-akira-seo:akiraSeoHealth',
        '/cms-akira-seo' => 'cms-akira-seo:akiraSeoAdminPage',
    ],
    'POST' => [
        '/cms-akira-seo/upsert' => 'cms-akira-seo:akiraSeoUpsertForm',
        '/cms-akira-seo/delete' => 'cms-akira-seo:akiraSeoDeleteForm',
    ],
];
