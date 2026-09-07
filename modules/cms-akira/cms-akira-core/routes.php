<?php

/**
 * Cms Akira Core Module — Routes
 *
 * Format: 'METHOD' => [ '/path' => 'module-id:handlerFunction' ]
 * URL parameters: '/path/{id}' passes $params['id'] to the handler.
 */

declare(strict_types=1);

return [
    'GET' => [
        '/admin/cms-akira-core' => 'cms-akira-core:pageCmsAkiraCoreHome',
        '/admin/ark-status' => 'cms-akira-core:pageAkiraArkStatus',
        '/api/v1/cms-akira-core/health' => 'cms-akira-core:apiCmsAkiraCoreHealth',
        '/api/v1/cms-akira-core/providers/health' => 'cms-akira-core:apiCmsAkiraCoreProvidersHealth',
        '/posts' => 'cms-akira-core:pageCmsAkiraPosts',
        '/posts/{slug}' => 'cms-akira-core:pageCmsAkiraPostDetail',
    ],
    'POST' => [
        '/api/v1/cms-akira/posts' => 'cms-akira-core:apiCmsAkiraPostCreate',
    ],
    'PUT' => [
        '/api/v1/cms-akira/posts/{slug}' => 'cms-akira-core:apiCmsAkiraPostUpdate',
    ],
];
