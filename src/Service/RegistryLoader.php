<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Infrastructure\Http\SafeHttpClient;
use ExtensionMesh\Shopware\Repository\GithubRepositoryProvider;

final class RegistryLoader
{
    public function __construct(
        private readonly SafeHttpClient $http,
        private readonly GithubRepositoryProvider $github,
        private readonly PluginArchiveInspector $inspector,
        private readonly RegistryParser $parser
    ) {
    }

    public function load(string $normalizedUrl, ?string $accessToken, ?string $cachedRegistry = null): string
    {
        try {
            return $this->http->getRegistry($normalizedUrl, $accessToken);
        } catch (ExtensionMeshException $exception) {
            $repository = $this->githubRepository($normalizedUrl);
            if ($exception->getCode() !== 404 || $repository === null) {
                throw $exception;
            }
        }

        return $this->githubRegistry($repository, $accessToken ?? '', $cachedRegistry);
    }

    public function credentialOrigin(string $normalizedUrl, string $downloadUrl): string
    {
        $repository = $this->githubRepository($normalizedUrl);
        if (
            $repository !== null
            && \preg_match(
                '#^https://api\.github\.com/repos/' . \preg_quote($repository, '#') . '/releases/assets/[0-9]+$#iD',
                $downloadUrl
            )
        ) {
            return 'https://api.github.com';
        }

        $parts = \parse_url($normalizedUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw ExtensionMeshException::invalidRegistryUrl('the normalized URL has no origin.');
        }

        return \strtolower($parts['scheme']) . '://' . \strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private function githubRepository(string $normalizedUrl): ?string
    {
        if (!\preg_match(
            '#^https://raw\.githubusercontent\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)'
                . '/extension-mesh-registry/extension-mesh-registry\.json$#iD',
            $normalizedUrl,
            $match
        )) {
            return null;
        }

        return $match[1];
    }

    private function githubRegistry(string $repository, string $credential, ?string $cachedRegistry): string
    {
        $apiBaseUrl = $this->github->defaultApiBaseUrl();
        try {
            $releases = $this->github->releases($repository, $apiBaseUrl, $credential);
        } catch (ExtensionMeshException $exception) {
            if ($exception->getCode() === 404) {
                throw ExtensionMeshException::invalidRepository(\sprintf(
                    'GitHub repository "%s" was not found or the access token cannot read it.',
                    $repository
                ));
            }

            throw $exception;
        }

        $cachedAssets = [];
        if ($cachedRegistry !== null) {
            foreach ($this->parser->parse($cachedRegistry)['extensions'] as $extension) {
                foreach ($extension['releases'] as $release) {
                    $cachedAssets[$release['downloadUrl']] = ['extension' => $extension, 'release' => $release];
                }
            }
        }

        $extensions = [];
        $versions = [];
        $lastError = null;
        foreach ($releases as $providerRelease) {
            foreach ($providerRelease['assets'] as $asset) {
                $cached = $cachedAssets[$asset['apiUrl']] ?? null;
                if ($cached !== null) {
                    // GitHub assigns a new asset ID on replacement. Keep the inspected metadata and pinned digest.
                    $extension = $cached['extension'];
                    $release = $cached['release'];
                    $release['releasedAt'] = $providerRelease['publishedAt'];
                } else {
                    $path = $this->github->downloadAsset($apiBaseUrl, $credential, $asset);
                    try {
                        try {
                            $archive = $this->inspector->inspect($path);
                            $digest = \hash_file('sha256', $path);
                            if (!\is_string($digest)) {
                                throw ExtensionMeshException::artifactRejected('the ZIP digest could not be calculated.');
                            }
                            $candidate = $this->parser->parse((string) \json_encode([
                                'schemaVersion' => 1,
                                'name' => $repository,
                                'extensions' => [[
                                    'name' => $archive['name'],
                                    'label' => $archive['label'],
                                    'description' => $archive['description'],
                                    'manufacturer' => $archive['manufacturer'],
                                    'license' => $archive['license'],
                                    'homepage' => $archive['homepage'],
                                    'releases' => [[
                                        'version' => $archive['version'],
                                        'shopware' => $archive['shopware'],
                                        'php' => $archive['php'],
                                        'downloadUrl' => $asset['apiUrl'],
                                        'sha256' => $digest,
                                        'releasedAt' => $providerRelease['publishedAt'],
                                    ]],
                                ]],
                            ], \JSON_THROW_ON_ERROR));
                        } catch (ExtensionMeshException $exception) {
                            $lastError = $exception->getMessage();
                            continue;
                        }
                    } finally {
                        $expected = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . \basename($path);
                        if (\hash_equals($expected, $path) && \is_file($expected)) {
                            @\unlink(\sys_get_temp_dir() . \DIRECTORY_SEPARATOR . \basename($path));
                        }
                    }

                    $extension = $candidate['extensions'][0];
                    $release = $extension['releases'][0];
                }

                $name = $extension['name'];
                if (isset($versions[$name][$release['version']])) {
                    continue;
                }
                if (!isset($extensions[$name])) {
                    $extensions[$name] = $extension;
                    $extensions[$name]['releases'] = [];
                }
                if (\count($extensions[$name]['releases']) >= 200) {
                    continue;
                }
                $versions[$name][$release['version']] = true;
                $extensions[$name]['releases'][] = $release;
            }
        }

        if ($extensions === []) {
            throw ExtensionMeshException::invalidRepository(\sprintf(
                'GitHub repository "%s" has no ExtensionMesh registry and no stable release containing a valid'
                    . ' Shopware plugin ZIP. Publish a built plugin ZIP as a release asset; source archives are insufficient.%s',
                $repository,
                $lastError === null ? '' : ' Last rejected ZIP: ' . $lastError
            ));
        }

        return (string) \json_encode([
            'schemaVersion' => 1,
            'name' => $repository,
            'extensions' => \array_values($extensions),
        ], \JSON_THROW_ON_ERROR);
    }
}
