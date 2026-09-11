# Akira beyond the CMS

status: direction (chair, 2026-09-10) · authority: product owner — *"Akira can now freely take its
intended shape and form, unbounded by WordPress's shadow and other CMS's. Let's break new ground."*

Depends on: [kernel-substrate-thesis.md](kernel-substrate-thesis.md).

The ecosystem has four deliberately different jobs: **Kernel = product; Akira = demonstrates the
claim; Daily Ledger = tries to break it; Workbench = proves the result.** Akira remains a reference
application and POC, not the boundary of the substrate.

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

Not yet covered, and not claimed: scheduled jobs, event handlers, CLI handlers and other direct
callables remain outside this inventory.

#### Standing authority architecture findings

These C6 findings are architectural work, not P2 status footnotes:

1. **Authority-store resolution is context-dependent.** The registry falls back to `app()->db()`;
   measurement for one tenant resolved web to the tenant DB and CLI to the kernel DB. Declaration
   and grant-state ownership, consequences, and chair questions are recorded in the
   [authority-store ADR](authority-store-adr.md). P3/P5 may not rely on ambient DB selection.
2. **Declaration must not restore grant state.** Code may declare requirements, but a repeated
   `seedPolicy()` may not overwrite an operator suspension or revocation. P2 closure adds an
   explicit `granted | suspended | revoked` lifecycle and audited transitions.

The sequence is: **P2 closure** (route coverage → authority-store semantics →
declaration/revocation → inventory beyond HTTP) → **P3** → **P4** → **P5**.


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

## What we will not build

The WordPress shadow is shed by refusing its shopping list:

- **No feature is implemented for parity alone.** Minimum product completeness — for example
  taxonomy, media, or a basic editor — is allowed when it demonstrates a named substrate claim
- no block-editor competition — the builder exists to prove that structured content is *diffable
  and semantically provable*, and it has done that
- no theme marketplace, no plugin store, no admin-UX arms race
- no feature that cannot name the substrate claim it proves
- no rebranding, renaming, or new major version before the substrate earns branding through proof

## Where this leaves the project

The kernel becomes the product. Akira becomes the reference application — small, honest, complete
enough to demonstrate what the substrate makes possible, and explicitly a POC. `daily-ledger` is
the second domain that tests whether the substrate generalises (see the in-flight F1–F4
measurement). Workbench is the instrument that makes all of it provable rather than asserted.

## Discipline

**New ground means new territory, not new standards.** Unchanged: the governed workflow
(architect → implement → review → release-gate), contracts before code, evidence over assertion,
tests as the oracle, CI as the gate, no scope creep, and honest reporting of what was not verified.

A feature request passes the discipline gate only if it names the substrate claim it demonstrates:

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
