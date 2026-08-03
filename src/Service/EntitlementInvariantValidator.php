<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;

final class EntitlementInvariantValidator
{
    public function __construct(
        private readonly EntitlementProductEligibility $eligibility,
        /** @var EntityRepository<OrderCollection> */
        private readonly EntityRepository $orders
    ) {
    }

    public function violation(
        string $customerId,
        string $productId,
        string $salesChannelId,
        ?string $orderId,
        Context $context
    ): ?string {
        if (!Uuid::isValid($customerId) || !Uuid::isValid($productId) || !Uuid::isValid($salesChannelId)) {
            return 'Customer, product and sales channel must use valid identifiers.';
        }
        if (!\in_array($productId, $this->eligibility->eligibleProductIds($context), true)) {
            return 'The selected product is not enabled for ExtensionMesh publication.';
        }
        if ($orderId === null) {
            return null;
        }
        if (!Uuid::isValid($orderId)) {
            return 'The selected order identifier is invalid.';
        }

        $criteria = (new Criteria([$orderId]))
            ->addAssociation('orderCustomer')
            ->setLimit(1);
        $order = $this->orders->search($criteria, $context)->first();
        if (!$order instanceof OrderEntity) {
            return 'The selected order does not exist.';
        }
        if (
            $order->getSalesChannelId() !== $salesChannelId
            || $order->getOrderCustomer()?->getCustomerId() !== $customerId
        ) {
            return 'The selected order does not belong to this customer and sales channel.';
        }

        return null;
    }
}
