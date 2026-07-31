<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Extension;

use ExtensionMesh\Shopware\Core\Content\Entitlement\EntitlementDefinition;
use ExtensionMesh\Shopware\Core\Content\ExtensionMeshProduct\ExtensionMeshProductDefinition;
use ExtensionMesh\Shopware\Core\Content\PublishedRelease\PublishedReleaseDefinition;
use ExtensionMesh\Shopware\Core\Content\RepositoryConnection\RepositoryConnectionDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

final class ProductExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(new OneToManyAssociationField('extensionMeshEntitlements', EntitlementDefinition::class, 'product_id'));
        $collection->add(new OneToManyAssociationField('extensionMeshProducts', ExtensionMeshProductDefinition::class, 'product_id'));
        $collection->add(new OneToManyAssociationField('extensionMeshPublishedReleases', PublishedReleaseDefinition::class, 'product_id'));
        $collection->add(new OneToManyAssociationField('extensionMeshRepositoryConnections', RepositoryConnectionDefinition::class, 'product_id'));
    }

    public function getEntityName(): string
    {
        return ProductDefinition::ENTITY_NAME;
    }
}
