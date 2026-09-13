# Contract — two-phase media deletion: contributors request, admin finalises

status: READY_FOR_IMPLEMENTATION
role: /architect
authority: chair, 2026-09-12 (owner unavailable; decisions recorded and reversible)

---

## Owner's instruction

> delete is purely admin scoped function. while other roles can run delete but final deletion
> remains with the admin.

Read as: **final deletion is administratively scoped; other roles may initiate it.** Today media
deletion is admin-only end to end, so non-admins have no path at all. This contract adds the missing
initiation half without moving the destructive half.

## Decisions taken by the chair (owner unavailable — all reversible)

**D1 — Two-phase, with separate capabilities.** Drafting roles *request*; an admin *executes*.
Deliberately **not** one capability that silently does nothing for non-admins — a capability that
appears to delete but doesn't is a lying surface, the exact defect this codebase just had fixed.

**D2 — Pending media stays visible and keeps rendering.** Pending rows remain in the library with a
badge and `akira.media.resolve@1` is **unchanged**, so published pages keep working until an admin
approves. Hiding or breaking live content before approval was rejected.

**D3 — Nothing is destroyed at request time.** The row `DELETE` and the file `unlink` stay on the
admin step only. Otherwise "requesting" is already the destructive act and approval is theatre.

**D4 — Computed reference counts are DEFERRED, deliberately.** The owner asked to warn the admin with
reference information. It cannot be done correctly inside this slice: `cms-akira-media` declares
`reads_tables: [cms_akira_media]` only, so counting references in `cms_akira_posts`
(`image`, `content`) or `cms_akira_compositions` would require cross-module table reads — which
`workbench:governance` measures as violations and which the module-boundary rules forbid.
Instead the approval surface states the irreversible consequence in words. A reference count needs
its own slice with a proper cross-module capability. **Report this as a named follow-up; do not
attempt it here.**

---

## scope

### allowed

1. `modules/cms-akira/cms-akira-media/database/migrations/003_add_delete_requests.sql` (new)
2. `modules/cms-akira/cms-akira-media/module.json`
3. `modules/cms-akira/cms-akira-media/helpers.php`
4. `modules/cms-akira/cms-akira-media/tests/media_contract_test.php`
5. `modules/cms-akira/cms-akira-shell/module.json`
6. `modules/cms-akira/cms-akira-shell/routes.php`
7. `modules/cms-akira/cms-akira-shell/handlers.php`
8. `modules/cms-akira/cms-akira-shell/helpers.php`
9. `modules/cms-akira/cms-akira-shell/tests/shell_contract_test.php`
10. `docs/architecture/akira-beyond-the-cms.md`

### constraints

- **`akira.media.delete@1` stays `allowed_roles = admin` and remains the ONLY destructive path.**
  No new code path may let a non-admin remove a row or unlink a file.
- New capabilities: `akira.media.delete.request@1` and `akira.media.delete.cancel@1`, both seeded
  with the contribution set (`contributor,author,editor,admin,administrator,superadmin`), both
  `requires_protocol: v2` with `effects.invalidates: [entity.list.media]`.
  `cancel` additionally requires an internal check: **the original requester or an admin role** —
  policy alone cannot express "your own request".
- Migration must follow the established idempotent ALTER pattern in
  `cms-akira-core/database/migrations/003_add_posts_deleted_at.sql`
  (`information_schema.columns` probe + `PREPARE`/`EXECUTE`/`DEALLOCATE`), MySQL 5.7-safe.
  Columns (all nullable): `delete_requested_at DATETIME NULL`,
  `delete_requested_by INT UNSIGNED NULL`, `delete_request_reason VARCHAR(255) NULL`.
  No `CHECK` constraints, no window functions, no CTEs.
- `akira.media.resolve@1` and its projection MUST NOT change. Pending state must not affect
  rendering.
- Mutations must go through the existing `camMediaMutate()` path (kernel idempotency + durable audit
  + single tenant transaction). Use `expected_updated_at` optimistic concurrency like the existing
  delete.
- Request must be idempotent: requesting an already-pending item is a success, not an error, and
  must not overwrite the original requester or timestamp.
- Do not weaken any existing assertion. Updating an exact-key projection assertion to include the
  new intentional fields is a spec change and must be explicit in the commit message.
- Do not touch `kernel/`, `phpstan.neon`, `phpstan-baseline.neon`, `.governance-baseline.json`.

---

## acceptance

| # | Requirement |
|---|---|
| A1 | Author requests deletion → `delete_requested_at`/`_by` set; **the row still exists and the file still exists**; `akira.media.resolve@1` still returns the projection |
| A2 | Admin executes `akira.media.delete@1` on a pending item → row and file are both removed |
| A3 | Author calling `akira.media.delete@1` → refused (403); row and file untouched |
| A4 | Requester cancels own request → pending cleared. A *different* non-admin cancelling → refused. Admin cancelling → allowed |
| A5 | Second request on an already-pending item → success, original requester/timestamp preserved |
| A6 | Seeded policy: `request`/`cancel` = contribution set; `delete` = `admin` only (assert the values) |
| A7 | `library`/`get` expose the pending fields; `resolve` projection keys are **unchanged** |
| A8 | Request performs no delete: assert row count and file presence before/after |
| A9 | Shell: contributor sees *Request deletion* and no *Delete*; pending rows show a badge; admin sees *Delete* plus *Approve*/*Cancel* on pending rows |
| A10 | No `kernel/` change; `php ikabud workbench:governance --all --gate` exit 0; baseline untouched |

## verification

```
php -l <every touched PHP file>
composer test
php scripts/run-tests.php
php ikabud workbench:governance --all --gate
```

Report **passed / failed / skipped** and say explicitly whether `media_contract_test` and
`shell_contract_test` RAN or SKIPPED. A skip is not a pass.

`media_contract_test` needs a writable `storage/cache/disyl-fragments`, which the dev user cannot
write. To make it run: rename the directory within the same filesystem (parent write is enough),
then create your own — restore the original `www-data:www-data 2755` afterwards and say so:

```bash
HOLD=storage/cache/.disyl-fragments-hold
mv storage/cache/disyl-fragments "$HOLD" && mkdir -p storage/cache/disyl-fragments
php modules/cms-akira/cms-akira-media/tests/media_contract_test.php
rm -rf storage/cache/disyl-fragments && mv "$HOLD" storage/cache/disyl-fragments
```

## e2e_acceptance

The chair verifies over real HTTP on tenant 54, not from the result block:

1. author requests deletion of an existing image → badge appears, image still renders
2. author's own `POST …/delete` → 403, row and file still present
3. admin approves → row and file gone

## risk

- Schema change on a table live pages read: additive nullable columns only, no data rewrite.
- New authority for `request`/`cancel`: bounded to the drafting set, and neither can destroy
  anything.
- Pending items could accumulate if never actioned — acceptable and visible by design; a queue view
  is a follow-up.

## status

READY_FOR_IMPLEMENTATION
