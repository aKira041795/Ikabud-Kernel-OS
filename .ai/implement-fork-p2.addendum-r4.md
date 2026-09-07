# P2 RE-RUN ADDENDUM — chair adjudication applied (re-read the amended contract tail)

The P2 contract `.ai/contract-fork-p2-2026-09-07.md` now carries a **CHAIR ADJUDICATION** section (P2 BLOCK #1
resolved). Apply it exactly:

1. **Prove P2 on the repo's actual validated runtime = SINGLE-TENANT app DB** (`APP_MULTI_TENANT_ENABLED=0` in both
   .env + .env.ci; DB_DATABASE = CONTROL_DB_DATABASE = ikabudsix; config/app.php: multi-tenancy optional, NOT the
   default target). Your own preflight confirmed the base DB `ikabudsix` contains ALL THREE tables on one
   connection (cms_akira_posts [add on provisioning] + kernel_idempotency_keys + audit_logs). The same-PDO
   `BEGIN → Idempotency::claim → Post write + audit(correlation_id) → commit → COMMIT` sequence IS provable there.
2. **Do NOT treat the dl1-5 dedicated tenant DBs as the target** — they are daily-ledger's local tenant-local dev
   DBs, not the kernel's default/CI runtime. The tenant-provisioning parity gap (kernel idempotency/outbox + audit +
   capability_authorization_policies absent in dedicated tenant DBs) is a REAL recorded follow-up for the
   tenant-provisioning workstream, NOT a P2 blocker.
3. **Use the legal single-tenant path** you confirmed: mutation handlers run in the guarded module context but the
   kernel allows `app()->db()` under kernel escalation to participate in the caller-managed transaction when the
   tables coexist on the one connection. Wire the P2 mutation to that path. If the guarded module context genuinely
   forbids the idempotency table access even on the single app DB, identify the narrowest legal kernel seam and STOP
   → BLOCKED with that single-tenant-specific evidence (do NOT silently edit kernel).
4. Record the follow-up in the contract preflight (one paragraph) and implement P2 exactly as the (amended) contract
   specifies: cms.post.create@1/update@1 (admin-only, CapabilityAuthorizationRegistry + Entity Authority,
   kernel-context tenant/actor, canonical slug, status/published_at), effects.invalidates ["entity.list.post"],
   durable idempotency + audit(correlation_id), R7 named non-GET mutation route + kernel CSRF, and the full P2 test
   set (cache-then-mutate-then-render freshness; idempotency replay/conflict/concurrency/no-invalidate-on-failure;
   authorization/CSRF/tenant-spoof/JWT-injection; audit correlation_id).

Verification: P2 tests green; P1 test 38/38 still green; php tests/capability_authority_audit_test.php 18/18; full
composer test green; both logs clean; diff vs pristine = intended P1+P2 deltas only.

Report the compact result block. recommended_next_state should say P2-PASS → P3 ADR / P4 marker ready if green.
