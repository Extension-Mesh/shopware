<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1785236000PaidEntitlements extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1785236000;
    }

    public function update(Connection $connection): void
    {
        $columns = $connection->createSchemaManager()->listTableColumns('extension_mesh_registry_source');

        if (!isset($columns['credential_ciphertext'])) {
            $connection->executeStatement(
                'ALTER TABLE `extension_mesh_registry_source`
                    ADD COLUMN `credential_ciphertext` LONGTEXT NULL AFTER `enabled`,
                    ADD COLUMN `credential_fingerprint` VARCHAR(16) NULL AFTER `credential_ciphertext`'
            );
        }

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `extension_mesh_access_token` (
                `id` BINARY(16) NOT NULL,
                `customer_id` BINARY(16) NOT NULL,
                `sales_channel_id` BINARY(16) NOT NULL,
                `label` VARCHAR(255) NULL,
                `last_used_at` DATETIME(3) NULL,
                `revoked_at` DATETIME(3) NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `idx.extension_mesh_access_token.customer` (`customer_id`, `revoked_at`),
                CONSTRAINT `fk.extension_mesh_access_token.customer`
                    FOREIGN KEY (`customer_id`) REFERENCES `customer` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.extension_mesh_access_token.sales_channel`
                    FOREIGN KEY (`sales_channel_id`) REFERENCES `sales_channel` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `extension_mesh_published_release` (
                `id` BINARY(16) NOT NULL,
                `product_download_id` BINARY(16) NOT NULL,
                `product_id` BINARY(16) NOT NULL,
                `media_id` BINARY(16) NOT NULL,
                `fingerprint` CHAR(64) NOT NULL,
                `technical_name` VARCHAR(128) NULL,
                `version` VARCHAR(64) NULL,
                `metadata` JSON NULL,
                `sha256` CHAR(64) NULL,
                `validation_error` LONGTEXT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.extension_mesh_published_release.download` (`product_download_id`),
                KEY `idx.extension_mesh_published_release.product` (`product_id`),
                KEY `idx.extension_mesh_published_release.extension` (`technical_name`, `version`),
                CONSTRAINT `json.extension_mesh_published_release.metadata`
                    CHECK (JSON_VALID(`metadata`))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
