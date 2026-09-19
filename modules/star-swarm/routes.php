<?php

declare(strict_types=1);

/*
 * Star Swarm routes. The page is public and anonymous (see the reasoned
 * governance exemptions in module.json). Both the bare and trailing-slash
 * paths resolve to the same handler so the static Tier 2 entry and a Tier 1
 * kernel dispatch stay reachable.
 */
return [
    'GET' => [
        '/star-swarm' => 'star-swarm:starSwarmPage',
        '/star-swarm/' => 'star-swarm:starSwarmPage',
    ],
];
