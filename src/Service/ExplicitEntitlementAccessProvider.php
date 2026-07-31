<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use ExtensionMesh\Shopware\Infrastructure\Persistence\EntitlementRepository;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;

final class ExplicitEntitlementAccessProvider implements CustomerProductAccessProvider
{
    public function __construct(private readonly EntitlementRepository $entitlements)
    {
    }

    public function productIds(string $customerId, string $salesChannelId, Context $context): array
    {
        return $this->entitlements->entitledProductIds($customerId, $salesChannelId, $context);
    }

    public function grants(
        string $customerId,
        string $productId,
        string $salesChannelId,
        Context $context
    ): bool {
        return $this->entitlements->isEntitled($customerId, $productId, $salesChannelId, $context);
    }

    public function productFilter(string $customerId, string $salesChannelId): Filter
    {
        return new MultiFilter(MultiFilter::CONNECTION_AND, [
            new EqualsFilter('extensionMeshEntitlements.customerId', $customerId),
            new EqualsFilter('extensionMeshEntitlements.salesChannelId', $salesChannelId),
            new EqualsFilter('extensionMeshEntitlements.enabled', true),
            new MultiFilter(MultiFilter::CONNECTION_OR, [
                new EqualsFilter('extensionMeshEntitlements.validUntil', null),
                new RangeFilter('extensionMeshEntitlements.validUntil', [
                    RangeFilter::GT => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                ]),
            ]),
        ]);
    }
}
