<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Content\RepositoryCredential;

use ExtensionMesh\Shopware\Core\Content\RepositoryConnection\RepositoryConnectionDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

final class RepositoryCredentialDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'extension_mesh_repository_credential';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return RepositoryCredentialEntity::class;
    }

    public function getCollectionClass(): string
    {
        return RepositoryCredentialCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('provider', 'provider', 32))->addFlags(new Required()),
            (new StringField('api_base_url', 'apiBaseUrl', 512))->addFlags(new Required()),
            (new LongTextField('credential_ciphertext', 'credentialCiphertext'))
                ->addFlags(new Required())
                ->removeFlag(ApiAware::class),
            (new StringField('credential_fingerprint', 'credentialFingerprint', 16))->addFlags(new Required()),
            new CreatedAtField(),
            new UpdatedAtField(),
            new OneToManyAssociationField(
                'connections',
                RepositoryConnectionDefinition::class,
                'credential_id'
            ),
        ]);
    }
}
