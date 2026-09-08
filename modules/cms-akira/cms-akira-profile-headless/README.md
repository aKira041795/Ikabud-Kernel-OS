# CMS Akira Headless Profile

An API-first CMS Akira installation bundle composed of:

- `cms-akira-core`
- `cms-akira-workflow`
- `cms-akira-search`

This profile is install metadata only — never an entry or authentication module. It has no shell, routes, handlers, helpers, tables, or migrations.

Install the profile through the Kernel module-install planner. The planner resolves its dependency closure and leaves the tenant entry module unchanged; tracking this bundle does not install or activate it for any tenant.
