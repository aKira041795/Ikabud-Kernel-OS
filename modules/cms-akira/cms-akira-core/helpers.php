<?php
/**
 * Cms Akira Core Module — Helpers
 *
 * This file is auto-loaded when the module is enabled.
 * Scoped helper functions provide isolated access to module context,
 * database, input, and rendering. Register event listeners here too.
 *
 * @see docs/kernel/module-development-guide.md
 * @see docs/kernel/module-quickstart.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers/capabilities.php';
require_once __DIR__ . '/helpers/entity-views.php';

/**
 * Activation-time, idempotent seed for the protocol-v2 mutation policy.
 * CapabilityAuthorizationRegistry is the legal kernel-owned channel: it
 * performs its own narrow KernelPDO escalation for its registry table.
 */
function cacSeedPostMutationPolicies(): void
{
    if (!function_exists('app')) {
        return;
    }
    $registry = new \Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry(app()->db());
    $rows = [];
    foreach (['cms.post.create@1', 'cms.post.update@1'] as $capabilityId) {
        $rows[] = [
            'policy_version' => 1,
            'capability_id' => $capabilityId,
            'capability_version' => '1',
            'provider' => 'cms-akira-core',
            'caller_module' => null,
            'allowed_roles' => 'admin',
            'provider_activation_required' => true,
            'requires_protocol' => 'v2',
            'is_active' => true,
        ];
    }
    $registry->seedPolicy($rows);
}

cacSeedPostMutationPolicies();

// ── Scoped Context Helpers ───────────────────────────────────────

function cacCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('cms-akira-core');
    if (!$ctx) {
        throw new \RuntimeException('Cms Akira Core module context unavailable');
    }
    return $ctx;
}

function cacDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return cacCtx()->db();
}

function cacInput(?string $key = null, mixed $default = null): mixed
{
    return cacCtx()->input($key, $default);
}

function cacRender(string $template, array $context = []): string
{
    $resolved = str_starts_with($template, 'modules/cms-akira-core/')
        ? $template
        : 'modules/cms-akira-core/' . ltrim($template, '/');

    return cacCtx()->render($resolved, kernelPrepareRenderContext($resolved, $context));
}

// ── Event Listeners ──────────────────────────────────────────────
// Register inter-module event listeners here. Examples:
//
// app()->events()->listen('order.placed', function (array $payload, string $event) {
//     // React to events from other modules
// }, 10, 'cms-akira-core');
