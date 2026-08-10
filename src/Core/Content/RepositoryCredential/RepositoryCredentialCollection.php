<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Content\RepositoryCredential;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/** @extends EntityCollection<RepositoryCredentialEntity> */
final class RepositoryCredentialCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return RepositoryCredentialEntity::class;
    }
}
