# Akira theme CLI fix (2026-09-13)

## Defect

`php ikabud theme:activate <slug>` reported `✓ Theme '...' activated.` while activating
nothing for any tenant. It wrote a **legacy** key that no runtime path reads:

| | CLI wrote (before) | Runtime reads |
|---|---|---|
| module | `cms` | `cms-akira-theme` |
| setting | `active_theme` | `active_theme_slug` |
| scope | base/app settings | **per-tenant** module setting |

`theme:current` read the same legacy key, so **the two CLI commands agreed with each other
and disagreed with the runtime**. Reproduced on demand:

```
$ php ikabud theme:current
  No active theme configured.          # CLI
$ curl -H 'Host: akiracms.test' http://127.0.0.1/ | grep -c data-ark-renderer
  1                                    # site was rendering akira-editorial
```

## Fix

`ikabud` theme commands now target the setting the runtime actually resolves:

- **`theme:activate <slug> --tenant=<id|key|domain>`** — requires an explicit tenant
  (the setting is per-tenant, so a target-less write is meaningless). Enforces the same
  gate activation enforces (`catThemeValidate()` + ARK-visibility) *before* writing, then
  **reads the value back** and fails if it does not match. A refusal or failed write can
  no longer be reported as success.
- **`theme:current [--tenant=]`** — reads the real per-tenant setting; with no tenant it
  lists every tenant's active theme instead of guessing.
- **`theme:deactivate --tenant=`** — clears the setting (empty ⇒ runtime falls back),
  verified by read-back.
- **`theme:list`** — reports active themes per tenant, replacing the misleading
  `(not available — CMS module not loaded)` line.

Writes go through the kernel's canonical `tenantWriteModuleSetting()` against
`app()->dbForTenant($id)` — the same storage path the capability uses.

## Tension to be aware of

The CLI writes the setting **directly**, so it does not run through
`akira.theme.activate@1` and therefore skips that capability's **audit row + idempotency
key**. This is deliberate and consistent with the existing operator CLI precedent
(`tenant:module:install` also acts directly via `ModuleInstallService` rather than the
capability bus), and the CLI states in its output that the audited path is the Theme
Studio at `/cms-akira-theme`. If CLI activation must be audited, the fix is a kernel
change to let CLI invoke module capabilities — `capability:call akira.theme.activate@1
--tenant=54 --with-modules` currently fails with `Capability not found`, because a bare
CLI bootstrap does not register module capability handlers.

## Related: `theme:validate` was not the activation gate

`theme:validate` ran its own checks and reported `✓ All checks passed` for a theme that
activation **rejected** (422). It now also runs the module's authoritative
`catThemeValidate()` and the ARK-renderability check. Verified by reintroducing the real
defect (missing `items` element schema on an `array` prop):

```
Activation gate
  ✗ block-definitions.json blocks[2] (card-grid) prop 'items': array items schema is required.
  ✗ 1 errors
```

Previously that theme reported `✓ All checks passed`.
