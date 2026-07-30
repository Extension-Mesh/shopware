<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

final class ExtensionMeshProductRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{enabled: bool, source: ?string}
     */
    public function status(string $productId): array
    {
        if (!Uuid::isValid($productId)) {
            return ['enabled' => false, 'source' => null];
        }
        $binaryId = Uuid::fromHexToBytes($productId);
        if ((bool) $this->connection->fetchOne(
            'SELECT 1
               FROM extension_mesh_repository_connection
              WHERE product_id = :productId
                AND enabled = 1
              LIMIT 1',
            ['productId' => $binaryId]
        )) {
            return ['enabled' => true, 'source' => 'repository'];
        }
        if ((bool) $this->connection->fetchOne(
            'SELECT 1
               FROM extension_mesh_product
              WHERE product_id = :productId
                AND enabled = 1',
            ['productId' => $binaryId]
        )) {
            return ['enabled' => true, 'source' => 'manual'];
        }

        return ['enabled' => false, 'source' => null];
    }

    public function setManual(string $productId, bool $enabled): void
    {
        if (!Uuid::isValid($productId)) {
            return;
        }
        $binaryId = Uuid::fromHexToBytes($productId);
        if (!$enabled) {
            $this->connection->delete('extension_mesh_product', ['product_id' => $binaryId]);

            return;
        }
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO extension_mesh_product (product_id, enabled, created_at)
                VALUES (:productId, 1, :createdAt)
                ON DUPLICATE KEY UPDATE enabled = 1, updated_at = :updatedAt
                SQL,
            [
                'productId' => $binaryId,
                'createdAt' => $now,
                'updatedAt' => $now,
            ]
        );
    }
}
