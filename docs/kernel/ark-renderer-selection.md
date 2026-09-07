# ARK renderer selection (opt-in)

`Ikabud\Kernel\Services\ArkRendererResolver` is the kernel runtime consumer for a theme's
`renderer-registry.json`. It selects only an exact entity-view key such as
`entity.list.post` or `entity.detail.post`; there is no `*` fallback.

Application code can use the shared accessor:

```php
$selection = app()->arkRenderers()->resolve('entity.list.post', $themeSlug);
$html = app()->arkRenderers()->render('entity.list.post', ['posts' => $posts], $themeSlug);
if ($selection === null || $html === null) {
    // Return the module's explicit 404/error response.
}
```

The theme slug must be passed explicitly. The resolver has no legacy-CMS active-theme fallback and
never derives a slug from a storage convention. Callers must obtain a trusted tenant theme slug from
their owning module or a Kernel-owned activation resolver before invoking this service. A null, empty,
or invalid slug—and missing themes, registries, exact mappings, files, or component registrations—returns
`null` and never selects a generic renderer.

Each exact key in `renderers` uses the existing validated fields:

```json
{
  "renderers": {
    "entity.list.post": {
      "template": "public/article-grid.disyl",
      "controls": ["columns"],
      "context_keys": ["posts"]
    }
  }
}
```

`template` may be a theme-relative DiSyL path, `ark.blocks.<name>`
(`public/blocks/<name>.block.disyl`), or `ark.layouts.<name>`
(`public/<name>.disyl`). `renders_as_component` must name a component present in
`ComponentRegistry`. The returned selection identifies `template` versus `component` and includes
the executable target. `render()` is the explicit opt-in entry; the existing
`ikb_entity_list`, `ikb_entity_detail`, and `DefaultEntityRenderer` paths do not call it.
