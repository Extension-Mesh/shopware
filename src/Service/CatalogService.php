<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Infrastructure\Http\RegistryUrlNormalizer;
use ExtensionMesh\Shopware\Infrastructure\Http\SafeHttpClient;
use ExtensionMesh\Shopware\Infrastructure\Persistence\ExtensionOwnershipRepository;
use ExtensionMesh\Shopware\Infrastructure\Persistence\RegistrySourceRepository;
use ExtensionMesh\Shopware\Infrastructure\Security\CredentialCipher;

final class CatalogService
{
    private const CACHE_TTL_SECONDS = 900;

    public function __construct(
        private readonly RegistrySourceRepository $sources,
        private readonly RegistryUrlNormalizer $urlNormalizer,
        private readonly SafeHttpClient $httpClient,
        private readonly RegistryParser $parser,
        private readonly CredentialCipher $credentialCipher,
        private readonly ExtensionOwnershipRepository $ownership
    ) {
    }

    public function addSource(string $inputUrl, ?string $accessToken = null): string
    {
        $normalizedUrl = $this->urlNormalizer->normalize($inputUrl);
        $accessToken = $this->normalizeCredential($accessToken);
        $json = $this->httpClient->getRegistry($normalizedUrl, $accessToken);
        $registry = $this->parser->parse($json);

        return $this->sources->add(
            $inputUrl,
            $normalizedUrl,
            $registry['name'],
            $json,
            $accessToken === null ? null : $this->credentialCipher->encrypt($accessToken),
            $accessToken === null ? null : $this->credentialCipher->fingerprint($accessToken)
        );
    }

    public function updateCredential(string $sourceId, ?string $accessToken): void
    {
        $source = $this->sources->get($sourceId);
        $accessToken = $this->normalizeCredential($accessToken);
        $json = $this->httpClient->getRegistry($source['normalizedUrl'], $accessToken);
        $registry = $this->parser->parse($json);
        $this->sources->updateCredential(
            $sourceId,
            $accessToken === null ? null : $this->credentialCipher->encrypt($accessToken),
            $accessToken === null ? null : $this->credentialCipher->fingerprint($accessToken),
            $registry['name'],
            $json
        );
    }

    public function refreshAll(): void
    {
        foreach ($this->sources->all() as $source) {
            if (!$source['enabled']) {
                continue;
            }

            try {
                $this->refreshSource($source);
            } catch (ExtensionMeshException $exception) {
                $this->sources->recordError($source['id'], $exception->getMessage());
            }
        }
    }

    /**
     * @return array{
     *     extensions: list<array<string, mixed>>,
     *     warnings: list<array{registryId: string, message: string}>
     * }
     */
    public function catalog(string $shopwareVersion, string $phpVersion, string $locale): array
    {
        $extensions = [];
        $warnings = [];
        $ownership = $this->ownership->all();

        foreach ($this->sources->all() as $source) {
            if (!$source['enabled']) {
                continue;
            }

            try {
                $registry = $this->loadSource($source);
            } catch (ExtensionMeshException $exception) {
                $warnings[] = ['registryId' => $source['id'], 'message' => $exception->getMessage()];
                continue;
            }

            foreach ($registry['extensions'] as $extension) {
                $release = $this->parser->newestCompatibleRelease(
                    $extension['releases'],
                    $shopwareVersion,
                    $phpVersion
                );
                if ($release === null) {
                    continue;
                }

                $entry = $this->toAdministrationEntry(
                    $source['id'],
                    $registry['name'],
                    $extension,
                    $release,
                    $locale,
                    isset($ownership[$extension['name']])
                        && \hash_equals($ownership[$extension['name']], $source['normalizedUrl'])
                );
                $technicalName = $extension['name'];
                if (isset($extensions[$technicalName])) {
                    $extensions[$technicalName]['extensionMesh']['conflict'] = true;
                    $extensions[$technicalName]['allowUpdate'] = false;
                    $warnings[] = [
                        'registryId' => $source['id'],
                        'message' => \sprintf(
                            'Extension "%s" is published by more than one configured registry; actions are disabled.',
                            $technicalName
                        ),
                    ];
                    continue;
                }

                $extensions[$technicalName] = $entry;
            }
        }

        return [
            'extensions' => \array_values($extensions),
            'warnings' => $warnings,
        ];
    }

    /**
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
    public function release(string $registryId, string $technicalName, string $shopwareVersion, string $phpVersion): array
    {
        $source = $this->sources->get($registryId);
        $registry = $this->loadSource($source);

        foreach ($registry['extensions'] as $extension) {
            if ($extension['name'] !== $technicalName) {
                continue;
            }

            $release = $this->parser->newestCompatibleRelease(
                $extension['releases'],
                $shopwareVersion,
                $phpVersion
            );
            if ($release === null) {
                throw ExtensionMeshException::extensionNotFound($technicalName);
            }

            return $release;
        }

        throw ExtensionMeshException::extensionNotFound($technicalName);
    }

    /**
     * @return array{
     *     release: array{
     *         version: string,
     *         shopware: string,
     *         php: ?string,
     *         downloadUrl: string,
     *         sha256: string,
     *         releasedAt: string,
     *         security: bool,
     *         changelogUrl: ?string
     *     },
     *     accessToken: ?string,
     *     credentialOrigin: string,
     *     registryUrl: string
     * }
     */
    public function download(
        string $registryId,
        string $technicalName,
        string $shopwareVersion,
        string $phpVersion
    ): array {
        $source = $this->sources->get($registryId);

        return [
            'release' => $this->release($registryId, $technicalName, $shopwareVersion, $phpVersion),
            'accessToken' => $this->credentialCipher->decrypt($source['credentialCiphertext']),
            'credentialOrigin' => $this->origin($source['normalizedUrl']),
            'registryUrl' => $source['normalizedUrl'],
        ];
    }

    /**
     * @param array{
     *     id: string,
     *     url: string,
     *     normalizedUrl: string,
     *     label: ?string,
     *     enabled: bool,
     *     credentialCiphertext: ?string,
     *     credentialFingerprint: ?string,
     *     cachedRegistry: ?string,
     *     lastRefreshedAt: ?string,
     *     lastError: ?string
     * } $source
     *
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
    private function loadSource(array $source): array
    {
        if ($this->isStale($source['lastRefreshedAt']) || $source['cachedRegistry'] === null) {
            try {
                return $this->refreshSource($source);
            } catch (ExtensionMeshException $exception) {
                $this->sources->recordError($source['id'], $exception->getMessage());
                if ($source['cachedRegistry'] === null) {
                    throw $exception;
                }
            }
        }

        return $this->parser->parse($source['cachedRegistry']);
    }

    /**
     * @param array{
     *     id: string,
     *     normalizedUrl: string,
     *     credentialCiphertext: ?string,
     *     cachedRegistry: ?string,
     *     lastRefreshedAt: ?string
     * } $source
     *
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
    private function refreshSource(array $source): array
    {
        $json = $this->httpClient->getRegistry(
            $source['normalizedUrl'],
            $this->credentialCipher->decrypt($source['credentialCiphertext'])
        );
        $registry = $this->parser->parse($json);
        $this->sources->updateCache($source['id'], $registry['name'], $json);

        return $registry;
    }

    private function isStale(?string $lastRefreshedAt): bool
    {
        if ($lastRefreshedAt === null) {
            return true;
        }

        $timestamp = \strtotime($lastRefreshedAt);

        return $timestamp === false || $timestamp < \time() - self::CACHE_TTL_SECONDS;
    }

    private function normalizeCredential(?string $credential): ?string
    {
        if ($credential === null || \trim($credential) === '') {
            return null;
        }
        $credential = \trim($credential);
        if (\strlen($credential) > 1024 || \preg_match('/[\x00-\x20\x7f]/', $credential)) {
            throw ExtensionMeshException::invalidCredential('it is too long or contains control characters.');
        }

        return $credential;
    }

    private function origin(string $url): string
    {
        $parts = \parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw ExtensionMeshException::invalidRegistryUrl('the normalized URL has no origin.');
        }

        return \strtolower($parts['scheme']) . '://' . \strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * @param array{
     *     name: string,
     *     label: array<string, string>,
     *     description: array<string, string>,
     *     manufacturer: ?string,
     *     license: ?string,
     *     homepage: ?string,
     *     icon: ?string
     * } $extension
     * @param array{
     *     version: string,
     *     shopware: string,
     *     php: ?string,
     *     downloadUrl: string,
     *     sha256: string,
     *     releasedAt: string,
     *     security: bool,
     *     changelogUrl: ?string
     * } $release
     *
     * @return array<string, mixed>
     */
    private function toAdministrationEntry(
        string $registryId,
        string $registryName,
        array $extension,
        array $release,
        string $locale,
        bool $owned
    ): array {
        return [
            'name' => $extension['name'],
            'label' => $this->translate($extension['label'], $locale, $extension['name']),
            'description' => $this->translate($extension['description'], $locale, ''),
            'version' => $release['version'],
            'latestVersion' => $release['version'],
            'type' => 'plugin',
            'source' => 'extension-mesh',
            'installedAt' => null,
            'updatedAt' => ['date' => $release['releasedAt']],
            'active' => false,
            'allowDisable' => true,
            'allowUpdate' => true,
            'configurable' => false,
            'isTheme' => false,
            'permissions' => [],
            'domains' => [],
            'privacyPolicyLink' => null,
            'privacyPolicyExtension' => null,
            'icon' => $extension['icon'],
            'iconRaw' => null,
            'extensionMesh' => [
                'registryId' => $registryId,
                'registryName' => $registryName,
                'manufacturer' => $extension['manufacturer'],
                'license' => $extension['license'],
                'homepage' => $extension['homepage'],
                'sha256' => $release['sha256'],
                'security' => $release['security'],
                'changelogUrl' => $release['changelogUrl'],
                'conflict' => false,
                'owned' => $owned,
            ],
        ];
    }

    /**
     * @param array<string, string> $values
     */
    private function translate(array $values, string $locale, string $fallback): string
    {
        return $values[$locale] ?? $values['en-GB'] ?? \reset($values) ?: $fallback;
    }
}
