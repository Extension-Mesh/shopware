<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit;

use ExtensionMesh\Shopware\Core\Content\AccessToken\AccessTokenCollection;
use ExtensionMesh\Shopware\Core\Content\AccessToken\AccessTokenDefinition;
use ExtensionMesh\Shopware\Core\Content\AccessToken\AccessTokenEntity;
use ExtensionMesh\Shopware\Core\Content\Entitlement\EntitlementCollection;
use ExtensionMesh\Shopware\Core\Content\Entitlement\EntitlementDefinition;
use ExtensionMesh\Shopware\Core\Content\Entitlement\EntitlementEntity;
use ExtensionMesh\Shopware\Core\Content\ExtensionMeshProduct\ExtensionMeshProductCollection;
use ExtensionMesh\Shopware\Core\Content\ExtensionMeshProduct\ExtensionMeshProductDefinition;
use ExtensionMesh\Shopware\Core\Content\ExtensionMeshProduct\ExtensionMeshProductEntity;
use ExtensionMesh\Shopware\Core\Content\ExtensionOwnership\ExtensionOwnershipCollection;
use ExtensionMesh\Shopware\Core\Content\ExtensionOwnership\ExtensionOwnershipDefinition;
use ExtensionMesh\Shopware\Core\Content\ExtensionOwnership\ExtensionOwnershipEntity;
use ExtensionMesh\Shopware\Core\Content\PublishedRelease\PublishedReleaseCollection;
use ExtensionMesh\Shopware\Core\Content\PublishedRelease\PublishedReleaseDefinition;
use ExtensionMesh\Shopware\Core\Content\PublishedRelease\PublishedReleaseEntity;
use ExtensionMesh\Shopware\Core\Content\RegistrySource\RegistrySourceCollection;
use ExtensionMesh\Shopware\Core\Content\RegistrySource\RegistrySourceDefinition;
use ExtensionMesh\Shopware\Core\Content\RegistrySource\RegistrySourceEntity;
use ExtensionMesh\Shopware\Core\Content\RepositoryConnection\RepositoryConnectionCollection;
use ExtensionMesh\Shopware\Core\Content\RepositoryConnection\RepositoryConnectionDefinition;
use ExtensionMesh\Shopware\Core\Content\RepositoryConnection\RepositoryConnectionEntity;
use ExtensionMesh\Shopware\Core\Content\RepositoryRelease\RepositoryReleaseCollection;
use ExtensionMesh\Shopware\Core\Content\RepositoryRelease\RepositoryReleaseDefinition;
use ExtensionMesh\Shopware\Core\Content\RepositoryRelease\RepositoryReleaseEntity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;

final class DalEntityContractTest extends TestCase
{
    /**
     * @param class-string<EntityDefinition> $definitionClass
     * @param class-string<Entity> $entityClass
     * @param class-string<EntityCollection<covariant Entity>> $collectionClass
     */
    #[DataProvider('definitions')]
    public function testEachDefinitionHasItsTypedEntityAndCollection(
        string $definitionClass,
        string $entityClass,
        string $collectionClass
    ): void {
        $definition = new $definitionClass();

        self::assertSame($entityClass, $definition->getEntityClass());
        self::assertSame($collectionClass, $definition->getCollectionClass());
    }

    /**
     * @return iterable<string, array{
     *     class-string<EntityDefinition>,
     *     class-string<Entity>,
     *     class-string<EntityCollection<covariant Entity>>
     * }>
     */
    public static function definitions(): iterable
    {
        yield 'access token' => [AccessTokenDefinition::class, AccessTokenEntity::class, AccessTokenCollection::class];
        yield 'entitlement' => [EntitlementDefinition::class, EntitlementEntity::class, EntitlementCollection::class];
        yield 'extension product' => [ExtensionMeshProductDefinition::class, ExtensionMeshProductEntity::class, ExtensionMeshProductCollection::class];
        yield 'ownership' => [ExtensionOwnershipDefinition::class, ExtensionOwnershipEntity::class, ExtensionOwnershipCollection::class];
        yield 'published release' => [PublishedReleaseDefinition::class, PublishedReleaseEntity::class, PublishedReleaseCollection::class];
        yield 'registry source' => [RegistrySourceDefinition::class, RegistrySourceEntity::class, RegistrySourceCollection::class];
        yield 'repository connection' => [RepositoryConnectionDefinition::class, RepositoryConnectionEntity::class, RepositoryConnectionCollection::class];
        yield 'repository release' => [RepositoryReleaseDefinition::class, RepositoryReleaseEntity::class, RepositoryReleaseCollection::class];
    }
}
