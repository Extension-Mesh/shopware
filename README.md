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
- Paginated customer-account license and release downloads
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

## Production operation

Repository onboarding and publication validation run asynchronously. Production
installations must keep Shopware's `async` Messenger transport under a process
supervisor; without a worker, newly connected repositories and product-download
changes remain queued:

```bash
bin/console messenger:consume async --time-limit=300 --memory-limit=512M
```

Keep Shopware's scheduled-task runner enabled as well so connected repositories
are refreshed periodically. Run both commands through systemd, Supervisor,
Kubernetes, or the equivalent platform facility instead of an interactive shell.

Seller access tokens use a rolling 90-day inactivity window. Successful registry
or artifact requests extend the window automatically, so active system-to-system
connections do not require manual rotation. A token is also revoked when its
customer loses all ExtensionMesh entitlements.

Publisher endpoints are rate-limited per access token and client address: 60
registry reads and 10 artifact downloads per minute. Rejected requests return
HTTP `429` with a `Retry-After` header.

## Add a registry

1. Open **Extensions → My extensions → Registries**.
2. Add a compatible registry URL or GitHub repository URL, for example
   `https://github.com/Extension-Mesh/shopware`.
3. Enter an access token when the source requires authentication.
4. Review compatible extensions and start installation or updates from the
   extension list.

Credentials are encrypted with the installation's `APP_SECRET`. They are not
returned through the Administration API.

## Publish from a seller installation

1. Create a digital product.
2. Attach a conventional Shopware plugin ZIP as a product download.
3. Complete the product configuration and access settings.
4. Entitled customers can download releases under **Account → My extensions**
   or connect another Shopware installation with the registry URL and token
   shown under **Account → Extension access**.

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

## Publish from a plugin repository

The reusable workflow from
[`Extension-Mesh/shopware-publisher`](https://github.com/Extension-Mesh/shopware-publisher)
builds a validated plugin ZIP and publishes the ZIP, checksum and ExtensionMesh
registry metadata with a GitHub Release. On every release it also updates a
generated registry channel, so consumers can keep using the repository URL
instead of a version-specific asset URL. See
[Publishing from GitHub](docs/github-publishing.md) for a minimal workflow and
the current alpha limitations.

## Development checks

```bash
composer install
composer validate --strict --no-check-version
composer test
composer analyse
composer audit --locked --no-interaction
find src -name '*.php' -print0 | xargs -0 -n1 php -l
```

The repository CI performs the same baseline checks, validates the Docker
configuration and scripts. The release workflow builds and validates the
installable archive with Shopware CLI and verifies that the published ZIP is
byte-identical to that artifact.

## Releases

The top-level `version` in `composer.json` is the release source of truth.
Changing it on `main` starts the complete release verification. The workflow
repeats the baseline checks and runs the Dockware integration suite. The
installable archive is built and validated with Shopware CLI. After both jobs
succeed, the workflow creates the matching version tag and publishes the
release. Changes to other Composer metadata do not publish a release while the
version remains unchanged.

Each release contains the installable plugin ZIP, its SHA-256 checksum, an
ExtensionMesh release manifest and a single-extension registry manifest.
Pre-release versions are marked as pre-releases automatically. The workflow can
also be started manually to verify the release path without publishing a
release. After publishing, it updates the generated `extension-mesh-registry`
branch with the current registry document.

## Docker integration environment

The repository includes a local integration environment with separate buyer
and seller installations plus a fixture registry.

Requirements: Docker with Compose, `make`, `curl`, `jq`, `openssl` and `zip`.

```bash
make setup
make battle-test
make down
```

The same integration environment runs nightly in GitHub Actions and can also be started manually with a selected Dockware image.

The default local endpoints are:

- Buyer Administration: <http://localhost:8081/admin>
- Seller Administration: <http://localhost:8082/admin>
- Fixture registry: <http://localhost:8090/registry.json>

The environment enables private-network registry URLs only inside the local
containers. Production defaults continue to require HTTPS and reject private
or reserved network targets.

## Project resources

- Website and documentation: <https://www.extension-mesh.dev>
- Publishing workflow: <https://github.com/Extension-Mesh/shopware-publisher>
- Issues: <https://github.com/Extension-Mesh/shopware/issues>
- Security reports: use GitHub's private vulnerability reporting for this repository

## License

MIT
