<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Content\PublishedRelease;

use Shopware\Core\Content\Media\MediaDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductDownload\ProductDownloadDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

final class PublishedReleaseDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'extension_mesh_published_release';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return PublishedReleaseEntity::class;
    }

    public function getCollectionClass(): string
    {
        return PublishedReleaseCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('product_download_id', 'productDownloadId', ProductDownloadDefinition::class))->addFlags(new Required()),
            (new ReferenceVersionField(ProductDownloadDefinition::class))->addFlags(new Required()),
            (new FkField('product_id', 'productId', ProductDefinition::class))->addFlags(new Required()),
            (new ReferenceVersionField(ProductDefinition::class))->addFlags(new Required()),
            (new FkField('media_id', 'mediaId', MediaDefinition::class))->addFlags(new Required()),
            (new StringField('fingerprint', 'fingerprint', 64))->addFlags(new Required()),
            new StringField('technical_name', 'technicalName', 128),
            new StringField('version', 'version', 64),
            new StringField('shopware_constraint', 'shopwareConstraint', 255),
            new JsonField('metadata', 'metadata'),
            new StringField('sha256', 'sha256', 64),
            new LongTextField('validation_error', 'validationError'),
            new CreatedAtField(),
            new UpdatedAtField(),
            new ManyToOneAssociationField('productDownload', 'product_download_id', ProductDownloadDefinition::class),
            new ManyToOneAssociationField('product', 'product_id', ProductDefinition::class),
            new ManyToOneAssociationField('media', 'media_id', MediaDefinition::class),
        ]);
    }
}
