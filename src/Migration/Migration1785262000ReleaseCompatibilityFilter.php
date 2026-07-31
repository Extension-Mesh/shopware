<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1785262000ReleaseCompatibilityFilter extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1785262000;
    }

    public function update(Connection $connection): void
    {
        $columns = $connection->createSchemaManager()->listTableColumns(
            'extension_mesh_published_release'
        );
        if (!isset($columns['shopware_constraint'])) {
            $connection->executeStatement(<<<'SQL'
                ALTER TABLE `extension_mesh_published_release`
                    ADD COLUMN `shopware_constraint` VARCHAR(255) NULL AFTER `version`
            SQL);
            $connection->executeStatement(<<<'SQL'
                UPDATE `extension_mesh_published_release`
                SET `shopware_constraint` = JSON_UNQUOTE(JSON_EXTRACT(`metadata`, '$.shopware'))
                WHERE `metadata` IS NOT NULL
            SQL);
        }

        $indexes = $connection->createSchemaManager()->listTableIndexes(
            'extension_mesh_published_release'
        );
        if (!isset($indexes['idx.extension_mesh_published_release.compatibility_page'])) {
            $connection->executeStatement(<<<'SQL'
                ALTER TABLE `extension_mesh_published_release`
                    ADD INDEX `idx.extension_mesh_published_release.compatibility_page`
                        (`product_id`, `shopware_constraint`, `created_at`, `id`)
            SQL);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
