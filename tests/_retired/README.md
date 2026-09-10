# Retired tests

These tests are retained for reference but renamed to `*.php.retired` so the `*_test.php` runner does not discover them. They depended on modules or capabilities that have never existed in this repository and were only reported as passing while uncaught bootstrap exceptions incorrectly exited with status 0.

- `disyl_document_renderer_test.php.retired` — requires `modules/cms/helpers/10-core.php`.
- `entity_context_registry_test.php.retired` — requires `modules/cms`.
- `entity_context_runtime_bridge_test.php.retired` — requires `modules/cms`.
- `infrastructure_test.php.retired` — requires `modules/cli-test-tmp/handlers.php`.
- `integration_bridge_validation_test.php.retired` — requires the absent `wms.stock.reserve@1` capability.
- `manifest_settings_defaults_test.php.retired` — requires absent ai, anti-spam, cms, contact-form, ecommerce, guidance, sms, and ticketing modules.
- `page_cache_smoke_test.php.retired` — requires `modules/cms/helpers.php`.
- `platform_tier1_operational_test.php.retired` — requires `ecOutboundWebhookDeliverJob()`.
- `render_context_contracts_test.php.retired` — requires `ecPublicRenderContext()`.
- `request_dispatch_integration_test.php.retired` — requires `modules/cms/helpers.php`.
- `trigger_validation_test.php.retired` — requires `modules/users/helpers.php`.

To restore a test after its module or capability is implemented, rename it back into `tests/` with its original `*_test.php` filename.
