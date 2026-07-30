<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1785251000OptionalRepositoryCredentials extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1785251000;
    }

    public function update(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        if (!$schema->tablesExist(['extension_mesh_repository_connection'])) {
            return;
        }
        $columns = $schema->listTableColumns('extension_mesh_repository_connection');
        if (
            !isset($columns['credential_ciphertext'], $columns['credential_fingerprint'])
            || (
                !$columns['credential_ciphertext']->getNotnull()
                && !$columns['credential_fingerprint']->getNotnull()
            )
        ) {
            return;
        }

        $connection->executeStatement(<<<'SQL'
            ALTER TABLE `extension_mesh_repository_connection`
                MODIFY COLUMN `credential_ciphertext` LONGTEXT NULL,
                MODIFY COLUMN `credential_fingerprint` VARCHAR(16) NULL
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
