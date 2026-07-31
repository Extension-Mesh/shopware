<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Content\RepositoryRelease;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/** @extends EntityCollection<RepositoryReleaseEntity> */
final class RepositoryReleaseCollection extends EntityCollection
{
    public function getApiAlias(): string
    {
        return 'extension_mesh_repository_release_collection';
    }

    protected function getExpectedClass(): string
    {
        return RepositoryReleaseEntity::class;
    }
}
