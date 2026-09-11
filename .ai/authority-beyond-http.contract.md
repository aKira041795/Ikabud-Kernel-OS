# CONTRACT — Inventory non-HTTP authority (complete the P2 primitive)

status: READY_FOR_IMPLEMENTATION
owner: implementation agent (Sol, via Pi)
repo: `/var/www/html/ikabudsix` — branch: `feat/authority-beyond-http`
chair: this session · authority: continues PR #119 (merged `fb08824`); P2 per
`docs/architecture/akira-beyond-the-cms.md`

Read first: `docs/architecture/akira-beyond-the-cms.md` §P2 (especially the shipped-C6 note and
*"Not yet covered, and not claimed: scheduled jobs, event handlers, CLI handlers and other direct callables
remain outside this inventory"*), `docs/architecture/authority-store-adr.md`, and
`.ai/c5-governance-census.contract.md` (the contract that built the HTTP census).

## Why this slice, now

The P2 primitive is *"the instrument reports the ungoverned remainder per module; the release gate refuses
to let it grow."* PR #119 wired 16 non-HTTP entry points to an explicit authority scope — and the instrument
**cannot see a single one of them**. The doc itself frames the gap as an *inventory* gap, so this is the
next honest increment: we wired them, so now measure them.

It also needs no schema change and no product decision, which is why it is being done ahead of the
tenant-ownership questions for the job queue and workflow runs.

## Verified facts — do not re-derive

- `GovernanceCensus::scanAll()` globs `modules/*/module.json` and `modules/*/*/module.json`.
- `scanManifest()` reads `$manifest['routes']` → a static PHP route file → `parseRoutes()`, and builds
  `operations` from **routes only**. Its own rule text says: *"Ratio denominator: routed business operations
  (POST/PUT/PATCH/DELETE); auth/session infrastructure is excluded."*
- So the census is HTTP-route-only **by construction**. Event handlers, workflow steps, CLI commands,
  Workbench commands, service and worker paths are invisible.
- The gate's frozen debt lives in `.governance-baseline.json` (tracked):
  `{"rule": "Frozen undeclared-route debt. ...", "baseline": {"<module>": <undeclaredCount>, ...}}`.
- The command is `case 'workbench:governance':` in `ikabud` (~line 6268); help text at ~line 1173 reads
  *"Census routed-operation authority"*.
- The census already does intra-module PHP token/call analysis (`reachesBus($handler, $functions)`), so the
  machinery for static analysis exists — reuse it, do not build a second analyser.

## Deliverables

### A1 — A non-HTTP authority census, reported separately

Enumerate, per module, capability call sites that are **not** HTTP route handlers: event/trigger handlers,
workflow step handlers, CLI command paths, Workbench commands, service and worker paths.

For each, report the same two independent facts the HTTP census reports, plus the transport:

- `transport` — which entry point: `event` | `workflow` | `cli` | `workbench` | `service` | `worker`
- `scope` — `declared` (runs inside an authority scope the entry point established) | `unscoped`
  (reaches the bus with no scope of its own) | `unresolved` (cannot be traced statically)
- `source` — `file:line`, or `unresolved`

**Report it as its own number. Never merge it into the routed ratio.** C6 established that rule for
dispatch vs reach; the same discipline applies here, because a non-HTTP callable is not a routed operation.

### A2 — Discovery must be static and honest

- Static analysis only: manifests + PHP token analysis. **No DB, no network, no runtime side effects.**
- Do **not** invent a declaration format that modules must adopt. This slice measures; it does not add a
  new manifest requirement. (A declaration schema for non-HTTP authority is a later, separable decision.)
- Where you cannot decide statically — dynamically named capabilities, a capability id held in a variable,
  indirection through a variable callable, `$options['provider']` routing — report `unresolved` and **count
  it as unresolved, not as governed and not as debt**. Say how many, and what defeats the analysis.

### A3 — Gate it, without weakening the existing gate

- Extend `.governance-baseline.json` with a **second** frozen map for non-HTTP debt, under its own rule
  text. Keep the existing routed map and its meaning **byte-for-byte intact** — do not rename, reorder or
  reinterpret it.
- `--gate` must fail if **either** map grows. A module whose non-HTTP unscoped count rises fails.
- `--update-baseline` writes both maps.
- `--json` must include both, clearly keyed.
- The existing HTTP output must not change for a module with no non-HTTP callables.

### A4 — Publish the honest number

Update the help text (currently "Census routed-operation authority") so it describes both axes, and report
in your result: the measured non-HTTP ratio per module, the unresolved count, and **exactly what the
instrument still cannot see**. Do not claim coverage you did not implement.

## Constraints

- **No schema changes, no migrations, no migrations registered, no change to authority behaviour.** This
  slice is measurement only. If measuring exposes a defect, report it — do not fix it here.
- Do not change `authorize()`, the read-path denial semantics, `AuthorityScopeResolver`, or the entry-point
  wiring from #119.
- MySQL 5.7 safe. No new dependencies.
- Do not touch `modules/daily-ledger/**` (untracked, live money domain). It will appear in the census output;
  that is fine and expected. Do not edit it.
- Do **not** modify `tests/admin_platform_api_test.php`, `tests/workflow_concurrency_test.php` or
  `tests/authority_scope_test.php`.
- Do not commit, push or branch.
- Back up `storage/modules.json` before `composer test` and restore its ownership/permissions
  (`kajagogoo:www-data`, mode 666). It is gitignored, so a missing file is not itself a failure.

## Acceptance

1. `php ikabud workbench:governance --all` prints **both** the routed ratio and the non-HTTP breakdown, and
   the routed numbers are **identical** to before this slice. Paste both.
2. A test proves the census finds at least one real non-HTTP call site per transport you claim to support,
   built from a fixture module — not from the product modules, so the test does not rot.
3. A falsifier for the gate: raising a module's non-HTTP unscoped count above the baseline makes `--gate`
   fail; the same module at or below baseline passes. State the falsifier and show both outcomes.
4. `--update-baseline` writes both maps and `.governance-baseline.json` still parses with the original routed
   map unchanged. Show a diff of the file.
5. The unresolved count is reported, with the reason analysis fails for those sites.
6. `composer test` `0 failed` with the `Total:` line. **State which tests skip locally** — several
   tenant-dependent tests skip here and run in CI, so a local green does not prove their paths.
7. `php ikabud architecture:check` pass, PHPStan 0 errors in `kernel/`+`src/`+`tests/` with no baseline
   drift, php-cs-fixer clean.
8. Live tenant 54 on `akiracms.test`: `/`, `/posts`, `/login` all 200, `error.log` empty.
9. An explicit, short list of what the instrument still cannot see.

## Deliverable

Result block: status, changed, implementation summary, verification, evidence, scope, risks, unresolved,
recommended next state. If a transport or case cannot be inventoried reliably, say so and report it as
unresolved rather than widening the definition of "governed" to make the number look better.
