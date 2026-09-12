# Akira beyond the CMS

status: direction (chair, 2026-09-10) · authority: product owner — *"Akira can now freely take its
intended shape and form, unbounded by WordPress's shadow and other CMS's. Let's break new ground."*
revised: 2026-09-12 (chair) — P2 status reconciled with shipped PRs #112–#120.
amended: 2026-09-12 (chair, on product-owner directive) — Akira reclassified from **POC** to
**reference product**; the parity rule narrowed so it refuses *undemonstrative breadth* rather than
table stakes; "intelligent but gated" stated as the product thesis. This amendment supersedes the
POC framing wherever this document previously used it.
refined: 2026-09-12 (chair, after product-owner review) — ecosystem roles restated as Kernel =
authority substrate, Akira = reference product and human experience of the substrate, Daily Ledger =
adversarial second-domain proof, Workbench = instrument and operator visibility; product work split
from substrate work into two parallel tracks; Workbench promoted to product item #2; the competitive
over-claim removed, since the claim worth making concerns where the guarantees live, not whether
anyone else could make them.

Depends on: [kernel-substrate-thesis.md](kernel-substrate-thesis.md).

The ecosystem has four deliberately different jobs: **Kernel = authority substrate; Akira = the
reference product, and the human experience of the substrate; Daily Ledger = adversarial
second-domain proof; Workbench = instrument and operator visibility.** Akira remains a reference
application and not the boundary of the substrate — but *reference* is not a licence to be merely
demonstrative. Akira is the kernel's proof of **usability**, and a system nobody can administer is
not proof of anything. It is therefore judged as a product, on one axis: **authority**.

> **Akira is the human-facing interpretation of Kernel OS.** The kernel holds the rules; Workbench
exposes them; Akira makes them usable.

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
audit, provenance (Cycle 4), and a domain-neutral instrument (Workbench). **The kernel was built to
make these guarantees substrate properties rather than application conventions.** That is the claim,
and it does not require asserting that nobody else could do it: the work is to prove Akira does it
better, not to claim a monopoly on the idea.

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
the model may do, and no way to prove what it did. That is the gap this thesis aims at — but it is a
**target, not current state**. An independent two-model panel on 2026-09-12 measured the distance
rather than assuming it (`.ai/akira-direction-synthesis.md`), and the measurement is unflattering:

- **`cms-akira-ai` is not AI.** It ships `_enabled: false` and is a deterministic local summariser
  with no provider SDK and no remote transport.
- **The AI seam is an echo.** `kernel/DiSyL/AI/` defaults to `EchoAiProvider`, which returns
  `[ai:MODEL] <prompt>`. Its `Policy` cost ceiling accumulates **in memory**, with `Policy.php`
  recording the per-tenant DB-backed ceiling as future work. Scheduling does not exist.
- **AI governance sits off the capability bus.** `AIGovernance` persists settings, review queue and
  audit trail as JSON files under `storage/` (`ai-governance.json`, `tenant-ai-settings.json`, JSON
  queues) and gates its admin surface by role — the same "reimplemented governance locally"
  anti-pattern that P2 exists to eliminate in `daily-ledger`. **This is a named defect: migrate it
  onto the bus or delete it. Do not extend it.**
- **P4 needs a new primitive.** `kernel/Crypto.php` is symmetric only, with no sign/verify path, so
  "it costs no new substrate" would be false.

The claim that survives the panel's objection is narrower than "an AI that cannot publish". Every CMS
already prevents that with a role permission, and drafting is commoditised. The defensible claim is:

> **A portable, off-server-verifiable record of who authorised this publication, under which grant.**

That requires delegated authority (P3) and a signed artifact (P4), and neither exists yet.
*Intelligent* means the system does real work; *gated* means the limits are enforced properties of
execution rather than settings, prompts or operator convention. Most of the market has the first;
almost none can demonstrate the second. That is the contender position — and it is **not yet built**.

### The surface gap (measured 2026-09-12, corrected twice the same day)

**The first version of this section was wrong, and it understated Akira badly.** It counted DiSyL
template files and concluded the product surface was largely absent. The admin surface is not
absent — it is built in PHP, so counting `.disyl` files could never have found it.

**The second version was accurate about the surface but stale within hours**, because the two gaps
it named were closed the same day. Current state:

| Surface | Reality |
|---|---|
| `cms-akira-shell` admin | 16 GET routes with handlers; **25 declared routes — 9 GET, 16 POST** |
| Post administration | Filtered list (search, category, status, pagination), editor with live preview, category panel, workflow state and allowed actions, revision history, CSRF, idempotency key, optimistic concurrency (`expected_updated_at`, `expected_status`) |
| Media administration | **Shipped 2026-09-12** — list, upload, delete at `GET/POST /cms-akira-shell/media`. A disallowed type is refused *by `akira.media.upload@1`* with 422, not hidden by the form |
| Read authority | **Shipped 2026-09-12** — 9 GET routes dispatch-enforced; read policies seeded per capability, each set equal to its handler's existing gate |
| `cms-akira-navigation`, `-seo`, `-search` | Still **0 declared routes** — capability providers with no user surface |
| builder UI | 6 source files |
| ARK theme | 21 templates (11 under `akira-ark` itself) |

**Two findings from closing those gaps are worth keeping.**

First, read authority was not merely unused — it was **untested**. Across all 17 manifests there were
26 declared routes and **zero GET declarations**; the declaration shape permits reads, but nothing had
ever exercised one. It works, and was proven so before it was relied on. The size of the gap was also
understated: **107 undeclared GET routes** exist repo-wide, not 16.

Second, the instrument cannot see reads at all. `GovernanceCensus::isBusiness()` returns false for any
non-write method, so the summary ratio is **byte-identical with and without** a read declaration —
including after nine were added. The headline "28/33" is a **write** figure presented as an authority
figure. That is reported, not yet fixed.

What remains, in the order the panel and the owner ranked it:

1. **Three capability modules still have no surface** — navigation, SEO, search. Media was the fourth
   and now has one; the same pattern applies, and it is ordinary product work rather than invention.
2. **The admin UI is PHP-built HTML**, not DiSyL templates or entity views. It works, and its
   `data-akira-entity-view` markers show the intent, but it is the one place in the product where the
   rendering pipeline is bypassed — which is much of the reason the substrate's governance is
   invisible *inside* the product rather than visible in it.
3. **Workbench is still a developer instrument**, not the operator's glass panel (product item #2).

This phase is therefore **productization, not architecture invention**, and it is narrower and
better-shaped than the first draft of this section implied. The work is not to build a CMS admin
from scratch; it is to govern the reads, surface the four remaining modules, and let the existing
admin render through the pipeline the rest of the product already uses.

**Measurement lesson worth keeping.** The panel was briefed with the wrong table and two panellists
reasoned from it — Sol even corrected the taxonomy row from the shell's route file rather than
trusting the summary. Counting one artefact type is not measuring a surface. The corrected figure
came from reading handlers, not files.

### Two tracks, one rule

Product work is not serialized behind substrate work. Serializing it produces the logic *P2
unfinished → no admin surface → no media UI → no editor → the product stagnates*, which is both
unnecessary and self-inflicted.

| Track A — substrate | Track B — product |
|---|---|
| P2 enforcement closure | Admin surface |
| P3 delegation | Workbench operator console |
| P4 verification | Delegation product surface |
| P5 extension authority | Provable history surface |
| | ARK Theme Studio |
| | Installation profiles |
| | Production floor |

**The rule that keeps the two honest: a product surface may use only substrate guarantees that
actually exist.** No screen may imply a governance property the primitive does not yet provide.
Where a surface would need an unclosed primitive it waits; everything else may proceed. This is the
difference between building the product and claiming the pillar — and only the second is gated.

Ordered — the product owner's ordering (2026-09-12); every item still passes the discipline gate:

1. **Admin surface** — content list, filters, bulk actions, editor, revisions. *(unblocks usability)*
2. **Workbench operator console** — the glass panel over the substrate: who may do what, what was
   denied and why, which grant exists, what is suspended, what changed, which routes remain
   undeclared. *(makes authority understandable, not merely present)*
3. **Delegation as a product (P3)** — grant issuance, an agent approval queue, visible "may not
   publish" bounds. *(the contender claim)*
4. **Provable history as a product (P4)** — per-change actor/authority timeline, one-click proof
   export. *(the compliance claim)*
5. **ARK Theme Studio** — visual theming, no PHP in themes, deterministic and diffable output.
6. **Installation profiles as editions** — the existing `profile-*` modules, packaged and named.
7. **Production floor** — onboarding, backup/export, redirects, sitemap/schema, images, scheduling.

**Why Workbench is #2 rather than last.** The product promise is not *authority exists* but
**authority is understandable**. A governed system whose operator cannot see who may do what, what
was denied, which grant is suspended and which routes remain undeclared will still feel invisible:
the architecture would be correct and the experience unchanged. For "intelligent but gated" this is
the entire demonstration, and a denial should be legible as something like

```text
Agent:            Akira Writer 01
Grant:            draft.create / draft.edit
Denied:           post.publish
Reason:           grant excludes publication
Required actor:   human / editor
Policy revision:  12
```

**Panel verdict, and how the owner resolved it (2026-09-12).** An independent two-model debate
(`.ai/akira-direction-synthesis.md`) converged on admin surface → one bounded machine actor → proof
export, and both panellists would have **cut** ARK Theme Studio and installation profiles as editions
— the first as a second JS build on a shared-hosting target competing with mature customisers, the
second as packaging rather than value. Sol also cut a customer-facing Workbench console. **The owner
overruled that cut and promoted Workbench to #2**, for the reason above; Theme Studio and editions
remain in the ordering, demoted below the authority-facing work. Flash's remaining objection is
**unresolved and stands on the record**: the wedge is low-confidence, the AI claim is not the purchase
reason, and the cheapest next step is buyer conversations rather than more code. What is not in
dispute is that #1 comes first.

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

## CMS media-contribution authority convention

In Akira, and in future CMS products built on this kernel, authority to contribute media follows the
CMS's canonical drafting-and-publishing participant roles. Upload, metadata update, library list,
and media detail therefore travel together: a contributor must be able to find and manage what they
uploaded. Each CMS derives this role set from its workflow definition and keeps a deterministic
fallback for module load independence; it does not create a separate media-role concept.

Destructive media authority is deliberately separate. Akira keeps media deletion administrator-only
because contributed media may already be referenced by published content. Public media resolution
also remains outside this governed contribution surface so rendering is not coupled to editorial
access.

Declaration defaults apply only where no grant row exists. A wider declaration never rewrites an
existing granted tenant policy: an authenticated operator must widen it in the Permissions surface,
where the new policy version and reason are audited.

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
