<?php

declare(strict_types=1);

return [
    'GET' => [
        '/api/v1/cms-akira-theme/health' => 'cms-akira-theme:catThemeHealth',
        '/api/v1/cms-akira-theme/resolve' => 'cms-akira-theme:catThemeResolveJson',
        '/api/v1/cms-akira-theme/themes' => 'cms-akira-theme:catThemeRegistryJson',
        '/api/v1/cms-akira-theme/blocks' => 'cms-akira-theme:catThemeBlocksJson',
        '/api/v1/cms-akira-theme/themes/{slug}/validate' => 'cms-akira-theme:catThemeValidateJson',
        '/cms-akira-theme' => 'cms-akira-theme:catThemeAdminPage',
    ],
    'POST' => [
        '/api/v1/cms-akira-theme/themes/{slug}/activate' => 'cms-akira-theme:catThemeActivateJson',
        '/api/v1/cms-akira-theme/customize' => 'cms-akira-theme:catThemeCustomizeJson',
        '/cms-akira-theme/activate' => 'cms-akira-theme:catThemeActivateForm',
        '/cms-akira-theme/customize' => 'cms-akira-theme:catThemeCustomizeForm',
    ],
];
