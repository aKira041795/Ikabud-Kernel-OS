# T3 CONTRACT (REVISED) — CMS Akira shell contribution registry (adopt kernel machinery, consumer-driven)

## Why revised
Previous run BLOCKED correctly: ground truth was wrong (cms-akira-theme/module.json has NO cms.sidebar
contribution; Theme Studio nav is hardcoded in the shell from T1). Chair has now diagnosed the real design.

## Verified kernel machinery (REUSE — do not reinvent)
src/helpers/module-manager.php already implements a contribution registry:
- `kernelContributionNormalize(raw, moduleId)` → FIXED nav shape: id/host/location/group/label/icon/route/
  permission/roles/order/active_key/module (extra fields DROPPED).
- `kernelContributionRegistry(modules, context)`, `kernelContributionsForHost(host, location, ...)`,
  `kernelContributionsForHostLocation(host, location, ...)`, `kernelContributionRoleAllowed(contrib, context)`,
  `kernelContributionRequestContext()`, `kernelContributionConflicts(modules)`.
- Reference CMS (host "cms", location "sidebar") renders via the `cms.admin.nav_items` hook +
  `kernelContributionBridgeCmsNavItems`. CMS Akira's shell is a DIFFERENT host (cms-akira-shell, its own nav
  builder in akiraShellPage) — it does NOT use that hook.

## Chair design decisions (locked — implement, do not re-derive)
1. **CMS Akira shell host id = `cms-akira-shell`.** Suite members with a real shell surface declare
   `admin_contributions` entries in their module.json with `host: "cms-akira-shell"`, `location: "sidebar"`
   (nav) or `"dashboard.widgets"` (widgets).
2. **cms-akira-theme declares its Theme Studio nav** (it owns the real surface; matches modules/cms-akira/
   README L141 rule): add to `modules/cms-akira/cms-akira-theme/module.json` an `admin_contributions` entry
   `{ id: "cms-akira-theme.theme-studio", host: "cms-akira-shell", location: "sidebar", label: "Theme Studio",
   route: "/cms-akira-theme", roles: ["admin"], order: 5 }` (use exactly the field names
   kernelContributionNormalize reads — verify against its code). Then REMOVE the hardcoded theme-studio entry
   from `cms-akira-shell/helpers.php` akiraShellPage() admin links (added by T1 G1) so the nav comes from the
   registry.
3. **Shell nav reads the kernel registry**: akiraShellPage() merges host-owned built-in items
   (dashboard/posts/categories/content-types; admin built-ins compositions/permissions/users/health) with
   registry sidebar contributions for host cms-akira-shell via
   `kernelContributionsForHostLocation('cms-akira-shell', 'sidebar', null, kernelContributionRequestContext())`,
   ordered by `order` then id. Role gating is the registry's job (context user role).
4. **Dashboard widgets**: because kernelContributionNormalize drops extra fields, a suite-side registry in
   `cms-akira-core` (the product-core that owns extension points) reads RAW enabled-suite-member manifests'
   `admin_contributions` entries where location === 'dashboard.widgets', validates a minimal widget shape
   {id,label,size?,order?,render_capability}, and exposes them via a NEW core capability
   `akira.extension.widgets@1` (read, mode first) returning the validated widget list for the current
   tenant/context. Shell dashboard calls `akira.extension.widgets@1` and renders EACH widget by calling the
   widget's OWN `render_capability` (per-contribution least-privilege; if the capability is missing/denied the
   widget is omitted — fail-closed per widget, never fails the page). Widget render capabilities return
   {ok:true, html:'...'} escaped server HTML (or {ok:false, error}).
   (Do NOT overload the kernel nav `permission` field to carry the render capability id.)
5. **First-party consumer = cms-akira-seo "Content health" widget**: cms-akira-seo declares one
   dashboard.widgets contribution and adds a NEW read capability `akira.seo.content_health@1` (mode first)
   returning {ok:true, html} = counts of posts with vs without native SEO metadata (delegate via the seo
   module's own read path/tables — capability-only; the shell never does foreign SQL). If reads require a
   policy row to resolve for tenant 54, seed one the way R5 seeded read policies for posts (in the module
   that owns the capability). Verify how other akira read capabilities resolve for tenant 54 and mirror it.
6. **Scope NOW INCLUDES** (authorized): modules/cms-akira/cms-akira-core/**, cms-akira-shell/**,
   cms-akira-seo/**, AND the single admin_contributions addition to cms-akira-theme/module.json. NO kernel/
   edits. NO new DB tables (prefer capability reads / existing seo tables). Do NOT wire
   settings.sections/content.processors/editor.tools (no consumer).
7. CI gates are mandatory BEFORE you finish: run
   `php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php <files>` (options before files; no
   --path-mode) and `php vendor/bin/phpstan analyse <module> --memory-limit=1G`; MULTILINE @return docblocks
   for new iterables; php -l on every touched file.

## Acceptance (verify each; report)
1. Theme Studio nav renders from cms-akira-theme's contribution via the registry (admin sees it; removing the
   hardcode did not remove it). Non-admin does not see admin-only nav (built-ins + contributions gated).
2. Dashboard shows the cms-akira-seo "Content health" widget (real counts) for admin; a role whose policy
   denies akira.seo.content_health@1 does not see the widget and the page still renders (fail-closed).
3. Real-env HTTP (tenant 54 authed): shell nav + dashboard verified (no duplicate Theme Studio, no 404s);
   public unaffected; app.log/error.log clean.
4. shell_contract_test green + a new focused registry/widget test (hermetic, cms-akira-core/tests) + seo
   tests; php -l; cs-fixer 0; phpstan 0 on touched modules.
5. No kernel/ edits; no new tables; no foreign SQL from the shell.

## Result format (final message)
status: changed: implementation_summary: (registry/shell_wiring/theme_contribution/seo_widget)
verification: (php_lint/cs_fixer/phpstan/registry_test/shell_test/http_dashboard/http_nav/logs)
scope: unexpected_files: risks: unresolved:
Stop + escalate only on repeated failure or a genuine need to edit kernel/ (report BLOCKED with rationale).
