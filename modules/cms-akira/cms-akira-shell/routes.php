<?php

declare(strict_types=1);

return [
    'GET' => [
        '/cms-akira-shell' => 'cms-akira-shell:akiraShellDashboard',
        '/cms-akira-shell/login' => 'cms-akira-shell:akiraShellLogin',
        '/cms-akira-shell/posts' => 'cms-akira-shell:akiraShellPostList',
        '/cms-akira-shell/posts/new' => 'cms-akira-shell:akiraShellPostCreateForm',
        '/cms-akira-shell/posts/{slug}/edit' => 'cms-akira-shell:akiraShellPostEditForm',
        '/cms-akira-shell/categories' => 'cms-akira-shell:akiraShellCategoryList',
        '/cms-akira-shell/content-types' => 'cms-akira-shell:akiraShellContentTypeList',
        '/cms-akira-shell/compositions' => 'cms-akira-shell:akiraShellCompositions',
        '/cms-akira-shell/compositions/{key}/edit' => 'cms-akira-shell:akiraShellCompositionEdit',
        '/cms-akira-shell/health' => 'cms-akira-shell:akiraShellModuleHealth',
        '/cms-akira-shell/forbidden' => 'cms-akira-shell:akiraShellForbidden',
    ],
    'POST' => [
        '/cms-akira-shell/posts' => 'cms-akira-shell:akiraShellPostCreate',
        '/cms-akira-shell/posts/{slug}' => 'cms-akira-shell:akiraShellPostUpdate',
        '/cms-akira-shell/posts/{slug}/taxonomies' => 'cms-akira-shell:akiraShellPostSetTaxonomies',
        '/cms-akira-shell/posts/{slug}/workflow' => 'cms-akira-shell:akiraShellPostWorkflowTransition',
        '/cms-akira-shell/posts/{slug}/delete' => 'cms-akira-shell:akiraShellPostDelete',
        '/cms-akira-shell/categories' => 'cms-akira-shell:akiraShellCategoryCreate',
        '/cms-akira-shell/categories/{id}' => 'cms-akira-shell:akiraShellCategoryUpdate',
        '/cms-akira-shell/categories/{id}/delete' => 'cms-akira-shell:akiraShellCategoryDelete',
        '/cms-akira-shell/content-types' => 'cms-akira-shell:akiraShellContentTypeCreate',
        '/cms-akira-shell/content-types/{id}' => 'cms-akira-shell:akiraShellContentTypeUpdate',
        '/cms-akira-shell/content-types/{id}/delete' => 'cms-akira-shell:akiraShellContentTypeDelete',
    ],
];
