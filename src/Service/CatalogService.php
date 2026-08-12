<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Infrastructure\Http\RegistryUrlNormalizer;
use ExtensionMesh\Shopware\Infrastructure\Http\SafeHttpClient;
use ExtensionMesh\Shopware\Infrastructure\Persistence\ExtensionOwnershipRepository;
use ExtensionMesh\Shopware\Infrastructure\Persistence\RegistrySourceRepository;
use ExtensionMesh\Shopware\Infrastructure\Security\CredentialCipher;
use Shopware\Core\Framework\Context;

final class CatalogService implements CatalogManager
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

    public function addSource(string $inputUrl, ?string $accessToken, Context $context): string
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
            $accessToken === null ? null : $this->credentialCipher->fingerprint($accessToken),
            $context
        );
    }

    /** @return array{id: string, created: bool, credentialUpdated: bool} */
    public function addSourceIdempotently(string $inputUrl, ?string $accessToken, Context $context): array
    {
        $normalizedUrl = $this->urlNormalizer->normalize($inputUrl);
        $normalizedCredential = $this->normalizeCredential($accessToken);
        $existing = $this->sources->findByNormalizedUrl($normalizedUrl, $context);

        if ($existing === null) {
            return [
                'id' => $this->addSource($inputUrl, $normalizedCredential, $context),
                'created' => true,
                'credentialUpdated' => false,
            ];
        }

        if ($normalizedCredential !== null) {
            $this->updateCredential($existing['id'], $normalizedCredential, $context);
        }

        return [
            'id' => $existing['id'],
            'created' => false,
            'credentialUpdated' => $normalizedCredential !== null,
        ];
    }

    public function updateCredential(string $sourceId, ?string $accessToken, Context $context): void
    {
        $source = $this->sources->get($sourceId, $context);
        $accessToken = $this->normalizeCredential($accessToken);
        $json = $this->httpClient->getRegistry($source['normalizedUrl'], $accessToken);
        $registry = $this->parser->parse($json);
        $this->sources->updateCredential(
            $sourceId,
            $accessToken === null ? null : $this->credentialCipher->encrypt($accessToken),
            $accessToken === null ? null : $this->credentialCipher->fingerprint($accessToken),
            $registry['name'],
            $json,
            $context
        );
    }

    /**
     * @return list<array{id: string, url: string, label: ?string, success: bool, error: ?string}>
     */
    public function refreshAll(Context $context): array
    {
        $results = [];
        foreach ($this->sources->all($context) as $source) {
            if (!$source['enabled']) {
                continue;
            }

            try {
                $this->refreshSource($source, $context);
                $results[] = [
                    'id' => $source['id'],
                    'url' => $source['normalizedUrl'],
                    'label' => $source['label'],
                    'success' => true,
                    'error' => null,
                ];
            } catch (ExtensionMeshException $exception) {
                $this->sources->recordError($source['id'], $exception->getMessage(), $context);
                $results[] = [
                    'id' => $source['id'],
                    'url' => $source['normalizedUrl'],
                    'label' => $source['label'],
                    'success' => false,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * @return array{
     *     extensions: list<array<string, mixed>>,
     *     warnings: list<array{registryId: string, message: string}>
     * }
     */
    public function catalog(
        string $shopwareVersion,
        string $phpVersion,
        string $locale,
        Context $context,
        bool $refreshStale = true
    ): array {
        $extensions = [];
        $warnings = [];
        $ownership = $this->ownership->all($context);

        foreach ($this->sources->all($context) as $source) {
            if (!$source['enabled']) {
                continue;
            }

            try {
                $registry = $this->loadSource($source, $context, $refreshStale);
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
                    $source['normalizedUrl'],
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
    public function release(
        string $registryId,
        string $technicalName,
        string $shopwareVersion,
        string $phpVersion,
        Context $context,
        bool $refreshStale = true
    ): array {
        $source = $this->sources->get($registryId, $context);
        $registry = $this->loadSource($source, $context, $refreshStale);

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
        string $phpVersion,
        Context $context,
        bool $refreshStale = true
    ): array {
        $source = $this->sources->get($registryId, $context);

        return [
            'release' => $this->release(
                $registryId,
                $technicalName,
                $shopwareVersion,
                $phpVersion,
                $context,
                $refreshStale
            ),
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
    private function loadSource(array $source, Context $context, bool $refreshStale = true): array
    {
        if ($refreshStale && ($this->isStale($source['lastRefreshedAt']) || $source['cachedRegistry'] === null)) {
            try {
                return $this->refreshSource($source, $context);
            } catch (ExtensionMeshException $exception) {
                $this->sources->recordError($source['id'], $exception->getMessage(), $context);
                if ($source['cachedRegistry'] === null) {
                    throw $exception;
                }
            }
        }

        if ($source['cachedRegistry'] === null) {
            throw ExtensionMeshException::registryUnavailable('no cached registry is available.');
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
    private function refreshSource(array $source, Context $context): array
    {
        $json = $this->httpClient->getRegistry(
            $source['normalizedUrl'],
            $this->credentialCipher->decrypt($source['credentialCiphertext'])
        );
        $registry = $this->parser->parse($json);
        $this->sources->updateCache($source['id'], $registry['name'], $json, $context);

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
        string $registryUrl,
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
                'registryUrl' => $registryUrl,
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
