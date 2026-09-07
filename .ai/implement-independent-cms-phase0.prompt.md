You are the /implement + /review agent for the **CMS Akira Independent-CMS Phase 0 gate** in Ikabud Kernel OS 6.x
(repo root /var/www/html/ikabudsix, main 8053dc1). Execute Phase 0 per the authoritative approved contract:

    .ai/contract-independent-cms-phase0-2026-09-07.md   (READ FIRST — freeze boundary + kernel prerequisites)
    .ai/current-task.md   (the parent independent-CMS contract; Phase 0 section + constraints)
    .ai/chair-adjudication-independent-cms-2026-09-07.md  (R3 = protocol-v2 enforcement is a REAL kernel prerequisite)

Phase 0 = FREEZE + EVIDENCE + TWO narrow Kernel changes. NO Akira member build here (that is Phase 1+).

## Deliverables (contract scope ONLY)
1. **protocol-v2 enforcement (Kernel)** — CapabilityAuthorizationRegistry::requiresProtocol() exists but authorize()
   does NOT enforce it. Wire FAIL-CLOSED enforcement into the canonical capability dispatch path (CapabilityBus
   authorize/call) for capabilities declaring requires_protocol v2. Additive: non-v2 capabilities unchanged. Focused
   allow/deny test. Do NOT change seedPolicy or the policy table schema.
2. **ARK theme-slug de-legacy (Kernel)** — kernel/Services/ArkRendererResolver.php resolveThemeSlug() falls back to
   cmsActiveTheme(); remove that legacy fallback so Akira resolves via an EXPLICIT theme slug or a Kernel-owned
   resolver — never cmsActiveTheme / storage-cms-themes. Migrate the ark_renderer_runtime_test (17/17) + any kernel
   consumer to the explicit-slug contract; update docs/kernel/ark-renderer-selection.md.
3. **Freeze + evidence record** — write `.ai/contract-independent-cms-phase0-2026-09-07.md` (the contract file, append
   the record): pinned @8053dc1 core module.json owns/reads/exposes/migrations + P1(38)/P2(26)/authority(18)
   baselines; forbidden-residue inventory across core + the 13 dormant members (every cms.*/search.index.*/tinymce/
   cmsActiveTheme/akira.content.get@1 ref with file:line); name reservations (akira.* capability families, the owned
   tables list, entity-view ids, the ONE entry_module rule, akira.editor.* not bare editor.*); pinned runtime
   contracts (_module_enabled/tenant_module_settings keys, tenantSetModuleActivationState internals,
   Idempotency (idempotency_key_hash, tenant_id) unique index, seedPolicy idempotency evidenced against the ACTUAL
   capability_authorization_policies unique index — via migration 016 + information_schema).

## Verification (do all)
- New focused tests (protocol-v2 allow/deny; ARK explicit-slug; seedPolicy idempotency) + full composer test +
  ark_renderer_runtime_test 17 + authority 18 + P1 38 + P2 26.
- grep: no cmsActiveTheme in ArkRendererResolver; protocol-v2 enforced at dispatch.
- php -l, targeted phpstan (no baseline additions), cs-fixer CI style; both logs clean.
- CI 6/6 expected.

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state: (Phase 1 ready?)
