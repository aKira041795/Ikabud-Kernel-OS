## What

Cycle 4 of the CMS Akira program: **governed provenance and time-travel** for compositions. Akira can now prove what it published, and render any state it has ever been in.

Cycle 4 was scoped against the substrate thesis (`docs/architecture/kernel-substrate-thesis.md`, separate PR): *authority over time* must read identically over a content revision and a ledger entry, so it is a substrate primitive rather than a CMS feature.

## Implementation

- **`akira.builder.provenance@1`** (new, tenant-scoped) — one timeline that unifies `cms_akira_composition_revisions` with existing `audit_logs` rows. **No parallel log table was introduced**; the audit trail remains the single source of truth for actions.
- **`akira.builder.render@1` accepts `revision_id`** — renders an explicitly owned historical revision. Unknown or foreign revisions **fail closed** rather than falling back to the current draft.
- **Semantic block-level diff** between any two revisions (`added` / `removed` / `reordered` / `properties changed`) — computed over the canonical JSON tree, not over rendered text.
- **Editor drawer** — "History & provenance" with per-entry actor, capability, timestamp and correlation ID, plus **"View as of rN"** which renders that revision into the preview iframe.
- Policy row for the new capability declares `allowed_roles = admin`, `requires_protocol = v2`; unauthorized callers are denied (asserted).

## Also included: `tests/_support/tenant_fixture.php`

Test infrastructure that this cycle's assertions depend on. Synthetic test tenants had no resolvable database configuration, so `moduleIsActive()` returned false, the capability bus correctly skipped the provider, and the builder contract test could not reach its assertions (`No permitted capability providers: akira.builder.validate@1`).

Root cause: `DatabaseManager::dbForTenant()` intentionally skips the request-tenant shortcut under CLI, so a tenant needs a connection row. The fixture creates a *shared-database* connection row in the control-plane's encrypted format, activates the module via `saveTenantModuleSettingsForTenant()`, asserts activation, and cleans up after itself.

This is test infrastructure only — no kernel or `src/` changes.

## Verification

Module contract test — **41 passed, 0 failed** (previously 11 passed, 1 failed; the new diff, foreign-revision and unauthorized-caller assertions were previously unreachable):

```
✓ render@1 renders an explicitly owned historical revision
✓ unknown revision render fails closed
✓ provenance unifies revisions/audits and returns a semantic known-pair diff
✓ unauthorized caller is denied the governed provenance read
```

Full suite — **103 files: 95 passed, 8 skipped, 0 failed**. All 8 skips are explicit and reasoned (6 are synthetic tenants with no resolvable DB configuration — now fixable with the fixture above; 2 are local environment limits: unwritable compiled cache, `TEST_TIMEOUT` too high). `storage/modules.json` backed up and restored around the run.

Live tenant 54 — login succeeded; the `t4a-page` timeline returned revisions 3–6 with publish/unpublish entries, actor `charlienacario884` and correlation IDs. Historical render of r4 differs from the current draft:

```
current revision 6: acec8e44ec19d7b1b6b5cd65803d0ba1c7bb1943a3b03c80f01b93e5a1c3750f
historical revision 4: 7d1de798b4ef37acabf8e0f630e4520f2a637133281d2049d7a5863673dbe0c0
```

Browser-verified end to end (the one gap the implementing agent could not close): the drawer opens, the timeline interleaves revision events with audit events carrying actor/capability/correlation, "View as of r4" renders the historical revision in the iframe (showing the old `quote` block), and the semantic diff reports correctly:

```
r4 → current draft
  added:   hero#1, card-grid#1
  removed: quote#1
  reordered: none
  Properties changed: none
```

Static: architecture checks 6/6, PHPStan clean, PHP CS Fixer clean, `tsc --noEmit` clean, build OK (bundle regenerated).

## Scope discipline

No schema changes, no migrations, no new npm dependencies, no changes to `kernel/` or `src/`. No commit to `main` until CI is green.
