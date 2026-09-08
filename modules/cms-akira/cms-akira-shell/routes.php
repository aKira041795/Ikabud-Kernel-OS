<?php

declare(strict_types=1);

return [
    'GET' => [
        '/cms-akira-shell' => 'cms-akira-shell:akiraShellDashboard',
        '/cms-akira-shell/login' => 'cms-akira-shell:akiraShellLogin',
        '/cms-akira-shell/posts' => 'cms-akira-shell:akiraShellPostList',
        '/cms-akira-shell/posts/new' => 'cms-akira-shell:akiraShellPostCreateForm',
        '/cms-akira-shell/posts/{slug}/edit' => 'cms-akira-shell:akiraShellPostEditForm',
        '/cms-akira-shell/compositions' => 'cms-akira-shell:akiraShellCompositions',
        '/cms-akira-shell/compositions/{key}/edit' => 'cms-akira-shell:akiraShellCompositionEdit',
        '/cms-akira-shell/health' => 'cms-akira-shell:akiraShellModuleHealth',
        '/cms-akira-shell/forbidden' => 'cms-akira-shell:akiraShellForbidden',
    ],
    'POST' => [
        '/cms-akira-shell/posts' => 'cms-akira-shell:akiraShellPostCreate',
        '/cms-akira-shell/posts/{slug}' => 'cms-akira-shell:akiraShellPostUpdate',
        '/cms-akira-shell/posts/{slug}/workflow' => 'cms-akira-shell:akiraShellPostWorkflowTransition',
        '/cms-akira-shell/posts/{slug}/delete' => 'cms-akira-shell:akiraShellPostDelete',
    ],
];
