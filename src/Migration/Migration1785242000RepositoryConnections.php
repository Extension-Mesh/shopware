<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1785242000RepositoryConnections extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1785242000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `extension_mesh_repository_connection` (
                `id` BINARY(16) NOT NULL,
                `provider` VARCHAR(32) NOT NULL,
                `repository` VARCHAR(255) NOT NULL,
                `api_base_url` VARCHAR(512) NOT NULL,
                `web_url` VARCHAR(512) NULL,
                `default_branch` VARCHAR(255) NULL,
                `repository_private` TINYINT(1) NOT NULL DEFAULT 1,
                `credential_ciphertext` LONGTEXT NULL,
                `credential_fingerprint` VARCHAR(16) NULL,
                `product_id` BINARY(16) NULL,
                `technical_name` VARCHAR(128) NULL,
                `config_path` VARCHAR(255) NULL,
                `onboarding_mode` VARCHAR(16) NULL,
                `onboarding_status` VARCHAR(32) NOT NULL DEFAULT 'ready',
                `onboarding_stage` VARCHAR(16) NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `last_synced_at` DATETIME(3) NULL,
                `last_error` LONGTEXT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.extension_mesh_repository_connection.source` (
                    `provider`,
                    `api_base_url`(191),
                    `repository`
                ),
                KEY `idx.extension_mesh_repository_connection.product` (`product_id`),
                KEY `idx.extension_mesh_repository_connection.enabled` (`enabled`, `last_synced_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `extension_mesh_repository_release` (
                `id` BINARY(16) NOT NULL,
                `connection_id` BINARY(16) NOT NULL,
                `provider_release_id` VARCHAR(128) NOT NULL,
                `provider_asset_id` VARCHAR(128) NOT NULL,
                `tag` VARCHAR(255) NOT NULL,
                `asset_name` VARCHAR(255) NOT NULL,
                `version` VARCHAR(64) NOT NULL,
                `sha256` CHAR(64) NOT NULL,
                `media_id` BINARY(16) NOT NULL,
                `product_download_id` BINARY(16) NOT NULL,
                `released_at` DATETIME(3) NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.extension_mesh_repository_release.asset` (
                    `connection_id`,
                    `provider_release_id`,
                    `provider_asset_id`
                ),
                KEY `idx.extension_mesh_repository_release.download` (`product_download_id`),
                CONSTRAINT `fk.extension_mesh_repository_release.connection`
                    FOREIGN KEY (`connection_id`)
                    REFERENCES `extension_mesh_repository_connection` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
