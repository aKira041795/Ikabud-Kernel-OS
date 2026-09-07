<?php

declare(strict_types=1);

/**
 * Canonical P1 Post presentation contracts.
 *
 * source_schema contains primitive storage/transport types only. Semantic roles
 * live in field_contracts. ARK selection remains theme-owned.
 */
function cacRegisterPostEntityViews(?\Ikabud\Kernel\EntityContext\EntityViewResolver $views = null): void
{
    if ($views === null) {
        if (!function_exists('app') || !method_exists(app(), 'entityViews')) {
            return;
        }
        $views = app()->entityViews();
    }

    $commonSchema = [
        'entity' => 'post',
        'owner' => 'cms-akira-core',
        'fields' => [
            'title' => 'string',
            'subtitle' => 'string',
            'image' => 'string',
            'body' => 'string',
            'metadata' => 'string',
            'actions' => 'json',
            'url' => 'string',
        ],
    ];
    $commonContracts = [
        'title' => ['role' => 'title'],
        'subtitle' => ['role' => 'subtitle'],
        'image' => ['role' => 'image'],
        'body' => ['role' => 'body'],
        'metadata' => ['role' => 'metadata'],
        'actions' => ['role' => 'actions'],
        'url' => ['role' => 'url'],
    ];

    $views->registerView('post', 'list', [
        'fields' => ['title', 'subtitle', 'image', 'metadata', 'actions', 'url'],
        'actions' => ['view'],
        'key_field' => 'slug',
        'action_urls' => ['view' => '/posts/{slug}'],
        'limit' => 25,
        'sort' => ['field' => 'published_at', 'direction' => 'desc'],
        'sortable_fields' => [
            'published_at' => 'published_at',
            'created_at' => 'created_at',
            'title' => 'title',
        ],
        'empty_state' => 'No published posts.',
        'field_contracts' => array_intersect_key($commonContracts, array_flip([
            'title', 'subtitle', 'image', 'metadata', 'actions', 'url',
        ])),
        'source_schema' => [
            'entity' => $commonSchema['entity'],
            'owner' => $commonSchema['owner'],
            'fields' => array_intersect_key($commonSchema['fields'], array_flip([
                'title', 'subtitle', 'image', 'metadata', 'actions', 'url',
            ])),
        ],
    ], 'cms-akira-core');

    $views->registerView('post', 'detail', [
        'fields' => ['title', 'subtitle', 'image', 'body', 'metadata', 'actions', 'url'],
        'actions' => ['view'],
        'key_field' => 'slug',
        'action_urls' => ['view' => '/posts/{slug}'],
        'field_contracts' => $commonContracts,
        'source_schema' => $commonSchema,
    ], 'cms-akira-core');
}

cacRegisterPostEntityViews();
