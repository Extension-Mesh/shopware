<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1785253000RepositoryProcessingStage extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1785253000;
    }

    public function update(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        if (!$schema->tablesExist(['extension_mesh_repository_connection'])) {
            return;
        }
        $columns = $schema->listTableColumns('extension_mesh_repository_connection');
        if (!isset($columns['onboarding_stage'])) {
            $connection->executeStatement(<<<'SQL'
                ALTER TABLE `extension_mesh_repository_connection`
                    ADD COLUMN `onboarding_stage` VARCHAR(16) NULL AFTER `onboarding_status`
            SQL);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
