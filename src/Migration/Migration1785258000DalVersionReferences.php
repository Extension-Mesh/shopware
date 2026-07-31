<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

final class Migration1785258000DalVersionReferences extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1785258000;
    }

    public function update(Connection $connection): void
    {
        $liveVersion = Uuid::fromHexToBytes(Defaults::LIVE_VERSION);

        $this->addRequiredLiveVersion(
            $connection,
            'extension_mesh_published_release',
            'product_download_version_id',
            'product_download_id',
            'product_download'
        );
        $this->addRequiredLiveVersion(
            $connection,
            'extension_mesh_published_release',
            'product_version_id',
            'product_id',
            'product'
        );
        $this->addOptionalLiveVersion(
            $connection,
            'extension_mesh_repository_connection',
            'product_version_id',
            'product_id',
            'product'
        );
        $this->addRequiredLiveVersion(
            $connection,
            'extension_mesh_repository_release',
            'product_download_version_id',
            'product_download_id',
            'product_download'
        );

        $productColumns = $connection->createSchemaManager()
            ->listTableColumns('extension_mesh_product');
        if (!isset($productColumns['product_version_id'])) {
            $connection->executeStatement(
                'ALTER TABLE `extension_mesh_product`
                    ADD COLUMN `product_version_id` BINARY(16) NULL AFTER `product_id`'
            );
            $connection->update(
                'extension_mesh_product',
                ['product_version_id' => $liveVersion],
                ['product_version_id' => null]
            );
            $connection->executeStatement(
                'ALTER TABLE `extension_mesh_product`
                    DROP PRIMARY KEY,
                    MODIFY COLUMN `product_version_id` BINARY(16) NOT NULL,
                    ADD PRIMARY KEY (`product_id`, `product_version_id`),
                    ADD CONSTRAINT `fk.extension_mesh_product.product`
                        FOREIGN KEY (`product_id`, `product_version_id`)
                        REFERENCES `product` (`id`, `version_id`)
                        ON DELETE CASCADE ON UPDATE CASCADE'
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function addRequiredLiveVersion(
        Connection $connection,
        string $table,
        string $versionColumn,
        string $idColumn,
        string $referencedTable
    ): void {
        $columns = $connection->createSchemaManager()->listTableColumns($table);
        if (isset($columns[$versionColumn])) {
            return;
        }

        $connection->executeStatement(
            \sprintf(
                'ALTER TABLE `%s` ADD COLUMN `%s` BINARY(16) NULL AFTER `%s`',
                $table,
                $versionColumn,
                $idColumn
            )
        );
        $connection->update(
            $table,
            [$versionColumn => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
            [$versionColumn => null]
        );
        $connection->executeStatement(
            \sprintf(
                'ALTER TABLE `%s`
                    MODIFY COLUMN `%s` BINARY(16) NOT NULL,
                    ADD CONSTRAINT `fk.%s.%s`
                        FOREIGN KEY (`%s`, `%s`)
                        REFERENCES `%s` (`id`, `version_id`)
                        ON DELETE CASCADE ON UPDATE CASCADE',
                $table,
                $versionColumn,
                $table,
                $referencedTable,
                $idColumn,
                $versionColumn,
                $referencedTable
            )
        );
    }

    private function addOptionalLiveVersion(
        Connection $connection,
        string $table,
        string $versionColumn,
        string $idColumn,
        string $referencedTable
    ): void {
        $columns = $connection->createSchemaManager()->listTableColumns($table);
        if (isset($columns[$versionColumn])) {
            return;
        }

        $connection->executeStatement(
            \sprintf(
                'ALTER TABLE `%s` ADD COLUMN `%s` BINARY(16) NULL AFTER `%s`',
                $table,
                $versionColumn,
                $idColumn
            )
        );
        $connection->executeStatement(
            \sprintf(
                'UPDATE `%s` SET `%s` = :liveVersion WHERE `%s` IS NOT NULL',
                $table,
                $versionColumn,
                $idColumn
            ),
            ['liveVersion' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );
        $connection->executeStatement(
            \sprintf(
                'ALTER TABLE `%s`
                    ADD CONSTRAINT `fk.%s.%s`
                        FOREIGN KEY (`%s`, `%s`)
                        REFERENCES `%s` (`id`, `version_id`)
                        ON DELETE SET NULL ON UPDATE CASCADE',
                $table,
                $table,
                $referencedTable,
                $idColumn,
                $versionColumn,
                $referencedTable
            )
        );
    }
}
