# CMS Akira — View / Layout Architecture Review

**Date:** 2026-09-15
**Scope:** how "views" are set with reference to "the layout" in CMS Akira, and whether the
arrangement is coherent.
**Branch / HEAD:** `feat/akira-editorial-and-authority-coverage` @ `bc19dc0`
**Method:** source reading with `file:line` evidence. **No test suite and no HTTP request was run
for this review** — every claim below is a static reading of committed code. Claims that would need
execution are marked *UNVERIFIED*.

---

## 1. The layer stack, as it actually is

A public page on a tenant host (verified by reading the code path, not by request):

```
GET /  or  /posts/{slug}
  │
  ├─ modules/cms-akira/cms-akira-shell/routes.php        → handler
  │
  ├─ handlers.php:47  app()->entityViews()->resolve('post','list', …)      ← FETCH
  │     contract registered by cms-akira-core/helpers/entity-views.php:45
  │     returns { rows, total } — the contract's fields/sort/limit shape the QUERY
  │
  ├─ handlers.php:63  akiraPublicThemeRender('entity.list.post', $context)
  │     └─ helpers.php:106
  │          ├─ akira.theme.resolve@1              → slug  (active_theme_slug)
  │          ├─ theme.manifest.json:14 "shell"     → layouts/public.disyl
  │          ├─ app()->arkRenderers()->render(viewId, …)      ← RENDER THE VIEW
  │          │     kernel/Services/ArkRendererResolver.php:111
  │          │     renderer-registry.json["entity.list.post"].template
  │          │       = entity-views/post-list.disyl
  │          ├─ customizer values merged into tokens + settings   (helpers.php:150-176)
  │          ├─ renderProviderRegion(…,'header')  → templates/regions/header.disyl
  │          ├─ renderProviderRegion(…,'footer')  → templates/regions/footer.disyl
  │          └─ app()->render(<theme>/layouts/public.disyl, $context + [
  │                 header_region, page_region, footer_region ])   helpers.php:199-202
  │
  └─ IF that returned null  →  handlers.php:114
       app()->render('modules/cms-akira-shell/public/home.disyl')
         which {extends "modules/cms-akira-shell/public/layout.disyl"}
```

**The boundary is correct, and this is the single most important thing in this review.** The layout
is the only thing that emits `<html>`; a view is a chrome-free content fragment. The layout takes
pre-rendered HTML through three named slots:

| Layer | File | Emits |
|---|---|---|
| Layout (theme-owned) | `storage/cms-themes/akira-editorial/layouts/public.disyl:2,150-152` | `<html>`, `<head>`, `<style>`, slots |
| Region: header | `storage/cms-themes/akira-editorial/templates/regions/header.disyl` | `{header_region\u007craw}` |
| Region: footer | `…/templates/regions/footer.disyl` | `{footer_region\u007craw}` |
| View (entity view) | `…/entity-views/post-list.disyl:3` | `<div class="home" data-ark-renderer="post-list">` |
| View (detail) | `…/entity-views/post-detail.disyl:2` | `<article class="post-detail" …>` |

The views contain **no** `<html>`, `<head>`, nav or footer — verified. They do not know the layout
exists, and the layout does not know which view filled `page_region`. That is a clean, deliberate
separation and the review does not propose changing it.

`data-akira-theme` is correct per theme (`akira-editorial`, `akira-ark`, `akira-ark-demo` each name
themselves) — no hardcoded-slug defect here. I expected one and it is not there.

---

## 2. Findings

Legend — **LIVE** = affects a page served today. **INERT** = declared but reached by nothing.

### V1 — `supported_slots` is declared in a vocabulary nothing uses. INERT.

All three theme manifests declare `supported_slots: ["header.main","content","footer.main"]`
(`akira-editorial/theme.manifest.json:28-32`, `akira-ark/…:23`). The mechanism actually in force uses
different names: `header_region`, `page_region`, `footer_region`
(`cms-akira-shell/helpers.php:199-202` filling `layouts/public.disyl:150-152`).

The validator only complains when a template references a slot that is **not declared**
(`kernel/Services/ThemeManifestValidator.php:695`). No template in any theme references
`header.main`, `content` or `footer.main` at all — so this check can never fire, and the declaration
is decorative. Three vocabularies now exist for one concept: `supported_slots` (manifest),
`regions{header,footer}` (manifest → region template paths), and the `*_region` context keys (shell).

### V2 — Four places declare which template renders which view; one is consumed; nothing checks agreement. LIVE.

| # | Declaration | Consumed by |
|---|---|---|
| 1 | `renderer-registry.json` → `entity.list.post.template` | **`ArkRendererResolver.php:47,111` — the only one that renders** |
| 2 | `theme.manifest.json` → `fallback_views` | `ThemeManifestValidator` (warnings only) |
| 3 | `entity-view-map.json` → `entity_views` | `ThemeManifestValidator:395-405` (warnings only) |
| 4 | the caller's hardcoded view id | `cms-akira-shell/handlers.php:63,104`; `cms-akira-builder/helpers.php:711` |

`ArkRendererResolver` is deliberately **exact-match only** — *"renderer selection never falls back to
`*`"* (`:111`). So a view id that does not appear in `renderer-registry.json` yields `null`, which
propagates to `akiraPublicThemeRender()` returning `null`, which makes the caller render the **shell
fallback layout** instead (`handlers.php:114`). A one-character mismatch in a view id therefore
**silently serves a completely different design** rather than failing.

### V3 — The theme's declared field map disagrees with the module's registered contract. LIVE.

`entity-view-map.json` is a lossy duplicate of the contract the module registers:

| View | Module registers (`cms-akira-core/helpers/entity-views.php`) | Theme declares (`akira-editorial/entity-view-map.json`) | Drift |
|---|---|---|---|
| `post.list` | `title, subtitle, image, metadata, categories, actions, url` (`:45-46`) | `title, subtitle, metadata, image, url` (`:6`) | **missing `categories`, `actions`** |
| `post.detail` | `title, subtitle, image, body, metadata, categories, actions, url` (`:70-71`) | `title, subtitle, body, metadata, image, url, categories` (`:11`) | **missing `actions`** |

The module's `post.list` also carries `key_field`, `action_urls`, `limit`, `sort`, `sortable_fields`,
`empty_state`, `field_contracts` and `source_schema`; the theme's entry carries only `fields` and
`actions`. So the theme's map reproduces a subset of a contract it cannot see.

### V4 — The registered contract is not applied on the path that renders. LIVE.

This is the systemic finding. The ARK path renders a theme template directly against a raw context
array:

```php
// cms-akira-shell/helpers.php:132-135
$rendererContext = $viewId === 'entity.list.post'
    ? ['posts' => $context['posts'] ?? []]
    : ['post' => $context['post'] ?? []];
$pageHtml = app()->arkRenderers()->render($viewId, $rendererContext, $slug);
```

`EntityViewResolver` (contract, field roles, `field_contracts`, `source_schema`) is **never
consulted** for rendering — only for fetching rows. The template reads whatever it likes off the
projection. Direct evidence: `entity-views/post-list.disyl:48-52` reads `post.image` and
`post.categories.name`, while the theme's own map omits `categories` (V3).

Consequence: if the module's post projection renames or drops a field, the theme template silently
renders an empty element and **no test fails** — the two surfaces never meet at a check.

### V5 — Two complete public presentations must be hand-synchronised; 404 never uses the theme. LIVE.

| Presentation | Layout | Styling |
|---|---|---|
| Theme path | `storage/cms-themes/<slug>/layouts/public.disyl` | hand-written CSS in a `<style>` block |
| Fallback path | `templates/modules/cms-akira-shell/public/layout.disyl:1-32` | Tailwind CDN + Alpine, hardcoded header/nav/footer |

Both are complete. The fallback is not dead code — it is the P5-1 fallback and it is what a tenant
gets whenever theme resolution fails for any reason.

`404` is worse than duplicated: `akiraPublicNotFound()` (`handlers.php:120-140`) renders the shell's
`404.disyl` **unconditionally**, with no theme attempt. So a fully themed site shows an unthemed,
Tailwind-styled error page. Meanwhile `akira.javaPublicPostSingle()` *does* try the theme first for
its "post unavailable" case (`handlers.php:71-84`) — so the module is internally inconsistent about
whether 404 is themeable.

### V6 — `composition.detail` is declared as a view contract but no PHP registers it. LIVE.

The theme's map declares `composition.detail` with 6 fields
(`akira-editorial/entity-view-map.json:13-19`), and `renderer-registry.json:12-16` maps it to
`entity-views/composition.disyl`. The builder calls it (`cms-akira-builder/helpers.php:711`). But a
grep for `registerView` across `kernel/`, `src/`, `modules/` finds registrations only for `post.list`
and `post.detail` (`cms-akira-core/helpers/entity-views.php:45,70`) — plus tests and the
`module.json`-driven auto-registration in `src/helpers/module-routes.php:375`, which no
`modules/**/module.json` uses (grep: 0 hits for `entity_views`/`views` in Akira manifests). So
composition renders against a declared contract that has no provider.

### V7 — Theme-render degradation is completely silent. LIVE, and it is what hides V2–V4.

`akiraPublicThemeRender()` has **13 bare `return null` paths** and **zero `write_log()` calls**
(`cms-akira-shell/helpers.php` — grep for `write_log` returns nothing in that file). An `active_theme`
that fails to resolve, a registry miss, a missing region, an unrenderable view and a caught
`Throwable` (`:205`) are all indistinguishable: the page degrades to the fallback with a clean log.
This is the mechanism by which every other finding stays invisible in production.

---

## 3. What this review did NOT establish

- **No live request was made.** Whether `akira-editorial` is the active theme on tenant 54 *right
  now*, and which path actually serves `/`, is **UNVERIFIED** here. Earlier evidence in
  `/memories/repo` says `akira-editorial` is active and `/` emits `<style data-akira-customizer>`,
  but I did not re-confirm it in this review.
- **No test was run.** The counts in the harness ledger are not evidence about this code.
- **`akira-ark` and `akira-ark-demo` were read only at manifest/map level.** Their templates were not
  audited field-by-field, so V3's drift is proven for `akira-editorial` and *suspected* for the other
  two (they declare the same map shape).
- **Why the tests never caught V3/V4** is not fully established. `theme_contract_test.php` and
  `post_entity_view_contract_test.php` exist and are the obvious places; establishing which assertion
  *should* have fired is implementation-phase work.

---

## 4. Recommended implementation slice (bounded)

Ordered by consequence, smallest correct change first. A4–A6 are recorded, **not** in this slice.

| ID | Change | Why |
|---|---|---|
| **A1** | Fix `entity-view-map.json` in `akira-editorial` and `akira-ark` so the declared field set matches the registered contract (`+categories`, `+actions` for list; `+actions` for detail). | V3 — make the declaration true. Data-only. |
| **A2** | Add a check to `catThemeValidate()` (`cms-akira-theme/helpers.php:450`): every field a theme declares in `entity-view-map.json` must exist in the registered contract for that entity+view. **Fail-open with a warning when no contract is registered** (a CLI bootstrap does not register module capabilities — see `.github/instructions/verification-harness.instructions.md`), **error when a contract exists and a declared field is absent.** | V3/V4/V6 — make the drift impossible instead of fixing it once. |
| **A3** | `akiraPublicThemeRender()` logs a structured reason (`theme_render.unavailable` + reason + slug + view id) on each `return null` path. | V7 — silent degradation becomes observable. This is the finding that makes the others findable. |
| A4 | Route `404` through the theme when a theme is active. | V5 — behaviour change; needs its own contract. |
| A5 | Reconcile or delete `supported_slots`. | V1 — inert declaration; cheap but touches all three manifests. |
| A6 | Give `composition.detail` a real registered contract, or delete it from the map. | V6 — needs a decision on whether composition is a first-class entity. |

**Explicitly rejected as scope:** replacing the shell fallback layout, unifying the two layout
mechanisms, or making the ARK path consume `field_contracts` for rendering. The first two remove a
deliberate fallback; the third is a rendering-architecture change that needs its own ADR.

---

## 5. Verdict

The **view↔layout boundary is sound** and should not be redesigned: one theme-owned layout, three
named slots, chrome-free content views, regions injected as pre-rendered HTML.

The defect is not the boundary — it is that **four declaration surfaces describe that boundary,
only one of them is read, none of them is checked against the others, and the failure mode is a
silent fallback to a different design with a clean log.** A1–A3 convert an unchecked arrangement into
an enforced one for a bounded cost.
