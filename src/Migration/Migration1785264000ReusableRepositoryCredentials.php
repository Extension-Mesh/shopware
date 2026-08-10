<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1785264000ReusableRepositoryCredentials extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1785264000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `extension_mesh_repository_credential` (
                `id` BINARY(16) NOT NULL,
                `provider` VARCHAR(32) NOT NULL,
                `api_base_url` VARCHAR(512) NOT NULL,
                `credential_ciphertext` LONGTEXT NOT NULL,
                `credential_fingerprint` VARCHAR(16) NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.extension_mesh_repository_credential.identity` (
                    `provider`,
                    `api_base_url`(191),
                    `credential_fingerprint`
                )
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);

        $columns = $connection->createSchemaManager()->listTableColumns('extension_mesh_repository_connection');
        if (!isset($columns['credential_id'])) {
            $connection->executeStatement(<<<'SQL'
                ALTER TABLE `extension_mesh_repository_connection`
                    ADD COLUMN `credential_id` BINARY(16) NULL AFTER `repository_private`,
                    ADD KEY `idx.extension_mesh_repository_connection.credential` (`credential_id`),
                    ADD CONSTRAINT `fk.extension_mesh_repository_connection.credential`
                        FOREIGN KEY (`credential_id`)
                        REFERENCES `extension_mesh_repository_credential` (`id`)
                        ON DELETE SET NULL ON UPDATE CASCADE
            SQL);
        }

        if (isset($columns['credential_ciphertext'], $columns['credential_fingerprint'])) {
            $connection->executeStatement(<<<'SQL'
                INSERT IGNORE INTO `extension_mesh_repository_credential` (
                    `id`,
                    `provider`,
                    `api_base_url`,
                    `credential_ciphertext`,
                    `credential_fingerprint`,
                    `created_at`
                )
                SELECT
                    UNHEX(REPLACE(UUID(), '-', '')),
                    `provider`,
                    `api_base_url`,
                    `credential_ciphertext`,
                    `credential_fingerprint`,
                    MIN(`created_at`)
                FROM `extension_mesh_repository_connection`
                WHERE `credential_ciphertext` IS NOT NULL
                  AND `credential_fingerprint` IS NOT NULL
                GROUP BY
                    `provider`,
                    `api_base_url`,
                    `credential_fingerprint`,
                    `credential_ciphertext`
            SQL);

            $connection->executeStatement(<<<'SQL'
                UPDATE `extension_mesh_repository_connection` AS `connection`
                INNER JOIN `extension_mesh_repository_credential` AS `credential`
                    ON `credential`.`provider` = `connection`.`provider`
                    AND `credential`.`api_base_url` = `connection`.`api_base_url`
                    AND `credential`.`credential_fingerprint` = `connection`.`credential_fingerprint`
                SET `connection`.`credential_id` = `credential`.`id`
                WHERE `connection`.`credential_id` IS NULL
            SQL);

            $connection->executeStatement(<<<'SQL'
                UPDATE `extension_mesh_repository_connection`
                SET `credential_ciphertext` = NULL,
                    `credential_fingerprint` = NULL
                WHERE `credential_id` IS NOT NULL
            SQL);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        $columns = $connection->createSchemaManager()->listTableColumns('extension_mesh_repository_connection');
        if (isset($columns['credential_ciphertext'])) {
            $connection->executeStatement(
                'ALTER TABLE `extension_mesh_repository_connection` DROP COLUMN `credential_ciphertext`'
            );
        }
        if (isset($columns['credential_fingerprint'])) {
            $connection->executeStatement(
                'ALTER TABLE `extension_mesh_repository_connection` DROP COLUMN `credential_fingerprint`'
            );
        }
    }
}
