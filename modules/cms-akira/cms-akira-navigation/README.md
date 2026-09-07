# CMS Akira Navigation

Native, tenant-installable navigation for CMS Akira. This member owns
`cms_akira_menus` and `cms_akira_menu_items`; it does not read legacy CMS tables
or another Akira member's tables.

## Contracts

Public reads:

- `akira.navigation.menus@1` — explicit menu projections.
- `akira.navigation.tree@1` — one menu and its deterministic ordered tree.
- `akira.navigation.resolve@1` — location-to-tree resolution.

Admin mutations are `akira.navigation.menu.create/update/delete@1` and
`akira.navigation.item.create/update/delete@1`. Every mutation requires protocol
v2, Kernel admin identity, Kernel idempotency, durable audit in the same
application-PDO transaction, and invalidates the single canonical
`entity.list.navigation-menu` tag after success. Tenant identity always comes
from Kernel context, never payload fields.

Items contain either a validated local/HTTP(S) URL or the stable Akira content
reference `{type: post, key: <canonical-slug>}`. References are deliberately not
SQL foreign keys. Menu/item/parent foreign keys use this member's stable ASCII
entity keys and cannot cross tenant or menu scope. Trees fail closed on orphan,
cycle, duplicate-key, depth, or stored-depth corruption; the mutation depth
limit is 8.

## Persistence

`database/migrations/001_initial.sql` is MySQL 5.7 compatible, uses InnoDB and
`utf8mb4_unicode_ci`, and has no JSON or MySQL-8-only syntax. The 190-character
slug/location columns keep `(tenant_id, value)` unique indexes at 764 bytes
under utf8mb4 (4 + 190×4), below the 767-byte conservative composite-key budget.
The Kernel migration ledger remains authoritative; `IF NOT EXISTS` also makes
an interrupted DDL rerun safe. Shared-schema access always has `tenant_id`
predicates. Dedicated tenants use the Kernel-selected tenant PDO and must pass
the Kernel base-database rejection guard.

The manifest stays `_enabled:false`; installation is explicit through the
module-install service after `cms-akira-core`.

## Verification

```bash
php modules/cms-akira/cms-akira-navigation/tests/navigation_contract_test.php
php ikabud module:certify cms-akira-navigation
```
