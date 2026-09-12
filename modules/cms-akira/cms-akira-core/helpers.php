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
require_once __DIR__ . '/helpers/governance.php';

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
    $rows = [];
    // Publish/unpublish/delete belong to the administrative tier, not the single
    // `admin` role: cawPostLifecycleTransitions() already admits author, editor,
    // admin, administrator and superadmin to publish, so seeding `admin` alone made
    // the workflow offer an action the capability then refused.
    $adminTier = function_exists('cacAkiraAdminRoleCsv') ? cacAkiraAdminRoleCsv() : 'admin,administrator,superadmin';
    foreach ([
        'akira.post.create@1' => ['contributor,author,editor,admin,administrator,superadmin', 'cms-akira-core,cms-akira-shell'],
        'akira.post.update@1' => ['contributor,author,editor,admin,administrator,superadmin', 'cms-akira-core,cms-akira-shell'],
        'akira.post.publish@1' => [$adminTier, 'cms-akira-core'],
        'akira.post.unpublish@1' => [$adminTier, 'cms-akira-core'],
        'akira.post.delete@1' => [$adminTier, 'cms-akira-core,cms-akira-shell'],
    ] as $capabilityId => [$allowedRoles, $allowedCallers]) {
        $rows[] = [
            'policy_version' => 1,
            'capability_id' => $capabilityId,
            'capability_version' => '1',
            'provider' => 'cms-akira-core',
            'caller_module' => $allowedCallers,
            'allowed_roles' => $allowedRoles,
            'provider_activation_required' => true,
            'requires_protocol' => 'v2',
            'is_active' => true,
        ];
    }
    \Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry::seedPolicyForCurrentScope($rows);
}

cacSeedPostMutationPolicies();

/**
 * Govern only the draft-capable administration read surface. Public Post and
 * entity-view reads intentionally have no policy rows and remain anonymous,
 * tenant-scoped, published-only projections.
 */
function cacSeedPostAdminReadPolicies(): void
{
    if (!function_exists('app')) {
        return;
    }
    $rows = [];
    foreach (['akira.post.admin.get@1', 'akira.post.admin.list@1'] as $capabilityId) {
        $rows[] = [
            'policy_version' => 1,
            'capability_id' => $capabilityId,
            'capability_version' => '1',
            'provider' => 'cms-akira-core',
            'caller_module' => 'cms-akira-core,cms-akira-shell,cms-akira-seo',
            'allowed_roles' => 'contributor,author,editor,admin,administrator,superadmin',
            'provider_activation_required' => true,
            'requires_protocol' => 'v1',
            'is_active' => true,
        ];
    }
    \Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry::seedPolicyForCurrentScope($rows);
}

cacSeedPostAdminReadPolicies();

/**
 * Seed route-level read authority for the Akira shell. The active policy may
 * have been cloned by the permissions UI, so declarations must join the
 * currently active version rather than silently landing only in version 1.
 */
function cacSeedShellReadPolicies(): void
{
    if (!function_exists('app')) {
        return;
    }

    $resolver = \Ikabud\Kernel\Capabilities\AuthorityScopeResolver::forApplication();
    $scope = $resolver->resolve(\Ikabud\Kernel\Capabilities\AuthorityScopeResolver::WEB, [
        'actor' => app()->user(),
    ]);
    $registry = new \Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry(
        null,
        $scope,
        $resolver,
        $resolver->failureReason() ?? 'missing_tenant_authority_scope'
    );
    $activeRows = $registry->activePolicyRows();
    $policyVersion = $activeRows === [] ? 1 : (int)($activeRows[0]['policy_version'] ?? 1);

    $rows = [];
    foreach ([
        'akira.taxonomy.list@1' => 'contributor,author,editor,admin,administrator,superadmin',
        'akira.content_type.list@1' => 'contributor,author,editor,admin,administrator,superadmin',
        'akira.policy.list@1' => 'admin,administrator,superadmin',
        'akira.user.list@1' => 'admin,administrator,superadmin',
    ] as $capabilityId => $allowedRoles) {
        $rows[] = [
            'policy_version' => $policyVersion,
            'capability_id' => $capabilityId,
            'capability_version' => '1',
            'provider' => 'cms-akira-core',
            'caller_module' => 'cms-akira-core,cms-akira-shell',
            'allowed_roles' => $allowedRoles,
            'provider_activation_required' => true,
            'requires_protocol' => 'v1',
            'is_active' => true,
        ];
    }
    \Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry::seedPolicyForCurrentScope($rows);
}

cacSeedShellReadPolicies();

/**
 * Activation-time, idempotent seed for the P1 governed taxonomy mutation
 * policy. Mirrors cacSeedPostMutationPolicies(): the CapabilityAuthorizationRegistry
 * is the legal kernel-owned channel and the rows are created per tenant DB.
 * Taxonomy terms are content taxonomy (categories/tags) — managed by editor and
 * administrator roles; reads stay ungoverned until R5 governs reads.
 */
function cacSeedTaxonomyMutationPolicies(): void
{
    if (!function_exists('app')) {
        return;
    }
    $rows = [];
    foreach ([
        'akira.taxonomy.create@1' => ['admin,editor,administrator,superadmin', 'cms-akira-core,cms-akira-shell'],
        'akira.taxonomy.update@1' => ['admin,editor,administrator,superadmin', 'cms-akira-core,cms-akira-shell'],
        'akira.taxonomy.delete@1' => ['admin,editor,administrator,superadmin', 'cms-akira-core,cms-akira-shell'],
    ] as $capabilityId => [$allowedRoles, $allowedCallers]) {
        $rows[] = [
            'policy_version' => 1,
            'capability_id' => $capabilityId,
            'capability_version' => '1',
            'provider' => 'cms-akira-core',
            'caller_module' => $allowedCallers,
            'allowed_roles' => $allowedRoles,
            'provider_activation_required' => true,
            'requires_protocol' => 'v2',
            'is_active' => true,
        ];
    }
    \Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry::seedPolicyForCurrentScope($rows);
}

cacSeedTaxonomyMutationPolicies();

/**
 * Activation-time, idempotent seed for the P1 governed content-type registry
 * mutation policy. Mirrors cacSeedTaxonomyMutationPolicies(): the
 * CapabilityAuthorizationRegistry is the legal kernel-owned channel and the
 * rows are created per tenant DB. Content types are declared content models —
 * managed by editor and administrator roles; reads stay ungoverned until R5
 * governs reads.
 */
function cacSeedContentTypeMutationPolicies(): void
{
    if (!function_exists('app')) {
        return;
    }
    $rows = [];
    foreach ([
        'akira.content_type.create@1' => ['admin,editor,administrator,superadmin', 'cms-akira-core,cms-akira-shell'],
        'akira.content_type.update@1' => ['admin,editor,administrator,superadmin', 'cms-akira-core,cms-akira-shell'],
        'akira.content_type.delete@1' => ['admin,editor,administrator,superadmin', 'cms-akira-core,cms-akira-shell'],
    ] as $capabilityId => [$allowedRoles, $allowedCallers]) {
        $rows[] = [
            'policy_version' => 1,
            'capability_id' => $capabilityId,
            'capability_version' => '1',
            'provider' => 'cms-akira-core',
            'caller_module' => $allowedCallers,
            'allowed_roles' => $allowedRoles,
            'provider_activation_required' => true,
            'requires_protocol' => 'v2',
            'is_active' => true,
        ];
    }
    \Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry::seedPolicyForCurrentScope($rows);
}

cacSeedContentTypeMutationPolicies();

/**
 * Activation-time, idempotent seed for the P1 post <-> taxonomy assignment
 * mutation policy. Mirrors cacSeedContentTypeMutationPolicies(): the
 * CapabilityAuthorizationRegistry is the legal kernel-owned channel and the
 * rows are created per tenant DB. Assigning taxonomy terms to a post is an
 * editorial content-model write — the same admin/editor/administrator/superadmin
 * allowlist taxonomy and content-type management use; reads stay ungoverned.
 */
function cacSeedPostTaxonomyMutationPolicies(): void
{
    if (!function_exists('app')) {
        return;
    }
    $rows = [];
    foreach ([
        'akira.post.set_taxonomies@1' => ['admin,editor,administrator,superadmin', 'cms-akira-core,cms-akira-shell'],
    ] as $capabilityId => [$allowedRoles, $allowedCallers]) {
        $rows[] = [
            'policy_version' => 1,
            'capability_id' => $capabilityId,
            'capability_version' => '1',
            'provider' => 'cms-akira-core',
            'caller_module' => $allowedCallers,
            'allowed_roles' => $allowedRoles,
            'provider_activation_required' => true,
            'requires_protocol' => 'v2',
            'is_active' => true,
        ];
    }
    \Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry::seedPolicyForCurrentScope($rows);
}

cacSeedPostTaxonomyMutationPolicies();

/**
 * Activation-time, idempotent seed for the P1 post revision revert mutation
 * policy. Mirrors cacSeedPostTaxonomyMutationPolicies(): the
 * CapabilityAuthorizationRegistry is the legal kernel-owned channel and the
 * rows are created per tenant DB. Reverting a post content snapshot is an
 * editorial content-model write over the same editorial allowlist taxonomy,
 * content-type and assignment management use. The revision read surface
 * (akira.post.revisions.list@1 / akira.post.revision.get@1) stays ungoverned
 * like the other P1 reads until R5 governs reads at the policy layer.
 */
function cacSeedPostRevisionMutationPolicies(): void
{
    if (!function_exists('app')) {
        return;
    }
    $rows = [];
    foreach ([
        'akira.post.revision.revert@1' => ['admin,editor,administrator,superadmin', 'cms-akira-core,cms-akira-shell'],
    ] as $capabilityId => [$allowedRoles, $allowedCallers]) {
        $rows[] = [
            'policy_version' => 1,
            'capability_id' => $capabilityId,
            'capability_version' => '1',
            'provider' => 'cms-akira-core',
            'caller_module' => $allowedCallers,
            'allowed_roles' => $allowedRoles,
            'provider_activation_required' => true,
            'requires_protocol' => 'v2',
            'is_active' => true,
        ];
    }
    \Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry::seedPolicyForCurrentScope($rows);
}

cacSeedPostRevisionMutationPolicies();

/** Seed the P3-1 governance writes; policy rows remain their sole role authority. */
function cacSeedGovernancePolicies(): void
{
    if (!function_exists('app')) {
        return;
    }
    $rows = [];
    foreach (['akira.policy.set_roles@1', 'akira.user.update_role@1', 'akira.user.set_active@1'] as $capabilityId) {
        $rows[] = [
            'policy_version' => 1, 'capability_id' => $capabilityId, 'capability_version' => '1',
            'provider' => 'cms-akira-core', 'caller_module' => 'cms-akira-shell,cms-akira-core',
            'allowed_roles' => 'admin,administrator,superadmin', 'provider_activation_required' => true,
            'requires_protocol' => 'v2', 'is_active' => true,
        ];
    }
    \Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry::seedPolicyForCurrentScope($rows);
}

cacSeedGovernancePolicies();

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
    /** @var \Ikabud\Kernel\Contracts\ModuleDB $db */
    $db = cacCtx()->db();
    return $db;
}

function cacInput(?string $key = null, mixed $default = null): mixed
{
    return cacCtx()->input($key, $default);
}

/**
 * @param array<string, mixed> $context
 */
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
