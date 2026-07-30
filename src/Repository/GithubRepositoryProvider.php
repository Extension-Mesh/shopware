<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Repository;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Infrastructure\Http\SafeHttpClient;

final class GithubRepositoryProvider implements RepositoryProvider
{
    private const DEFAULT_API_BASE_URL = 'https://api.github.com';

    public function __construct(private readonly SafeHttpClient $http)
    {
    }

    public function key(): string
    {
        return 'github';
    }

    public function label(): string
    {
        return 'GitHub';
    }

    public function defaultApiBaseUrl(): string
    {
        return self::DEFAULT_API_BASE_URL;
    }

    public function inspect(string $repository, string $apiBaseUrl, string $credential): array
    {
        $repository = $this->normalizeRepository($repository);
        $apiBaseUrl = $this->normalizeApiBaseUrl($apiBaseUrl);
        $document = $this->json(
            $this->repositoryUrl($repository, $apiBaseUrl),
            $credential,
            $apiBaseUrl
        );

        $defaultBranch = $document['default_branch'] ?? null;
        $webUrl = $document['html_url'] ?? null;
        $private = $document['private'] ?? null;
        $fullName = $document['full_name'] ?? null;
        if (
            !\is_string($defaultBranch)
            || !\preg_match('/^[^\x00-\x1f\x7f]{1,255}$/D', $defaultBranch)
            || !\is_string($webUrl)
            || !\is_bool($private)
            || !\is_string($fullName)
            || \strcasecmp($fullName, $repository) !== 0
        ) {
            throw ExtensionMeshException::invalidRepository('GitHub returned incomplete repository metadata.');
        }

        return [
            'repository' => $fullName,
            'apiBaseUrl' => $apiBaseUrl,
            'webUrl' => $webUrl,
            'defaultBranch' => $defaultBranch,
            'private' => $private,
        ];
    }

    public function releases(string $repository, string $apiBaseUrl, string $credential): array
    {
        $repository = $this->normalizeRepository($repository);
        $apiBaseUrl = $this->normalizeApiBaseUrl($apiBaseUrl);
        $documents = $this->jsonList(
            $this->repositoryUrl($repository, $apiBaseUrl) . '/releases?per_page=100',
            $credential,
            $apiBaseUrl
        );
        $releases = [];

        foreach ($documents as $document) {
            if (
                !\is_array($document)
                || ($document['draft'] ?? true) === true
                || ($document['prerelease'] ?? true) === true
            ) {
                continue;
            }

            $id = $document['id'] ?? null;
            $tag = $document['tag_name'] ?? null;
            $name = $document['name'] ?? $tag;
            $releaseNotes = $document['body'] ?? null;
            $publishedAt = $document['published_at'] ?? null;
            if (
                !(\is_int($id) || \is_string($id))
                || !\is_string($tag)
                || $tag === ''
                || !\is_string($name)
                || ($releaseNotes !== null && !\is_string($releaseNotes))
                || !\is_string($publishedAt)
            ) {
                continue;
            }

            $assets = [];
            foreach (\is_array($document['assets'] ?? null) ? $document['assets'] : [] as $asset) {
                if (!\is_array($asset)) {
                    continue;
                }
                $assetId = $asset['id'] ?? null;
                $assetName = $asset['name'] ?? null;
                $assetSize = $asset['size'] ?? null;
                $assetApiUrl = $asset['url'] ?? null;
                if (
                    !(\is_int($assetId) || \is_string($assetId))
                    || !\is_string($assetName)
                    || !\preg_match('/\.zip$/iD', $assetName)
                    || !\is_int($assetSize)
                    || $assetSize < 1
                    || $assetSize > 100 * 1024 * 1024
                    || !\is_string($assetApiUrl)
                    || !$this->isAssetUrl($assetApiUrl, $repository, $apiBaseUrl)
                ) {
                    continue;
                }

                $assets[] = [
                    'id' => (string) $assetId,
                    'name' => \mb_substr($assetName, 0, 255),
                    'size' => $assetSize,
                    'apiUrl' => $assetApiUrl,
                ];
            }

            if ($assets !== []) {
                $releases[] = [
                    'id' => (string) $id,
                    'tag' => \mb_substr($tag, 0, 255),
                    'name' => \mb_substr($name, 0, 255),
                    'releaseNotes' => \is_string($releaseNotes) && \trim($releaseNotes) !== ''
                        ? \mb_substr(\trim($releaseNotes), 0, 100000)
                        : null,
                    'publishedAt' => $this->date($publishedAt),
                    'assets' => $assets,
                ];
            }
        }

        return $releases;
    }

    public function readFile(
        string $repository,
        string $apiBaseUrl,
        string $credential,
        string $path,
        string $reference
    ): ?string {
        $repository = $this->normalizeRepository($repository);
        $apiBaseUrl = $this->normalizeApiBaseUrl($apiBaseUrl);
        $path = $this->normalizePath($path);
        if ($reference === '' || \preg_match('/[\x00-\x20\x7f]/', $reference)) {
            throw ExtensionMeshException::invalidRepository('the Git reference is invalid.');
        }

        $url = $this->repositoryUrl($repository, $apiBaseUrl)
            . '/contents/' . \implode('/', \array_map('rawurlencode', \explode('/', $path)))
            . '?ref=' . \rawurlencode($reference);

        try {
            return $this->http->getDocument(
                $url,
                $this->headers($credential),
                $this->origin($apiBaseUrl),
                'application/vnd.github.raw+json',
                2 * 1024 * 1024
            );
        } catch (ExtensionMeshException $exception) {
            if (\str_contains($exception->getMessage(), 'HTTP 404')) {
                return null;
            }

            throw $exception;
        }
    }

    public function listFiles(
        string $repository,
        string $apiBaseUrl,
        string $credential,
        string $path,
        string $reference
    ): array {
        $repository = $this->normalizeRepository($repository);
        $apiBaseUrl = $this->normalizeApiBaseUrl($apiBaseUrl);
        $path = $this->normalizePath($path);
        if ($reference === '' || \preg_match('/[\x00-\x20\x7f]/', $reference)) {
            throw ExtensionMeshException::invalidRepository('the Git reference is invalid.');
        }

        $url = $this->repositoryUrl($repository, $apiBaseUrl)
            . '/contents/' . \implode('/', \array_map('rawurlencode', \explode('/', $path)))
            . '?ref=' . \rawurlencode($reference);
        try {
            $documents = $this->jsonList($url, $credential, $apiBaseUrl);
        } catch (ExtensionMeshException $exception) {
            if (\str_contains($exception->getMessage(), 'HTTP 404')) {
                return [];
            }

            throw $exception;
        }

        $files = [];
        $prefix = $path . '/';
        foreach ($documents as $document) {
            if (!\is_array($document) || ($document['type'] ?? null) !== 'file') {
                continue;
            }
            $filePath = $document['path'] ?? null;
            $size = $document['size'] ?? null;
            if (
                !\is_string($filePath)
                || !\str_starts_with($filePath, $prefix)
                || \str_contains(\substr($filePath, \strlen($prefix)), '/')
                || !\is_int($size)
                || $size < 1
                || $size > 2 * 1024 * 1024
            ) {
                continue;
            }
            $files[] = ['path' => $this->normalizePath($filePath), 'size' => $size];
        }
        \usort(
            $files,
            static fn (array $left, array $right): int => \strnatcasecmp($left['path'], $right['path'])
        );

        return $files;
    }

    public function downloadAsset(string $apiBaseUrl, string $credential, array $asset): string
    {
        $apiBaseUrl = $this->normalizeApiBaseUrl($apiBaseUrl);
        if (
            $asset['size'] < 1
            || $asset['size'] > 100 * 1024 * 1024
            || !\str_starts_with($asset['apiUrl'], $apiBaseUrl . '/')
        ) {
            throw ExtensionMeshException::invalidRepository('the release asset is invalid.');
        }

        return $this->http->downloadFile(
            $asset['apiUrl'],
            $this->headers($credential),
            $this->origin($apiBaseUrl),
            'application/octet-stream'
        );
    }

    private function normalizeRepository(string $repository): string
    {
        $repository = \trim($repository);
        if (\preg_match('#^https://github\.com/([^/]+/[^/]+?)(?:\.git)?/?$#iD', $repository, $match)) {
            $repository = $match[1];
        }
        $repository = \preg_replace('/\.git$/iD', '', $repository) ?? $repository;
        if (
            !\preg_match('/^[A-Za-z0-9_.-]{1,100}\/[A-Za-z0-9_.-]{1,100}$/D', $repository)
            || \str_contains($repository, '..')
        ) {
            throw ExtensionMeshException::invalidRepository('use a GitHub "owner/repository" name.');
        }

        return $repository;
    }

    private function normalizeApiBaseUrl(string $apiBaseUrl): string
    {
        $apiBaseUrl = \rtrim(\trim($apiBaseUrl), '/');
        if ($apiBaseUrl === '') {
            return self::DEFAULT_API_BASE_URL;
        }
        $parts = \parse_url($apiBaseUrl);
        if (
            $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || !\in_array(\strtolower($parts['scheme']), ['https', 'http'], true)
        ) {
            throw ExtensionMeshException::invalidRepository('the provider API base URL is invalid.');
        }

        return $apiBaseUrl;
    }

    private function repositoryUrl(string $repository, string $apiBaseUrl): string
    {
        return $apiBaseUrl . '/repos/' . \implode(
            '/',
            \array_map('rawurlencode', \explode('/', $repository))
        );
    }

    private function isAssetUrl(string $url, string $repository, string $apiBaseUrl): bool
    {
        return \str_starts_with(
            $url,
            $this->repositoryUrl($repository, $apiBaseUrl) . '/releases/assets/'
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $credential): array
    {
        $credential = \trim($credential);
        if ($credential === '') {
            return [];
        }
        if (\strlen($credential) > 1024 || \preg_match('/[\x00-\x20\x7f]/', $credential)) {
            throw ExtensionMeshException::invalidRepository('the GitHub access token is invalid.');
        }

        return ['Authorization' => 'Bearer ' . $credential];
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $url, string $credential, string $apiBaseUrl): array
    {
        $decoded = $this->decode($url, $credential, $apiBaseUrl);
        if (\array_is_list($decoded)) {
            throw ExtensionMeshException::invalidRepository('GitHub returned an unexpected JSON document.');
        }

        return $decoded;
    }

    /**
     * @return list<mixed>
     */
    private function jsonList(string $url, string $credential, string $apiBaseUrl): array
    {
        $decoded = $this->decode($url, $credential, $apiBaseUrl);
        if (!\array_is_list($decoded)) {
            throw ExtensionMeshException::invalidRepository('GitHub returned an unexpected JSON list.');
        }

        return $decoded;
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $url, string $credential, string $apiBaseUrl): array
    {
        $json = $this->http->getDocument(
            $url,
            $this->headers($credential),
            $this->origin($apiBaseUrl),
            'application/vnd.github+json'
        );
        try {
            $decoded = \json_decode($json, true, 128, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw ExtensionMeshException::invalidRepository('GitHub returned invalid JSON: ' . $exception->getMessage());
        }
        if (!\is_array($decoded)) {
            throw ExtensionMeshException::invalidRepository('GitHub returned an unexpected document.');
        }

        return $decoded;
    }

    private function normalizePath(string $path): string
    {
        $path = \str_replace('\\', '/', \trim($path));
        if (
            $path === ''
            || \str_starts_with($path, '/')
            || \str_contains('/' . $path . '/', '/../')
            || \preg_match('/[\x00-\x1f\x7f]/', $path)
        ) {
            throw ExtensionMeshException::invalidRepository('a repository file path is invalid.');
        }

        return $path;
    }

    private function date(string $date): string
    {
        try {
            return (new \DateTimeImmutable($date))->format(\DATE_ATOM);
        } catch (\Exception) {
            throw ExtensionMeshException::invalidRepository('a GitHub release date is invalid.');
        }
    }

    private function origin(string $url): string
    {
        $parts = \parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw ExtensionMeshException::invalidRepository('the provider credential origin is invalid.');
        }

        return \strtolower($parts['scheme']) . '://' . \strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
