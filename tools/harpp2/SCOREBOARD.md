# AKIRA CMS AUTONOMY RUN — scoreboard

Crude on purpose. One line per run, updated by the chair when an item lands. **The number that matters most is
Director interventions** — if items complete with zero, something important has changed.

| Measure | Count |
|---|---|
| Product items attempted | 5 |
| Product items completed | 4 |
| Product items verified | 4 |
| Chair self-replans | 2 |
| Worker retries | 3 |
| Harness faults caused by the chair | 3 |
| **Director interventions** | **1** |
| False completion claims | 0 |
| Incorrect Chair stops | 1 |

## Items

| # | Item | Result | Evidence |
|---|---|---|---|
| 1 | Page-cache replays its own `Content-Type` | **verified** | `tests/page_cache_content_type_test.php` 11/0/0 + live red→green (same ETag, `text/html` → `application/xml`) |
| 2 | Theme read authority reconciled with the shell-owned Theme Studio | **verified** | `theme_read_authority_test` **17/0** (was 10/6); shell owns `GET /cms-akira-theme` → `akira.shell.admin_page@1` |
| 3 | **A.1** malformed metadata renders nothing | **verified** | `tests/entity_view_malformed_metadata_test.php` **39/39**; both resolvers now `return []` on malformed and never substitute the allowlist |
| 4 | **A.2a** serialized payloads obey `action_payload_fields` / `editable_context_fields` | **verified** | `tests/entity_view_payload_projection_test.php` passes (re-run by the chair) |
| 5 | **A.2b** row context and row-click obey `template_fields` / `url_key_fields` | in flight | `tests/entity_view_context_projection_test.php` |

**Repo health after items 3–4:** `composer test` → **194 files — 141 passed, 53 skipped, 0 failed** (141 = 140 baseline
+ the new acceptance file). Both pre-existing red gates are now resolved: the stale census count (47 → 48, invariant
unchanged, the authorised P3.3 route being the 48th) and the theme read-authority seed.

## The walk

**Two items landed back-to-back with zero Director intervention** — A.1 verified 10:32, A.2a verified 10:55 — driven by
the chain: skip-if-verified → run → promote the executor on failure → resume the same item. That is the first evidence
of the property the director asked for.

## Notes

- **Director interventions: 1** — *"this is not progressing as I wanted it to be"*, which produced the objective
  re-target (item acceptance ≠ regression guards ≠ milestone completion). Before that the loop optimised for four green
  gates.
- **Worker retries: 3** — all from run 1, where the dispatched lanes exited **126** because `dispatch.sh` had no
  execute bit. A file mode, not a worker failure.
- **Harness faults caused by the chair: 3** — (1) tracking a run as a HARPP job made the away-mode guard refuse that
  run's own lanes; (2) a killed driver's children kept the `flock` fd, so the relaunch was refused while no process was
  alive; (3) the first chain stopped on `did not verify`, which is not one of the three legitimate stop conditions. All
  three corrected; the third is now a hand-off that promotes the executor and names the category.
- **Incorrect Chair stops: 1** — run 2's theme change was killed as "weakening a check"; the complete diff showed the
  opposite (10/6 → 17/0, invariant strengthened). The work was kept.
- **False completion claims: 0** — the gate-as-goal defect was caught before four green commands could print
  `objective verified` and declare Akira CMS complete.
