# Finding: post slugs cannot change — P2.2 was scoped on a false premise

**Discovered:** 2026-09-13 during P2.2 pre-flight · **Discovered by:** Codex Sol, **verified by chair**
**Status:** roadmap re-scoped

## The finding

The roadmap scoped P2.2 as *"redirects / URL lifecycle — preserve inbound URLs after slug changes."*
**Slug changes are not possible in this implementation.** Verified in three places:

1. **The update statement never sets the slug.** `cms-akira-core/helpers/capabilities.php:678`:
   ```sql
   UPDATE cms_akira_posts SET title = :title, subtitle = :subtitle, content = :content,
          image = :image WHERE tenant_id = :tenant AND slug = :slug AND updated_at = :expected
   ```
   `slug` is the **WHERE key**. It does not appear in `SET`. The slug is treated as the record's
   immutable identity.

2. **The shell pins the slug to the route's own value.** `cms-akira-shell/helpers.php:546`:
   `$savedSlug = $existingSlug !== null ? $existingSlug : trim(...)` — the existing slug wins over any
   submitted value.

3. **No event to hook.** `cms-akira-core/module.json` declares `events: []`, so even if the slug became
   mutable, no module could subscribe to the change to create a redirect.

**Therefore a redirects feature cannot derive `from_path`/`to_path` from a slug change, because the
change never happens.** Wiring it would require modifying `cms-akira-core` and likely
`cms-akira-shell` — outside P2.2's permitted scope, which is why Sol stopped instead of widening.

## Why this matters more than the feature

This is the **sixth** time this programme has found a deliverable resting on an unverified assumption —
and the first where the assumption was in **the roadmap itself** rather than in a brief or a model answer:

| # | Assumption | Reality |
|---|---|---|
| 1 | `theme:validate` reflects activation | it did not |
| 2 | nav declares the right roles | it declared `["admin"]`, hiding a feature |
| 3 | tenant activation gates execution | it does not |
| 4 | `canNavigationActor()` admits administrators | it refused them |
| 5 | "7 modules have no UI" | route ownership ≠ user surface |
| 6 | **slug changes happen** | **they cannot** |

The pattern is stable: **a plausible-sounding mechanism is assumed to exist and never measured.**
Cheap to check; expensive to build on.

## Re-scoped P2.2

**P2.2a — manual redirect management + resolution engine.**
Independently valuable and *not* dependent on slug mutability: preserving legacy URLs when migrating
from another site, retiring content, campaign links. Operators create redirects explicitly.
Scope unchanged except that the "slug-change integration" precondition is **removed**, and the
capability/table/UI work is unchanged.

**Open question — resolution integration.** A redirect must fire when a path would otherwise 404.
Whether that can be done from a module (kernel hook) or requires `public/index.php` / kernel changes is
**not yet measured**, and it decides whether P2.2a is a module-only slice. This must be answered before
P2.2a is dispatched — a redirect store that never redirects is not a feature.

### ANSWERED (chair, 2026-09-13) — the integration splits by path kind

Measured: the kernel exposes hooks (`kernel.boot`, `kernel.home_url`, `kernel.gui_css`,
`kernel.database.query.before/after`, …) but **no request-path hook**. There is **no
`kernel.request.before_dispatch`**. Every 404 is set directly in `public/index.php` (sites at lines ~81,
317, 323, 332, 379, 388, behind an `IK_FAST_404` flag).

**But a module-level hook already exists for the case that matters most.**

`cms-akira-shell/handlers.php` resolves a post, and when the lookup fails it calls
`akiraPublicNotFound()`. That function is the natural interception point: **before returning 404, it can
consult the redirects store.** So:

| Path kind | Integration point | Risk |
|---|---|---|
| **Post paths** (`/posts/{slug}`) | `akiraPublicNotFound()` — **module-level** | Low — confined to the shell's own 404 path |
| **Arbitrary paths** (legacy URLs from another site) | `public/index.php` global 404 | **High** — the shared request path; a mistake takes down every route |

**Chair decision: P2.2a is scoped to post-path redirects only.** That delivers the primary use case —
preserving old post URLs — entirely within module boundaries, with no change to the shared request path.

**Arbitrary-path redirects are deferred**, and deliberately: they are the more valuable feature for site
migration but require touching the global 404 path, which is the highest-blast-radius code in the
application. That deserves its own contract, its own regression test, and a considered rollback story
rather than riding along with a feature slice.


**P2.2b — mutable slugs + automatic redirect creation.** Requires: making `slug` settable in the core
update path, declaring a post-update event, and subscribing to it. This is **core work with a data
consequence** (a changed slug breaks inbound links until the redirect lands) and needs its own contract
and regression test. Sequenced after P2.2a.

## Chair decision

**Do not build P2.2b speculatively.** Mutable slugs are a product decision about how the CMS behaves,
not merely an engineering gap — and the roadmap's justification for redirects was only *one* of several
legitimate use cases. P2.2a proceeds on its own merit; P2.2b is queued and needs a director view on
whether slugs should be mutable at all.
