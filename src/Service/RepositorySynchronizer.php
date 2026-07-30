<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Infrastructure\Persistence\RepositoryConnectionRepository;
use ExtensionMesh\Shopware\Repository\RepositoryProvider;
use ExtensionMesh\Shopware\Repository\RepositoryProviderRegistry;
use Shopware\Core\Content\Media\File\FileSaver;
use Shopware\Core\Content\Media\File\MediaFile;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Product\Aggregate\ProductDownload\ProductDownloadCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

final class RepositorySynchronizer
{
    public function __construct(
        private readonly RepositoryConnectionRepository $connections,
        private readonly RepositoryProviderRegistry $providers,
        private readonly RepositoryCredentialService $credentials,
        private readonly PluginArchiveInspector $inspector,
        private readonly RepositoryProductWriter $products,
        /** @var EntityRepository<MediaCollection> */
        private readonly EntityRepository $mediaRepository,
        /** @var EntityRepository<ProductDownloadCollection> */
        private readonly EntityRepository $productDownloadRepository,
        private readonly FileSaver $fileSaver
    ) {
    }

    /**
     * @return array{
     *     archive: array<string, mixed>,
     *     release: array<string, mixed>,
     *     asset: array<string, mixed>
     * }
     */
    public function discoverLatest(
        RepositoryProvider $provider,
        string $repository,
        string $apiBaseUrl,
        string $credential
    ): array {
        $offset = 0;
        do {
            $batch = $this->discoverLatestBatch(
                $provider,
                $repository,
                $apiBaseUrl,
                $credential,
                $offset
            );
            if (\is_array($batch['match'])) {
                return $batch['match'];
            }
            $offset = $batch['nextOffset'] ?? 0;
        } while ($batch['nextOffset'] !== null);

        throw ExtensionMeshException::invalidRepository(
            'no stable provider release contains a valid Shopware plugin ZIP.'
        );
    }

    /**
     * @return array{
     *     match: ?array{
     *         archive: array<string, mixed>,
     *         release: array<string, mixed>,
     *         asset: array<string, mixed>
     *     },
     *     nextOffset: ?int
     * }
     */
    public function discoverLatestBatch(
        RepositoryProvider $provider,
        string $repository,
        string $apiBaseUrl,
        string $credential,
        int $offset,
        int $limit = 5
    ): array {
        $releases = $provider->releases($repository, $apiBaseUrl, $credential);
        $offset = \max(0, $offset);
        $batch = \array_slice($releases, $offset, \max(1, $limit));
        $lastError = null;
        foreach ($batch as $release) {
            foreach ($release['assets'] as $asset) {
                $path = null;
                try {
                    $path = $provider->downloadAsset($apiBaseUrl, $credential, $asset);
                    try {
                        $archive = $this->inspector->inspect($path);
                    } catch (ExtensionMeshException $exception) {
                        $lastError = $exception->getMessage();
                        continue;
                    }

                    return [
                        'match' => [
                            'archive' => $archive,
                            'release' => $release,
                            'asset' => $asset,
                        ],
                        'nextOffset' => null,
                    ];
                } finally {
                    if ($path !== null) {
                        $this->removeTemporaryFile($path);
                    }
                }
            }
        }

        $nextOffset = $offset + \count($batch);
        if ($nextOffset < \count($releases)) {
            return ['match' => null, 'nextOffset' => $nextOffset];
        }

        throw ExtensionMeshException::invalidRepository(
            'no stable provider release contains a valid Shopware plugin ZIP'
            . ($lastError === null ? '.' : ': ' . $lastError)
        );
    }

    /**
     * @return array{imported: int, skipped: int}
     */
    public function synchronize(string $id, Context $context): array
    {
        $imported = 0;
        $skipped = 0;
        $offset = 0;
        do {
            $batch = $this->synchronizeBatch($id, $context, $offset);
            $imported += $batch['imported'];
            $skipped += $batch['skipped'];
            $offset = $batch['nextOffset'] ?? 0;
        } while (!$batch['finished']);

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * @return array{imported: int, skipped: int, finished: bool, nextOffset: ?int}
     */
    public function synchronizeBatch(
        string $id,
        Context $context,
        int $offset,
        int $limit = 5
    ): array {
        $connection = $this->connections->get($id);
        if ($connection === null) {
            throw ExtensionMeshException::repositoryNotFound($id);
        }
        if (!$connection['enabled']) {
            return ['imported' => 0, 'skipped' => 0, 'finished' => true, 'nextOffset' => null];
        }
        if (!$this->connections->acquireLock($id)) {
            return ['imported' => 0, 'skipped' => 0, 'finished' => true, 'nextOffset' => null];
        }

        try {
            return $this->doSynchronizeBatch($connection, $context, \max(0, $offset), \max(1, $limit));
        } catch (\Throwable $exception) {
            $this->connections->markFailed($id, $exception->getMessage());
            throw $exception;
        } finally {
            $this->connections->releaseLock($id);
        }
    }

    public function synchronizeAll(Context $context): void
    {
        foreach ($this->connections->all(true) as $connection) {
            try {
                $this->synchronize((string) $connection['id'], $context);
            } catch (\Throwable) {
                // The per-connection diagnostic is persisted by synchronize().
            }
        }
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array{imported: int, skipped: int, finished: bool, nextOffset: ?int}
     */
    private function doSynchronizeBatch(
        array $connection,
        Context $context,
        int $offset,
        int $limit
    ): array
    {
        $productId = $connection['productId'] ?? null;
        if (!\is_string($productId)) {
            throw ExtensionMeshException::invalidRepository('the connection has no linked Shopware product.');
        }
        $this->products->assertProductExists($productId);

        $credential = $this->credentials->resolve($connection);
        $provider = $this->providers->get((string) $connection['provider']);
        $releases = \array_reverse($provider->releases(
            (string) $connection['repository'],
            (string) $connection['apiBaseUrl'],
            $credential
        ));
        $total = \count($releases);
        $batch = \array_slice($releases, $offset, $limit);
        $nextOffset = $offset + \count($batch);
        $finished = $nextOffset >= $total;

        $expectedTechnicalName = \is_string($connection['technicalName'] ?? null)
            ? $connection['technicalName']
            : null;
        $imported = 0;
        $skipped = 0;
        foreach ($batch as $release) {
            if ($this->connections->hasImportedRelease((string) $connection['id'], $release['id'])) {
                $this->connections->updateImportedReleaseNotes(
                    (string) $connection['id'],
                    (string) $release['id'],
                    \is_string($release['releaseNotes'] ?? null)
                        ? $release['releaseNotes']
                        : null
                );
                ++$skipped;
                continue;
            }

            foreach ($release['assets'] as $asset) {
                $path = null;
                try {
                    $path = $provider->downloadAsset((string) $connection['apiBaseUrl'], $credential, $asset);
                    try {
                        $archive = $this->inspector->inspect($path);
                    } catch (ExtensionMeshException) {
                        ++$skipped;
                        continue;
                    }
                    if (
                        $expectedTechnicalName !== null
                        && $archive['name'] !== $expectedTechnicalName
                    ) {
                        ++$skipped;
                        continue;
                    }

                    if ($expectedTechnicalName === null) {
                        $expectedTechnicalName = $archive['name'];
                        $this->connections->setTechnicalName((string) $connection['id'], $expectedTechnicalName);
                    }
                    if ($this->connections->hasImportedVersion(
                        (string) $connection['id'],
                        (string) $archive['version']
                    )) {
                        ++$skipped;
                        break;
                    }

                    $this->import(
                        (string) $connection['id'],
                        $productId,
                        $release,
                        $asset,
                        $archive,
                        $path,
                        $context
                    );
                    ++$imported;
                    break;
                } finally {
                    if ($path !== null) {
                        $this->removeTemporaryFile($path);
                    }
                }
            }
        }

        if ($finished && $expectedTechnicalName === null) {
            throw ExtensionMeshException::invalidRepository('no release matched a Shopware plugin ZIP.');
        }

        if ($finished) {
            $this->products->markDigital($productId, $context);
            $this->connections->markSynchronized((string) $connection['id']);
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'finished' => $finished,
            'nextOffset' => $finished ? null : $nextOffset,
        ];
    }

    /**
     * @param array<string, mixed> $release
     * @param array<string, mixed> $asset
     * @param array<string, mixed> $archive
     */
    private function import(
        string $connectionId,
        string $productId,
        array $release,
        array $asset,
        array $archive,
        string $path,
        Context $context
    ): void {
        $mediaId = Uuid::randomHex();
        $downloadId = Uuid::randomHex();
        $this->mediaRepository->create([[
            'id' => $mediaId,
            'private' => true,
        ]], $context);

        try {
            $fileSize = \filesize($path);
            if (!\is_int($fileSize) || $fileSize < 1 || $fileSize > 100 * 1024 * 1024) {
                throw ExtensionMeshException::artifactRejected('the repository ZIP has an invalid size.');
            }
            $this->fileSaver->persistFileToMedia(
                new MediaFile($path, 'application/zip', 'zip', $fileSize),
                \strtolower((string) $archive['name'])
                    . '-' . (string) $archive['version']
                    . '-' . \substr(\hash('sha256', $connectionId), 0, 12),
                $mediaId,
                $context
            );
            $this->productDownloadRepository->create([[
                'id' => $downloadId,
                'productId' => $productId,
                'mediaId' => $mediaId,
                'position' => 0,
            ]], $context);

            $digest = \hash_file('sha256', $path);
            if (!\is_string($digest)) {
                throw ExtensionMeshException::artifactRejected('the repository ZIP digest could not be calculated.');
            }
            $this->connections->recordImportedAsset(
                $connectionId,
                (string) $release['id'],
                (string) $asset['id'],
                (string) $release['tag'],
                (string) $asset['name'],
                (string) $archive['version'],
                \is_string($release['releaseNotes'] ?? null)
                    ? $release['releaseNotes']
                    : null,
                $digest,
                $mediaId,
                $downloadId,
                (string) $release['publishedAt']
            );
        } catch (\Throwable $exception) {
            try {
                $this->productDownloadRepository->delete([['id' => $downloadId]], $context);
            } catch (\Throwable) {
            }
            try {
                $this->mediaRepository->delete([['id' => $mediaId]], $context);
            } catch (\Throwable) {
            }

            throw $exception;
        }
    }

    private function removeTemporaryFile(string $path): void
    {
        $expected = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . \basename($path);
        if (\hash_equals($expected, $path) && \is_file($expected)) {
            @\unlink(\sys_get_temp_dir() . \DIRECTORY_SEPARATOR . \basename($path));
        }
    }
}
