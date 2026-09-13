Your binding task contract is `.ai/media-delete-request.contract.md` in the Ikabud Kernel OS at
/var/www/html/ikabudsix. Create branch `feat/media-delete-request` from current `main` (cf830bb). Read
the contract IN FULL first, then read every file it cites before editing.

Why you: this adds a state to a table that live pages read, and it touches the authorization surface,
so the chair is delegating it to the precision lane.

The slice in one sentence: contributor roles may REQUEST a media deletion; only an admin EXECUTES it.
Today deletion is admin-only end-to-end, so the initiation half does not exist at all.

Four things are load-bearing and easy to get subtly wrong:

1. NOTHING IS DESTROYED AT REQUEST TIME. The `DELETE FROM cms_akira_media` and the file unlink stay
   exclusively on the admin step (`camMediaMutateDelete` / `camMediaRemoveFile`). If a request removes
   a row or a file, the slice is wrong no matter how green the tests look.

2. NOT A LYING CAPABILITY. Do not make `akira.media.delete@1` "sometimes request, sometimes delete"
   depending on the caller's role. Separate capabilities: `akira.media.delete.request@1` and
   `akira.media.delete.cancel@1` for the drafting set, `akira.media.delete@1` stays admin-only and
   stays the only destructive path. This codebase just spent a PR fixing a surface that admitted a
   role the capability refused - do not recreate that class of defect.

3. PENDING MUST NOT BREAK RENDERING. `akira.media.resolve@1` and its projection are UNCHANGED, and the
   media test asserts their exact keys. Pending items stay in the library with a badge and keep
   rendering in published content. Hiding them or failing resolution before approval is the one
   outcome that must not happen. `library`/`get` may gain the new fields (that is an explicit,
   intended spec change to their exact-key assertions - call it out in the commit message).

4. `cancel` needs to mean "your own request". Policy alone cannot express that, so the capability
   takes the contribution set and enforces internally: the original requester or an admin role.
   A different non-admin must be refused (A4).

D4 in the contract is a deliberate deferral, not an oversight: the owner asked for a reference count
so the admin sees what uses the media. It cannot be built here, because `cms-akira-media` declares
`reads_tables: [cms_akira_media]` only and counting references would need cross-module reads of
`cms_akira_posts`/`cms_akira_compositions`, which the module-boundary rules forbid and
`workbench:governance` measures as violations. State the irreversible consequence in the approval UI
in words, and REPORT the reference count as a named follow-up. Do not attempt it.

Migration: follow `cms-akira-core/database/migrations/003_add_posts_deleted_at.sql` exactly - an
`information_schema.columns` probe plus PREPARE/EXECUTE/DEALLOCATE. MySQL 5.7-safe: no CHECK, no
window functions, no CTEs. Columns are additive and nullable. Register the migration in module.json.

Both new capabilities need `requires_protocol: v2` and `effects.invalidates: [entity.list.media]`,
and must be seeded in `camMediaMutationPolicyRows`-style code with the contribution set, with `delete`
remaining `admin`.

Run all acceptance checks A1-A10. Report passed/failed/skipped for both runners and say EXPLICITLY
whether `media_contract_test` and `shell_contract_test` RAN or SKIPPED - a skip is not a pass. To make
the media test actually run you must give it a writable fragment root, using the same-filesystem
rename in the contract; restore the original `www-data:www-data 2755` directory and say that you did.

Do not touch kernel/, phpstan.neon, phpstan-baseline.neon, or .governance-baseline.json. Do not weaken
an existing assertion. If you discover the contract cannot be satisfied within this file list, STOP
and return ARCHITECTURE_DECISION_REQUIRED with the evidence rather than widening scope silently.
Return the exact result block from the contract.
