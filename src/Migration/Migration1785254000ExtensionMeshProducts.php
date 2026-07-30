<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1785254000ExtensionMeshProducts extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1785254000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `extension_mesh_product` (
                `product_id` BINARY(16) NOT NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`product_id`),
                KEY `idx.extension_mesh_product.enabled` (`enabled`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
