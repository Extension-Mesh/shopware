<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Content\RepositoryConnection;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/** @extends EntityCollection<RepositoryConnectionEntity> */
final class RepositoryConnectionCollection extends EntityCollection
{
    public function getApiAlias(): string
    {
        return 'extension_mesh_repository_connection_collection';
    }

    protected function getExpectedClass(): string
    {
        return RepositoryConnectionEntity::class;
    }
}
