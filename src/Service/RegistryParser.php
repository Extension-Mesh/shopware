<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use ExtensionMesh\Shopware\Exception\ExtensionMeshException;

final class RegistryParser
{
    /**
     * @return array{
     *     schemaVersion: 1,
     *     name: string,
     *     extensions: list<array{
     *         name: string,
     *         label: array<string, string>,
     *         description: array<string, string>,
     *         manufacturer: ?string,
     *         license: ?string,
     *         homepage: ?string,
     *         icon: ?string,
     *         releases: list<array{
     *             version: string,
     *             shopware: string,
     *             php: ?string,
     *             downloadUrl: string,
     *             sha256: string,
     *             releasedAt: string,
     *             security: bool,
     *             changelogUrl: ?string
     *         }>
     *     }>
     * }
     */
    public function parse(string $json): array
    {
        try {
            $document = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw ExtensionMeshException::invalidRegistry($exception->getMessage());
        }

        if (!\is_array($document)) {
            throw ExtensionMeshException::invalidRegistry('the document root must be an object.');
        }
        $this->assertOnlyFields($document, ['schemaVersion', 'name', 'extensions'], 'document');

        if (($document['schemaVersion'] ?? null) !== 1) {
            throw ExtensionMeshException::invalidRegistry('schemaVersion must be 1.');
        }

        $name = $this->requiredString($document, 'name', 255);
        $rawExtensions = $document['extensions'] ?? null;
        if (!\is_array($rawExtensions) || !\array_is_list($rawExtensions) || \count($rawExtensions) > 1000) {
            throw ExtensionMeshException::invalidRegistry('extensions must be a list with at most 1000 entries.');
        }

        $extensions = [];
        $names = [];
        foreach ($rawExtensions as $index => $rawExtension) {
            if (!\is_array($rawExtension)) {
                throw ExtensionMeshException::invalidRegistry(\sprintf('extensions[%d] must be an object.', $index));
            }

            $extension = $this->parseExtension($rawExtension, $index);
            if (isset($names[$extension['name']])) {
                throw ExtensionMeshException::invalidRegistry(
                    \sprintf('technical name "%s" occurs more than once.', $extension['name'])
                );
            }

            $names[$extension['name']] = true;
            $extensions[] = $extension;
        }

        return [
            'schemaVersion' => 1,
            'name' => $name,
            'extensions' => $extensions,
        ];
    }

    /**
     * @param list<array{
     *     version: string,
     *     shopware: string,
     *     php: ?string,
     *     downloadUrl: string,
     *     sha256: string,
     *     releasedAt: string,
     *     security: bool,
     *     changelogUrl: ?string
     * }> $releases
     *
     * @return array{
     *     version: string,
     *     shopware: string,
     *     php: ?string,
     *     downloadUrl: string,
     *     sha256: string,
     *     releasedAt: string,
     *     security: bool,
     *     changelogUrl: ?string
     * }|null
     */
    public function newestCompatibleRelease(array $releases, string $shopwareVersion, string $phpVersion): ?array
    {
        $compatible = \array_filter(
            $releases,
            static function (array $release) use ($shopwareVersion, $phpVersion): bool {
                try {
                    if (!Semver::satisfies($shopwareVersion, $release['shopware'])) {
                        return false;
                    }

                    return $release['php'] === null || Semver::satisfies($phpVersion, $release['php']);
                } catch (\UnexpectedValueException|\InvalidArgumentException) {
                    return false;
                }
            }
        );

        \usort(
            $compatible,
            static fn (array $left, array $right): int => \version_compare($right['version'], $left['version'])
        );

        return $compatible[0] ?? null;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array{
     *     name: string,
     *     label: array<string, string>,
     *     description: array<string, string>,
     *     manufacturer: ?string,
     *     license: ?string,
     *     homepage: ?string,
     *     icon: ?string,
     *     releases: list<array{
     *         version: string,
     *         shopware: string,
     *         php: ?string,
     *         downloadUrl: string,
     *         sha256: string,
     *         releasedAt: string,
     *         security: bool,
     *         changelogUrl: ?string
     *     }>
     * }
     */
    private function parseExtension(array $raw, int $index): array
    {
        $this->assertOnlyFields(
            $raw,
            ['name', 'label', 'description', 'manufacturer', 'license', 'homepage', 'icon', 'releases'],
            \sprintf('extensions[%d]', $index)
        );

        $name = $this->requiredString($raw, 'name', 128);
        if (!\preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $name)) {
            throw ExtensionMeshException::invalidRegistry(
                \sprintf('extensions[%d].name is not a valid Shopware technical name.', $index)
            );
        }

        $rawReleases = $raw['releases'] ?? null;
        if (!\is_array($rawReleases) || !\array_is_list($rawReleases) || $rawReleases === [] || \count($rawReleases) > 200) {
            throw ExtensionMeshException::invalidRegistry(
                \sprintf('extensions[%d].releases must contain between 1 and 200 entries.', $index)
            );
        }

        $releases = [];
        $versions = [];
        foreach ($rawReleases as $releaseIndex => $rawRelease) {
            if (!\is_array($rawRelease)) {
                throw ExtensionMeshException::invalidRegistry(
                    \sprintf('extensions[%d].releases[%d] must be an object.', $index, $releaseIndex)
                );
            }

            $release = $this->parseRelease($rawRelease, $index, $releaseIndex);
            if (isset($versions[$release['version']])) {
                throw ExtensionMeshException::invalidRegistry(
                    \sprintf('extension "%s" contains release "%s" more than once.', $name, $release['version'])
                );
            }

            $versions[$release['version']] = true;
            $releases[] = $release;
        }

        return [
            'name' => $name,
            'label' => $this->localized($raw['label'] ?? null, \sprintf('extensions[%d].label', $index), true),
            'description' => $this->localized(
                $raw['description'] ?? [],
                \sprintf('extensions[%d].description', $index),
                false
            ),
            'manufacturer' => $this->optionalString($raw, 'manufacturer', 255),
            'license' => $this->optionalString($raw, 'license', 255),
            'homepage' => $this->optionalUrl($raw, 'homepage'),
            'icon' => $this->optionalUrl($raw, 'icon'),
            'releases' => $releases,
        ];
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array{
     *     version: string,
     *     shopware: string,
     *     php: ?string,
     *     downloadUrl: string,
     *     sha256: string,
     *     releasedAt: string,
     *     security: bool,
     *     changelogUrl: ?string
     * }
     */
    private function parseRelease(array $raw, int $extensionIndex, int $releaseIndex): array
    {
        $prefix = \sprintf('extensions[%d].releases[%d]', $extensionIndex, $releaseIndex);
        $this->assertOnlyFields(
            $raw,
            ['version', 'shopware', 'php', 'downloadUrl', 'sha256', 'releasedAt', 'security', 'changelogUrl'],
            $prefix
        );

        $version = $this->requiredString($raw, 'version', 64);
        if (!\preg_match('/^[0-9]+(?:\.[0-9]+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
            throw ExtensionMeshException::invalidRegistry($prefix . '.version is not a supported semantic version.');
        }

        $sha256 = \strtolower($this->requiredString($raw, 'sha256', 64));
        if (!\preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            throw ExtensionMeshException::invalidRegistry($prefix . '.sha256 must be a lowercase SHA-256 digest.');
        }

        $releasedAt = $this->requiredString($raw, 'releasedAt', 64);
        $dateMatches = [];
        if (!\preg_match(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.(\d{1,6}))?(?:Z|[+-]\d{2}:\d{2})$/D',
            $releasedAt,
            $dateMatches
        )) {
            throw ExtensionMeshException::invalidRegistry($prefix . '.releasedAt must be an RFC 3339 date-time.');
        }

        $normalizedDate = \str_ends_with($releasedAt, 'Z')
            ? \substr($releasedAt, 0, -1) . '+00:00'
            : $releasedAt;
        $dateFormat = isset($dateMatches[1])
            ? '!Y-m-d\TH:i:s.uP'
            : '!Y-m-d\TH:i:sP';
        $date = \DateTimeImmutable::createFromFormat($dateFormat, $normalizedDate);
        $dateErrors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            throw ExtensionMeshException::invalidRegistry($prefix . '.releasedAt is not a valid date-time.');
        }

        $shopware = $this->requiredString($raw, 'shopware', 255);
        $php = $this->optionalString($raw, 'php', 255);
        $versionParser = new VersionParser();
        try {
            $versionParser->parseConstraints($shopware);
            if ($php !== null) {
                $versionParser->parseConstraints($php);
            }
        } catch (\UnexpectedValueException $exception) {
            throw ExtensionMeshException::invalidRegistry(
                $prefix . '.shopware and .php must contain valid Composer version constraints.'
            );
        }

        $security = $raw['security'] ?? false;
        if (!\is_bool($security)) {
            throw ExtensionMeshException::invalidRegistry($prefix . '.security must be a boolean.');
        }

        return [
            'version' => $version,
            'shopware' => $shopware,
            'php' => $php,
            'downloadUrl' => $this->requiredUrl($raw, 'downloadUrl'),
            'sha256' => $sha256,
            'releasedAt' => $releasedAt,
            'security' => $security,
            'changelogUrl' => $this->optionalUrl($raw, 'changelogUrl'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requiredString(array $data, string $field, int $maxLength): string
    {
        $value = $data[$field] ?? null;
        if (!\is_string($value) || \trim($value) === '' || \mb_strlen($value) > $maxLength) {
            throw ExtensionMeshException::invalidRegistry(
                \sprintf('"%s" must be a non-empty string no longer than %d characters.', $field, $maxLength)
            );
        }

        return \trim($value);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function optionalString(array $data, string $field, int $maxLength): ?string
    {
        if (!isset($data[$field]) || $data[$field] === '') {
            return null;
        }

        return $this->requiredString($data, $field, $maxLength);
    }

    /**
     * @param mixed $value
     *
     * @return array<string, string>
     */
    private function localized(mixed $value, string $field, bool $required): array
    {
        if (!\is_array($value) || ($value !== [] && \array_is_list($value)) || ($required && $value === [])) {
            throw ExtensionMeshException::invalidRegistry($field . ' must be a locale-to-string object.');
        }

        $localized = [];
        foreach ($value as $locale => $text) {
            if (!\is_string($locale) || !\preg_match('/^[a-z]{2}-[A-Z]{2}$/', $locale)) {
                throw ExtensionMeshException::invalidRegistry($field . ' contains an invalid locale.');
            }
            if (!\is_string($text) || \mb_strlen($text) > 5000) {
                throw ExtensionMeshException::invalidRegistry($field . ' contains an invalid localized value.');
            }

            $localized[$locale] = $text;
        }

        return $localized;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requiredUrl(array $data, string $field): string
    {
        $value = $this->requiredString($data, $field, 2048);
        $parts = \parse_url($value);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !\in_array(\strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw ExtensionMeshException::invalidRegistry(\sprintf('"%s" must be an absolute HTTP(S) URL.', $field));
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw ExtensionMeshException::invalidRegistry(\sprintf('"%s" cannot contain credentials or a fragment.', $field));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function optionalUrl(array $data, string $field): ?string
    {
        if (!isset($data[$field]) || $data[$field] === '') {
            return null;
        }

        return $this->requiredUrl($data, $field);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $allowed
     */
    private function assertOnlyFields(array $data, array $allowed, string $context): void
    {
        foreach (\array_keys($data) as $field) {
            if (!\in_array($field, $allowed, true)) {
                throw ExtensionMeshException::invalidRegistry(
                    \sprintf('%s contains unsupported field "%s".', $context, $field)
                );
            }
        }
    }
}
