# DEBATE BRIEF — Akira: what is the next ground, and what is the contender wedge?

repo: `/var/www/html/ikabudsix` · read the repo yourself; this brief is the question, not the evidence
role: you are ONE panellist. Answer independently. You are **not** being asked to agree with anyone —
including the chair, including the brief. Where you think the framing is wrong, say so and say why.

## Context

Ikabud Kernel OS is a governed application substrate. Modules declare capabilities; a capability bus
enforces authorization policy; actions leave a capability + actor + audit record with provenance and
idempotency. The kernel is the product. Two applications exercise it: `cms-akira` (publication) and
`daily-ledger` (money/POS — the second domain that exists to break publishing-shaped assumptions).

The product owner has amended the direction doc (`docs/architecture/akira-beyond-the-cms.md`, PR
#126) to say Akira is **not a POC**: it is the reference **product**, judged on the authority axis.
Verbatim:

> "while Akira CMS is a POC of the ikabud kernel os, this does not mean it must be just an evidentiary
> module. Akira must exhibit the power of ikabud kernel when used properly. ARK/Theme studio,
> Workbench/Disyl when working and leverage well showcases a new CMS that is uniquely good, easy to
> manage, useful and production ready. While we are dictated by popular CMS in terms of features, but
> I believe Akira CMS can also be a contender."

and the thesis the panel is asked to test:

> "we must be grounded with what the kernel is very capable now and what the future is, AI driven but
> gated. A CMS that is intelligent but also responsible in knowing the limits."

The owner also said, separately, that they are **out of ideas** for Akira's next iterations. A list of
plausible features is therefore the least useful thing you can return. What is wanted is judgement:
what ground, in what order, and what would prove it wrong.

## Measured facts you must respect

Verify these yourself if you doubt them; do not contradict them from memory.

1. **Substrate capability that exists and is enforced today.**
   - Capability bus with per-tenant policy rows; **route authority declared in `module.json`
     (`capabilities.routes`) and enforced at dispatch, before any handler body runs**; denial returns
     `403 {"state":"route_authority_denied","capability":"..."}` and the handler does not execute.
   - Idempotency keys, kernel audit, and provenance (P1, Cycle 4): a revision knows its capability,
     actor, timestamp and correlation, and can be rendered as of any point.
   - Grant lifecycle: `grant_state ∈ {granted, suspended, revoked}`; `transitionGrantState()` is the
     only state mover (authenticated actor, mandatory reason, `FOR UPDATE`, audited); seeding may
     narrow but never widen, and can never resurrect a revocation.
   - `AuthorityScope{tenantId, actor, declarationRevision, entryPoint}` established by 8 non-HTTP
     entry points (web, cli, cron, queue, service, event, workbench, test). The ambient `app()->db()`
     fallback is gone from the authorization registry.
   - `GovernanceCensus` + `workbench:governance --gate` measure routed **and** non-HTTP coverage
     against frozen baselines the gate refuses to let grow.
2. **The honest numbers (2026-09-12).** Akira: **28/33** routes dispatch-enforced. `daily-ledger`:
   **0/52** business operations through the capability path — it reimplemented governance locally.
   Non-HTTP capability call sites: **95 total — 1 declared, 0 unscoped, 94 unresolved** (static
   analysis cannot prove transport for generic helpers, so they are reported unresolved rather than
   claimed as governed or manufactured into debt). **57 business writes remain undeclared**.
3. **Scope boundary.** The authority model covers **tenant-scoped** surfaces only. On the kernel host
   no tenant resolves, so a kernel-scoped surface has **no authority store at all**. Governing one
   requires an explicit non-tenant store path that does not exist yet.
4. **The measured surface gap.** This is the crux and it is stark:
   - 15 `cms-akira-*` modules contain **0 DiSyL templates between them**.
   - `cms-akira-media`, `-editor`, `-seo`, `-navigation`, `-search`, `-ai` each have **0 declared
     routes** — they are capability providers with **no user surface at all**.
   - `cms-akira-shell`: 14 routes, **6 templates** (`home`, `posts`, `single`, `404`, `layout`,
     `login`).
   - Builder UI: **6** `.ts`/`.tsx` source files. ARK theme: 21 templates — the richest surface.
   - Consequently: media, taxonomy, menus, SEO and search **cannot be managed in Akira today**.
5. **Rendering/extension model.** Entity views are the primary rendering engine (Kernel OS 6.0+):
   `{ikb_entity_list}` / `{ikb_entity_detail}`, view contracts registered via `{ikb_entity_view}` in
   `helpers/views/`. DiSyL v4 is the template language (interpreted + compiled pipelines; compiled is
   production). ARK is the theme system (`storage/cms-themes/<slug>`). Suite/extension contract v1
   exists: `suite`, `kind` (`product-core` / `extension` / `profile` / `standalone-application`),
   `extends`, `contributes`, `extension_points`. Four installation profiles already exist
   (`profile-minimal`, `-standard`, `-visual`, `-headless`).
6. **Deployment reality.** Target is **Bluehost shared hosting, MySQL 5.7** (no window functions, no
   CTEs, no `JSON_TABLE`, no enforced `CHECK`; InnoDB required; FK types must match exactly). One
   codebase; tenant resolved from request host; **one database per tenant**; the control plane runs on
   the same shared host. CI runs mysql-8, mysql-5.7 and mariadb-10.6.
7. **What does not exist yet.** P3 (delegation: `Actor`/`Grant`/`ExecutionContext`) — designed, not
   built. P4 (third-party-verifiable artifact export) — designed, not built. P5 (extension authority
   grants) — designed, not built. There is no AI actor, no grant-issuance UI, no approval queue, no
   proof export, no admin surface for the six capability-only modules.
8. **Recent evidence that this is not theoretical.** A DiSyL engine defect left `{if}` markers
   unrendered inside `<script>` in the login page, so the browser POSTed to a URL containing `%7Bif…`
   → HTTP 500 and **no kernel admin could log in**. Every HTTP-level check passed throughout, because
   the login *API* was fine — only the page's own JavaScript was broken. Only a real browser caught it
   (PR #125). Treat "it passed our tests" as weak evidence in this codebase.

## The questions

Answer **all** of them. Be specific and falsifiable; name files, modules, capabilities or numbers
where it helps. Where you lack evidence, say so rather than filling the gap with plausibility.

**Q1 — The wedge.** Pick **one** position Akira should contend on, and defend why incumbents
(WordPress, Contentful, Sanity, Strapi, Payload, Directus) will not or cannot occupy it. Be concrete
about who buys it and what they currently do instead. If you believe there is no wedge, say that
plainly — that is a legitimate answer and worth more than a manufactured one.

**Q2 — Sequencing.** Give the first **three** iterations, in order. For each: (a) the substrate claim
it demonstrates, (b) the user-visible value, (c) why it must come before the others, (d) the smallest
version that is still real. "Build an admin surface" is too vague to be useful — say which screens,
which capabilities, which entity views.

**Q3 — Is "intelligent but gated" a product or a demo?** Akira can plausibly claim: an AI may draft,
summarise, suggest and schedule but **may not publish**; a human holding *different* authority
approves; the published artifact carries actor, grant, policy version and provenance; and an exported
artifact stays verifiable after it leaves the server. Attack this. Would a buyer pay for it? What
would make it undeniable, and what would reduce it to a conference demo? What is the cheapest
experiment that would tell the difference?

**Q4 — What we must NOT build, and where we are over-invested.** The doc already refuses undemonstrative
breadth. What *else* should be refused — including things the current direction doc proposes? Name
anything you think is a distraction dressed as a differentiator.

**Q5 — Falsification.** State what evidence would prove this direction wrong, and roughly what it
would cost to obtain. A direction that cannot be falsified is not a direction.

## Constraints on your answer

- Ground claims in **this** repository where you can. Distinguish measured fact from inference, and
  label which is which.
- No new substrate primitives unless you argue why an existing one cannot serve.
- MySQL 5.7 / Bluehost shared hosting is a hard deployment constraint.
- Cost and effort are real. Prefer the smallest thing that proves the claim.
- Do not write code. This is a direction debate, not an implementation task.
- Disagreement with the brief, the doc, or the chair is welcome and useful. Say so directly.

## Output format

```
WEDGE:
  position:      <one sentence>
  why_unoccupied:<why incumbents won't take it>
  buyer:         <who, and what they do today instead>
  confidence:    high | medium | low   (and what would raise it)

SEQUENCE:                       # exactly three
  1. <name>
     claim:      <substrate claim demonstrated>
     value:      <user-visible value>
     order_why:  <why before the others>
     smallest:   <smallest version that is still real>
  2.
  3.

GATED_AI:
  verdict:        product | demo | depends
  argument:       <the case, including what would break it>
  cheapest_test:  <the cheapest experiment that separates the two>

DO_NOT_BUILD:
  - <item + why>
OVER_INVESTED:
  - <item + why>

FALSIFICATION:
  - <evidence that would prove the direction wrong> (cost: <rough>)

BIGGEST_RISK_YOU_SEE:
  <one paragraph>

WHAT_I_COULD_NOT_VERIFY:
  - <claim you could not check, and why>
```
