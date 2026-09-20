# Comprehensive review — CMS Akira, 2026-09-12

role: /review  ·  authority: chair  ·  scope: everything touched or verified this session

---

## 1. Verified working (evidence, not inference)

| Surface | Evidence |
|---|---|
| Public home `/` | `HTTP 200`, 3151 bytes, lists the published post |
| Public archive `/posts` | `HTTP 200`, "All posts · 1 published stories", `Read →` |
| Public detail `/posts/{slug}` | `HTTP 200`, 2466 bytes, body renders as markup, **0** escaped entities |
| Post lifecycle | create → sanitize → publish → render (Playwright journey passes end-to-end) |
| Tenant routing | probe `{"tenant":54,"db":"akira","error":null,"total":1,"row_count":1}` |
| Kernel DB isolation | `db()` refuses base-DB connections for a resolved tenant |
| Public page cache | `X-Page-Cache: hit`, TTL 300s, invalidated on shell save/publish/delete |
| Effects → invalidation | `CapabilityBus::invalidateProviderEffects()` → `entityViews()->invalidateEntityCache()` |
| Test suite | 137 files — **106 passed / 0 failed / 31 skipped** |

The entity view pipeline is real and wired: `resolve()` → view contract → module capability
(`entity.list.post@1`) → projection DTO → ARK renderer → DiSyL. The philosophy is implemented;
the gaps below are about **ownership**, not mechanism.

## 2. Defects found and fixed this session

1. **Kernel DB served tenant data.** `dbForTenant()` had two isolation guards; `db()` — the path all
   module code uses — had none, so a resolved tenant could silently get the control-plane DB.
   Fixed: config-level rejection + post-connect connected-identity check, both fail closed.
2. **The test suite destroyed live tenant data.** Six Akira tests hardcoded `HTTP_HOST='akiracms.test'`
   (the live domain → tenant 54), so `app()->db()` handed them real data and their teardown `DELETE`
   wiped it. Proven with a `BEFORE DELETE` trigger + timestamped suite run (`posts 1 → 0`).
   Fixed: `requireNotLiveTenantDatabase()` — SKIPs rather than mutating a provisioned tenant DB.
   Acceptance test now shows `1 → 1`.
3. **Authored HTML was escaped** on post detail (`{post.body}`), so `<p>` rendered literally.
   Fixed to `|raw` in the shell fallback + three ARK theme templates.
4. **Stored XSS opened by (3).** Rendering as markup without sanitising let any post-authoring role
   plant `<script>` on the public site. Fixed: `cacPostSanitizeHtml()` allowlist sanitizer on write
   and on the detail projection; `post_sanitize_test.php` (15 assertions) pins it.
5. **Composition selector was permanently empty.** `shell/handlers.php` read `$row['slug']`, which the
   `post.list` projection deliberately omits, so `entity_key` was always `''` and every row was
   filtered out. Fixed by deriving the key from the module-exposed canonical `url`
   (`akiraShellPostKeyFromUrl`, accepting only canonical `/posts/{slug}` shapes).
6. **11 stale `cms_akira_*` tables** (test debris, partial schema) lived in the kernel DB.
   Dropped; kernel DB now holds zero Akira tables.

## 3. Corrections to earlier claims (recorded so they are not re-derived)

- "No view contracts are registered" — **wrong.** They are declared by the *theme*
  (`entity-view-map.json`); `viewContract()` also cascades to `kernel.builtin` / `kernel.generic`
  and never returns null, so that early return is dead code.
- "The list rendering is broken" — **wrong.** It rendered correctly; the table was empty because the
  tests had deleted the rows.

## 4. Architectural gaps against the entity view philosophy

```
MODULE owns truth → ENTITY VIEW owns presentation semantics → ARK owns presentation choice → DiSyL renders
```

**Gap A — RETRACTED (chair error, 2026-09-12). The module already owns its contracts.**
I claimed the theme owned Post presentation semantics. That was wrong. Verified evidence:
- `modules/cms-akira/cms-akira-core/helpers/entity-views.php` registers `post.list` (line 45) and
  `post.detail` (line 70) via `$views->registerView(..., 'cms-akira-core')` — the **provider is the
  module**, exactly as the philosophy requires.
- Loaded at `modules/cms-akira/cms-akira-core/helpers.php:16-18`.
- The theme's `entity-view-map.json` is **validation-only** — `kernel/Services/ThemeManifestValidator.php:395-405`
  reads it and returns `$errors[]`; it is not the authority.
Why I got it wrong: I searched for `{ikb_entity_view}` / `loadViewConfigs()` and concluded "no contracts".
The module registers **programmatically** instead — a mechanism I had even recorded in my own workspace
notes on 2026-09-08 (`cacRegisterPostEntityViews`) and failed to connect. Lesson: absence of one
mechanism is not absence of the capability; search for the outcome, not for the idiom.

**Gap B — DEFUSED, not a defect.** Two mechanisms exist by design: `{ikb_entity_view}` (DiSyL config,
validates `table|compact|card_grid|detailed|summary`) and `registerView()` (programmatic, provider-aware,
accepts semantic names like `list`/`detail`). Modules use the latter; the composition is deliberate.
Sol's Slice 2 explicitly considered switching to DiSyL view files and correctly rejected it as
introducing vocabulary and provider-provenance problems. **No canonicalisation is needed** — the earlier
proposal to rename 5 call sites is withdrawn.

**Gap C — core-capability mutations do not invalidate the public page cache.**
`cms-akira-core/helpers/capabilities.php` contains no invalidation; only the shell save/publish/delete
paths and the builder call `akiraShellInvalidatePublicCache()`. A post published through the
capability API alone can leave public pages stale for up to 300s.

**Gap D — mutation coverage is thin locally.** Five Akira mutation tests now SKIP (they can no longer
bind to a live tenant). CI still runs them against the base DB; local coverage needs a provisioned
dedicated test tenant.

## 5. Delegated next slices

| Slice | Gap | Contract |
|---|---|---|
| Mutation-driven public cache invalidation | C | `.ai/akira-mutation-cache-invalidation.contract.md` — **done + chair-verified** (its test skips locally; runs in CI) |
| Module-owned entity view contracts | A + B | `.ai/akira-entity-view-contracts.contract.md` — **done (investigation found no production change needed); A retracted, B defused** |
| Dedicated test tenant | D | `.ai/akira-dedicated-test-tenant.contract.md` — dispatched 2026-09-12 |

**Withdrawn following Sol's grounded Phase 1:** the proposal to canonicalise the view vocabulary across
5 call sites. There is no conflict to resolve — see Gap B above.
