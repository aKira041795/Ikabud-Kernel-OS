<?php

declare(strict_types=1);

return [
    'GET' => [
        '/api/v1/cms-akira-navigation/health' => 'cms-akira-navigation:akiraNavigationHealth',
        '/cms-akira-navigation' => 'cms-akira-navigation:akiraNavigationAdminPage',
    ],
    'POST' => [
        '/cms-akira-navigation/menu/create' => 'cms-akira-navigation:akiraNavigationMenuCreateForm',
    ],
];
