# CMS Akira Visual Profile

The full visual CMS Akira installation bundle composed of Builder, core, shell, editor, theme, navigation, media, SEO, workflow, and search.

This profile is install metadata only — never an entry or authentication module. It has no routes, handlers, helpers, tables, or migrations.

Install the profile through the Kernel module-install planner. The planner resolves its dependency closure and uses `cms-akira-shell` as the entry module where appropriate; tracking this bundle does not install or activate it for any tenant.

This profile was certified and tracked at the independent-CMS Builder gate (Phase 10B) once the full `cms-akira-builder` graph it installs passed.
