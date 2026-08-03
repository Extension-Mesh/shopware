<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Service;

use ExtensionMesh\Shopware\Service\CustomerProductAccessProvider;
use ExtensionMesh\Shopware\Service\CustomerProductAccessResolver;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\Uuid\Uuid;

final class CustomerProductAccessResolverTest extends TestCase
{
    public function testItCombinesProviderGrantsWithoutDuplicates(): void
    {
        $customerId = Uuid::randomHex();
        $salesChannelId = Uuid::randomHex();
        $firstProduct = Uuid::randomHex();
        $secondProduct = Uuid::randomHex();
        $repository = $this->createMock(EntityRepository::class);
        $resolver = new CustomerProductAccessResolver($repository, [
            $this->provider([$firstProduct, $secondProduct], [$firstProduct]),
            $this->provider([$firstProduct], [$secondProduct]),
        ]);

        self::assertSame(
            [$firstProduct, $secondProduct],
            $resolver->productIds($customerId, $salesChannelId, Context::createCLIContext())
        );
        self::assertTrue($resolver->grants(
            $customerId,
            $firstProduct,
            $salesChannelId,
            Context::createCLIContext()
        ));
        self::assertTrue($resolver->grants(
            $customerId,
            $secondProduct,
            $salesChannelId,
            Context::createCLIContext()
        ));
        self::assertFalse($resolver->grants(
            $customerId,
            Uuid::randomHex(),
            $salesChannelId,
            Context::createCLIContext()
        ));
    }

    public function testPaginationLoadsOnlyOnePageAndALookAheadRow(): void
    {
        $context = Context::createCLIContext();
        $products = $this->createMock(EntityRepository::class);
        $entities = new ProductCollection([
            $this->product('Alpha'),
            $this->product('Bravo'),
            $this->product('Charlie'),
        ]);
        $products->expects(self::once())
            ->method('search')
            ->willReturnCallback(static function (Criteria $criteria, Context $actualContext) use ($context, $entities): EntitySearchResult {
                self::assertSame($context, $actualContext);
                self::assertSame(3, $criteria->getLimit());
                self::assertSame(0, $criteria->getOffset());
                self::assertSame(Criteria::TOTAL_COUNT_MODE_NONE, $criteria->getTotalCountMode());

                return new EntitySearchResult(
                    'product',
                    $entities->count(),
                    $entities,
                    new AggregationResultCollection(),
                    $criteria,
                    $actualContext
                );
            });
        $resolver = new CustomerProductAccessResolver($products, [
            $this->provider([Uuid::randomHex()], []),
        ]);

        $result = $resolver->paginateProducts(
            Uuid::randomHex(),
            Uuid::randomHex(),
            1,
            2,
            $context
        );

        self::assertSame(['Alpha', 'Bravo'], \array_column($result['items'], 'name'));
        self::assertFalse($result['hasPrevious']);
        self::assertTrue($result['hasNext']);
    }

    public function testInvalidIdentifiersNeverReachProvidersOrPersistence(): void
    {
        $products = $this->createMock(EntityRepository::class);
        $products->expects(self::never())->method('search');
        $resolver = new CustomerProductAccessResolver($products, [
            $this->provider([Uuid::randomHex()], [Uuid::randomHex()]),
        ]);

        self::assertSame([], $resolver->productIds('invalid', Uuid::randomHex(), Context::createCLIContext()));
        self::assertFalse($resolver->grants(
            'invalid',
            Uuid::randomHex(),
            Uuid::randomHex(),
            Context::createCLIContext()
        ));
        self::assertSame(
            [],
            $resolver->paginateProducts('invalid', Uuid::randomHex(), 1, 20, Context::createCLIContext())['items']
        );
    }

    /**
     * @param list<string> $productIds
     * @param list<string> $grantedIds
     */
    private function provider(array $productIds, array $grantedIds): CustomerProductAccessProvider
    {
        return new class($productIds, $grantedIds) implements CustomerProductAccessProvider {
            /** @var list<string> */
            private readonly array $productIds;

            /** @var list<string> */
            private readonly array $grantedIds;

            /**
             * @param list<string> $productIds
             * @param list<string> $grantedIds
             */
            public function __construct(array $productIds, array $grantedIds)
            {
                $this->productIds = $productIds;
                $this->grantedIds = $grantedIds;
            }

            public function productIds(string $customerId, string $salesChannelId, Context $context): array
            {
                return $this->productIds;
            }

            public function grants(
                string $customerId,
                string $productId,
                string $salesChannelId,
                Context $context
            ): bool {
                return \in_array($productId, $this->grantedIds, true);
            }

            public function productFilter(string $customerId, string $salesChannelId): Filter
            {
                return new EqualsFilter('extensionMeshEntitlements.customerId', $customerId);
            }
        };
    }

    private function product(string $name): ProductEntity
    {
        $product = new ProductEntity();
        $product->setId(Uuid::randomHex());
        $product->setProductNumber('SW-' . \strtoupper($name));
        $product->setTranslated(['name' => $name]);

        return $product;
    }
}
