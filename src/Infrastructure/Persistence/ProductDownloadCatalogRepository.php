<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

final class ProductDownloadCatalogRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int}
     */
    public function paginate(string $productId, int $page, int $limit): array
    {
        if (!Uuid::isValid($productId)) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'limit' => $limit];
        }
        $parameters = [
            'productId' => Uuid::fromHexToBytes($productId),
            'liveVersion' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
        ];
        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
               FROM product_download
              WHERE product_id = :productId
                AND product_version_id = :liveVersion
                AND version_id = :liveVersion',
            $parameters
        );
        $page = \min($page, \max(1, (int) \ceil($total / $limit)));
        $rows = $this->connection->fetchAllAssociative(
            'SELECT pd.id,
                    pd.media_id,
                    pd.position,
                    pd.created_at,
                    media.file_name,
                    media.file_extension,
                    media.file_size,
                    media.mime_type,
                    published_release.id AS release_id,
                    published_release.technical_name,
                    published_release.version,
                    published_release.metadata,
                    published_release.validation_error
               FROM product_download pd
               INNER JOIN media ON media.id = pd.media_id
               LEFT JOIN extension_mesh_published_release published_release
                 ON published_release.product_download_id = pd.id
              WHERE pd.product_id = :productId
                AND pd.product_version_id = :liveVersion
                AND pd.version_id = :liveVersion
              ORDER BY pd.created_at DESC, pd.id DESC
              LIMIT :limit OFFSET :offset',
            [
                ...$parameters,
                'limit' => $limit,
                'offset' => ($page - 1) * $limit,
            ],
            [
                'limit' => ParameterType::INTEGER,
                'offset' => ParameterType::INTEGER,
            ]
        );

        return [
            'items' => \array_map($this->hydrate(...), $rows),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $metadata = null;
        if (\is_string($row['metadata'] ?? null)) {
            $decoded = \json_decode($row['metadata'], true);
            $metadata = \is_array($decoded) ? $decoded : null;
        }

        return [
            'id' => Uuid::fromBytesToHex((string) $row['id']),
            'mediaId' => Uuid::fromBytesToHex((string) $row['media_id']),
            'fileName' => (string) $row['file_name']
                . ((string) $row['file_extension'] === '' ? '' : '.' . (string) $row['file_extension']),
            'fileSize' => (int) ($row['file_size'] ?? 0),
            'mimeType' => (string) ($row['mime_type'] ?? ''),
            'position' => (int) $row['position'],
            'createdAt' => (string) $row['created_at'],
            'releaseId' => $row['release_id'] === null
                ? null
                : Uuid::fromBytesToHex((string) $row['release_id']),
            'technicalName' => \is_string($row['technical_name'] ?? null)
                ? $row['technical_name']
                : null,
            'version' => \is_string($row['version'] ?? null) ? $row['version'] : null,
            'shopware' => \is_string($metadata['shopware'] ?? null) ? $metadata['shopware'] : null,
            'php' => \is_string($metadata['php'] ?? null) ? $metadata['php'] : null,
            'validationError' => \is_string($row['validation_error'] ?? null)
                ? $row['validation_error']
                : null,
            'publicationStatus' => $row['release_id'] === null
                ? 'pending'
                : ($row['validation_error'] === null ? 'published' : 'rejected'),
        ];
    }
}
