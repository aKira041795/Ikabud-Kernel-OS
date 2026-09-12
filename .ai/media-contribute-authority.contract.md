# Contract — Media contribute authority follows the drafting/publishing role set

status: READY_FOR_IMPLEMENTATION
role: /architect
authority: product-owner decision, 2026-09-12

---

## task

Owner decision, verbatim:

> for Akira or any CMS in the future, admin and authors and any role that has drafting or publishing
> permissions can upload images, attachments and etc

Today every `cms-akira-media` capability except `akira.media.resolve@1` is seeded `allowed_roles: 'admin'`
(`modules/cms-akira/cms-akira-media/helpers.php`, `camSeedMediaMutationPolicies()` and
`camSeedMediaReadPolicies()`). An `author` cannot upload, and cannot even see the media library.

## objective

Make a role's authority to **contribute media** follow the role set that already governs **drafting and
publishing content** — in Akira, and as a stated convention for any future CMS on this kernel.

---

## scope

### allowed

1. `modules/cms-akira/cms-akira-media/helpers.php`
2. `modules/cms-akira/cms-akira-core/helpers/governance.php` (provider filter only — see D5)
3. `modules/cms-akira/cms-akira-shell/module.json` (Media nav entry `roles` only)
4. `modules/cms-akira/cms-akira-media/tests/media_contract_test.php`
5. `docs/architecture/akira-beyond-the-cms.md` (record the convention)

### constraints

- **Do not change `akira.media.delete@1`.** Widening destructive authority was not requested; a role that
  can upload should not be able to delete media referenced by published content. It stays `admin`.
  Report this as an explicit decision the owner may reverse.
- **Do not change `akira.media.resolve@1`.** It is the public render path and is deliberately ungoverned.
- **Do not weaken `CapabilityAuthorizationRegistry::seedPolicy()`'s widening refusal.** It must still refuse
  a wider `allowed_roles` declaration on an existing `granted` row. Any test asserting that must still pass.
- **Engine-first**: no template bandaid. If the permissions page cannot render a media row, fix the cause
  (D5), not the row.
- Never edit the template/manifest to dodge a handler problem.
- Never edit `.governance-baseline.json`.
- **No new dependency edge.** `cms-akira-media` depends on `cms-akira-core` only; do not add a dependency
  on `cms-akira-workflow`.
- Keep the diff bounded. Do not refactor unrelated seeded policies.

---

## the canonical role set

It already exists; do not invent a fourth copy of the concept.

- Post drafting (`akira.post.create@1`, `akira.post.update@1`) and the draft-capable admin reads
  (`akira.post.admin.get@1`, `akira.post.admin.list@1`) are seeded
  `'contributor,author,editor,admin,administrator,superadmin'` —
  `modules/cms-akira/cms-akira-core/helpers.php:32-33,73`.
- `cawPostLifecycleParticipantRoles()` (`modules/cms-akira/cms-akira-workflow/helpers.php:59`) **derives**
  exactly that set from the workflow transitions. It is the authoritative definition of "has drafting or
  publishing permission".

So the required set is:

```
contributor, author, editor, admin, administrator, superadmin
```

`cms-akira-media` must not depend on `cms-akira-workflow`. Use the guarded-call precedent already in this
codebase — `modules/cms-akira/cms-akira-shell/helpers.php:146`:

```php
$roles = function_exists('cawPostLifecycleParticipantRoles') ? cawPostLifecycleParticipantRoles() : [];
```

Wrap the derived set in **one** named helper in `cms-akira-media` so the ordered CSV is produced in a single
place, with an explicit `contributor,author,editor,admin,administrator,superadmin` fallback for load orders
where the workflow module's helpers are not yet loaded. Sort/normalise deterministically — the seeded string
must be byte-stable, because `seedPolicy()` compares stored vs declared text.

---

## acceptance

### A1 — Seeded mutation policies

| capability | required `allowed_roles` |
|---|---|
| `akira.media.upload@1` | drafting set |
| `akira.media.update@1` | drafting set |
| `akira.media.delete@1` | `admin` — **unchanged** |

`camSeedMediaMutationPolicies()` currently loops three identical rows; restructure it per-capability the way
`cacSeedPostMutationPolicies()` already does. The seeded set must contain at least one administrator role.

### A2 — Seeded read policies

`akira.media.library@1` and `akira.media.get@1` → drafting set.

Rationale to state in the code comment: an uploader who cannot list the library cannot manage what they
uploaded. Widening upload while leaving the library `admin`-only would be incoherent, and the owner's
decision is not satisfied by it.

### A3 — Media nav entry

`modules/cms-akira/cms-akira-shell/module.json`, the Media nav entry's `roles` → drafting set, matching the
Dashboard/Posts/Categories entries. Otherwise an author has upload authority and no way to reach it.

### A4 — The decision is operable and auditable

`akira.policy.list@1` (`cac_cap_akira_policy_list_1`, `cms-akira-core/helpers/governance.php:102-118`) filters
rows to providers `['cms-akira-core','cms-akira-workflow']`. **Media rows are therefore invisible in the
Permissions surface**, and no operator can see or change them. Widen the filter so every `akira.*` row is
listed. Keep the provider values rendered (the page already prints `provider · caller`).

`cac_cap_akira_policy_set_roles_1()` must then be able to widen a **media** row through the sanctioned
`replaceActiveRowRoles()` path (it requires an existing transaction and an authenticated actor). Prove it,
do not assume it: media rows store `caller_module` as `null`, and the page posts `caller_module` as a hidden
string field.

### A5 — No silent widening (guard intact)

Prove the guard still holds:

- declaring a **wider** `allowed_roles` on an existing `granted` row is still refused
  (`widening_refused` / `operator_regrant_required`);
- declaring a **narrower** set still applies.

New defaults apply to **fresh** installs (no existing row). An existing tenant is widened deliberately by an
operator through the Permissions surface — audited, actor-attributed, with a reason. **Do not** add a
migration, seeder, or CLI that widens an existing granted row automatically.

### A6 — Environment independence

Per PR #135: a test must derive from the state it actually asserts on, never from ambient data.

- No hard-coded tenant ids.
- No assertion calibrated to a specific existing tenant's row counts.
- Any test that genuinely cannot run must print `SKIP: <precise reason>` and exit 0
  (`tests/_support/env_guard.php`).
- **Required proof:** back up the policy table, delete every row, run the real suite
  (`php scripts/run-tests.php`), confirm exit 0, restore. Report the observed result.

### A7 — No assertion weakened

`modules/cms-akira/cms-akira-media/tests/media_contract_test.php` must still pass, and every existing
assertion must remain. Update assertions only where this contract changes the specified value.

### A8 — Gates

```
php -l                          every touched PHP file
composer test
php scripts/run-tests.php
php ikabud workbench:governance --all --gate      # must stay exit 0, baseline untouched
```

---

## measurement to report (do NOT silently fix)

`camSeedMediaMutationPolicies()` seeds with a **fixed** `'policy_version' => 1`, while
`camSeedMediaReadPolicies()` deliberately joins the **active** version, because (its own docblock says) the
permissions surface can clone version 1 and a fixed-version seed would then leave declared routes without
authority.

Measure and report: for a tenant whose active policy version is > 1, do the mutation rows seeded by
`camSeedMediaMutationPolicies()` land in an inert version? If yes, report it as a **finding with evidence**.
Fix it only if it prevents A1–A4 from being satisfied. Do not fold an unrelated repair into this slice
without saying so.

---

## e2e_acceptance

The chair will independently verify over real HTTP, not from this result block:

1. sign in as an `author` in the Akira shell;
2. the Media page is reachable (A3);
3. uploading an image succeeds and the media library lists it (A1, A2);
4. `delete` is still refused for `author` (A1);
5. as tenant admin, the Permissions surface lists a `cms-akira-media` row and can widen it, producing a new
   policy version (A4) with an audit record.

---

## verification

```
php -l <touched files>
composer test
php scripts/run-tests.php
php ikabud workbench:governance --all --gate
```

## risk

- **Authorization surface.** The change grants new authority to `contributor/author/editor` over media
  upload and library reads. Delete is deliberately excluded. Risk accepted by the owner; the scope boundary
  is the point of A1.
- **Ordering/byte-stability** of the seeded role CSV — `seedPolicy()` compares text; a non-deterministic
  order would cause pointless policy churn or spurious narrowing logs.
- **A4 widens what the Permissions page displays.** Rows for `cms-akira-builder`, `-navigation`, `-search`,
  `-seo`, `-theme` become visible. That is intended (the surface should not lie by omission) but it is a
  visible product change — confirm the page renders them without layout breakage.

## status

READY_FOR_IMPLEMENTATION
