# CMS Akira Independent-CMS — Phase 0 gate: freeze boundary + kernel prerequisites

task: Execute Phase 0 of the APPROVED independent-CMS contract (`.ai/current-task.md` @ 8053dc1 +
`.ai/chair-adjudication-independent-cms-2026-09-07.md`). Phase 0 = FREEZE BOUNDARY + EVIDENCE + the two Kernel
prerequisite changes (protocol-v2 enforcement R3, ARK theme-slug de-legacy). NO Akira member build happens here.

objective: Prove the kernel is ready for the Akira mutation + render gates and freeze the boundary so every later
phase is deterministic:
1. **R3 protocol-v2 enforcement (Kernel change):** `CapabilityAuthorizationRegistry::requiresProtocol()` exists but
   NO caller enforces it — `authorize()` does not. Implement FAIL-CLOSED protocol-v2 enforcement in the canonical
   capability dispatch path (CapabilityBus authorize/call) so a capability declaring `requires_protocol: v2` is
   enforced (caller must speak protocol v2 / be re-authorized), with tests. This is the same class of kernel
   completion the Akira gates depend on. Model on how kernel.audit.record@1 + the idempotency bridge were wired.
2. **ARK theme-slug de-legacy (Kernel change):** `ArkRendererResolver::resolveThemeSlug()` falls back to
   `cmsActiveTheme()`. Remove the legacy-cms theme fallback from the Akira render path: the resolver must accept an
   explicit theme slug and/or resolve via a Kernel-owned tenant theme-activation resolver — never `cmsActiveTheme`,
   never `storage/cms-themes` convention as an Akira dependency. (Read kernel/Services/ArkRendererResolver.php +
   docs/kernel/ark-renderer-selection.md + the ark_renderer_runtime_test to keep the fixture theme + 17/17 green;
   adjust tests to the new explicit-slug contract.)
3. **EVIDENCE + FREEZE (record in `.ai/contract-independent-cms-phase0-2026-09-07.md`):**
   - Pin the frozen boundary @ 8053dc1: exact `modules/cms-akira/cms-akira-core/module.json` owns/reads/exposes/
     migrations; P1 (38) + P2 (26) test baselines + authority audit 18/18.
   - Forbidden-residue inventory across the 13 dormant members + core (each cms.*/search.index.*/tinymce/
     cmsActiveTheme/akira.content.get@1 reference with file:line) — the list later gates must clear.
   - Reserve names: the akira.* capability families, owned tables (cms_akira_posts/menus/menu_items/media/
     media_variants/seo_metadata/search_documents/compositions/composition_revisions), entity view ids, the ONE
     `entry_module:true` rule (only the future cms-akira-shell), the akira.editor.* (not bare editor.*) rule.
   - Pin the runtime contracts later phases rely on: `_module_enabled`/`tenant_module_settings` keys,
     `tenantSetModuleActivationState` internals, Idempotency claim/commit/release + `(idempotency_key_hash,
     tenant_id)` unique index, `CapabilityAuthorizationRegistry::seedPolicy()` + the ACTUAL unique index on
     `capability_authorization_policies` (evidence it via information_schema + the migration 016 file) — prove
     seedPolicy's ON DUPLICATE KEY UPDATE is idempotent against that index.

scope:
  allowed:
    - kernel/Capabilities/CapabilityBus.php + CapabilityAuthorizationRegistry.php (only the protocol-v2 enforcement
      wiring — do NOT change seedPolicy semantics or the policy table schema)
    - kernel/Services/ArkRendererResolver.php (explicit theme-slug resolution; remove cmsActiveTheme fallback)
    - tests/ (focused: protocol-v2 enforcement; ARK explicit-slug contract; seedPolicy unique-index idempotency)
    - docs/kernel/ark-renderer-selection.md (update to explicit-slug contract)
    - .ai/contract-independent-cms-phase0-2026-09-07.md (freeze record — evidence + reservation + residue inventory)
  prohibited:
    - NO Akira member build/rename/enable/track (that is Phase 1+).
    - NO change to Idempotency, seedPolicy table schema, ThemeManifestValidator, EntityViewResolver, the dormant
      members' files.
    - NO-BROADEN; NO legacy-cms dependency introduced.
constraints:
  - Protocol-v2 enforcement must be fail-closed + additive: capabilities WITHOUT requires_protocol v2 keep current
    behavior (full suite green); v2-declared capabilities are enforced at the canonical dispatch path. PHP 7.4/8,
    phpstan (no baseline additions), cs-fixer CI style.
  - ARK de-legacy: existing callers/tests that relied on cmsActiveTheme fallback must be migrated to the explicit
    slug contract (check the ark_renderer_runtime_test + any kernel consumer of arkRenderers()).
acceptance:
  - A capability declaring requires_protocol v2 is DENIED when the caller does not satisfy the v2 protocol; the
    focused test proves allow + deny. Existing (non-v2) capabilities unaffected (full composer test green).
  - ArkRendererResolver no longer references cmsActiveTheme/storage-cms-themes for slug fallback; it resolves via the
    explicit theme slug or a Kernel-owned resolver; fixture theme + 17/17 green under the new contract.
  - `.ai/contract-independent-cms-phase0-2026-09-07.md` records all freeze/evidence items above (name reservations,
    residue inventory with file:line, seedPolicy unique-index evidence, runtime contract pins).
  - authority audit 18/18; P1 38/38; P2 26/26; composer test green; CI 6/6; both logs clean.
verification:
  - New focused tests (protocol-v2 enforcement allow/deny; ARK explicit-slug; seedPolicy idempotency vs the real
    unique index) + full composer test + ark_renderer_runtime_test (17) + authority (18) + P1 (38) + P2 (26).
  - grep: no cmsActiveTheme in ArkRendererResolver; no unguarded requiresProtocol path.
risk:
  - MEDIUM (two narrow kernel changes). Mitigations: additive + fail-closed, focused tests, full suite as gate.
status: READY_FOR_IMPLEMENTATION (chair-approved; Phase 0 of the independent-CMS contract)

---

## Phase 0 freeze and evidence record (executed 2026-09-07)

### Frozen boundary and baseline

- Source boundary is pinned to Git `8053dc1` (the clean tracked-tree HEAD at preflight). The 14 existing product
  directories are core plus 13 dormant members; the future shell makes 15. All 13 non-core manifests are ignored by
  `.gitignore:27` and carry `_enabled:false`; core also carries `_enabled:false` at this boundary. No member was
  renamed, enabled, unignored, or otherwise built in Phase 0.
- `modules/cms-akira/cms-akira-core/module.json` is frozen with:
  - `owns_tables`: `cms_akira_posts`
  - `reads_tables`: `cms_akira_posts`
  - `migrations`: `database/migrations/001_initial.sql`, `database/migrations/002_create_posts.sql`
  - `capabilities.exposes`: `cms.post.get@1`, `cms.post.list@1`, `cms.post.create@1`,
    `cms.post.update@1`, `entity.list.post@1`, `entity.get.post@1`
  - `capabilities.depends`: `kernel.idempotency.hash@1`, `kernel.idempotency.claim@1`,
    `kernel.idempotency.commit@1`, `kernel.idempotency.release@1`, `kernel.audit.record@1`
- Frozen executable baselines are P1 `post_read_render_test.php` **38/38**, P2 `post_mutation_test.php` **26/26**,
  and `capability_authority_audit_test.php` **18/18**. These are compatibility baselines, not approval of the legacy
  names inventoried below.

### Reserved independent-CMS names

- Capability families are reserved to their owning Akira members: `akira.post.*@1`, `akira.media.*@1`,
  `akira.navigation.*@1`, `akira.theme.*@1`, `akira.seo.*@1`, `akira.search.*@1`, `akira.workflow.*@1`,
  `akira.ai.*@1`, `akira.builder.*@1`, and `akira.editor.*@1`. Bare `editor.*` is forbidden. `cms.*` is not
  canonical Akira authority; an alias requires its own ADR/gate.
- Owned table names are reserved exactly as follows: `cms_akira_posts`, `cms_akira_menus`,
  `cms_akira_menu_items`, `cms_akira_media`, `cms_akira_media_variants`, `cms_akira_seo_metadata`,
  `cms_akira_search_documents`, `cms_akira_compositions`, `cms_akira_composition_revisions`.
- Entity-view IDs are reserved by owned entity: `entity.list.post`/`entity.detail.post`,
  `entity.list.navigation`/`entity.detail.navigation`, `entity.list.media`/`entity.detail.media`,
  `entity.list.seo_metadata`/`entity.detail.seo_metadata`, `entity.list.search_document`/
  `entity.detail.search_document`, and `entity.list.composition`/`entity.detail.composition`. Manifest capability
  bridges use the corresponding versioned `@1` contract. Exact-match ARK renderer keys remain unversioned.
- Exactly one product member may declare `entry_module:true`: the future `cms-akira-shell`. Profiles are data-free
  installation selections and never entry modules. The frozen `cms-akira-profile-standard` declaration is known
  residue to remove in its own gate, not precedent.

### Runtime contracts pinned for later phases

- `database/migrations/007_tenant_module_settings.sql` owns `tenant_module_settings` with columns
  `tenant_id`, `module_id`, `setting_key`, `setting_value` and unique key
  `(tenant_id,module_id,setting_key)`. Activation is the JSON setting key `_module_enabled`; explicit `false`
  deactivates while retaining data. Reads/writes in `src/helpers/module-manager.php` use tenant context, prepared
  tenant/module/key operations, KernelPDO escalation, and invalidate `_tenant_module_settings_cache`.
- **Baseline defect recorded, not broadened here:** `kernel/Services/TenantProvisioner.php:200` calls
  `tenantSetModuleActivationState($tenantPdo, $tenantId, $plan, true)` before the separate control-plane status CAS,
  but a full no-ignore source scan at `8053dc1` finds no function definition. Therefore there are no implementation
  internals to pin beyond this call signature/order, and the call can fatal if reached. The future module-install
  service must not copy this dangling/non-compensating path; resolving it remains a Kernel prerequisite outside the
  two approved Phase-0 changes.
- `kernel/Http/Idempotency.php` is pinned as the sole durable primitive: canonical payload SHA-256; `claim()` obtains
  a tenant/key advisory lock and atomically inserts processing state; `commit()` persists the reusable outcome;
  `release()` removes only an owned processing claim. Duplicate, conflict, and in-progress are distinct fail-closed
  results. `migrations/011_kernel_idempotency_keys.sql` evidences unique key `uq_key_tenant
  (idempotency_key_hash, tenant_id)`.
- `CapabilityAuthorizationRegistry::seedPolicy()` is unchanged. It uses MySQL-5.7-compatible `INSERT ... ON
  DUPLICATE KEY UPDATE` over policy fields including `requires_protocol`. Migration
  `migrations/016_capability_authorization_policies.sql` declares the actual unique natural key
  `uq_capability_authorization_policy(policy_version, capability_id, capability_version, provider)`. The focused
  test queries `information_schema.statistics` for that exact ordered, unique index, runs migration 016 twice, and
  seeds the same natural key twice, proving one row is updated rather than duplicated.
- Protocol v2 is now executable policy: canonical `CapabilityBus::call()` asks `requiresProtocol()` for each
  capability/version/provider and compares it to protocol derived only from trusted provider metadata/service
  configuration. A v2 policy with missing/v1 transport is denied as `protocol_mismatch`; matching v2 proceeds to
  normal registry re-authorization. Non-v2 capabilities retain prior behavior. Caller options cannot spoof this.
- ARK selection now requires an explicit, trusted theme slug. Null/empty slug fails closed; the resolver contains no
  active-theme helper or CMS storage fallback. A caller may obtain the slug from its owning module or a future
  Kernel-owned tenant theme activation resolver.

### Forbidden-residue inventory (frozen no-ignore scan)

Command: `rg -n --no-ignore -i '(cms\\.[A-Za-z0-9_.@*-]*|search\\.index\\.[A-Za-z0-9_.@*-]*|tinymce|cmsActiveTheme|akira\\.content\\.get@1)' modules/cms-akira`.
The following are every matching file and line at the frozen boundary (multiple forbidden tokens on one line count
once). Builder and all four profiles have zero matches; their migration/runtime scaffolding is separately gated.

- `modules/cms-akira/README.md`: 54, 55, 56, 57, 58, 59, 61, 72, 73, 74, 80
- `modules/cms-akira/cms-akira-core/README.md`: 48, 49
- `modules/cms-akira/cms-akira-core/handlers.php`: 59, 98, 121, 139, 141
- `modules/cms-akira/cms-akira-core/helpers.php`: 31
- `modules/cms-akira/cms-akira-core/helpers/capabilities.php`: 16, 17, 18, 19, 104, 134, 392, 455, 466, 480, 514
- `modules/cms-akira/cms-akira-core/module.json`: 18, 19, 20, 21, 22, 37, 38, 44, 52, 60, 74
- `modules/cms-akira/cms-akira-core/tests/post_mutation_test.php`: 31, 88, 98, 99, 102, 136, 138, 147, 157,
  172, 190, 199, 208, 217, 225, 246, 262, 268, 281
- `modules/cms-akira/cms-akira-core/tests/post_read_render_test.php`: 50, 82
- `modules/cms-akira/cms-akira-ai/module.json`: 30
- `modules/cms-akira/cms-akira-editor/helpers.php`: 82, 85, 122, 125, 186, 190, 191, 193
- `modules/cms-akira/cms-akira-editor/module.json`: 62
- `modules/cms-akira/cms-akira-media/helpers.php`: 65, 73
- `modules/cms-akira/cms-akira-media/module.json`: 30, 31
- `modules/cms-akira/cms-akira-navigation/helpers.php`: 67, 71, 78
- `modules/cms-akira/cms-akira-navigation/module.json`: 30, 31, 32
- `modules/cms-akira/cms-akira-search-adapter/handlers.php`: 25
- `modules/cms-akira/cms-akira-search-adapter/helpers.php`: 107
- `modules/cms-akira/cms-akira-search-adapter/module.json`: 30, 31
- `modules/cms-akira/cms-akira-seo/helpers.php`: 74, 78
- `modules/cms-akira/cms-akira-seo/module.json`: 40, 41
- `modules/cms-akira/cms-akira-theme/handlers.php`: 27
- `modules/cms-akira/cms-akira-theme/module.json`: 30
- `modules/cms-akira/cms-akira-workflow/helpers.php`: 71, 79
- `modules/cms-akira/cms-akira-workflow/module.json`: 30

This inventory is a removal checklist for later individual member gates. It is evidence only; Phase 0 does not edit
Akira source.

### Phase 0 verification outcome

- PASS: focused authorization/migration test 13/13 (v2 allow/deny and real unique-index evidence); ARK runtime 17/17;
  P1 38/38; P2 26/26; authority 18/18; full `composer test` 105/105; changed-file `php -l`; targeted PHPStan;
  changed-file PHP-CS-Fixer CI rules; `git diff --check`; application/error logs both zero bytes.
- Local full-CI reproduction is not clean for pre-existing/out-of-scope reasons: strict manifest guard reports
  `daily-ledger`'s ownerless `audit_logs` co-owner; architecture check reports the dormant standard profile's legacy
  auth routes; full PHPStan/PHP-CS-Fixer discover the physically present ignored Akira scaffolds and existing
  daily-ledger style debt; Workbench CI lacks four modules and its competitive benchmark corpus. No baseline was
  added and none of these files was changed.
- Review status: **PARTIAL / REVIEW_REQUIRED** until a pristine checkout demonstrates the expected GitHub CI 6/6 or
  the owner assigns separate baseline repairs. Phase 1 should not be declared ready while the missing
  `tenantSetModuleActivationState()` implementation and required CI result remain unresolved.
