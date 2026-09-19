# Escalation: tools/harpp2/objectives/akira-theme-editor-milestone.md

**Condition:** boundary

**Exact blocker:** All six browser requirements, syntax checks, actual module authority test, and composer suite pass; completion cannot continue because the manifest invokes nonexistent tests/theme_read_authority_test.php, while correcting tools/harpp2/projects/akira-theme-editor.json or adding the tests-path shim is outside the declared writable scope.

## Options

1. Authorize a narrowly scoped exception; work can continue, but the named boundary or impact is accepted.
2. Change the objective to avoid the blocker; this preserves the boundary but may reduce or delay the outcome.
3. Leave the objective unchanged and stopped; no further workspace changes are made.

## Recommendation

Choose option 2 when a safe path exists after changing scope; otherwise explicitly decide between options 1 and 3.
