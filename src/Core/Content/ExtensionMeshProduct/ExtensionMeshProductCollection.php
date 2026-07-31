<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Content\ExtensionMeshProduct;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/** @extends EntityCollection<ExtensionMeshProductEntity> */
final class ExtensionMeshProductCollection extends EntityCollection
{
    public function getApiAlias(): string
    {
        return 'extension_mesh_product_collection';
    }

    protected function getExpectedClass(): string
    {
        return ExtensionMeshProductEntity::class;
    }
}
