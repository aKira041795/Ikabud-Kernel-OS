# Review — HARPP and the HARNESS · 2026-09-16

Chair's review, written at the director's request. Every claim below carries the command that produced it. Where a
number is unverified, it says so.

---

## Verdict

The harness **works and has produced two verified product fixes today**, but it has not felt like progress because
**I made the wrong thing the goal**: the objective I wrote turned four repo-health gates into the definition of
"done", so the loop optimised for green gates rather than for the Akira plan. The engineering is sound; the target was
mis-specified, by me, and I compounded it by killing a working run on a partial reading of a diff.

---

## 1. HARPP (away mode)

| Measure | Value | Source |
|---|---|---|
| Service | `https://harpp.ikabudkernel.com`, tenant 212 — **reachable** | `harpp check` → `OK` |
| Bridge on the live path | `applicationostest/tools/harpp-bridge` — the **older** copy | `harpp` symlink, watch daemon pid 1985 |
| Evidence enforcement on that copy | **absent** (0 markers; the ikabudsix copy has 34 and passes 262 self-tests) | `grep -c is_constant_true_verify\|negative_control` |
| Workflows ever | 20 → 7 `done`, 8 `blocked`, 5 `failed`; **nothing since 2026-09-02** | `harpp workflow list` |
| Jobs ever | 100, all `finished`; **62 used a verifier that cannot fail** (`verify: "true"` or none) | `harpp job list` |
| Workspaces driven | applicationostest 66 · /var/www/html/harpp 22 · legacy 4 · **ikabudsix 0** | `harpp job list` |
| Workspace config | `workspace = /var/www/html/applicationostest` | `harpp config get workspace` |

**So away mode is not currently capable of completing a project**, for two measurable reasons: its gate accepts
verifiers that cannot fail, and it is pointed at a different workspace than the project. Both are one-line fixes on the
director's own surface (`harpp config set workspace`, the `PATH` symlink, a daemon restart) — not mine to make.

## 2. HARNESS (at the desk, v2)

| Component | State |
|---|---|
| `tools/harpp2/CONSTITUTION.md` | one requirement, five primitives, **two modes with "owner in the loop? no/no"**, exactly three stop conditions |
| `tools/harpp2/dispatch.sh` | executable; pty-safe dispatch; **one-writer guard**; away-mode check; journal |
| `tools/harpp2/harpp2.php` | 28 KB driver: gates → chunk → dispatch → observe → verify → journal; `--max-stalls`; stall → re-dispatch; escalation file |
| Objective | `tools/harpp2/objectives/akira-cms.md` — **mis-specified, see §4.1** |
| v1 | frozen (`.ai/HARPP-V1-FROZEN.md`); no new doctrine, no dispatches through its ledger |
| Run notification | run tracked as HARPP job `9b2b69c81386` → completion reported, not noticed |

**Proven, not asserted:** the loop ran red→green end to end (`[exit 1]` before the artifact, `[exit 0]` after,
`[harpp2] objective verified`); **two boundary falsifications** — `outside objective scope` → exit 4, and
`data/file destroyed` → exit 4; a **live second dispatch was refused** (`exit=3`) while a lane held the lock; the
stall rule is in the code and journals `{"event":"stall","result":"redispatch"}`.

## 3. What today actually produced

**Product — verified by me, by re-running, not by reading a report:**

1. **Page-cache `Content-Type`** (CD-56 F2): `pageCacheResolveContentType()` + pure `pageCacheServeHeaders()`.
   Test **11 passed / 0 failed / 0 skipped**; live red→green on the same cached entry (same ETag,
   `text/html` → `application/xml`), then invalidated and left clean.
2. **Theme read authority reconciled with the shell-owned Theme Studio** (the change I wrongly killed):
   `theme_read_authority_test` **17 passed / 0 failed** (was **10 / 6**), and the shell now owns
   `GET /cms-akira-theme` → `akira.shell.admin_page@1`. The rewritten test *strengthens* the invariant — it still
   forbids a policy version pinned to `1`.

**Repo health, measured after both:** `composer test` → **`Total: 193 files — 140 passed, 53 skipped`, 0 failed.**

**Harness:** v1 frozen · v2 constitution, dispatcher, driver, objective · 9 chair decisions · 1 module review (33 KB)
· 1 escalation artifact · the `harpp` module copied in as-is (97 files) and its `reset_url` log leak fixed.

**Scoreboard, honestly: 2 product fixes, 1 module port, ~1 harness subsystem, 9 decisions.** The ratio is the
director's complaint, and it is a fair one.

## 4. Why it has not felt like progress

**4.1 The objective made gates the goal — my specification error.** The driver reads the objective's acceptance
commands as the *definition of done*. I listed four repo-health gates. So: (a) the loop's "next action" became *find a
route to an acceptance failure* — its own words — rather than *advance the plan*; (b) once those gates go green the
driver prints `objective verified`, which would have been **a false claim that Akira CMS is complete**. I mitigated (b)
with prose in the objective; the driver does not read prose to decide. Prose was the wrong instrument.

**4.2 I killed a working run on a partial reading — chair error, and the expensive one.** I saw removed lines in a
test (`grep "^-"`) and concluded "weakening a check to obtain a pass". Reading the diff afterwards shows the opposite:
it removed the theme's own `/cms-akira-theme` route *because CD-58/59 moved that page into the shell*, and rewrote the
assertion to require the shell's declaration. The removal was correct; my stop was wrong. The rule I broke is one I
wrote down twice today: **read the artifact before judging it.**

**4.3 The gate is order-dependent, so two of my measurements were taken on a transient state.** The twenty
`modules/harpp/tests/*` files reported `[FAIL]` on the first suite run after the copy and report
`[SKIP] tenant 1 has no resolvable database configuration` on later runs — because `scripts/run-tests.php` unlinks
`storage/modules.json` as a side effect, changing what those tests resolve. My "my copy added 20 failures" was measured
in the first window; the reproducible state is **SKIP**. A check whose verdict depends on how many times you have run
it is not a check.

**4.4 The chair's attention followed the apparatus, not the product.** Nine decisions this session; the first
instinct on "HARPP and the harness must be in sync" was a coordination model, and the first instinct on a red test was
to suspect the test. Both were meta-work.

## 5. Decisions taken now (not questions)

**A — Re-target the objective: one bounded plan item per run, gates demoted to regression guards.** The acceptance
command becomes the item's own deterministic check; the four gates must **not regress** rather than become green.
Umbrella completion ("Milestone 1 is done") is judged against the plan by the chair, never by four commands.

**B — Resume on product, not on gates.** The next run's first item is a plan item, not a gate repair.

**C — No further harness decisions today.** The next artifact from this desk is a product chunk with evidence.

## 6. Residual risks and unknowns

- **The order-dependent gate (§4.3)** is a real defect in the apparatus and needs its own fix; until then, suite
  numbers are only comparable within one registry state.
- **The 20 harpp tests SKIP**, which proves nothing about that module's DB behaviour (module review §6 said the same,
  with a different count — both are "unverified", neither is a pass).
- **Away mode remains pointed at the wrong workspace with a gate that cannot fail.** Until the director aligns it,
  HARPP cannot complete this project while he is away.
- **The objective's umbrella ("complete Milestone 1") has no mechanical completion test.** Successive bounded runs are
  the honest mechanism available today; a real one would need the driver to understand "remaining work", which does not
  exist yet and will not be invented until a run is blocked by its absence.
