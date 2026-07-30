# Publishing from GitHub

The ExtensionMesh publishing action turns a conventional Shopware 6 plugin
repository into an independent extension source. It builds and validates the
plugin with Shopware CLI, then attaches these files to a GitHub Release:

- the installable plugin ZIP
- `SHA256SUMS`
- `extension-mesh-release.json`
- `extension-mesh-registry.json`

The current action is an alpha interface. Pin the exact alpha version and
review changes before updating it.

## Requirements

The repository must contain:

- a Shopware 6 plugin at its root
- `type: shopware-platform-plugin` in `composer.json`
- `version` in `composer.json`
- `require.shopware/core` and `require.php` constraints
- the plugin class configured through `extra.shopware-plugin-class`

The Git tag and `composer.json` version must match. For example, version
`1.2.0` is published from tag `v1.2.0`.

## Minimal workflow

Create `.github/workflows/publish-extension.yml` in the plugin repository:

```yaml
name: Publish extension

on:
  push:
    tags:
      - "v*"

permissions:
  contents: write
  id-token: write
  attestations: write

jobs:
  publish:
    runs-on: ubuntu-latest
    steps:
      - name: Check out source
        uses: actions/checkout@11d5960a326750d5838078e36cf38b85af677262 # v4
        with:
          fetch-depth: 0

      - name: Build and publish
        uses: Extension-Mesh/shopware/.github/actions/publish-shopware@v0.1.0-alpha.3
```

Commit the workflow, set the release version in `composer.json`, then create and
push the matching tag:

```bash
git tag v1.2.0
git push origin v1.2.0
```

The workflow creates the GitHub Release and its registry metadata. It also
updates the generated `extension-mesh-registry` branch with the current
registry document. Do not edit that branch or create the release manually
before the workflow runs.

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

## Current alpha boundaries

- The action currently targets conventional Shopware 6 plugin repositories.
- Tags and release versions use semantic versions.
- The generated registry channel represents the most recently published
  release, including prereleases.
- The action and generated registry format may change during the alpha phase.
- Test the complete install and update path in an isolated Shopware 6.7
  installation before wider use.
