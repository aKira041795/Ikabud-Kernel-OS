<?php

declare(strict_types=1);

// Authenticated JSON capability-bridge endpoints for the Akira Builder admin.
// These are thin HTTP wrappers over the akira.builder.*@1 capability handler map
// owned by cms-akira-builder (see helpers.php) — NO new business logic here.
// Kernel-auth + admin role guarded; tenant context is always supplied by the Kernel,
// never accepted from the payload.
return [
    'GET' => [
        '/api/v1/cms-akira-builder/health' => 'cms-akira-builder:akiraBuilderHealth',
        '/api/v1/cms-akira/builder/compositions' => 'cms-akira-builder:akiraBuilderApiCompositions',
        '/api/v1/cms-akira/builder/compositions/{key}' => 'cms-akira-builder:akiraBuilderApiGet',
        '/api/v1/cms-akira/builder/compositions/{key}/revisions' => 'cms-akira-builder:akiraBuilderApiRevisions',
        '/api/v1/cms-akira/builder/compositions/{key}/render' => 'cms-akira-builder:akiraBuilderApiRender',
    ],
    'POST' => [
        '/api/v1/cms-akira/builder/validate' => 'cms-akira-builder:akiraBuilderApiValidate',
        '/api/v1/cms-akira/builder/compositions' => 'cms-akira-builder:akiraBuilderApiCreate',
        '/api/v1/cms-akira/builder/compositions/{key}' => 'cms-akira-builder:akiraBuilderApiUpdate',
        '/api/v1/cms-akira/builder/compositions/{key}/publish' => 'cms-akira-builder:akiraBuilderApiPublish',
        '/api/v1/cms-akira/builder/compositions/{key}/unpublish' => 'cms-akira-builder:akiraBuilderApiUnpublish',
        '/api/v1/cms-akira/builder/compositions/{key}/delete' => 'cms-akira-builder:akiraBuilderApiDelete',
    ],
];
