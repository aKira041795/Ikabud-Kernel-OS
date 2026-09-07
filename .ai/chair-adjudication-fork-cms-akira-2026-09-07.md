# CMS Akira Fork — Chair Adjudication (2026-09-07)

## Debate provenance
- Intent: `.ai/fork-cms-akira-debate-intent.md`
- Run: `DEBATE_MAX_ROUNDS=3 python3 tools/pi-arch-debate.py "$(cat .ai/fork-cms-akira-debate-intent.md)"`
- Participants: **DeepSeek Pro** (drafter, 3 rounds) ↔ **Codex Sol** (critic, 3 rounds) — genuine different-model debate.
- Verdict after 3 rounds: **REVISIONS** (Codex Sol round-3 critique, 8 findings). Round-3 critique preserved at
  `.ai/debate-round3-critique-fork-cms-akira.txt`.
- Converged draft (baseline contract): `.ai/contract-fork-cms-akira-2026-09-07.md` (copied from `.ai/current-task.md`,
  19.7 KB — task/objective/scope/constraints/acceptance/verification, P1 build + P2 gate + P3 ADR + P4 deferred).

## CHAIR ADJUDICATION — APPROVED with the following 8 amendments (all accepted, folded into the contract)

R1 — **source_schema vs field_contracts (correctness).** `EntityViewResolver` accepts only primitive schema types
(string/int/float/bool/json/date/datetime/reference) in `source_schema.fields`. Semantic roles
(title/subtitle/image/body/metadata/actions/url) MUST NOT be declared as `source_schema.fields` types. Put primitive
types in `source_schema.fields` and semantic role/presentation metadata in `field_contracts`. The draft's "role types
declared using kernel-supported field_contracts/source_schema keys" is ratified with this exact split.

R2 — **Two explicit capability layers (executable chain).** The `entity.list.post` / `entity.get.post` bridge
handlers MUST call `cms.post.list@1` / `cms.post.get@1` through `CapabilityBus`, then project the RESULT into fresh
allowlisted DTOs. They MUST NOT query `cms_akira_posts` directly. Canonical chains to state in code+tests:
  - `GET /posts` → `entity.list.post` (view bridge) → `cms.post.list@1` (domain cap) → DTO project → ARK → DiSyL.
  - `GET /posts/{slug}` → `entity.get.post` (view bridge) → `cms.post.get@1` (domain cap) → DTO project → ARK → DiSyL.
  Resolves the ambiguity between the `entity.detail.post` RENDERER key (ARK mapping, article-page) and the
  `entity.get.post` CAPABILITY id (view bridge). The detail view is registered as `registerView('post','detail',…)`;
  the bridge capability is `entity.get.post`; the ARK renderer key is `entity.detail.post`→`article-page`.

R3 — **P1 exposes NO inactive mutation capabilities.** P1 manifests + registrations expose ONLY
`cms.post.get@1`, `cms.post.list@1`, `entity.list.post`, `entity.get.post`. `cms.post.create@1`/`cms.post.update@1`
names are RESERVED in the ADR only — their manifest exposure, handlers, authority policies, and `effects.invalidates`
are added ATOMICALLY at the P2 gate. A declared-but-unhandled mutation capability must never be visible to the
capability/dependency audits during P1.

R4 — **Name the audit destination + transaction topology (P2 preflight, must be proven or replaced).** P2's
single-connection `BEGIN → claim() → Post write + durable audit → commit() → COMMIT` sequence is valid ONLY if
`cms_akira_posts`, `kernel_idempotency_keys`, and the durable audit store are reachable transactionally on the SAME
PDO/DB. Preflight MUST: (a) name the existing audit destination (kernel `audit_logs` via `kernel.audit.record@1`, or
the kernel durable-event outbox if audit_logs is not same-connection) — NO new audit table (one-table DDL limit);
(b) prove all three writes share one connection/DB; (c) if not provable, replace the atomicity claim with an explicit
cross-database recovery design (documented, no hidden assumption). Record the preflight result inside the contract
before P2 implementation.

R5 — **Tenant provisioning, not just migration order.** State explicitly that `cms_akira_posts` is migrated in EVERY
applicable tenant database BEFORE that tenant can activate the `/posts` routes/capabilities, with failure + retry
behavior. `CREATE TABLE IF NOT EXISTS` is not sufficient migration idempotency — rely on the repository migration
ledger/version mechanism and add tests for partial provisioning and reruns (tenant A migrated, tenant B not yet →
tenant B routes inactive; rerun converges).

R6 — **Stored-output security (new requirements).** Add: slug validation + canonical encoding when building
`/posts/{slug}` URLs (reject unsafe slug chars; single canonical slug form); context-appropriate HTML/attribute/URL
escaping on the DiSyL/component path for title/subtitle/body/metadata/image/URL values; negative stored-XSS tests
(title/subtitle/body/metadata/image/url payloads). DTO allowlisting alone does not prevent stored XSS or unsafe URLs.

R7 — **Mutation HTTP scope decided.** P2 DOES include a minimal non-GET mutation endpoint (e.g. `POST/PUT
/api/v1/…/posts/{slug}` or the module's canonical mutation route) that enforces the kernel CSRF mechanism for
session/browser requests, plus direct `CapabilityBus` authorization tests. Acceptance must not depend on an undefined
endpoint — the P2 contract names the endpoint(s) before implementation.

R8 — **Dependency acceptance wording (tightened).** Replace "`cms.*` dependencies are gone" with: "legacy module
dependency `cms` and legacy capability dependencies `cms.content.*` / `cms.themes.*` are gone; fork-owned
`cms.post.*` capabilities intentionally remain (content authority = `cms-akira-core`)." This removes ambiguity about
the retained fork-owned `cms.post.*` namespace.

## Status
`contract-fork-cms-akira-2026-09-07.md` + this adjudication = **APPROVED (chair, after genuine 3-round two-model
debate + 8 verified amendments)**. P1 = READY_FOR_IMPLEMENTATION as its own gate. P2 = own contract/gate. P3 = ADR
ratify/freeze. P4 = deferred marker. NO-BROADEN into kernel/modules; stabilization baselines untouched.
