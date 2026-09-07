You are the /implement + /review agent for Ikabud Kernel OS (repo root /var/www/html/ikabudsix, main 9e49602).
Execute the **capability_authorization_policies migration + requires_protocol propagation** kernel micro-gate per the
authoritative contract:

    .ai/contract-authz-policy-migration-gate-2026-09-07.md

READ THE CONTRACT FIRST. Kernel 6.5 authority completion; additive. Do not broaden.

## Context anchors (verify, don't guess)
- kernel/Capabilities/CapabilityAuthorizationRegistry.php — the DB-backed registry: INSERT
  `capability_authorization_policies` (L140-149: policy_version, capability_id, capability_version, provider,
  caller_module, allowed_roles, provider_activation_required, requires_protocol, is_active, updated_at) +
  ON DUPLICATE KEY UPDATE; SELECT by policy_version + is_active=1 (L228); reads/writes via kernel escalation on
  app()->db(). Read the FULL seedPolicy + authorize flow + parseAllowedRoles to confirm column types + the natural
  UNIQUE key the upsert needs (likely policy_version+capability_id+capability_version+provider).
- migrations/ — next number is 016 (max 015_kernel_durable_event_outbox). Check an existing kernel migration for the
  house style (e.g. 011 idempotency or 015 outbox).
- kernel/Capabilities/CapabilityBus.php:491 — `requires_protocol` v2 check on capability meta. FIND where module
  capability meta is built (module-manager registration → capability registration) and confirm whether
  `requires_protocol` from a module capability declaration is propagated. The fork P2 implementer verified it is NOT
  propagated — confirm and fix minimally (additive).
- Also verify: is there ANY boot path that calls seedPolicy() for declared policies (kernel or module)? If none, note
  it in the contract; this gate ships the migration + propagation + a fixture test proving the mechanism, and P2
  seeds its own policy through whatever the correct channel is (do not invent a seeding subsystem).

## Deliverables (contract scope ONLY)
1. migrations/016_capability_authorization_policies.sql (MySQL 5.7, InnoDB utf8mb4_unicode_ci, registry-compatible
   columns + the UNIQUE key the upsert needs + index on (policy_version, is_active) + id PK + created_at/updated_at).
   Idempotent (CREATE TABLE IF NOT EXISTS) + registered per repo convention.
2. Minimal additive propagation of module capability `requires_protocol` into the capability meta the bus checks
   (CapabilityBus.php:491), IF the gap is confirmed (it is per the fork implementer). Existing capabilities without
   it are unaffected.
3. Focused test (repo plain-PHP style): (a) migration applies + rerun converges; (b) seedPolicy() upserts (seed the
   same row twice → one row, updated); (c) a governed module capability with requires_protocol v2 + roles admin is
   re-authorized by the real registry path: admin allowed, non-admin denied, wrong tenant/caller denied.
4. docs note if applicable. Append result to the contract.

## Verification
- php -l; targeted phpstan (no baseline additions); cs-fixer CI style.
- Migration runner idempotency; focused authz test passes; full `composer test`; CI 6/6.
- Grep confirms requires_protocol propagates from module capability declaration → bus meta.
- Both logs clean; capability authority 18/18; durable idempotency 30, workflow suite, outbox 22, ark runtime 17
  stay green.

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state:
