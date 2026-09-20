<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/src/helpers/module-manager.php';
require_once dirname(__DIR__) . '/src/helpers/module-migrations.php';
foreach (['cms-akira-core','cms-akira-workflow','cms-akira-search','cms-akira-ai','cms-akira-builder','kernel'] as $m) {
    printf("%-24s tenant54=%-10s unresolved-tenant=%s\n", $m, moduleActivationState($m, 54), moduleActivationState($m, 99999999));
}
