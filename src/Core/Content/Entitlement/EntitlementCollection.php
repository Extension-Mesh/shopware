<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Content\Entitlement;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/** @extends EntityCollection<EntitlementEntity> */
final class EntitlementCollection extends EntityCollection
{
    public function getApiAlias(): string
    {
        return 'extension_mesh_entitlement_collection';
    }

    protected function getExpectedClass(): string
    {
        return EntitlementEntity::class;
    }
}
