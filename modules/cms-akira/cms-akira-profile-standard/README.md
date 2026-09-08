# CMS Akira Standard Profile

The standard CMS Akira publishing bundle composed of:

- `cms-akira-core`
- `cms-akira-shell`
- `cms-akira-editor`
- `cms-akira-theme`
- `cms-akira-navigation`
- `cms-akira-media`
- `cms-akira-seo`

This profile is install metadata only — never an entry or authentication module. It has no routes, handlers, helpers, tables, or migrations.

Install the profile through the Kernel module-install planner. The planner resolves its dependency closure and uses `cms-akira-shell` as the entry module where appropriate; tracking this bundle does not install or activate it for any tenant.
