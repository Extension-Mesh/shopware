<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1753603200CreateRegistrySource extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1753603200;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `extension_mesh_registry_source` (
                `id` BINARY(16) NOT NULL,
                `url` VARCHAR(2048) NOT NULL,
                `normalized_url` VARCHAR(512) NOT NULL,
                `label` VARCHAR(255) NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `cached_registry` LONGTEXT NULL,
                `last_refreshed_at` DATETIME(3) NULL,
                `last_error` LONGTEXT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.extension_mesh_registry_source.normalized_url` (`normalized_url`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}

