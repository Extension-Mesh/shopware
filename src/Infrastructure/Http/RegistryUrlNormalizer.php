<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Infrastructure\Http;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;

final class RegistryUrlNormalizer
{
    private const MAX_NORMALIZED_URL_LENGTH = 512;

    public function normalize(string $input): string
    {
        $input = \trim($input);
        if ($input === '' || \strlen($input) > 2048) {
            throw ExtensionMeshException::invalidRegistryUrl('the URL is empty or too long.');
        }

        $parts = \parse_url($input);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw ExtensionMeshException::invalidRegistryUrl('an absolute HTTP(S) URL is required.');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw ExtensionMeshException::invalidRegistryUrl('credentials and fragments are not allowed.');
        }

        $host = \strtolower($parts['host']);
        $path = $parts['path'] ?? '/';

        if ($host === 'github.com') {
            $segments = \array_values(\array_filter(\explode('/', \trim($path, '/')), static fn (string $part): bool => $part !== ''));
            if (\count($segments) === 2) {
                [$owner, $repository] = $segments;
                $repository = \preg_replace('/\.git$/i', '', $repository) ?? $repository;
                $this->assertGitHubSegment($owner);
                $this->assertGitHubSegment($repository);

                return $this->assertStorable(\sprintf(
                    'https://github.com/%s/%s/releases/latest/download/extension-mesh-registry.json',
                    $owner,
                    $repository
                ));
            }
        }

        if (!\in_array(\strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw ExtensionMeshException::invalidRegistryUrl('only HTTP(S) URLs are supported.');
        }

        return $this->assertStorable($input);
    }

    private function assertGitHubSegment(string $segment): void
    {
        if (!\preg_match('/^[A-Za-z0-9_.-]+$/', $segment)) {
            throw ExtensionMeshException::invalidRegistryUrl('the GitHub repository path is invalid.');
        }
    }

    private function assertStorable(string $url): string
    {
        if (\strlen($url) > self::MAX_NORMALIZED_URL_LENGTH) {
            throw ExtensionMeshException::invalidRegistryUrl('the normalized URL is longer than 512 bytes.');
        }

        return $url;
    }
}
