<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;

interface CustomerProductAccessProvider
{
    /** @return list<string> */
    public function productIds(string $customerId, string $salesChannelId, Context $context): array;

    public function grants(
        string $customerId,
        string $productId,
        string $salesChannelId,
        Context $context
    ): bool;

    /**
     * Returns a filter rooted at the product entity.
     *
     * Providers are combined with OR. This lets a future free-product provider
     * grant access as a product policy without materializing one entitlement per customer.
     */
    public function productFilter(string $customerId, string $salesChannelId): Filter;
}
