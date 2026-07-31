<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Content\RegistrySource;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/** @extends EntityCollection<RegistrySourceEntity> */
final class RegistrySourceCollection extends EntityCollection
{
    public function getApiAlias(): string
    {
        return 'extension_mesh_registry_source_collection';
    }

    protected function getExpectedClass(): string
    {
        return RegistrySourceEntity::class;
    }
}
