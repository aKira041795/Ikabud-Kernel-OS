# CMS Akira Builder — Admin UI (React + Vite + TypeScript)

Functional composition-editor MVP for the Akira Builder server composition authority
(`akira.builder.*@1`). It is mounted inside the authenticated `cms-akira-shell` admin shell at:

- `/cms-akira-shell/compositions` — compositions list + "attach to a post" picker
- `/cms-akira-shell/compositions/{key}/edit` — tree editor / save draft / validate / revisions /
  preview / publish / unpublish / delete

It talks only to the authenticated JSON capability bridge at
`/api/v1/cms-akira/builder/*` (see `../README.md`). React escapes rendered text by default;
server-rendered preview HTML is displayed inside a `sandbox`ed iframe via `srcDoc` — it is never
injected as live markup.

## Prerequisites

Only node 18 is present on this host (`v18.19.1`, npm `9.2.0`). Toolchain targets Vite `^5` +
React `^18` + TypeScript `^5` (Vite 6/7 require node 20+ and are intentionally not used).

## Build / run / verify

```bash
cd admin-ui
npm install        # first time; commits package-lock.json for reproducible npm ci
npm run type-check # tsc --noEmit over src/
npm run build      # vite build -> ../../../../public/admin/assets/cms-akira-builder
npm run dev        # optional local dev server (vite)
```

`npm run build` emits the production bundle under
`public/admin/assets/cms-akira-builder/assets/*` (JS entry `cms-akira-builder-admin.js` +
`index.css`) plus an inert `index.html`. The shell page globs `assets/*.js` and `assets/*.css` and
injects them as static `self` `<script type="module">` / `<link>` tags into
`#cms-akira-builder-root`.

## CSP posture

- Bundle is minified, sourcemaps disabled, no `new Function` / `eval` / `unsafe-eval` usage — it
  never requires `script-src 'unsafe-eval'`.
- Scripts/styles are static same-origin `self` assets (no nonces required).
- No inline executable script: the authorized bootstrap is a `text/json` element parsed at
  runtime, and all API input travels as JSON.
- Preview output is confined to an `<iframe sandbox="" srcDoc=…>` so scripts/styles cannot escape.

## Route map (consumed by this UI)

| Action | Method + path (against `apiBase`) |
| --- | --- |
| List compositions | `GET /compositions` |
| Get one + preview tree | `GET /compositions/{key}` |
| Revision history | `GET /compositions/{key}/revisions` |
| Render preview/published | `GET /compositions/{key}/render?source=preview|published` |
| Validate a tree | `POST /validate` |
| Create draft | `POST /compositions` |
| Save draft (base revision) | `POST /compositions/{key}` |
| Publish | `POST /compositions/{key}/publish` |
| Unpublish | `POST /compositions/{key}/unpublish` |
| Delete | `POST /compositions/{key}/delete` |

## Capabilities exercised

Reads: `akira.builder.compositions/get/revisions/render@1`.
Governed (protocol-v2, idempotency + durable audit):
`akira.builder.create/update/publish/unpublish/delete/validate@1`.
