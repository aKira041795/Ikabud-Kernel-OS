# Akira CMS — completeness research brief (chair, 2026-09-13)

**Purpose.** The director reports that Akira CMS is not production-ready: the admin is missing
sections (themes install, theme customizer, module/extension install). The director has explicitly
refused a rushed fix: *"better to plan this carefully … not a reflex reaction but a grounded roadmap."*

This brief is the input to independent research and a structured debate. **Answer from evidence. Where
you rely on general knowledge of the CMS field, say so and name the systems you are reasoning from.**

---

## 1. What Akira is (so the answer is not generic)

CMS Akira is not a WordPress clone and must not become one. It is the **reference product proving the
Ikabud Kernel OS governs application modules**:

- content operations run through a **capability bus** (policy row, idempotency, audit in one tenant
  transaction, cache invalidation) rather than direct handler writes;
- **every tenant has its own database** — no `tenant_id` columns;
- presentation is **ARK** (declarative, sandboxed theme packages; no executable PHP in themes);
- templating is **DiSyL**; modules declare routes + capabilities in `module.json` and route authority
  is **declared and enforced** (`capabilities.routes`, fail-closed).

The repo's own ADR states the identity explicitly: **"governed content operations, not a WP clone"**,
and records the refusal **"no feature for parity alone."** Any recommendation that adds a section
merely because another CMS has it is out of scope and must be argued differently.

## 2. Measured state (verified on tenant 54, 2026-09-13 — treat as fact)

| Measure | Value |
|---|---|
| Akira modules installed | 15 |
| Akira shell routes | 36 (reference CMS has 44) |
| Admin UI (non-API) routes per module | shell 12 · theme 3 · core 5 · builder 1 |
| Modules with **zero** admin UI routes | **7** — `ai`, `editor`, `media`, `navigation`, `search`, `seo`, `workflow` |
| Admin sections that render | dashboard, posts, categories, content-types, media, permissions, users, compositions, health |
| Sidebar links actually rendered | 14 |

**The two findings that matter most:**

1. **A complete feature is hidden by a one-word mismatch.** Theme Studio exists and returns 200 at
   `/cms-akira-theme`, with a working themes list (5 × `Activate`) and a customizer
   (`POST /cms-akira-theme/customize`, "Save customization"). Its sidebar declaration is
   `"roles": ["admin"]` — but the only admin on the tenant has role **`administrator`**. The nav
   registry filters it out, so a finished feature is invisible. Same defect class as the login
   email mismatch fixed earlier today.
2. **Seven installed modules expose no UI at all.** They are enabled, policy-governed capability
   providers with no screens. This is a systemic pattern: the capability layer was built, the
   presentation layer was not.

Sections returning **404** today: `/cms-akira-shell/settings`, `/modules`, `/extensions`.

## 3. The reference bar (measured, not remembered)

**This product's own reference CMS** (`/var/www/html/applicationostest/modules/cms`) exposes these admin
route prefixes — 18 sections:

```
ai-automation  categories  content  content-types  customize  extensions
import-export  media  menus  modules  page-builder  permissions
react-builder  redirects  report-approvals  settings  themes  users
```

**WordPress**, extracted from `wp-admin/menu.php` (the source that defines it) — 10 top-level:

```
Dashboard · Posts · Media · Pages · Comments ·
Appearance (themes · customize · widgets · menus) · Plugins · Users · Tools ·
Settings (general · writing · reading · discussion · media · permalinks)
```

Cross-reference these two. Where they agree, that is the industry floor. Where the reference CMS has
something WordPress does not (`permissions`, `redirects`, `import-export`, `report-approvals`,
`react-builder`, `ai-automation`), decide whether it is a CMS requirement or a product-specific one.

## 4. Questions to answer independently

**Q1 — The floor.** What is the *minimum* set of admin sections a CMS must have to be credible in
production? Justify each by what breaks without it, not by convention. Distinguish "a CMS must have"
from "this CMS, given §1, must have".

**Q2 — The 18.** Take the reference CMS's 18 sections. For each: **required / required-but-different /
deliberately excluded**, with a one-line reason. Do not accept the list as a target; it is evidence.

**Q3 — The seven.** Seven modules have capabilities but no UI. Is the right answer (a) build admin UI
for each, (b) make them headless and screen-less by design with a documented surface, or (c) surface
them through *other* sections rather than their own nav entries? Commit to one position and defend it.

**Q4 — The governance angle.** Which sections does the kernel's governance model make *necessary* that
a conventional CMS does not need — and which conventional sections does it make *obsolete*? For
example: does per-capability policy make a generic "Permissions" screen different in kind? Does
auditability change what "Settings" must be?

**Q5 — Definition of done.** What makes "production-ready CMS" **falsifiable** for Akira? Propose
acceptance criteria that can *fail* — a named journey an operator must complete end-to-end without a
developer. State explicitly what is **not** in scope.

**Q6 — Sequence.** Order the work so each phase is independently shippable and demonstrable. What
must precede what, and what can run in parallel? Identify the smallest first increment that makes the
product visibly more credible to the director, given §2 finding 1.

## 5. Constraints

- Do **not** propose features for parity alone. Every recommendation needs a reason rooted in §1 or
  in an operator journey.
- Respect the existing refusals: no marketplace or unsigned third-party uploads; themes stay
  declarative and never executable PHP; extensions are typed capability-bound contribution points,
  never ambient global hooks; builder is structured-JSON with deterministic server render, never
  HTML-as-source; no child-theme or per-route override sprawl.
- MySQL 5.7 compatible (shared-hosting target). No CTEs, window functions, `JSON_TABLE`.
- Every claim about Akira's current state must match §2. If you believe §2 is wrong, say so and give
  the evidence that would settle it.

## 6. Required output

1. Answers to Q1–Q6, each with a position stated, not options listed.
2. A section table: section · required? · why · Akira state · effort (S/M/L) · dependency.
3. Falsifiable acceptance criteria for "production-ready".
4. An ordered, independently shippable phase list.
5. **A list of things to deliberately not build**, with reasons.
6. Your confidence per answer, and the specific evidence that would change your mind.
