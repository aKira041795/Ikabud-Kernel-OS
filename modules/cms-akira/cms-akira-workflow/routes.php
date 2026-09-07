<?php

declare(strict_types=1);

return [
    'GET' => [
        '/api/v1/cms-akira-workflow/health' => 'cms-akira-workflow:cawWorkflowHealth',
        '/api/v1/cms-akira-workflow/{entity_type}/{entity_key}' => 'cms-akira-workflow:cawWorkflowEvaluateJson',
        '/api/v1/cms-akira-workflow/{entity_type}/{entity_key}/runs' => 'cms-akira-workflow:cawWorkflowRunsJson',
    ],
    'POST' => [
        '/api/v1/cms-akira-workflow/{entity_type}/{entity_key}/transition' => 'cms-akira-workflow:cawWorkflowTransitionJson',
    ],
];
