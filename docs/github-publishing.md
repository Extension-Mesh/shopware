# Publishing from GitHub

The ExtensionMesh publisher turns a conventional Shopware 6 plugin repository
into an independent extension source. It builds and validates the plugin with
Shopware CLI, then attaches these files to a GitHub Release:

- the installable plugin ZIP
- `SHA256SUMS`
- `extension-mesh-release.json`
- `extension-mesh-registry.json`

The publisher is maintained separately in
[`Extension-Mesh/shopware-publisher`](https://github.com/Extension-Mesh/shopware-publisher).
It is versioned independently from the Shopware connector. Pin the exact
reviewed reference and review changes before updating it.

## Requirements

The repository must contain:

- a Shopware 6 plugin at its root
- `type: shopware-platform-plugin` in `composer.json`
- `version` in `composer.json`
- `require.shopware/core` and `require.php` constraints
- the plugin class configured through `extra.shopware-plugin-class`

The `composer.json` version is the release source of truth. Version `1.2.0`
creates release tag `v1.2.0`.

## Complete release workflow

Create `.github/workflows/publish-extension.yml` in the plugin repository:

```yaml
name: Publish extension

on:
  push:
    branches:
      - main
    paths:
      - composer.json
      - .github/workflows/publish-extension.yml

permissions: {}

jobs:
  publish:
    permissions:
      contents: write
      id-token: write
      attestations: write
    uses: Extension-Mesh/shopware-publisher/.github/workflows/publish.yml@v1.0.0
```

Commit the workflow together with a new version in `composer.json`. The
workflow creates the matching tag, GitHub Release and registry metadata. It
also updates the generated `extension-mesh-registry` branch with the current
registry document. Do not edit that branch or create the release manually.

When adding the workflow to a repository with existing releases, use a new
plugin version. The publisher does not modify an existing release that lacks
ExtensionMesh metadata.

## Add ExtensionMesh to an existing release job

Repositories that already have release triggers, tests and versioning can keep
them. Replace only the step that creates the GitHub Release with the composite
publisher action:

```yaml
permissions:
  contents: write
  id-token: write
  attestations: write

steps:
  - uses: actions/checkout@11d5960a326750d5838078e36cf38b85af677262 # v4
    with:
      fetch-depth: 0

  - name: Publish with ExtensionMesh metadata
    uses: Extension-Mesh/shopware-publisher@v1.0.0
    env:
      EXTENSION_MESH_TAG: ${{ github.ref_name }}
```

Run this job for a semantic version tag such as `v1.2.3`. The action derives
the repository information from `composer.json`, builds and validates the ZIP,
creates the GitHub Release, attaches the checksum and manifests, and updates
the stable registry channel. Do not create the same release in an earlier
step.

## Publish an already verified ZIP

If an earlier job builds the installable ZIP, pass that artifact to the action
instead of rebuilding it:

```yaml
- name: Publish the verified archive
  uses: Extension-Mesh/shopware-publisher@v1.0.0
  with:
    source-archive: ${{ runner.temp }}/verified/MyPlugin.zip
  env:
    EXTENSION_MESH_TAG: ${{ github.ref_name }}
```

The archive must contain `<TechnicalName>/composer.json`, and its Composer
version must match the tag. The published ZIP remains byte-for-byte identical
to the supplied artifact. This lets a repository retain its existing build and
verification flow while the publisher adds only the release information and
stable repository channel ExtensionMesh needs.

## Connect the repository

Administrators add the repository URL itself:

```text
https://github.com/owner/plugin-repository
```

The connector resolves this URL to the generated registry channel:

```text
https://raw.githubusercontent.com/owner/plugin-repository/extension-mesh-registry/extension-mesh-registry.json
```

Every successful release updates this document, including prereleases. The
repository URL therefore remains stable while the published version changes.

## Current publisher boundaries

- The action currently targets conventional Shopware 6 plugin repositories.
- Tags and release versions use semantic versions.
- The generated registry channel represents the most recently published
  release, including prereleases.
- Publisher `v1.0.0` generates registry documents with `schemaVersion: 1`.
  Connector `1.0.0` accepts exactly this schema version. A future incompatible
  registry format requires a new schema version and explicit connector support;
  existing version 1 documents remain the compatibility baseline for the 1.x
  connector series.
- Test the complete install and update path in an isolated Shopware 6.7
  installation before wider use.
