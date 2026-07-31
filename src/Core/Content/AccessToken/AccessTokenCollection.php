<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Content\AccessToken;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/** @extends EntityCollection<AccessTokenEntity> */
final class AccessTokenCollection extends EntityCollection
{
    public function getApiAlias(): string
    {
        return 'extension_mesh_access_token_collection';
    }

    protected function getExpectedClass(): string
    {
        return AccessTokenEntity::class;
    }
}
