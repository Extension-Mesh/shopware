<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1785256000ExtensionOwnership extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1785256000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `extension_mesh_extension_ownership` (
                `technical_name` VARCHAR(255) NOT NULL,
                `registry_url` VARCHAR(512) NOT NULL,
                `prepared_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`technical_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
