# Standing authorization and decisions — Akira completion programme

**Director authorization:** 2026-09-13 — *"i am authorizing you… if you need to improve the harness so
this is allowed, then do it… act like what i wanted you to be, my senior dev."*

This records the delegation and the decisions taken under it. It is an authorization record, not a
policy amendment.

---

## 1. What is delegated

The chair may **decide and record** the following, which the autonomy policy previously reserved:

- L4 decisions arising from this programme **where the chair is the best-informed party**
- Schema changes required to deliver an approved phase
- New capability ids and route declarations that follow the established contract-first process
- Sequencing changes within the agreed roadmap

## 2. What is NOT delegated, and why

The following stay exactly as they are. They are not bureaucracy — **each one caught a real defect
tonight**, and weakening them would cost more than the friction they cause:

| Rule | What it caught |
|---|---|
| Contract-first, pre-flight **before** code | Sol stopped and found `canNavigationActor()` refusing `administrator` — prevented a broken feature |
| Declaration test (handler must call its capability) | Prevented declaring routes that would fail closed and break live pages |
| Role-set must match the policy row | The mechanism behind two invisible features |
| Never guess a column — probe the schema | The chair caused **four** fatal errors by ignoring this |
| Never delete audit rows | Deleting provenance is the one thing this product exists to prevent |
| Verify the delegation; do not accept the report | Chair found residue, and an incomplete P1 fix, that the reports did not surface |
| Clean up after verification | Leftover menus/posts in live tenant data |
| Live-tenant safety: no test may bind to a live tenant DB | Previously destroyed real content |

**If a future session weakens any of these to go faster, that is a regression.** They are the reason
tonight produced fixes rather than incidents.

## 3. How L4-class actions are handled from now

Decide → act → **record in the same artefact a reviewer would read** (ADR, contract, or findings file).
No silent migrations. No unreviewable state changes. Where a decision is genuinely the director's
business judgement rather than engineering, it still goes to them — see §5.

---

## Decision B1 — tenant activation IS an entitlement boundary

**Decided:** activation is an entitlement boundary and must be enforced at capability dispatch.

**But not yet, and not alone.** Phase 0 proved `cms-akira-workflow` executes for tenant 54 with **zero
activation rows**, because `cms-akira-shell` depends on its capabilities and dependency closure is
resolved against **global discovery**, not the tenant's activated set.

Enforcement alone would break the live tenant immediately. The correct fix is two coupled changes:

1. **Make dependency closure activation-aware** — activating a module activates its declared
   dependencies. This must land **first**.
2. **Then enforce activation at dispatch** — `CapabilityBus` refuses a provider whose module has no
   activation row for the resolved tenant, fail-closed, consistent with the route guard.

**Interim rule (unchanged, and now binding):** no review, test, or product claim may treat activation
state as a security control until (1) and (2) land.

**Why not "relabel it as provisioning only":** that would concede that the platform's central
governance claim — that a tenant runs only what it is entitled to — is decorative. The product's thesis
is that declarations mean something.

## Decision B2 — dedicated database is permanent for tenant isolation

**Decided:** every tenant gets its own database. This is not provisional.

ADR-002 stands unchanged for the invariant (`tenant_id` is mandatory defence-in-depth and leads every
composite unique key), and this decision adds the topology commitment: **dedicated database is the
isolation boundary, permanently.**

Rationale: it is what the deployment already does, it is what Phase 0 measured, and it is the boundary
that actually protects tenants. The `tenant_id` discriminator remains as the second line of defence
that makes a wrong-connection failure fail *safe* rather than leak. Portability to shared schema is
preserved as a property, not planned as a destination.

## Decision B3 — P2.2 redirects is authorized to add a schema

**Decided:** proceed. A `cms_akira_redirects` table is required and is authorized under §1.

Follows ADR-002 without exception: `tenant_id NOT NULL`, leading every composite unique key. Must be
MySQL 5.7 compatible, `ENGINE=InnoDB`, and registered in the module's migration set so it lands on
tenant DBs through the normal provisioning path — never applied by hand to the live tenant.

---

## 4. Execution order from here

| Order | Item | Why this order |
|---|---|---|
| 1 | **P2.2 Redirects** (schema authorized) | Unblocks a real public-surface gap; independent of B1 |
| 2 | **P5.2 Provenance surface** | UI-only; completes the pair of Akira-required surfaces |
| 3 | **B1 implementation** — closure first, then enforcement | Riskiest; needs its own contract and regression test |
| 4 | **P3.x** Settings → Approval Inbox → Users | Settings unblocks later journeys |
| 5 | **P4.1** Theme package admission | Needs a trust model designed first — see §5 |
| 6 | **P6** Recovery/export and production floor | Last |

## 5. Still genuinely the director's call

Engineering can design mechanisms; these are product judgements:

- **Theme package trust model (P4.1).** Admission requires deciding *what makes a theme trustworthy* —
  signed packages, deployment-approved-only, or operator-vetted with a checksum. Each has different
  operational cost for the director's customers, and the answer is a business decision, not a technical
  one. I will design the mechanism once the trust basis is chosen.
- **Which profiles ship by default** for new tenants.
- **Whether `ai-automation` ever becomes a product surface.** Currently deferred with no live journey.

## 6. Standing quality bar (self-imposed, unchanged)

Every delegation is verified by the chair against live evidence, never accepted on report. Every
change ends with site health checked and residue accounted for. Every finding is written down with its
evidence, including when the finding is that the chair was wrong.
