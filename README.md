# ExtensionMesh for Shopware

ExtensionMesh is an open-source connector for extension registries in Shopware
6.7. It adds registry sources to the Administration and uses the native plugin
lifecycle for installation and updates.

> **Project status:** Pre-release. Evaluate the connector in an isolated
> environment before using it in production.

## Current capabilities

- Persistent registry sources
- Registry discovery from compatible GitHub repositories
- Compatibility selection for Shopware and PHP versions
- Package checksum and ZIP validation
- Installation and updates through the native plugin lifecycle
- Public, private and authenticated registry sources
- Publication from digital-product ZIP files
- GitHub release synchronization for seller repositories
- Customer-scoped access tokens for restricted extensions
- Encrypted registry and repository credentials

## Installation

Tagged releases will provide an `ExtensionMesh-*.zip` file that can be uploaded
and activated through **Extensions → My extensions** in the Administration.

For development from the current branch:

```bash
composer config repositories.extension-mesh vcs https://github.com/Extension-Mesh/shopware
composer require extension-mesh/shopware:dev-main
bin/console plugin:refresh
bin/console plugin:install --activate ExtensionMesh
```

Do not use `dev-main` as an unattended production update source.

## Add a registry

1. Open **Extensions → My extensions → Registries**.
2. Add a compatible registry URL or GitHub repository URL.
3. Enter an access token when the source requires authentication.
4. Review compatible extensions and start installation or updates from the
   extension list.

Credentials are encrypted with the installation's `APP_SECRET`. They are not
returned through the Administration API.

## Publish from a seller installation

1. Create a digital product.
2. Attach a conventional Shopware plugin ZIP as a product download.
3. Complete the product configuration and access settings.
4. Provide entitled customers with the registry URL and token shown under
   **Account → Extension access**.

ExtensionMesh validates each plugin ZIP, derives its metadata, calculates its
SHA-256 checksum and exposes compatible releases through the seller registry.
Adding a newer ZIP to the same product publishes an update.

## Connect a GitHub repository

The **My Repositories** tab can connect public repositories without
credentials. Private repositories require a fine-grained GitHub token limited
to the selected repository with read-only **Contents** permission.

Stable GitHub Release assets can be linked to an existing product or imported
into a new inactive draft product. Repository synchronization runs
asynchronously and can also be started manually.

## Development checks

```bash
composer install
composer validate --strict
composer audit --locked --no-interaction
find src .github/actions -name '*.php' -print0 | xargs -0 -n1 php -l
```

The repository CI performs the same baseline checks and validates the generated
release archive.

## Project resources

- Website and documentation: <https://www.extension-mesh.dev>
- Issues: <https://github.com/Extension-Mesh/shopware/issues>
- Security reports: use GitHub's private vulnerability reporting for this repository

## License

MIT
