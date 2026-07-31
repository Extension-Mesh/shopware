<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Infrastructure\Persistence\PublicationRepository;
use Psr\Http\Message\StreamInterface;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Symfony\Component\Filesystem\Filesystem;

final class PublicationSynchronizer
{
    private const MAX_ARCHIVE_BYTES = 100 * 1024 * 1024;
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly PublicationRepository $releases,
        private readonly MediaService $mediaService,
        private readonly PluginArchiveInspector $inspector,
        private readonly Filesystem $filesystem
    ) {
    }

    public function synchronize(Context $context): void
    {
        $offset = 0;
        do {
            $downloads = $this->releases->digitalProductDownloads(
                $offset,
                self::BATCH_SIZE,
                $context
            );
            $cached = $this->releases->byDownloadIds(
                \array_column($downloads, 'downloadId'),
                $context
            );

            foreach ($downloads as $download) {
                if (
                    isset($cached[$download['downloadId']])
                    && $cached[$download['downloadId']]['fingerprint'] === $download['fingerprint']
                ) {
                    continue;
                }

                if ($download['fileExtension'] !== 'zip') {
                    $this->saveError($download, 'Only ZIP downloads can be published.', $context);
                    continue;
                }
                if ($download['fileSize'] <= 0 || $download['fileSize'] > self::MAX_ARCHIVE_BYTES) {
                    $this->saveError($download, 'The ZIP must contain between 1 byte and 100 MiB.', $context);
                    continue;
                }

                $temporaryPath = \tempnam(\sys_get_temp_dir(), 'extension-mesh-publish-');
                if (!\is_string($temporaryPath)) {
                    throw ExtensionMeshException::registryUnavailable('a publication temporary file could not be created.');
                }

                try {
                    $stream = $context->scope(
                        Context::SYSTEM_SCOPE,
                        fn (Context $systemContext): StreamInterface => $this->mediaService->loadFileStream(
                            $download['mediaId'],
                            $systemContext
                        )
                    );
                    $this->copyStream($stream, $temporaryPath);
                    $metadata = $this->inspector->inspect($temporaryPath);
                    $digest = \hash_file('sha256', $temporaryPath);
                    if (!\is_string($digest)) {
                        throw ExtensionMeshException::artifactRejected('the archive digest could not be calculated.');
                    }

                    $this->releases->save(
                        $download['downloadId'],
                        $download['productId'],
                        $download['mediaId'],
                        $download['fingerprint'],
                        [
                            ...$metadata,
                            'releaseNotes' => \is_string($download['releaseNotes'] ?? null)
                                ? $download['releaseNotes']
                                : null,
                        ],
                        $digest,
                        null,
                        $context
                    );
                } catch (\Throwable $exception) {
                    $this->saveError($download, $exception->getMessage(), $context);
                } finally {
                    $this->filesystem->remove($temporaryPath);
                }
            }

            $offset += \count($downloads);
        } while (\count($downloads) === self::BATCH_SIZE);

        $this->releases->removeMissingDownloads($context);
    }

    /**
     * @param array<string, mixed> $download
     */
    private function saveError(array $download, string $message, Context $context): void
    {
        $this->releases->save(
            (string) $download['downloadId'],
            (string) $download['productId'],
            (string) $download['mediaId'],
            (string) $download['fingerprint'],
            null,
            null,
            \mb_substr($message, 0, 65535),
            $context
        );
    }

    private function copyStream(StreamInterface $stream, string $target): void
    {
        $file = new \SplFileObject($target, 'wb');
        $bytes = 0;

        while (!$stream->eof()) {
            $chunk = $stream->read(1024 * 1024);
            $bytes += \strlen($chunk);
            if ($bytes > self::MAX_ARCHIVE_BYTES) {
                throw ExtensionMeshException::artifactRejected('the archive exceeds the 100 MiB limit.');
            }
            if ($chunk !== '' && $file->fwrite($chunk) !== \strlen($chunk)) {
                throw ExtensionMeshException::registryUnavailable('the publication archive could not be buffered.');
            }
        }
    }
}
