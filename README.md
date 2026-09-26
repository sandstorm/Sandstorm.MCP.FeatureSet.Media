# Sandstorm.MCP.FeatureSet.Media

MCP tools for migrating content into Neos 9: import files from a folder on the server into the
media library, attach them to nodes and clean up migrated nodes safely. Builds on
[sjs/flow-mcp](https://github.com/sjsone/SJS.Flow.MCP) and
[sjs/neos-mcp](https://github.com/sjsone/SJS.Neos.MCP).

## Tools

The prefix `media_import_` comes from the feature set class name (`MediaImportFeatureSet`).

| Tool | What it does |
|---|---|
| `media_import_list_images` | Lists all files under the resource folder (recursively), or checks whether one exists |
| `media_import_import_image` | Imports a file from the resource folder into the media library; returns the asset id |
| `media_import_set_asset_property` | Sets an image/asset property on a node (the property tools cannot write assets) |
| `media_import_soft_remove_migrated_node` | Soft removes a node, **only** if it matches the `removableNodes` whitelist |

To get files into the resource folder, copy them there before the migration. A Neos
`Data/Persistent/Resources` dump can be placed there as-is. Use the optional `filename`
argument of `import_image` for sha1-named files without an extension.

### Safety

- Nothing writes to the `live` workspace.
- `soft_remove_migrated_node` refuses any node that is not of a whitelisted node type **and** below a whitelisted ancestor. Removals are soft (`removed` subtree tag, like the backend), so they stay reviewable and can be discarded until published.
- The whitelist is empty by default, so nothing can be removed until you configure it.

## Installation

For setting up the whole toolset in a Neos project (sjs base packages, both Sandstorm feature sets,
database migration, connection token, MCP client), follow the
[full setup guide in Sandstorm.MCP.FeatureSet.Content](https://github.com/sandstorm/Sandstorm.MCP.FeatureSet.Content#setting-up-the-full-mcp-toolset-in-a-neos-project).
The minimum for this package alone:

The package is not on Packagist. Add the VCS repositories to your **root** `composer.json`:

```json
"repositories": {
  "sandstorm-flow-mcp": { "type": "vcs", "url": "https://github.com/sandstorm/SJS.Flow.MCP" },
  "sandstorm-mcp-feature-set-media": { "type": "vcs", "url": "https://github.com/sandstorm/Sandstorm.MCP.FeatureSet.Media" }
},
"require": {
  "sjs/flow-mcp": "dev-sandstorm as 1.0.2",
  "sandstorm/mcp-feature-set-media": "^0.1"
}
```

The feature set registers itself on the default server (`SJS.Flow.MCP.server.mcp.featureSets.media_import`).

## Configuration

Put your values into your **site package**, and add `"sandstorm/mcp-feature-set-media": "*"` to its `composer.json` `require`. Your settings then load after this package's defaults and win. Composer dependency changes take effect only after an app/container restart.

```yaml
Sandstorm:
  MCP:
    FeatureSet:
      Media:
        # default: '%FLOW_PATH_DATA%Persistent/McpResources/'
        resourceFolder: '%FLOW_PATH_DATA%Persistent/McpResources/'

        # (nodeType, ancestorAggregateId) pairs soft_remove_migrated_node may remove.
        # nodeType uses isOfType, so 'Neos.Neos:Content' matches every content node type.
        # Aggregate ids differ per environment; entries that match nothing are inert, so
        # local and staging ids can coexist.
        removableNodes:
          - nodeType: 'Vendor.Site:Document.News.Post'
            ancestorAggregateId: '<aggregate id of the news parent document>'
          - nodeType: 'Neos.Neos:Content'
            ancestorAggregateId: '<aggregate id of the news parent document>'
```

Check the effective values with
`./flow configuration:show --path Sandstorm.MCP.FeatureSet.Media`.

## License

AGPL-3.0-or-later, like the `sjs/*` packages it extends.
