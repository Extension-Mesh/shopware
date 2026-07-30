<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1785255000RepositoryReleaseNotes extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1785255000;
    }

    public function update(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        if (!$schema->tablesExist(['extension_mesh_repository_release'])) {
            return;
        }
        $columns = $schema->listTableColumns('extension_mesh_repository_release');
        if (!isset($columns['release_notes'])) {
            $connection->executeStatement(<<<'SQL'
                ALTER TABLE `extension_mesh_repository_release`
                    ADD COLUMN `release_notes` LONGTEXT NULL AFTER `version`
            SQL);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
