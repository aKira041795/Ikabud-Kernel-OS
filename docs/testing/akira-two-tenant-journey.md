# CMS Akira R6 two-tenant HTTP journey

`scripts/akira-two-tenant-journey.sh` is the P0-exit/release acceptance gate. The editorial and isolation assertions use real HTTP only: host-based tenant resolution, Kernel login cookies, CSRF tokens parsed from rendered HTML, module routing, capability dispatch, workflow, and audit. The runner never injects tenant/request context. CLI and SQL are limited to fixture provisioning, durable-state assertions, and cleanup.

## Prerequisites

- Apache (or an equivalent server) serves this checkout for both tenant hosts.
- Both hosts resolve to the server. `curl --resolve` handles local resolution; the web server must accept both host names.
- MySQL credentials can create/drop the temporary database and are also valid for its tenant connection.
- Tenant A exists and has CMS Akira enabled. Defaults are `akiracms.test` and `charlienacario884`.
- `curl`, `php`, and the `mysql` client are installed.

Run:

```bash
RUN_TWO_TENANT_JOURNEY=1 \
JOURNEY_TENANT_A_PASS='<tenant-A-admin-password>' \
JOURNEY_TENANT_B_PASS='<temporary-admin-and-author-password>' \
JOURNEY_DB_USER=root JOURNEY_DB_PASS='<mysql-password>' \
bash scripts/akira-two-tenant-journey.sh
```

Optional variables include `JOURNEY_DB_HOST`, `JOURNEY_DB_PORT`, `JOURNEY_TENANT_A_HOST`, `JOURNEY_TENANT_A_ADMIN`, `JOURNEY_TENANT_B_HOST`, `JOURNEY_TENANT_B_KEY`, and `JOURNEY_TENANT_B_DB`. Set `JOURNEY_KEEP_TENANT_B=1` only when debugging.

## Provisioning and assertions

The exact sanctioned provisioning sequence is:

```text
php ikabud tenant:create <key> <host> --entry=cms-akira-shell
php ikabud tenant:db:set <id> --host=... --port=... --name=... --user=... --pass=...
php ikabud tenant:provision <id> --admin-user=... --admin-pass=... --admin-name=...
php ikabud tenant:module:install <id> cms-akira-profile-standard --set-entry
```

The temporary tenant's base `users.role` enum is then expanded with the same editorial roles used by the existing tenant-54 acceptance fixture, and an author is inserted with a `password_hash()` credential. This fixture-only schema change disappears when B's database is dropped; application migrations and persistent schemas are not changed.

The journey then:

1. Logs into B, renders the editor, and verifies output contains `_token` plus the named `akiraContentEditor()` Alpine component (and neither obsolete `_csrf_token` nor an inline body object).
2. Creates a post and follows `submit -> approve -> publish`, requiring HTTP 303 responses.
3. Attempts `approve` as an author and requires HTTP 422.
4. Replays the identical publish request and verifies SQL state is `published/published` with exactly one publish transition log and one publish audit row.
5. Creates a temporary A marker and verifies A cannot read or mutate B's post, and B cannot read or mutate A's marker (404 reads, 422 writes).

A trap drops B's database and removes B's control-plane tenant/domain/connection rows. The A marker is deleted through governed HTTP. On interruption, the same trap runs. CI and developer runs are a no-op unless `RUN_TWO_TENANT_JOURNEY=1`; infrastructure-capable release CI should provide the secrets and flag.
