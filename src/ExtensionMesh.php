<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

final class ExtensionMesh extends Plugin
{
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        if ($this->container === null) {
            throw new \LogicException('The plugin container is unavailable during uninstall.');
        }
        $connection = $this->container->get(Connection::class);
        if (!$connection instanceof Connection) {
            throw new \LogicException('The database connection is unavailable during uninstall.');
        }
        foreach ([
            'extension_mesh_repository_release',
            'extension_mesh_published_release',
            'extension_mesh_entitlement',
            'extension_mesh_access_token',
            'extension_mesh_extension_ownership',
            'extension_mesh_product',
            'extension_mesh_repository_connection',
            'extension_mesh_registry_source',
        ] as $table) {
            $connection->executeStatement(\sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }
    }
}
