# CMS Akira Editor

Native, table-free content preparation for Akira Posts.

## Contract

The module exposes five versioned capabilities:

- `akira.editor.render@1`
- `akira.editor.normalize@1`
- `akira.editor.sanitize@1`
- `akira.editor.validate@1`
- `akira.editor.assets@1`

Content handlers accept an allowlisted Post detail projection with `title`, `subtitle`, `image`, `body`, `metadata`, `actions`, and `url`. A caller may alternatively provide a canonical `slug`; the editor resolves it through `entity.get.post@1`. Domain rows and internal fields such as tenant or persistence identifiers fail closed.

Normalization and sanitization use one deterministic, local HTML policy. Executable elements, event/style attributes, unsafe links, and undeclared attributes are removed. Rendering returns only this canonical safe fragment. The asset capability returns Akira-owned JavaScript and CSS under `/assets/modules/cms-akira-editor/` and never requires a network provider.

The five operations are pure reads/transforms. They do not mutate Post state, so mutation protocol, CSRF, idempotency, and audit governance do not apply. The module owns and reads no tables, has no migrations, and registers no host admin contributions. Its only route is a dependency-free health endpoint. Tenant installation remains explicit; repository tracking does not auto-activate it.

## Verification

```bash
php modules/cms-akira/cms-akira-editor/tests/editor_contract_test.php
php ikabud capability:audit --json
php ikabud module:certify cms-akira-editor
```
