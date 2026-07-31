<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Content\ExtensionOwnership;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/** @extends EntityCollection<ExtensionOwnershipEntity> */
final class ExtensionOwnershipCollection extends EntityCollection
{
    public function getApiAlias(): string
    {
        return 'extension_mesh_extension_ownership_collection';
    }

    protected function getExpectedClass(): string
    {
        return ExtensionOwnershipEntity::class;
    }
}
