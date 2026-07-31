<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Content\ExtensionOwnership;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

final class ExtensionOwnershipDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'extension_mesh_extension_ownership';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return ExtensionOwnershipEntity::class;
    }

    public function getCollectionClass(): string
    {
        return ExtensionOwnershipCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new StringField('technical_name', 'technicalName'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('registry_url', 'registryUrl', 512))->addFlags(new Required()),
            (new DateTimeField('prepared_at', 'preparedAt'))->addFlags(new Required()),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
