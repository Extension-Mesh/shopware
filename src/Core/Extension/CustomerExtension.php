<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Extension;

use ExtensionMesh\Shopware\Core\Content\AccessToken\AccessTokenDefinition;
use ExtensionMesh\Shopware\Core\Content\Entitlement\EntitlementDefinition;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

final class CustomerExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(new OneToManyAssociationField('extensionMeshAccessTokens', AccessTokenDefinition::class, 'customer_id'));
        $collection->add(new OneToManyAssociationField('extensionMeshEntitlements', EntitlementDefinition::class, 'customer_id'));
    }

    public function getEntityName(): string
    {
        return CustomerDefinition::ENTITY_NAME;
    }
}
