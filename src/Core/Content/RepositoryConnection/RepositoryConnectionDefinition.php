<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Content\RepositoryConnection;

use ExtensionMesh\Shopware\Core\Content\RepositoryRelease\RepositoryReleaseDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

final class RepositoryConnectionDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'extension_mesh_repository_connection';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return RepositoryConnectionEntity::class;
    }

    public function getCollectionClass(): string
    {
        return RepositoryConnectionCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('provider', 'provider', 32))->addFlags(new Required()),
            (new StringField('repository', 'repository'))->addFlags(new Required()),
            (new StringField('api_base_url', 'apiBaseUrl', 512))->addFlags(new Required()),
            new StringField('web_url', 'webUrl', 512),
            new StringField('default_branch', 'defaultBranch'),
            (new BoolField('repository_private', 'repositoryPrivate'))->addFlags(new Required()),
            (new LongTextField('credential_ciphertext', 'credentialCiphertext'))->removeFlag(ApiAware::class),
            new StringField('credential_fingerprint', 'credentialFingerprint', 16),
            new FkField('product_id', 'productId', ProductDefinition::class),
            new ReferenceVersionField(ProductDefinition::class),
            new StringField('technical_name', 'technicalName', 128),
            new StringField('config_path', 'configPath'),
            new StringField('onboarding_mode', 'onboardingMode', 16),
            (new StringField('onboarding_status', 'onboardingStatus', 32))->addFlags(new Required()),
            new StringField('onboarding_stage', 'onboardingStage', 16),
            (new BoolField('enabled', 'enabled'))->addFlags(new Required()),
            new DateTimeField('last_synced_at', 'lastSyncedAt'),
            new LongTextField('last_error', 'lastError'),
            new CreatedAtField(),
            new UpdatedAtField(),
            new ManyToOneAssociationField('product', 'product_id', ProductDefinition::class),
            new OneToManyAssociationField('releases', RepositoryReleaseDefinition::class, 'connection_id'),
        ]);
    }
}
