<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Extension;

use ExtensionMesh\Shopware\Core\Content\PublishedRelease\PublishedReleaseDefinition;
use ExtensionMesh\Shopware\Core\Content\RepositoryRelease\RepositoryReleaseDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductDownload\ProductDownloadDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

final class ProductDownloadExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(new OneToManyAssociationField('extensionMeshPublishedReleases', PublishedReleaseDefinition::class, 'product_download_id'));
        $collection->add(new OneToManyAssociationField('extensionMeshRepositoryReleases', RepositoryReleaseDefinition::class, 'product_download_id'));
    }

    public function getEntityName(): string
    {
        return ProductDownloadDefinition::ENTITY_NAME;
    }
}
