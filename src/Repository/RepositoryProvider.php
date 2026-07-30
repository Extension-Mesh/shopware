<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Repository;

interface RepositoryProvider
{
    public function key(): string;

    public function label(): string;

    public function defaultApiBaseUrl(): string;

    /**
     * @return array{
     *     repository: string,
     *     apiBaseUrl: string,
     *     webUrl: string,
     *     defaultBranch: string,
     *     private: bool
     * }
     */
    public function inspect(string $repository, string $apiBaseUrl, string $credential): array;

    /**
     * @return list<array{
     *     id: string,
     *     tag: string,
     *     name: string,
     *     releaseNotes: ?string,
     *     publishedAt: string,
     *     assets: list<array{id: string, name: string, size: int, apiUrl: string}>
     * }>
     */
    public function releases(string $repository, string $apiBaseUrl, string $credential): array;

    public function readFile(
        string $repository,
        string $apiBaseUrl,
        string $credential,
        string $path,
        string $reference
    ): ?string;

    /**
     * @return list<array{path: string, size: int}>
     */
    public function listFiles(
        string $repository,
        string $apiBaseUrl,
        string $credential,
        string $path,
        string $reference
    ): array;

    /**
     * @param array{id: string, name: string, size: int, apiUrl: string} $asset
     */
    public function downloadAsset(string $apiBaseUrl, string $credential, array $asset): string;
}
