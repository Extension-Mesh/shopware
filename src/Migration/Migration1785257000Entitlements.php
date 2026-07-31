<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1785257000Entitlements extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1785257000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `extension_mesh_entitlement` (
                `id` BINARY(16) NOT NULL,
                `customer_id` BINARY(16) NOT NULL,
                `product_id` BINARY(16) NOT NULL,
                `product_version_id` BINARY(16) NOT NULL,
                `sales_channel_id` BINARY(16) NOT NULL,
                `order_id` BINARY(16) NULL,
                `order_version_id` BINARY(16) NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `valid_until` DATETIME(3) NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `idx.extension_mesh_entitlement.grant`
                    (`customer_id`, `product_id`, `sales_channel_id`),
                KEY `idx.extension_mesh_entitlement.product`
                    (`product_id`, `product_version_id`),
                KEY `idx.extension_mesh_entitlement.order`
                    (`order_id`, `order_version_id`),
                KEY `idx.extension_mesh_entitlement.access`
                    (`customer_id`, `sales_channel_id`, `enabled`),
                CONSTRAINT `fk.extension_mesh_entitlement.customer`
                    FOREIGN KEY (`customer_id`) REFERENCES `customer` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.extension_mesh_entitlement.product`
                    FOREIGN KEY (`product_id`, `product_version_id`)
                    REFERENCES `product` (`id`, `version_id`)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.extension_mesh_entitlement.sales_channel`
                    FOREIGN KEY (`sales_channel_id`) REFERENCES `sales_channel` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.extension_mesh_entitlement.order`
                    FOREIGN KEY (`order_id`, `order_version_id`)
                    REFERENCES `order` (`id`, `version_id`)
                    ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
