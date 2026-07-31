<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Infrastructure\Persistence;

use ExtensionMesh\Shopware\Core\Content\ExtensionMeshProduct\ExtensionMeshProductCollection;
use ExtensionMesh\Shopware\Core\Content\RepositoryConnection\RepositoryConnectionCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

final class ExtensionMeshProductRepository
{
    public function __construct(
        /** @var EntityRepository<ExtensionMeshProductCollection> */
        private readonly EntityRepository $products,
        /** @var EntityRepository<RepositoryConnectionCollection> */
        private readonly EntityRepository $connections
    ) {
    }

    /** @return array{enabled: bool, source: ?string} */
    public function status(string $productId, Context $context): array
    {
        if (!Uuid::isValid($productId)) {
            return ['enabled' => false, 'source' => null];
        }
        $connectionCriteria = (new Criteria())
            ->addFilter(new EqualsFilter('productId', $productId))
            ->addFilter(new EqualsFilter('enabled', true))
            ->setLimit(1);
        if ($this->connections->searchIds($connectionCriteria, $context)->getTotal() > 0) {
            return ['enabled' => true, 'source' => 'repository'];
        }

        $manualCriteria = (new Criteria())
            ->addFilter(new EqualsFilter('productId', $productId))
            ->addFilter(new EqualsFilter('productVersionId', Defaults::LIVE_VERSION))
            ->addFilter(new EqualsFilter('enabled', true))
            ->setLimit(1);
        if ($this->products->searchIds($manualCriteria, $context)->getTotal() > 0) {
            return ['enabled' => true, 'source' => 'manual'];
        }

        return ['enabled' => false, 'source' => null];
    }

    public function setManual(string $productId, bool $enabled, Context $context): void
    {
        if (!Uuid::isValid($productId)) {
            return;
        }
        $key = ['productId' => $productId, 'productVersionId' => Defaults::LIVE_VERSION];
        if (!$enabled) {
            $this->products->delete([$key], $context);

            return;
        }

        $this->products->upsert([[...$key, 'enabled' => true]], $context);
    }
}
