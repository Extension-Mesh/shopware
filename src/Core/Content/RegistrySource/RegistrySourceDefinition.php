<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Content\RegistrySource;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

final class RegistrySourceDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'extension_mesh_registry_source';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return RegistrySourceEntity::class;
    }

    public function getCollectionClass(): string
    {
        return RegistrySourceCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('url', 'url', 2048))->addFlags(new Required()),
            (new StringField('normalized_url', 'normalizedUrl', 512))->addFlags(new Required()),
            new StringField('label', 'label'),
            (new BoolField('enabled', 'enabled'))->addFlags(new Required()),
            (new LongTextField('credential_ciphertext', 'credentialCiphertext'))->removeFlag(ApiAware::class),
            new StringField('credential_fingerprint', 'credentialFingerprint', 16),
            (new LongTextField('cached_registry', 'cachedRegistry'))->removeFlag(ApiAware::class),
            new DateTimeField('last_refreshed_at', 'lastRefreshedAt'),
            new LongTextField('last_error', 'lastError'),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
