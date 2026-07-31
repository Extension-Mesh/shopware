<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1785259000CompleteDalSchema extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1785259000;
    }

    public function update(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        $ownershipColumns = $schema->listTableColumns('extension_mesh_extension_ownership');
        if (!isset($ownershipColumns['created_at'])) {
            $connection->executeStatement(<<<'SQL'
                ALTER TABLE `extension_mesh_extension_ownership`
                    ADD COLUMN `created_at` DATETIME(3) NULL AFTER `prepared_at`,
                    ADD COLUMN `updated_at` DATETIME(3) NULL AFTER `created_at`
            SQL);
            $connection->executeStatement(<<<'SQL'
                UPDATE `extension_mesh_extension_ownership`
                SET `created_at` = `prepared_at`
                WHERE `created_at` IS NULL
            SQL);
            $connection->executeStatement(<<<'SQL'
                ALTER TABLE `extension_mesh_extension_ownership`
                    MODIFY COLUMN `created_at` DATETIME(3) NOT NULL
            SQL);
        }

        $releaseColumns = $schema->listTableColumns('extension_mesh_repository_release');
        if (!isset($releaseColumns['updated_at'])) {
            $connection->executeStatement(<<<'SQL'
                ALTER TABLE `extension_mesh_repository_release`
                    ADD COLUMN `updated_at` DATETIME(3) NULL AFTER `created_at`
            SQL);
        }

        $this->addMediaForeignKey(
            $connection,
            'extension_mesh_published_release',
            'fk.extension_mesh_published_release.media'
        );
        $this->addMediaForeignKey(
            $connection,
            'extension_mesh_repository_release',
            'fk.extension_mesh_repository_release.media'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function addMediaForeignKey(Connection $connection, string $table, string $name): void
    {
        foreach ($connection->createSchemaManager()->listTableForeignKeys($table) as $foreignKey) {
            if ($foreignKey->getName() === $name) {
                return;
            }
        }

        $connection->executeStatement(\sprintf(
            'ALTER TABLE `%s`
                ADD CONSTRAINT `%s`
                    FOREIGN KEY (`media_id`) REFERENCES `media` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE',
            $table,
            $name
        ));
    }
}
