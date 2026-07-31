<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Extension;

use ExtensionMesh\Shopware\Core\Content\AccessToken\AccessTokenDefinition;
use ExtensionMesh\Shopware\Core\Content\Entitlement\EntitlementDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;

final class SalesChannelExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(new OneToManyAssociationField('extensionMeshAccessTokens', AccessTokenDefinition::class, 'sales_channel_id'));
        $collection->add(new OneToManyAssociationField('extensionMeshEntitlements', EntitlementDefinition::class, 'sales_channel_id'));
    }

    public function getEntityName(): string
    {
        return SalesChannelDefinition::ENTITY_NAME;
    }
}
