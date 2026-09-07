# P2 ADDENDUM #3 — consume the merged authz policy migration + requires_protocol propagation

Merged main 63b6ec1 (PR #34). Both P2 kernel blockers are resolved:
- `migrations/016_capability_authorization_policies.sql` creates the registry table (registry-exact columns,
  natural upsert UNIQUE key, active-policy index); seedPolicy() upserts work; focused test 10/10; composer 105/105.
- `src/helpers/module-routes.php` now propagates module capability `requires_protocol` into CapabilityBus provider
  meta.
- NOTE: there is NO boot-time seedPolicy() caller and NO seeding subsystem — P2 MUST seed its own admin-only policy
  for `cms.post.create@1`/`cms.post.update@1` through the correct channel (verify how a module declares/seeds
  CapabilityAuthorizationRegistry policies at activation; if the only path is direct seedPolicy() calls under kernel
  escalation, P2 should seed idempotently at module activation via the documented mechanism — but ONLY via a legal
  kernel-authorized channel, never by weakening KernelPDO).

## Amend the P2 mutation path
1. Run migrations 016 on the app DB (single-tenant runtime ikabudsix) before P2 tests.
2. `cms.post.create@1`/`cms.post.update@1` handlers declare `requires_protocol` (so the bus re-authorizes) and are
   governed by a CapabilityAuthorizationRegistry policy (allowed_roles admin). Seed the policy idempotently at
   module activation through the legal channel (mirror how the focused test seeds via seedPolicy under kernel
   escalation, but from the module's activation/registration path — verify the supported mechanism; do NOT bypass
   KernelPDO).
3. Keep addenda 1+2 exactly: single-tenant app-DB target; kernel.idempotency.{claim,commit,release,hash}@1 via
   CapabilityBus on the caller's transaction; kernel.audit.record@1 for the correlation_id audit row; effects.
   invalidates ["entity.list.post"]; R7 named non-GET mutation route + kernel CSRF; tenant/actor from kernel context
   only; canonical slug; status/published_at; P2 test set.
4. If ANY further genuine kernel gap is discovered, STOP → BLOCKED with evidence (do not silently edit kernel).

Verification: P2 tests green; P1 post_read_render_test 38/38; capability_authority_audit 18/18; full composer test
green; both logs clean; diff vs pristine = intended P1+P2 deltas only.

Report the compact result block. recommended_next_state: P2-PASS → P3 ADR / P4 marker if green.
