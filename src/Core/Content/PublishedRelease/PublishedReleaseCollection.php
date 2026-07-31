<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Content\PublishedRelease;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/** @extends EntityCollection<PublishedReleaseEntity> */
final class PublishedReleaseCollection extends EntityCollection
{
    public function getApiAlias(): string
    {
        return 'extension_mesh_published_release_collection';
    }

    protected function getExpectedClass(): string
    {
        return PublishedReleaseEntity::class;
    }
}
