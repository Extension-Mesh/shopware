<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Content\RepositoryRelease;

use ExtensionMesh\Shopware\Core\Content\RepositoryConnection\RepositoryConnectionDefinition;
use Shopware\Core\Content\Media\MediaDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductDownload\ProductDownloadDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

final class RepositoryReleaseDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'extension_mesh_repository_release';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return RepositoryReleaseEntity::class;
    }

    public function getCollectionClass(): string
    {
        return RepositoryReleaseCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('connection_id', 'connectionId', RepositoryConnectionDefinition::class))->addFlags(new Required()),
            (new StringField('provider_release_id', 'providerReleaseId', 128))->addFlags(new Required()),
            (new StringField('provider_asset_id', 'providerAssetId', 128))->addFlags(new Required()),
            (new StringField('tag', 'tag'))->addFlags(new Required()),
            (new StringField('asset_name', 'assetName'))->addFlags(new Required()),
            (new StringField('version', 'version', 64))->addFlags(new Required()),
            new LongTextField('release_notes', 'releaseNotes'),
            (new StringField('sha256', 'sha256', 64))->addFlags(new Required()),
            (new FkField('media_id', 'mediaId', MediaDefinition::class))->addFlags(new Required()),
            (new FkField('product_download_id', 'productDownloadId', ProductDownloadDefinition::class))->addFlags(new Required()),
            (new ReferenceVersionField(ProductDownloadDefinition::class))->addFlags(new Required()),
            (new DateTimeField('released_at', 'releasedAt'))->addFlags(new Required()),
            new CreatedAtField(),
            new UpdatedAtField(),
            new ManyToOneAssociationField('connection', 'connection_id', RepositoryConnectionDefinition::class),
            new ManyToOneAssociationField('media', 'media_id', MediaDefinition::class),
            new ManyToOneAssociationField('productDownload', 'product_download_id', ProductDownloadDefinition::class),
        ]);
    }
}
