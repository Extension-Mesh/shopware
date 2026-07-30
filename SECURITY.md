# Security

## Supported versions

ExtensionMesh is currently pre-release. Security fixes are applied to the
latest available version on the supported Shopware 6.7 line.

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability. Use GitHub's private
vulnerability reporting for this repository and include:

- affected ExtensionMesh and Shopware versions;
- reproduction steps and required privileges;
- the observed impact; and
- a proof of concept when it can be shared safely.

## Operational guidance

- Use HTTPS registry endpoints in production.
- Restrict plugin maintenance permissions to trusted administrators.
- Review the registry operator, publisher and package before installation.
- Keep `APP_SECRET` confidential and backed up.
- Never place access tokens in registry URLs or logs.
- Use separate, expiring, read-only tokens for private repositories.
- Back up the database and plugin directory before updates.
- Keep debug-only private-network access disabled in production.
