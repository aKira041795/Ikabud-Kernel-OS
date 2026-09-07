You are the /implement + /review agent for the **CMS Akira fork P2 gate** in Ikabud Kernel OS 6.x
(repo root /var/www/html/ikabudsix, main ad250b3; fork P1 complete in the working tree). Execute P2 per the
authoritative approved contract:

    .ai/contract-fork-p2-2026-09-07.md

READ IT FIRST (plus the fork contract + chair adjudication it references). This is the P2 build gate — the Kernel
6.x mutation + invalidation proof. Feature dev frozen. Do not broaden.

## Work location + state (verify before coding)
- Work target: `/var/www/html/ikabudsix/modules/cms-akira/cms-akira-core/` + `storage/cms-themes/cms-akira-posts/`
  (P1 state present: content authority cms.post.get/list@1, versioned bridges entity.list.post@1/entity.get.post@1,
  view contracts, routes /posts + /posts/{slug}, theme, 13 dormant members _enabled:false).
- Kernel READ-ONLY. All kernel machinery already merged: CapabilityBus effects.invalidates (see
  kernel/Capabilities/CapabilityBus.php — effects.invalidates post-success, mode-aware), Idempotency primitive
  (kernel/Http/Idempotency.php — claim/commit/release/canonicalPayloadHash; WAIT_CAP_SECONDS), EntityViewResolver
  invalidateEntityCache (kernel/EntityContext/EntityViewResolver.php:62-80), Entity Authority registry
  (kernel/EntityAuthority/EntityAuthorityRegistry.php), CapabilityAuthorizationRegistry.
- Pristine fork baseline for diff: /var/www/html/applicationostest/modules/cms-akira. modules/* gitignored (runtime).
- Reference mutation patterns already in this repo: modules/daily-ledger handlers (authorizeBranch + write via
  module()->db()), and the stabilization WorkflowEngine/EventBus use of Idempotency claim/commit/release on a PDO.

## P2 deliverables (contract scope — honor exactly; incl. the R4 preflight recorded BEFORE code)
See the contract. Summary: cms.post.create@1 + cms.post.update@1 (admin-only, CapabilityAuthorizationRegistry +
Entity Authority, kernel-context tenant/actor only); effects.invalidates ["entity.list.post"] (single tag); durable
idempotency single-connection sequence (BEGIN → claim → write + audit(correlation_id) → commit → COMMIT, fail-closed
semantics); R4 preflight naming the audit destination + proving same-PDO/DB topology OR documenting cross-DB
recovery; R7 named non-GET mutation route + kernel CSRF; P2 tests; docs note; append result to the contract.

## Verification (do all)
- php -l every touched file; json parse; theme validate still clean; both logs clean after every run.
- New P2 tests (cache-then-mutate-then-render freshness; idempotency replay/conflict/concurrency/no-invalidate-on-
  failure; authorization/CSRF/tenant-spoof/JWT-injection; audit correlation_id + topology).
- P1 test still green: php modules/cms-akira/cms-akira-core/tests/post_read_render_test.php (38/38).
- php tests/capability_authority_audit_test.php → 18/18; full composer test green.
- diff -rq against pristine = intended P1+P2 deltas only.

## Constraints
- Bounded repair ~3 rounds. If the R4 DB-topology preflight cannot be proven (posts vs idempotency vs audit on one
  connection), STOP → return BLOCKED with the concrete topology evidence + options (do NOT invent a bridge or new
  audit table). If a genuine NEW kernel gap is found, STOP → BLOCKED (do not silently edit kernel).
- Do NOT weaken published-only reads, baselines, or P1 behavior.

## Report (compact)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:
preflight_recorded: (audit destination named, DB topology proven/documented)
changed:
implementation_summary:
verification:
scope: unexpected_files
risks:
unresolved:
recommended_next_state:
