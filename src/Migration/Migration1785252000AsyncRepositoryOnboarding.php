<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1785252000AsyncRepositoryOnboarding extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1785252000;
    }

    public function update(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        if (!$schema->tablesExist(['extension_mesh_repository_connection'])) {
            return;
        }
        $columns = $schema->listTableColumns('extension_mesh_repository_connection');
        if (!isset($columns['onboarding_mode'])) {
            $connection->executeStatement(<<<'SQL'
                ALTER TABLE `extension_mesh_repository_connection`
                    ADD COLUMN `onboarding_mode` VARCHAR(16) NULL AFTER `config_path`
            SQL);
        }
        if (!isset($columns['onboarding_status'])) {
            $connection->executeStatement(<<<'SQL'
                ALTER TABLE `extension_mesh_repository_connection`
                    ADD COLUMN `onboarding_status` VARCHAR(32) NOT NULL DEFAULT 'ready'
                    AFTER `onboarding_mode`
            SQL);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
