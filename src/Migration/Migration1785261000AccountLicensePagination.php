<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1785261000AccountLicensePagination extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1785261000;
    }

    public function update(Connection $connection): void
    {
        $indexes = $connection->createSchemaManager()->listTableIndexes(
            'extension_mesh_published_release'
        );
        if (isset($indexes['idx.extension_mesh_published_release.account_page'])) {
            return;
        }

        $connection->executeStatement(<<<'SQL'
            ALTER TABLE `extension_mesh_published_release`
                ADD INDEX `idx.extension_mesh_published_release.account_page`
                    (`product_id`, `created_at`, `id`)
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
