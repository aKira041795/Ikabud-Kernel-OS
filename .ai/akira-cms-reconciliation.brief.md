# Reconciliation round — Akira CMS completeness (chair, 2026-09-13)

Two models answered the same brief (`akira-cms-completeness.brief.md`) **independently and without
seeing each other**. Their answers diverge on specific points. This round resolves the divergence.

## Chair verification performed between rounds (treat as fact)

The chair checked the strongest claims rather than accepting either answer:

| Claim | Verified result |
|---|---|
| Theme Studio is a *complete* feature merely hidden | **FALSE.** Declared capabilities are `activate, blocks, customize, customizer.schema, customizer.values, registry, resolve, validate`. **There is no install capability, no upload route, no package-admission surface.** "Installed themes" lists themes already on disk. |
| The activation form route is declared for authority | **FALSE.** `capabilities.routes` declares only `POST /api/v1/cms-akira-theme/customize` and `POST /cms-akira-theme/customize`. **`POST /cms-akira-theme/activate` is undeclared** — route-authority debt. |
| "Tenant tables have no `tenant_id`" | **FALSE.** `tenant_id` appears in **11** Akira migration files (core 002,003,004,005,006,007; builder 002; media; navigation; search; seo). The chair propagated this claim from repository instructions without checking the schema it had itself already printed. |
| Sidebar renders 14 links for `administrator` | **FALSE.** `akiraShellPage()` builds 5 participant links + 4 administrator links + enabled sidebar contributions. Source predicts **9** (10 after the Theme Studio gate is fixed). The chair counted every `href` on the page, including per-post edit links. |
| Theme Studio is filtered from the sidebar by a role mismatch | **TRUE, confirmed in code.** `kernelContributionRoleAllowed()` compares the contribution's `roles` (`["admin"]`) against the user's role (`administrator`) and excludes it. |

**Consequence for the original brief:** its §1 architecture claim and §2 measurement 1/3/4 were
**wrong or overstated**, and its framing of the director's complaints was partly wrong too — one of the
three complaints ("no themes install") is **substantively correct**.

## The divergences to resolve

**D1 — Where does theme/package *installation* live?**
DeepSeek: install is an operator/kernel concern; the tenant shell should not host it.
Codex Sol: install does not exist at all; `akira.theme.install@1` is absent, so the lifecycle is
incomplete regardless of where it belongs.
*Resolve:* is the correct target (a) a governed install capability in the kernel control plane,
(b) a tenant-shell install surface, or (c) explicitly no install — themes ship with the product?

**D2 — What is the authoritative section model?**
DeepSeek proposes an 8-section generic floor + 2 Akira-specific (authority-legibility, provenance).
Codex Sol proposes its own floor and rejects the reference CMS's 18 as a target.
*Resolve:* produce **one merged table**. Where they differ, state which you now accept and why.

**D3 — Is "7 modules with no UI" a defect, a design, or a measurement error?**
Both now agree route ownership is a poor proxy (media/editor/workflow are surfaced through the shell).
*Resolve:* give the correct test for "this module needs a screen" and apply it to all 15 modules.

**D4 — Does the platform need a *tenant-schema reconciliation* step before more feature work?**
Codex Sol's closing position: *"the highest-priority evidence gap is not another feature census. It is
reconciling the actual tenant schema and deployed HTML with the architectural and measured claims."*
Given the chair just proved it had mixed source state with deployed state, and that a claimed
architectural invariant (`no tenant_id`) is false, is reconciliation a **prerequisite** for the roadmap
or a parallel activity? Commit.

**D5 — Sequence.** Both propose an early role-gate fix. Neither has seen the other's ordering.
*Resolve:* one merged ordering, with the smallest first increment named.

## Required output

1. For each of D1–D5: **your committed position** (not options), with the reason.
2. A **single merged section table**: section · required? · why · current state (source **and** tenant
   separately where they may differ) · effort · dependency · which phase.
3. **Falsifiable acceptance criteria** for "production-ready Akira CMS" — journeys that can fail.
4. An ordered phase list where each phase is independently shippable.
5. What you **now accept** from the other model's position, and what you still reject and why.
6. Explicitly: what must be **measured on the live tenant before any of this is planned as fact**.

Do not repeat your previous answer wholesale. Answer only what this round asks.
