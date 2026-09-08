# CMS Akira AI

Native, table-free suggestion support for Akira Posts.

## Authority model

The module exposes one read-only, first-mode capability: `akira.ai.summary.suggest@1`. Its payload contains only an opaque Post key (`id`, with `slug` accepted as an equivalent key). The module resolves that key through core's `entity.get.post@1` capability in the active Kernel tenant context. It never accepts tenant identity from the payload and never reads core storage directly.

Suggestions use only the allowlisted Post detail text fields `title`, `subtitle`, and `body`. Markup is reduced to plain text, the summary is an extractive prefix/sentence selection, and keywords are ranked by deterministic frequency and source order. Identical projections therefore produce identical results without an external service.

The Kernel capability envelope is always successful and its `data` is one of:

```text
{status: "ok", summary: string, keywords: string[]}
{status: "unavailable", reason: string}
```

Missing or unreadable entities return `entity_unavailable`; malformed keys return `invalid_entity_reference`; an upstream projection outside the public allowlist returns `projection_unavailable`. A tenant can explicitly disable local suggestions with the `local_suggestions_enabled=false` module setting, which returns `local_mode_disabled`. These states are non-fatal and do not expose entity contents.

The module has no mutations, effects, protocol-v2 requirement, tables, direct database access, provider SDK, or remote transport. A future Kernel-governed AI seam may be added only through a separate gate; this module never blocks on such infrastructure and remains provider-free. No Akira member or profile depends on this optional extension.

Repository tracking does not activate the module. `_enabled:false` keeps installation tenant-explicit.

## Verification

```bash
php modules/cms-akira/cms-akira-ai/tests/ai_contract_test.php
php ikabud capability:audit --json
php ikabud module:certify cms-akira-ai
```
