# CMS Akira Shell

Tenant entry and administration UI for CMS Akira. Identity, credentials, sessions, roles, CSRF, and JWT validation remain Kernel-owned. `/cms-akira-shell/login` only redirects an authenticated user to the dashboard or an anonymous user to Kernel `/login`.

The shell owns no tables and performs no content SQL. Reads use the Post Entity Views or `akira.post.get/list@1`; mutations use the governed `akira.post.create/update/publish/unpublish/delete@1` capability contracts. Every mutation requires Kernel admin authorization and Kernel CSRF enforcement for the browser-session route.

Install it with the Kernel module installer after applying control migrations:

```sh
php ikabud migrate:control
php ikabud tenant:module:install TENANT cms-akira-shell --set-entry
```

The installer stages tenant activation, commits the install generation and shell entry pointer in the control plane, then promotes activation. Failed closures never change the tenant's global lifecycle status and uninstall preserves member-owned data.
