# Akira beyond the CMS

status: direction (chair, 2026-09-10) · authority: product owner — *"Akira can now freely take its
intended shape and form, unbounded by WordPress's shadow and other CMS's. Let's break new ground."*

Depends on: [kernel-substrate-thesis.md](kernel-substrate-thesis.md) (Akira = POC, kernel =
substrate, Workbench = instrument).

## The ground we are leaving

A CMS is software that stores content and renders it. WordPress, Contentful, Sanity, Strapi,
Payload — all of them, regardless of polish, are **content stores with a rendering path**. Their
architectures record *what the content is*. None of them record *what the system was permitted to
do, or who permitted it*.

So every CMS in existence bolts governance on afterwards: plugins with unrestricted power, audit
logs the operator can edit, "AI features" with no notion of authority, approvals implemented as
workflow settings rather than as an enforced property of the system.

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

Also measured: Akira's own ratio has **never been established** with the same method. Calling it
"the known-good instrumented reference" has been an assumption, not a finding.

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

Two findings from that verification, both recorded rather than papered over:

1. **The authorization registry reads `app()->db()`** (the kernel store), not the tenant's database.
   For a tenant-local module the authority is therefore kernel-scoped. Whether that is correct for
   a multi-tenant product or a gap in tenant-scoped authority is an architectural question for P5.
2. **Policies are re-asserted from code.** `seedPolicy()` runs `ON DUPLICATE KEY UPDATE … is_active
   = VALUES(is_active)`, so deactivating a policy row in the database is silently undone on the next
   activation. Revocation's real lever is the module's seed declaration. Policy-as-code is defensible
   — a silent no-op is not, and must be surfaced.

Not yet covered, and not claimed: scheduled jobs, event handlers, CLI handlers and other direct
callables remain outside this inventory.


### P3 — Delegation: machines can hold bounded authority **(the new ground)**

Not "AI writes a draft". An **actor** with an identity, a **grant** with scope/constraints/expiry,
**enforcement at the bus**, **attribution** in provenance, and **segregation of duties** where a
policy requires human authorization for machine-originated change.

This is the piece that does not exist anywhere. Every CMS treats a machine as a client of its API;
here a machine is a *participant with accountable authority*. And it is only possible because
authority is a runtime property of the substrate rather than a plugin's opinion.

### P4 — Verification: the claim proves itself to a third party

A CMS audit log is for the operator and can be edited by the operator. Here, a published artifact
can answer *who authored this, who authorized it, under which policy, at what time* — verifiably,
without the verifier trusting the operator. Trust shifts from "trust the CMS" to "verify the
claim" — which is the only honest basis for machine-authored work.

### P5 — Consent: authority is granted, never assumed

The WordPress extension model installs code and grants it everything. Here an extension **declares
the authority it needs**, and the tenant grants or denies it. This inverts the security model of
the entire extension ecosystem and is a natural consequence of policy rows existing at all.

## What we will not build

The WordPress shadow is shed by refusing its shopping list:

- no parity features (media library, taxonomies, menus, widgets, SEO scorecards)
- no block-editor competition — the builder exists to prove that structured content is *diffable
  and semantically provable*, and it has done that
- no theme marketplace, no plugin store, no admin-UX arms race
- no feature that cannot name the substrate claim it proves

## Where this leaves the project

The kernel becomes the product. Akira becomes the reference application — small, honest, complete
enough to demonstrate what the substrate makes possible, and explicitly a POC. `daily-ledger` is
the second domain that tests whether the substrate generalises (see the in-flight F1–F4
measurement). Workbench is the instrument that makes all of it provable rather than asserted.

## Discipline

**New ground means new territory, not new standards.** Unchanged: the governed workflow
(architect → implement → review → release-gate), contracts before code, evidence over assertion,
tests as the oracle, CI as the gate, no scope creep, and honest reporting of what was not verified.

The risk of "break new ground" is inventing something unfalsifiable. Mitigation: every pillar must
be demonstrable in Akira, measurable by Workbench, and breakable by a test that would fail if the
claim were false.
