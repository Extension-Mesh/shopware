<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

final class PublicationRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<array{
     *     downloadId: string,
     *     productId: string,
     *     mediaId: string,
     *     fileName: string,
     *     fileExtension: string,
     *     fileSize: int,
     *     releaseNotes: ?string,
     *     fingerprint: string
     * }>
     */
    public function digitalProductDownloads(int $offset = 0, int $limit = 100): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT pd.id AS download_id,
                    pd.product_id,
                    pd.media_id,
                    m.file_name,
                    m.file_extension,
                    m.file_size,
                    repository_release.release_notes,
                    pd.created_at AS download_created_at,
                    pd.updated_at AS download_updated_at,
                    m.created_at AS media_created_at,
                    m.updated_at AS media_updated_at
               FROM product_download pd
               INNER JOIN media m ON m.id = pd.media_id
               LEFT JOIN extension_mesh_repository_release repository_release
                 ON repository_release.product_download_id = pd.id
              WHERE pd.version_id = :liveVersion
                AND pd.product_version_id = :liveVersion
                AND (
                    EXISTS (
                        SELECT 1
                          FROM extension_mesh_repository_connection connection
                         WHERE connection.product_id = pd.product_id
                           AND connection.enabled = 1
                    )
                    OR EXISTS (
                        SELECT 1
                          FROM extension_mesh_product integrated
                         WHERE integrated.product_id = pd.product_id
                           AND integrated.enabled = 1
                    )
                )
              ORDER BY pd.product_id, pd.position, pd.created_at, pd.id
              LIMIT :limit OFFSET :offset',
            [
                'liveVersion' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                'limit' => $limit,
                'offset' => $offset,
            ],
            [
                'limit' => ParameterType::INTEGER,
                'offset' => ParameterType::INTEGER,
            ]
        );

        return \array_map(static function (array $row): array {
            $fingerprintData = [
                Uuid::fromBytesToHex((string) $row['media_id']),
                (string) ($row['file_size'] ?? 0),
                (string) ($row['download_updated_at'] ?? $row['download_created_at'] ?? ''),
                (string) ($row['media_updated_at'] ?? $row['media_created_at'] ?? ''),
                (string) ($row['release_notes'] ?? ''),
            ];

            return [
                'downloadId' => Uuid::fromBytesToHex((string) $row['download_id']),
                'productId' => Uuid::fromBytesToHex((string) $row['product_id']),
                'mediaId' => Uuid::fromBytesToHex((string) $row['media_id']),
                'fileName' => (string) ($row['file_name'] ?? ''),
                'fileExtension' => \strtolower((string) ($row['file_extension'] ?? '')),
                'fileSize' => (int) ($row['file_size'] ?? 0),
                'releaseNotes' => \is_string($row['release_notes'] ?? null)
                    ? $row['release_notes']
                    : null,
                'fingerprint' => \hash('sha256', \implode("\0", $fingerprintData)),
            ];
        }, $rows);
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     limit: int
     * }
     */
    public function paginate(int $page, int $limit): array
    {
        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM extension_mesh_published_release'
        );
        $page = \min($page, \max(1, (int) \ceil($total / $limit)));
        $rows = $this->connection->fetchAllAssociative(
            'SELECT *
               FROM extension_mesh_published_release
              ORDER BY created_at, id
              LIMIT :limit OFFSET :offset',
            [
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
     * @param list<string> $downloadIds
     *
     * @return array<string, array<string, mixed>>
     */
    public function byDownloadIds(array $downloadIds): array
    {
        if ($downloadIds === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT *
               FROM extension_mesh_published_release
              WHERE product_download_id IN (:downloadIds)',
            ['downloadIds' => \array_map(Uuid::fromHexToBytes(...), $downloadIds)],
            ['downloadIds' => ArrayParameterType::BINARY]
        );
        $releases = [];
        foreach ($rows as $row) {
            $releases[Uuid::fromBytesToHex((string) $row['product_download_id'])] = $this->hydrate($row);
        }

        return $releases;
    }

    /**
     * @param list<string> $mediaIds
     *
     * @return array<string, array<string, mixed>>
     */
    public function byMediaIds(array $mediaIds): array
    {
        if ($mediaIds === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT pr.*
               FROM extension_mesh_published_release pr
              WHERE pr.media_id IN (:mediaIds)
                AND pr.validation_error IS NULL
                AND pr.metadata IS NOT NULL
                AND pr.sha256 IS NOT NULL
                AND (
                    EXISTS (
                        SELECT 1
                          FROM extension_mesh_repository_connection connection
                         WHERE connection.product_id = pr.product_id
                           AND connection.enabled = 1
                    )
                    OR EXISTS (
                        SELECT 1
                          FROM extension_mesh_product integrated
                         WHERE integrated.product_id = pr.product_id
                           AND integrated.enabled = 1
                    )
                )',
            ['mediaIds' => \array_map(Uuid::fromHexToBytes(...), $mediaIds)],
            ['mediaIds' => ArrayParameterType::BINARY]
        );
        $releases = [];
        foreach ($rows as $row) {
            $releases[Uuid::fromBytesToHex((string) $row['media_id'])] = $this->hydrate($row);
        }

        return $releases;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM extension_mesh_published_release WHERE id = :id',
            ['id' => Uuid::fromHexToBytes($id)]
        );

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function save(
        string $downloadId,
        string $productId,
        string $mediaId,
        string $fingerprint,
        ?array $metadata,
        ?string $sha256,
        ?string $validationError
    ): void {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO extension_mesh_published_release (
                    id,
                    product_download_id,
                    product_id,
                    media_id,
                    fingerprint,
                    technical_name,
                    version,
                    metadata,
                    sha256,
                    validation_error,
                    created_at,
                    updated_at
                ) VALUES (
                    :id,
                    :downloadId,
                    :productId,
                    :mediaId,
                    :fingerprint,
                    :technicalName,
                    :version,
                    :metadata,
                    :sha256,
                    :validationError,
                    :createdAt,
                    :updatedAt
                )
                ON DUPLICATE KEY UPDATE
                    product_id = VALUES(product_id),
                    media_id = VALUES(media_id),
                    fingerprint = VALUES(fingerprint),
                    technical_name = VALUES(technical_name),
                    version = VALUES(version),
                    metadata = VALUES(metadata),
                    sha256 = VALUES(sha256),
                    validation_error = VALUES(validation_error),
                    updated_at = VALUES(updated_at)
                SQL,
            [
                'id' => Uuid::fromHexToBytes(Uuid::randomHex()),
                'downloadId' => Uuid::fromHexToBytes($downloadId),
                'productId' => Uuid::fromHexToBytes($productId),
                'mediaId' => Uuid::fromHexToBytes($mediaId),
                'fingerprint' => $fingerprint,
                'technicalName' => $metadata['name'] ?? null,
                'version' => $metadata['version'] ?? null,
                'metadata' => $metadata === null ? null : \json_encode($metadata, \JSON_THROW_ON_ERROR),
                'sha256' => $sha256,
                'validationError' => $validationError,
                'createdAt' => $now,
                'updatedAt' => $now,
            ]
        );
    }

    public function removeMissingDownloads(): void
    {
        $this->connection->executeStatement(
            'DELETE pr
               FROM extension_mesh_published_release pr
              LEFT JOIN product_download pd
                 ON pd.id = pr.product_download_id
                AND pd.version_id = :liveVersion
                AND pd.product_version_id = :liveVersion
              WHERE pd.id IS NULL
                 OR NOT (
                    EXISTS (
                        SELECT 1
                          FROM extension_mesh_repository_connection connection
                         WHERE connection.product_id = pr.product_id
                           AND connection.enabled = 1
                    )
                    OR EXISTS (
                        SELECT 1
                          FROM extension_mesh_product integrated
                         WHERE integrated.product_id = pr.product_id
                           AND integrated.enabled = 1
                    )
                 )',
            ['liveVersion' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );
    }

    /**
     * @param list<string> $productIds
     *
     * @return list<array<string, mixed>>
     */
    public function validForProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $binaryIds = \array_map(Uuid::fromHexToBytes(...), $productIds);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT pr.*
               FROM extension_mesh_published_release pr
               INNER JOIN product_download pd
                 ON pd.id = pr.product_download_id
                AND pd.version_id = :liveVersion
              WHERE pr.product_id IN (:productIds)
                AND pr.validation_error IS NULL
                AND pr.metadata IS NOT NULL
                AND pr.sha256 IS NOT NULL
                AND (
                    EXISTS (
                        SELECT 1
                          FROM extension_mesh_repository_connection connection
                         WHERE connection.product_id = pr.product_id
                           AND connection.enabled = 1
                    )
                    OR EXISTS (
                        SELECT 1
                          FROM extension_mesh_product integrated
                         WHERE integrated.product_id = pr.product_id
                           AND integrated.enabled = 1
                    )
                )
              ORDER BY pr.technical_name, pr.version',
            [
                'liveVersion' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                'productIds' => $binaryIds,
            ],
            ['productIds' => ArrayParameterType::BINARY]
        );

        return \array_map($this->hydrate(...), $rows);
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
            'downloadId' => Uuid::fromBytesToHex((string) $row['product_download_id']),
            'productId' => Uuid::fromBytesToHex((string) $row['product_id']),
            'mediaId' => Uuid::fromBytesToHex((string) $row['media_id']),
            'fingerprint' => (string) $row['fingerprint'],
            'technicalName' => \is_string($row['technical_name'] ?? null) ? $row['technical_name'] : null,
            'version' => \is_string($row['version'] ?? null) ? $row['version'] : null,
            'metadata' => $metadata,
            'sha256' => \is_string($row['sha256'] ?? null) ? $row['sha256'] : null,
            'validationError' => \is_string($row['validation_error'] ?? null) ? $row['validation_error'] : null,
            'releasedAt' => (new \DateTimeImmutable((string) $row['created_at']))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
