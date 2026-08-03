<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1785263000AccessTokenLifecycle extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1785263000;
    }

    public function update(Connection $connection): void
    {
        $columns = $connection->createSchemaManager()->listTableColumns('extension_mesh_access_token');
        if (!isset($columns['expires_at'])) {
            $connection->executeStatement(<<<'SQL'
                ALTER TABLE `extension_mesh_access_token`
                    ADD COLUMN `expires_at` DATETIME(3) NULL AFTER `last_used_at`
            SQL);
        }
        if (!isset($columns['active_slot'])) {
            $connection->executeStatement(<<<'SQL'
                ALTER TABLE `extension_mesh_access_token`
                    ADD COLUMN `active_slot` TINYINT(1) NULL DEFAULT 1 AFTER `revoked_at`
            SQL);
        }

        $connection->executeStatement(<<<'SQL'
            UPDATE `extension_mesh_access_token`
            SET `expires_at` = DATE_ADD(`created_at`, INTERVAL 90 DAY)
            WHERE `expires_at` IS NULL
        SQL);
        $connection->executeStatement(<<<'SQL'
            UPDATE `extension_mesh_access_token`
            SET `active_slot` = NULL
            WHERE `revoked_at` IS NOT NULL
        SQL);
        $connection->executeStatement(<<<'SQL'
            UPDATE `extension_mesh_access_token` AS `token`
            INNER JOIN `extension_mesh_access_token` AS `newer`
                ON `newer`.`customer_id` = `token`.`customer_id`
                AND `newer`.`sales_channel_id` = `token`.`sales_channel_id`
                AND `newer`.`revoked_at` IS NULL
                AND (
                    `newer`.`created_at` > `token`.`created_at`
                    OR (`newer`.`created_at` = `token`.`created_at` AND `newer`.`id` > `token`.`id`)
                )
            SET `token`.`revoked_at` = NOW(3), `token`.`active_slot` = NULL
            WHERE `token`.`revoked_at` IS NULL
        SQL);

        $columns = $connection->createSchemaManager()->listTableColumns('extension_mesh_access_token');
        if (!$columns['expires_at']->getNotnull()) {
            $connection->executeStatement(<<<'SQL'
                ALTER TABLE `extension_mesh_access_token`
                    MODIFY COLUMN `expires_at` DATETIME(3) NOT NULL
            SQL);
        }

        $indexes = $connection->createSchemaManager()->listTableIndexes('extension_mesh_access_token');
        if (!isset($indexes['uniq.extension_mesh_access_token.active_customer'])) {
            $connection->executeStatement(<<<'SQL'
                ALTER TABLE `extension_mesh_access_token`
                    ADD UNIQUE INDEX `uniq.extension_mesh_access_token.active_customer`
                        (`customer_id`, `sales_channel_id`, `active_slot`),
                    ADD INDEX `idx.extension_mesh_access_token.expiry` (`expires_at`)
            SQL);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
