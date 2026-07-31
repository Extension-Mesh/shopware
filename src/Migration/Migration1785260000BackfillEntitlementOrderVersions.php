<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

final class Migration1785260000BackfillEntitlementOrderVersions extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1785260000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            UPDATE `extension_mesh_entitlement`
            SET `order_version_id` = :liveVersion
            WHERE `order_id` IS NOT NULL AND `order_version_id` IS NULL
        SQL, [
            'liveVersion' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
        ]);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
