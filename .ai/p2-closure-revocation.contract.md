# CONTRACT — P2 closure: declaration vs revocation, and thesis tightening

status: READY_FOR_IMPLEMENTATION
owner: implementation agent (Sol, via Pi)
repo: `/var/www/html/ikabudsix` — branch to create: `feat/p2-closure-revocation-semantics`
chair: this session · source: external architecture review (go-with-changes), forwarded by the product owner

Read `docs/architecture/akira-beyond-the-cms.md` and `.ai/c6-route-authority.result.md` first.
Both are current. Do not re-derive the C6 measurement.

## Why

C6 made route authority a dispatch-time property and published the honest number
(`5/33` dispatch-enforced for Akira; `0/52` for `daily-ledger`). The review approved the direction
and named **one correction that must land before P3**:

> **Code may declare authority requirements. Code must not silently restore a revoked grant.**

Today it does exactly that. `CapabilityAuthorizationRegistry::seedPolicy()` upserts with
`ON DUPLICATE KEY UPDATE … is_active = VALUES(is_active)`, and the Akira core seeds run on
activation. Verified live on 2026-09-11: a revoked policy row returned to `is_active = 1` with its
full role list after a single request. An operator revocation is therefore a silent no-op — which
contradicts the model C6 just established.

## Deliverables

### D1 — Policy lifecycle (the correction; highest priority)

Separate **declaration** from **grant state**, so a revocation is durable and the code cannot
resurrect it.

- Add a migration (`migrations/017_*.sql`, MySQL 5.7 safe, idempotent, `ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`) adding explicit lifecycle state. Suggested
  vocabulary — use it unless you have a stronger reason: `granted` | `suspended` | `revoked`.
  Every existing row migrates to `granted` (backward compatible; `is_active` must keep working).
- `seedPolicy()` semantics become:
  - row absent → INSERT as **granted** (a new declaration is a new grant);
  - row present → update the **declaration** fields (`caller_module`, `allowed_roles`,
    `requires_protocol`, `provider_activation_required`) **only when the grant is `granted`**;
  - row present and NOT `granted` → **leave the grant state alone**. Do not flip it back.
- Revocation must be an explicit, recorded operation carrying **who / when / why** — not a bare
  `UPDATE`. Reuse the kernel's existing audit path (`kernel.audit.record@1`) rather than inventing a
  parallel log. If an operator-facing revocation entry point is needed, the smallest honest one is
  fine; do not build an admin UI.
- The authorization decision must respect the new state: a `revoked` or `suspended` grant denies,
  and the denial reason must name the state (`grant_revoked` / `grant_suspended`), not `unknown_role`.
  This also closes the `unknown_role` mislabelling recorded in the C6 result.

### D2 — Authority store: record the decision, do not move the data

The review's point is that there are two different things:

| | What it is | Where it belongs |
|---|---|---|
| **Policy declaration** | "this capability requires editor" — structural | code / module contract |
| **Policy grant state** | "Alice is editor", "this integration was revoked" | the tenant's authority store |

`CapabilityAuthorizationRegistry::db()` resolves `app()->db()`, which **differs between the web and
CLI contexts** for the same tenant (measured: web → tenant DB, CLI → kernel DB). That ambiguity is
itself a defect worth recording.

Write an ADR — `docs/architecture/authority-store-adr.md` — that states the decision and its
consequences. **Do not implement a store migration in this slice.** The review explicitly warns
against simply moving all policy rows into tenant databases because centralised authority semantics
can be lost. The ADR must cover: declaration vs state, which store owns each, the web/CLI
resolution ambiguity, and what P3/P5 will require of it. Mark anything you cannot decide as an open
question for the chair rather than guessing.

### D3 — Thesis tightening (`docs/architecture/akira-beyond-the-cms.md`)

Apply the review's changes. Each is a specific edit, not a rewrite:

1. **Competitive claim — remove the absolute.** "None of them record what the system was permitted
   to do" is technically false (IAM, workflow, provenance and policy engines each do a part). The
   defensible claim is: *in Akira, authority, execution, provenance and verification are intended to
   be **one continuous enforced model** rather than independently bolted-on subsystems.* Include the
   review's diagram (Authority governs execution → Capability → evidence / provenance / effects).
2. **P3 — define the primitive as a generic delegated actor, not AI.** `Actor{identity: human |
   service | machine}`, `Grant{grantor, grantee, capability, subject scope, constraints, issued_at,
   expires_at, revocable}`, `ExecutionContext{actor, delegated_by, grant_id}`. The kernel must
   understand **delegated authority**, never **AI authority** — so HARPP, a cron worker, an ERP
   integration, a device and an AI agent all use one primitive.
3. **Segregation of duties belongs to Authority, not to AI.** State the principle: *no actor may
   satisfy incompatible authority roles in the same decision.* Sketch it in `daily-ledger` terms
   (cashier records / supervisor voids / the cashier cannot approve their own void) — that is the
   demonstration that the substrate is not publishing-shaped.
4. **P4 — require tamper-evident evidence, but start minimal.** A `GET /audit` is not third-party
   verifiable because the operator controls the server. State the minimal shape:
   `artifact.json` + `proof.json` + a public key + `verify-artifact()`, where the proof binds content
   hash, actor, authority/grant, policy version and provenance chain. Explicitly refuse blockchain,
   public ledgers, PKI infrastructure and DID **for now**; the goal is that one exported artifact can
   be verified after it leaves the server.
5. **P5 — rename `Consent` to `Grant`.** "Consent" carries privacy/GDPR/medical meaning that is not
   what is described. Note that `Consent` is reserved if data-subject consent is ever meant.
6. **Promote the two C6 findings out of the P2 status block into standing architectural items** —
   they are not footnotes. Cross-reference the new ADR from D2.
7. **Replace "no parity features" with "No feature is implemented for parity alone."** Minimum
   product completeness is allowed when it demonstrates a named substrate claim (the review gives
   taxonomy, media and a basic editor as examples).
8. **Add the acceptance rule:** *no substrate primitive is considered general until it is
   demonstrated in at least two materially different domains* (publication and financial operations).
9. **Add the discipline gate** — the review's decision flowchart: a feature request must name the
   substrate claim it demonstrates, or be rejected/deferred.
10. **Add the ecosystem framing**: Kernel = product; Akira = demonstrates the claim; Daily Ledger =
    tries to break it; Workbench = proves the result. Put this near the top, where the doc currently
    positions Akira.
11. **Sequence**: P2 closure (route coverage → authority-store semantics → declaration/revocation →
    inventory beyond HTTP) → P3 → P4 → P5.
12. **No renaming, no new major version.** The idea earns branding after proof, not before.

## Chair-locked decisions (do not re-litigate)

- P5 is named **Grant**. P3's primitive is a **delegated actor**. SoD lives in Authority. P4 starts
  with the minimal local verifier. "No feature for parity alone." Two-domain rule. No rebranding.
- Keep the C6 enforcement mechanism untouched. This slice changes **policy lifecycle**, not route
  authority enforcement.

## Constraints

- MySQL 5.7: no window functions, no CTEs, no `JSON_TABLE`, no `CHECK` constraints.
- No new dependencies. Reuse kernel primitives; no parallel machinery.
- `modules/daily-ledger/**` is untracked and is a **live money domain** — you may read it as
  evidence for the doc, you may not modify, enable, migrate or commit it.
- Do not touch tenant 54's database. Do not enable or disable modules.
- Back up `storage/modules.json` before running any test suite and restore it afterwards
  (gui-settings ON, all `cms-akira-*` ON, `daily-ledger` OFF).
- `dump_autoload`/composer are not needed.
- If a deliverable needs a kernel primitive beyond the lifecycle change, **STOP** and return
  `ARCHITECTURE_DECISION_REQUIRED` with options.

## Acceptance

1. A test proves: seed → revoke → seed again → **still revoked**. It must fail if `seedPolicy()`
   overwrites lifecycle state. Paste raw output.
2. A revoked and a suspended grant both deny, each with its own readable reason; `role_not_allowed`
   replaces `unknown_role` for a present-but-unauthorized role.
3. Migration applies and re-runs cleanly on a fresh DB; existing rows land as `granted`.
4. Existing behaviour is preserved: the full suite is `0 failed`, and
   `php ikabud workbench:governance --all --gate` still passes.
5. The doc contains every one of D3's twelve items — list them in your result with the line where
   each landed.
6. `docs/architecture/authority-store-adr.md` exists and explicitly marks undecided points as open
   questions.
7. Raw command output pasted as evidence for 1–4.

## Deliverable

Result block: status, changed, implementation summary, verification, evidence, scope, risks,
unresolved, recommended next state. **Do not commit, push or branch.** Leave everything in the
working tree for the chair to review.
