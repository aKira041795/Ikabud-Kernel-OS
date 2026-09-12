# Akira beyond the CMS

status: direction (chair, 2026-09-10) · authority: product owner — *"Akira can now freely take its
intended shape and form, unbounded by WordPress's shadow and other CMS's. Let's break new ground."*
revised: 2026-09-12 (chair) — P2 status reconciled with shipped PRs #112–#120.
amended: 2026-09-12 (chair, on product-owner directive) — Akira reclassified from **POC** to
**reference product**; the parity rule narrowed so it refuses *undemonstrative breadth* rather than
table stakes; "intelligent but gated" stated as the product thesis. This amendment supersedes the
POC framing wherever this document previously used it.

Depends on: [kernel-substrate-thesis.md](kernel-substrate-thesis.md).

The ecosystem has four deliberately different jobs: **Kernel = product; Akira = the reference
product that shows the claim is usable; Daily Ledger = tries to break it; Workbench = proves the
result.** Akira remains a reference application and not the boundary of the substrate — but
*reference* is not a licence to be merely demonstrative. Akira is the kernel's proof of **usability**,
and a system nobody can administer is not proof of anything. It is therefore judged as a product,
on one axis: **authority**.

## The ground we are leaving

A CMS is software that stores content and renders it. WordPress, Contentful, Sanity, Strapi and
Payload are principally **content stores with a rendering path**. IAM, workflow, provenance and
policy engines can each record part of authority or history; the distinction intended in Akira is
that authority, execution, provenance and verification form **one continuous enforced model**, not
independently bolted-on subsystems:

```text
Authority governs execution
          |
          v
      Capability
       /      \\
      v        v
 evidence   provenance
      \\        /
       v      v
        effects
```

Conventional CMS governance is commonly added afterwards: plugins with broad power, operator-owned
audit logs, machine features with no delegated authority, and approvals implemented as workflow
settings rather than an enforced property of execution.

That is the shadow we were building inside. Competing there means competing on blocks, themes,
media libraries and editor ergonomics — a mature, crowded, well-defended category where our
substrate advantage is *invisible*.

## The ground we are taking

> **Akira is not a CMS. It is the reference implementation of an authority-native publication
> system: one where every published claim carries provable authority, every change is reversible
> rather than destructive, and non-human actors can be delegated real but bounded power.**

The substrate already has the ingredients — capability bus, per-tenant policy rows, idempotency,
audit, provenance (Cycle 4), and a domain-neutral instrument (Workbench). What no CMS can do is
precisely what the kernel was built to do.

Five pillars, in order — the order below was corrected on 2026-09-10 by measurement (see P2). Each
must be *minimal but real*, must demonstrate a substrate primitive, and must be observable in
Workbench.

### P1 — Provenance: who did it, provably *(shipped, Cycle 4)*

A revision now knows its capability, actor, timestamp and correlation, and can be rendered as of
any point. Verified in the browser, in PR #108.

### P2 — Enforcement: authority is not optional **(the actual next ground)**

Measured 2026-09-10 against the second domain (`daily-ledger`, money/POS/deliveries):

- **0 of 52** business operations run through the capability path.
- Route dispatch is a direct call — `executeModuleHandler()` invokes `$routeCallable($params)`
  (`src/helpers/module-manager.php:2992-2998`) with **no** capability authorization, **no** kernel
  audit and **no** kernel idempotency.
- The module did not lack governance. It **reimplemented it locally** — `dl_auditLog()` →
  `$ctx->audit()`, plus cache-backed idempotency helpers — because the substrate offered no path
  for a handler-first domain.
- The instrument cannot currently explain a decision at all: `workbench:explain` requires a run
  ID, and `workbench:audit` audits `kernel/WorkflowEngine.php`, not policy outcomes.

Governance that every module invents for itself is non-uniform, unverifiable and invisible to the
instrument. That is the opposite of a substrate — and it means P3–P5 are **deferred behind this**,
because delegation, third-party verification and consent all presuppose that authority is actually
enforced. Building them first would be a beautiful facade on an unenforced system.

Also measured at the time: Akira's own ratio had **never been established** with the same method, so
calling it "the known-good instrumented reference" was an assumption, not a finding. C6 below
established it — 5 of 33.

The primitive: **authority is a property of the request, not a courtesy of the handler.** A module
declares the authority each route requires; dispatch enforces it; the instrument reports the
ungoverned remainder per module; the release gate refuses to let it grow.

**Shipped 2026-09-11 (C6).** The primitive is now real, and the honest number is published:

| | dispatch-enforced | bus-reachable | undeclared | total |
|---|---|---|---|---|
| Akira (`cms-akira-*` + `gui-settings`) | **5** | 31 | 28 | 33 |
| `daily-ledger` | 0 | 0 | 52 | 52 |

*Dispatch-enforced* means the route declares its required capability in `module.json`
(`capabilities.routes`) and the declaration is checked **before the handler body runs**. The old
93.9% figure was bus-reachability — a bus call inside a handler is not request authority, so the
census now reports both numbers and never merges them.

Enforcement states: **declared** fails closed (denial, missing policy row and an unavailable
registry all prevent invocation); **exempt** proceeds and is recorded with its declared reason;
**undeclared** proceeds as observed compatibility debt against a frozen baseline that
`workbench:governance --gate` refuses to let grow.

Verified over real HTTP on tenant 54: an `author`-role caller was refused `POST
/api/v1/cms-akira/posts/{slug}/publish` with `403 {"state":"route_authority_denied",
"capability":"akira.post.publish@1"}` while the target stayed a draft — the handler body did not
execute. An allowed caller created a post (201), and replaying the idempotency key produced one
domain transition, not two.

**Shipped 2026-09-11/12 — the two remaining P2 steps.**

- **Explicit authority scope (#118, #119).** `AuthorityScopeResolver` carries
  `AuthorityScope{tenantId, actor, declarationRevision, entryPoint}`, and the non-HTTP entry points
  declare one: web, cli, cron, queue, service, event, workbench, test. Where a scope is resolvable
  the entry point establishes it; where it is not, the work still happens and the capability call
  fails closed on its own — refusing the operation was never the goal. The ambient `app()->db()`
  fallback is **gone** from `CapabilityAuthorizationRegistry`; an unresolvable store now produces
  safe reads (`allowed=false`, no policy, no rows) instead of an ambient guess.
- **Inventory beyond HTTP (#120).** The census no longer sees routes only. It statically reports
  capability call sites outside HTTP as `transport` (event/workflow/cli/workbench/service/worker)
  × `scope` (declared/unscoped/unresolved) — reported **separately** from the routed ratio and
  gated by a second frozen baseline map.

The honest non-HTTP number, measured 2026-09-12: **95 capability call sites — 1 declared, 0
unscoped, 94 unresolved.** The debt baseline is therefore empty. The gate is armed and proven to
bite, but static analysis cannot yet prove scope for the 94: they sit in generic helpers whose
transport is unprovable, and the kernel→module call edge passes through dynamic callables. A site
counts as debt only when the transport is statically certain *and* no scope reaches it; anything
else stays `unresolved` — visible, but neither claimed as governed nor manufactured into debt.
**This is the honest limit of the instrument, and it is not a claim of coverage.**

#### Standing authority architecture findings

These C6 findings were architectural work, not P2 status footnotes. Each is recorded with how it
closed, because both were invisible until they were measured:

1. **Authority-store resolution is context-dependent.** *(Write path closed, #115; read path closed,
   #118.)* The registry used to fall back to `app()->db()`; measurement resolved web to the tenant DB
   and CLI to the kernel DB, and CLI seeding wrote 46 declaration rows into the **kernel** table (44
   of them `akira.*`) rather than the tenant's. `seedPolicyForCurrentScope()` no longer falls back on
   the write path, the read path exposes `authorityStoreIssue()` instead of guessing, and the 46
   contaminated kernel rows were removed. Declaration and grant-state ownership, consequences, and
   the chair questions are recorded in the [authority-store ADR](authority-store-adr.md). P3/P5 may
   not rely on ambient DB selection — this is now enforced rather than merely noted.
2. **Declaration must not restore grant state.** *(Shipped, #112.)* Code may declare requirements,
   but a repeated `seedPolicy()` may not overwrite an operator suspension or revocation.
   `017_capability_policy_grant_lifecycle.sql` adds `grant_state ENUM('granted','suspended','revoked')`;
   `transitionGrantState()` is the only state mover (authenticated actor, mandatory reason, `FOR UPDATE`,
   audited), and seeding now applies narrowing while refusing widening.

The sequence is: **P2 closure** (route coverage → authority-store semantics → declaration/revocation →
inventory beyond HTTP) → **P3** → **P4** → **P5**.

Of those four P2 steps, **three are done**: authority-store semantics are
[decided](authority-store-adr.md) (three-model debate, 2026-09-11) and the prerequisite that gated
them — an explicit authority scope replacing ambient `app()->db()` resolution — has **shipped**
(#118, #119); declaration/revocation shipped with the grant lifecycle and narrowing-only seeding;
and the inventory now reaches beyond HTTP.

**Route coverage is the one step still open.** Measured 2026-09-12 after two slices: Akira is at
**28/33** dispatch-enforced (23 of the 25 open writes declared; `cms-akira-core`'s 5 were already
declared). **57 business writes remain undeclared** — 5 in Akira and 52 in `daily-ledger` (the module
has 56 write routes; 4 are auth infrastructure, excluded from the denominator by `isBusiness()`).

The five Akira holdouts are not one category:

- **`gui-settings` (2) — deliberately excluded, not debt.** GUI Settings is a kernel-admin companion
  module, configured directly by the operator. Its routes are not declared **by decision**: putting an
  administrative convenience surface behind the authority model it exists to help administer is a
  worse trade than leaving it user-set.
- **shell post delete (1) and theme activate (2) — declarable.** The only reason these are not declared
  is a stop condition in the closing slice that was too broad. Each handler already calls the very
  capability its route would declare, so the narrower policy is already in force and declaring would
  *mirror* it rather than narrow it. They are a small, known follow-up.

**Architectural boundary found while deciding the above.** The authority model covers
**tenant-scoped** surfaces only. `AuthorityScopeResolver::forApplication()` derives the subject tenant
from `app()->tenant()->current()`, and on the kernel host no tenant resolves — so a kernel-scoped
surface has **no authority store at all**, and `seedPolicyForCurrentScope()` deliberately skips rather
than writing to one (that skip is what stopped CLI work contaminating the kernel authority table).
Governing any kernel-scoped surface therefore requires an explicit non-tenant store path, and that
amends the [authority-store ADR](authority-store-adr.md), which today models federation between tenant
stores only.

No exemption has been declared anywhere yet: `governance.exemptions` remains unused, including for the
deliberate `gui-settings` exclusion above — so that exclusion currently reads as compatibility debt in
the census rather than as the decision it is. Route coverage is the next P2 work, and P3–P5 remain
deferred behind it.


### P3 — Delegation: actors can hold bounded authority **(the new ground)**

This is generic delegation, not "AI writes a draft":

- `Actor{identity: human | service | machine}`
- `Grant{grantor, grantee, capability, subject scope, constraints, issued_at, expires_at, revocable}`
- `ExecutionContext{actor, delegated_by, grant_id}`

The kernel understands **delegated authority**, never **AI authority**. HARPP, a cron worker, an ERP
integration, a device and an AI agent all use this one primitive. Enforcement occurs at the bus and
attribution remains in provenance because authority is a runtime property, not a plugin's opinion.

Segregation of duties belongs to Authority, not AI: **no actor may satisfy incompatible authority
roles in the same decision.** In Daily Ledger terms, a cashier records a transaction, a supervisor
voids it, and that cashier cannot approve their own void. This financial-operations case is a
required demonstration that the substrate is not publishing-shaped.

### P4 — Verification: the claim proves itself to a third party

A CMS audit log — including `GET /audit` — is not third-party verifiable when its operator controls
the server. Start with the smallest tamper-evident export: `artifact.json` + `proof.json` + a public
key + `verify-artifact()`. The proof binds the content hash, actor, authority/grant, policy version
and provenance chain, so one exported artifact remains verifiable after it leaves the server.
Blockchain, public ledgers, PKI infrastructure and DID are explicitly refused **for now**; they are
not required to prove the local-verifier claim.

### P5 — Grant: authority is granted, never assumed

The WordPress extension model installs code and grants it everything. Here an extension **declares
the authority it needs**, and the tenant grants or denies it. `Grant` is the authority primitive;
`Consent` is reserved for any future data-subject consent with privacy, GDPR or medical semantics.
This inverts the security model of the extension ecosystem and follows from policy rows existing.

## Product doctrine: intelligent, and gated

The substrate is not the evidence standing behind the product — it **is** the product feature.
Capabilities that competitors also have arrive here carrying a guarantee they cannot make, and that
is what makes the feature worth shipping.

### The thesis: intelligent, but gated

> **An AI-driven CMS is only useful if it is also a responsible one — one that knows its own limits
> and can prove it stayed inside them.**

The market is currently attaching AI to content systems with plugin-grade power, no bound on what
the model may do, and no way to prove what it did. Akira already holds every primitive needed to
answer that: `Grant` (P3), per-tenant policy rows, provenance (P1), segregation of duties and
idempotency. The resulting product claim is concrete and demonstrable today:

- an agent may **draft, summarise, suggest and schedule** — it may not publish;
- a human holding *different* authority approves the transition;
- the published artifact carries the whole chain — actor, grant, policy version, provenance;
- and an exported artifact stays verifiable after it leaves the server (P4).

*Intelligent* means the system does real work. *Gated* means its limits are enforced properties of
execution, not settings, prompts or operator convention. Most of the market has the first; almost
none can demonstrate the second. That is the contender position, and it costs no new substrate.

### The surface gap (measured 2026-09-12)

The substrate is built; the product surface is largely absent. Measured in this repository:

| Surface | Reality |
|---|---|
| 15 `cms-akira-*` modules | **0 DiSyL templates between them** |
| `cms-akira-media`, `-editor`, `-seo`, `-navigation`, `-search`, `-ai` | **0 declared routes** — capability providers with no user surface |
| `cms-akira-shell` | 14 routes, **6 templates** (`home`, `posts`, `single`, `404`, `layout`, `login`) |
| builder UI | 6 source files |
| ARK theme | 21 templates — the richest surface present |

Media, taxonomy, menus, SEO and search cannot be managed in Akira today. That is the honest reason
it still reads as a prototype — and it is equally where the substrate should be *shown* rather than
asserted, because every list, filter and write in an admin surface is a capability-governed call and
an entity-view render. Closing this gap is ordinary product work that demonstrates the substrate
while doing something users actually need.

Ordered direction — direction, not commitment; every item still passes the discipline gate:

1. **Admin surface** — content list, filters, bulk actions, editor, revisions. *(unblocks usability)*
2. **Delegation as a product (P3)** — grant issuance, an agent approval queue, visible "may not
   publish" bounds. *(the contender claim)*
3. **Provable history as a product (P4)** — per-change actor/authority timeline, one-click proof
   export. *(the compliance claim)*
4. **ARK Theme Studio** — visual theming, no PHP in themes, deterministic and diffable output.
5. **Installation profiles as editions** — the existing `profile-*` modules, packaged and named.
6. **Production floor** — onboarding, backup/export, redirects, sitemap/schema, images, scheduling.
7. **Workbench as the operator's console** — governance and degradation made visible to operators,
   not only to developers.

**Relationship to the pillar sequence.** The substrate order (**P2 closure → P3 → P4 → P5**) governs
when a *primitive* may be relied upon; it does not serialise the *product surface*. An admin surface
depends on nothing unresolved — it exercises capabilities that are already declared and enforced.
Items #2 and #3 make product claims that rest on P3 and P4 respectively, and **may not ship those
claims ahead of the primitives**. Where a surface would require an unclosed primitive it waits;
everything else may proceed in parallel. This is the difference between building the product and
claiming the pillar, and only the second of those is gated.

## What we will not build

The WordPress shadow is shed by refusing its shopping list:

- **No feature is implemented *for* parity alone — but parity is no longer refused on principle.**
  The refused category is *undemonstrative breadth*: feature count that proves nothing. Table stakes
  are a different matter, and refusing them was an error — it forbade the very work that turns
  plumbing into a product. Storage, editing, media, taxonomy, navigation, search and SEO are
  **allowed and expected**, built on the capability bus and rendered through entity views so that
  governance is visible *in* them rather than demonstrated beside them
- no block-editor competition — the builder exists to prove that structured content is *diffable
  and semantically provable*, and it has done that
- no theme marketplace, no plugin store, no admin-UX arms race
- no feature that is *undemonstrative breadth* — a feature must either name the substrate claim it
  proves, or belong to the minimum completeness without which no claim can be judged at all
- no rebranding, renaming, or new major version before the substrate earns branding through proof

## Where this leaves the project

The kernel becomes the product. Akira becomes the **reference product** — a real, manageable CMS that
is deliberately minimal outside the authority axis, and a contender *on that axis* rather than a POC
awaiting one. `daily-ledger` is
the second domain that tests whether the substrate generalises; the F1–F4 measurement (contract
`.ai/thesis-measurement.contract.md`, run 2026-09-10) fixed the falsification criteria and returned
the P2 finding above — 0 of 52 business operations through the capability path. Workbench is the
instrument that makes all of it provable rather than asserted, and the F3 explicability gap that
measurement exposed — `workbench:explain` requires a run ID, so a policy decision cannot be
explained — is still open.

## Discipline

**New ground means new territory, not new standards.** Unchanged: the governed workflow
(architect → implement → review → release-gate), contracts before code, evidence over assertion,
tests as the oracle, CI as the gate, no scope creep, and honest reporting of what was not verified.

A feature request passes the discipline gate if it **either** names the substrate claim it
demonstrates **or** belongs to the minimum product completeness without which no claim can be judged
at all. The gate refuses undemonstrative breadth, not table stakes:

```text
Feature request
      |
      v
Names a substrate claim? -- no --> reject or defer
      |
     yes
      v
Define the falsifiable two-domain demonstration --> govern implementation
```

**No substrate primitive is considered general until it is demonstrated in at least two materially
different domains: publication and financial operations.** The risk of "break new ground" is
inventing something unfalsifiable; every pillar must therefore be demonstrable in Akira, challenged
by Daily Ledger, measurable by Workbench, and breakable by a test that fails when the claim is false.

**Where that bar stands (2026-09-12): not yet met.** Route coverage is **28/33** in publication
(Akira) and **0/52** in financial operations (`daily-ledger`); 57 business writes across both remain
undeclared. The primitive is real, and enforced where declared — it is not yet *general*, and this
document claims no more than that.
