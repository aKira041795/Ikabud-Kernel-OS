# CMS Akira Shell

Tenant entry and administration UI for CMS Akira. Identity, credentials, sessions, roles, CSRF, and JWT validation remain Kernel-owned. `/cms-akira-shell/login` only redirects an authenticated user to the dashboard or an anonymous user to Kernel `/login`.

Dashboard and Posts admit roles derived from the Akira Post workflow transitions; per-post buttons come only from workflow `evaluate()` results. Compositions, module health, and delete remain administrator-only surfaces. The tenant 54 author is an explicit test fixture (`database/seeds/tenant_54_akira_author.php`), not a provisioning default; governed content-role seeding belongs in P3.

The shell owns no tables and performs no content SQL. Reads use the Post Entity Views or `akira.post.get/list@1`; create, update, and delete use their governed Post capabilities. Publication is exclusively workflow-driven: the editor evaluates `akira.workflow.evaluate@1`, renders the current state and role-allowed actions, and submits lifecycle changes through `akira.workflow.transition@1`. Every mutation requires Kernel admin authorization and Kernel CSRF enforcement for the browser-session route.

Install it with the Kernel module installer after applying control migrations:

```sh
php ikabud migrate:control
php ikabud tenant:module:install TENANT cms-akira-shell --set-entry
```

The installer stages tenant activation, commits the install generation and shell entry pointer in the control plane, then promotes activation. Failed closures never change the tenant's global lifecycle status and uninstall preserves member-owned data.
