<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;

final class CustomerProductAccessResolver
{
    /**
     * @param EntityRepository<ProductCollection> $products
     * @param iterable<CustomerProductAccessProvider> $providers
     */
    public function __construct(
        private readonly EntityRepository $products,
        private readonly iterable $providers
    ) {
    }

    /** @return list<string> */
    public function productIds(string $customerId, string $salesChannelId, Context $context): array
    {
        if (!Uuid::isValid($customerId) || !Uuid::isValid($salesChannelId)) {
            return [];
        }

        $ids = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->productIds($customerId, $salesChannelId, $context) as $productId) {
                $ids[$productId] = true;
            }
        }

        return \array_keys($ids);
    }

    public function grants(
        string $customerId,
        string $productId,
        string $salesChannelId,
        Context $context
    ): bool {
        if (!Uuid::isValid($customerId) || !Uuid::isValid($productId) || !Uuid::isValid($salesChannelId)) {
            return false;
        }

        foreach ($this->providers as $provider) {
            if ($provider->grants($customerId, $productId, $salesChannelId, $context)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The result intentionally has no total count. Only one bounded page plus
     * a look-ahead row is loaded, so large license catalogs stay predictable.
     *
     * @return array{items: list<array{id: string, name: string, productNumber: string}>, page: int, hasPrevious: bool, hasNext: bool}
     */
    public function paginateProducts(
        string $customerId,
        string $salesChannelId,
        int $page,
        int $limit,
        Context $context
    ): array {
        $page = \max(1, \min(10000, $page));
        $limit = \max(1, \min(50, $limit));
        $filters = $this->productFilters($customerId, $salesChannelId);
        if ($filters === []) {
            return ['items' => [], 'page' => $page, 'hasPrevious' => $page > 1, 'hasNext' => false];
        }

        $criteria = (new Criteria())
            ->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, $filters))
            ->addSorting(new FieldSorting('name'))
            ->addSorting(new FieldSorting('productNumber'))
            ->addSorting(new FieldSorting('id'))
            ->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_NONE)
            ->setOffset(($page - 1) * $limit)
            ->setLimit($limit + 1);
        $result = $this->products->search($criteria, $context);
        $hasNext = $result->count() > $limit;
        $items = [];
        foreach (\array_slice($result->getElements(), 0, $limit, true) as $product) {
            $items[] = $this->hydrateProduct($product);
        }

        return [
            'items' => $items,
            'page' => $page,
            'hasPrevious' => $page > 1,
            'hasNext' => $hasNext,
        ];
    }

    /** @return array{id: string, name: string, productNumber: string}|null */
    public function product(
        string $customerId,
        string $productId,
        string $salesChannelId,
        Context $context
    ): ?array {
        if (!Uuid::isValid($customerId) || !Uuid::isValid($productId) || !Uuid::isValid($salesChannelId)) {
            return null;
        }
        $filters = $this->productFilters($customerId, $salesChannelId);
        if ($filters === []) {
            return null;
        }
        $criteria = (new Criteria([$productId]))
            ->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, $filters))
            ->setLimit(1);
        $product = $this->products->search($criteria, $context)->first();

        return $product instanceof ProductEntity ? $this->hydrateProduct($product) : null;
    }

    /** @return list<\Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter> */
    private function productFilters(string $customerId, string $salesChannelId): array
    {
        if (!Uuid::isValid($customerId) || !Uuid::isValid($salesChannelId)) {
            return [];
        }

        $filters = [];
        foreach ($this->providers as $provider) {
            $filters[] = $provider->productFilter($customerId, $salesChannelId);
        }

        return $filters;
    }

    /** @return array{id: string, name: string, productNumber: string} */
    private function hydrateProduct(ProductEntity $product): array
    {
        $name = $product->getTranslation('name');

        return [
            'id' => $product->getId(),
            'name' => \is_string($name) && $name !== '' ? $name : $product->getProductNumber(),
            'productNumber' => $product->getProductNumber(),
        ];
    }
}
