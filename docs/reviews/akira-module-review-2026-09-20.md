# CMS Akira — module review and reimplementation stance

Reviewed 2026-09-20, against `modules/cms-akira/*` as shipped on branch
`feat/akira-editorial-and-authority-coverage`. Every figure below is measured on this tree or on the live
tenant (54, `akiracms.test`); nothing here is inferred from a document.

## Verdict

**The module is sound in structure and weak in one specific way: authority is expressed in four places
that must agree, and nothing reconciles them.** That single weakness accounts for the nine recorded
instances of "a declaration does not control what it appears to control", and it — not any individual
bug — is what I would reimplement first.

It is **not** a rewrite case. The capability bus, the policy store, the entity view pipeline, the
governed mutation path (policy row → idempotency → audit in one tenant transaction → cache
invalidation) are all real and working. What is missing is a *reconciliation* layer over authority, and
two of the four comparisons now exist as a result of this session.

## What is actually there

| Module | Capabilities | Routes | Notes |
|---|---|---|---|
| `cms-akira-core` | 45 | 5 | 415 KB — the bulk of the domain, including governance |
| `cms-akira-shell` | 1 | **50** | the tenant entry surface; almost all routing, almost no capability |
| `cms-akira-theme` | 9 | 8 | declares an extension point set it does not consume |
| `cms-akira-builder` | 11 | 6 | richest mutation metadata (`requires_protocol`, `effects.invalidates`) |
| `cms-akira-navigation` | 9 | 1 | |
| `cms-akira-media` | 8 | 0 | capability-only, no HTTP surface |
| `cms-akira-editor` | 5 | 0 | |
| `cms-akira-seo` | 5 | 2 | |
| `cms-akira-search` | 5 | 0 | |
| `cms-akira-workflow` | 3 | 1 | |
| `cms-akira-ai` | 1 | 0 | |
| `profile-*` × 4 | 0 | 0 | **zero files each** — a profile is a selection, not a module |

15 modules, 102 capabilities, 73 routes. All manifests carry `_enabled: false` (shipped-dormant); the
live tenant has 8 enabled. The four `profile-*` entries are the clearest structural finding: they exist
as manifest-only directories, which means "profile" is modelled as a module when it is a *selection over
modules*.

## The five decisions I would make differently

### 1. One authority fact, not four that must agree — PARTLY FIXED

The four sources were: `capabilities.exposes[]`, `capabilities.routes`, the declared policy rows in
`ModuleInstallService`, and the active row in `capability_authorization_policies`.

**Fixed for sources 3 vs 4** (`63ec609`): `ikabud capability:census` now compares declaration against the
active store and names each divergence with its direction. On tenant 54 it reads 69 declarations against
69 store rows and reports:

```
[divergent/widening fields=caller_module] akira.shell.admin_page@1 declared=v30 active=v30
```

That is decision 115 — the divergence I found by hand from log noise among nine identical warnings. A
machine now finds it. Route mapping (sources 1 vs 2) remains outstanding and is a smaller, mechanical
follow-up.

### 2. Govern reads from the start — YOURS

Measured: `write_ratio = 100`, **`read_ratio = 42.4`, `read_undeclared = 27`**, and four separate comments
in the tree say reads stay ungoverned until R5.

The consequence is not theoretical. Every governance property this programme claims — that a declaration
controls what happens, that authority is auditable per caller — holds for writes and **does not hold for
reads**, on 27 undeclared read paths. This is a scope decision, not a defect report: R5 was a deliberate
deferral, and I am reporting that the deferral has a measured cost.

### 3. Governance and lifecycle do not belong in `cms-akira-core` — YOURS

`akira.policy.set_roles@1` and `akira.module.manage@1` are flagged *widening* and live in the domain
module. P4.2a-r1 returned `BLOCKED (correct return)` because a module-owned capability could not reach
kernel-owned install tables — the boundary refusing an ownership mistake rather than a logic error.

The kernel owns install and policy; a product module owning a capability that writes them inverts the
dependency. Moving them is a capability-ownership change, so it is yours.

### 4. Content identity is not a transported hash — FIXED

`cacBundlePlan()` decided an entry's identity from a `hash` field it never verified against the `payload`
beside it, breaking `bundle.php`'s own promise that a no-op replay classifies as a skip. Measured on real
tenant data, 39 live posts:

| | normal export | no transported hash | stale hash on all |
|---|---|---|---|
| before | `skip=39` | **`update=39`** | **`update=39`** |
| after | `skip=39` | `skip=39` | `skip=39` |

Latent because the normal path carries correct hashes; it bites only for a bundle from an older or
foreign source — and then it rewrites the whole tenant and defeats `apply`'s replay guard. Fixed in
`5143231`.

### 5. An extension point is created by its first consumer — YOURS

`cms-akira-core` publishes **five** extension points and `grep '"contributes"'` across every manifest
returns **nothing**. Measurement now available (`e91d02a`):

```
Manifests read: 19
Declared points: 5; contributions: 0
unconsumed 5 | orphan 0
```

Five published interfaces that nothing exercises: no evidence they work, no test that would notice them
breaking, and no way to distinguish "supported extension point" from "a name in a manifest". Either give
each one a consumer or remove it — both change published contract surface, so both are yours.

## What belongs to whom

**Mine, done:** the authority census (declaration vs store), the extension point census, bundle content
identity, the seed refusal severity, and the harness defects found along the way.

**Yours, because each changes what the system permits or what a third party may build against:**

1. `akira.shell.admin_page@1`'s `caller_module` — which side is authoritative (decision 115).
2. Read governance scope — is R5 still deferred deliberately, given `read_undeclared = 27`?
3. Capability ownership — move `policy.set_roles` / `module.manage` out of `cms-akira-core`?
4. The five unconsumed extension points — consume or remove.
5. `profile-*` as manifest-only directories — model profiles as selections instead of modules?
6. The harpp governance-gate CI blocker (decision 116).

## Remaining risk, stated plainly

- The census reports; it does not correct. Nothing currently forces a divergence to be resolved, so a
  divergence can be *visible and still unfixed* — the same shape as a warning nobody reads, one level up.
- Route mapping (sources 1 vs 2) is not yet compared, so a route that reaches no capability, or names an
  undeclared one, is still invisible. The extension census catches the analogous case for extension
  points, not for routes.
- `read_undeclared = 27` is a measurement, not an audit: I have not established which of those 27 are
  legitimately public read surfaces and which are gaps.

## Verification behind this review

Suite `208 files — 155 passed, 53 skipped, 0 failed`. `error.log` 0 bytes; `app.log` zero `[warning]`
lines (was 9 per bootstrap). Harness self-test `153 passed, 0 failed`. `continue-check` exit 0. Working
tree clean at `e91d02a`.
