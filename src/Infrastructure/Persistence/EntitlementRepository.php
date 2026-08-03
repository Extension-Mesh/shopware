<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Infrastructure\Persistence;

use ExtensionMesh\Shopware\Core\Content\Entitlement\EntitlementEntity;
use ExtensionMesh\Shopware\Core\Content\Entitlement\EntitlementCollection;
use ExtensionMesh\Shopware\Core\Content\ExtensionMeshProduct\ExtensionMeshProductCollection;
use ExtensionMesh\Shopware\Core\Content\ExtensionMeshProduct\ExtensionMeshProductEntity;
use ExtensionMesh\Shopware\Core\Content\RepositoryConnection\RepositoryConnectionCollection;
use ExtensionMesh\Shopware\Core\Content\RepositoryConnection\RepositoryConnectionEntity;
use ExtensionMesh\Shopware\Service\OrderEntitlementReconciler;
use ExtensionMesh\Shopware\Service\EntitlementProductEligibility;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class EntitlementRepository implements OrderEntitlementReconciler, EntitlementProductEligibility
{
    private const ORDER_VALIDITY_DAYS_CONFIG =
        'ExtensionMesh.config.orderEntitlementValidityDays';

    public function __construct(
        /** @var EntityRepository<EntitlementCollection> */
        private readonly EntityRepository $repository,
        /** @var EntityRepository<OrderCollection> */
        private readonly EntityRepository $orders,
        /** @var EntityRepository<ProductCollection> */
        private readonly EntityRepository $products,
        /** @var EntityRepository<ExtensionMeshProductCollection> */
        private readonly EntityRepository $integratedProducts,
        /** @var EntityRepository<RepositoryConnectionCollection> */
        private readonly EntityRepository $connections,
        private readonly SystemConfigService $systemConfig
    ) {
    }

    /** @return list<string> */
    public function entitledProductIds(string $customerId, ?string $salesChannelId, Context $context): array
    {
        $criteria = $this->activeCriteria($customerId, $salesChannelId);
        if ($criteria === null) {
            return [];
        }

        $productIds = [];
        $entitlements = $this->repository->search($criteria, $context);
        foreach ($entitlements as $entity) {
            $productIds[$entity->getProductId()] = true;
        }

        return \array_keys($productIds);
    }

    public function isEntitled(string $customerId, string $productId, ?string $salesChannelId, Context $context): bool
    {
        if (!Uuid::isValid($productId)) {
            return false;
        }
        $criteria = $this->activeCriteria($customerId, $salesChannelId);
        if ($criteria === null) {
            return false;
        }
        $criteria->addFilter(new EqualsFilter('productId', $productId))->setLimit(1);

        return $this->repository->searchIds($criteria, $context)->getTotal() > 0;
    }

    public function issueForOrder(string $orderId, Context $context): int
    {
        if (!Uuid::isValid($orderId)) {
            return 0;
        }

        $criteria = (new Criteria([$orderId]))
            ->addAssociation('orderCustomer')
            ->addAssociation('lineItems');
        $order = $this->orders->search($criteria, $context)->first();
        if (!$order instanceof OrderEntity || $order->getOrderCustomer()?->getCustomerId() === null) {
            return 0;
        }

        $quantities = [];
        foreach ($order->getLineItems() ?? [] as $lineItem) {
            $productId = $lineItem->getProductId();
            if ($productId !== null) {
                $quantities[$productId] = ($quantities[$productId] ?? 0) + $lineItem->getQuantity();
            }
        }
        $eligible = \array_flip($this->eligibleProductIds($context));
        $quantities = \array_filter(
            $quantities,
            static fn (string $productId): bool => isset($eligible[$productId]),
            \ARRAY_FILTER_USE_KEY
        );
        if ($quantities === []) {
            return 0;
        }

        $existingCriteria = (new Criteria())
            ->addFilter(new EqualsFilter('orderId', $orderId))
            ->addFilter(new EqualsFilter('orderVersionId', Defaults::LIVE_VERSION));
        /** @var array<string, list<EntitlementEntity>> $existing */
        $existing = [];
        $existingEntitlements = $this->repository->search($existingCriteria, $context);
        foreach ($existingEntitlements as $entity) {
            $existing[$entity->getProductId()][] = $entity;
        }

        $now = new \DateTimeImmutable();
        $validityDays = $this->orderValidityDays($order->getSalesChannelId());
        $validUntil = $validityDays === null ? null : $now->modify(\sprintf('+%d days', $validityDays));
        $writes = [];
        $updates = [];
        foreach ($quantities as $productId => $quantity) {
            $productEntitlements = $existing[$productId] ?? [];
            foreach (\array_slice($productEntitlements, 0, $quantity) as $entitlement) {
                if (!$entitlement->isEnabled()) {
                    $updates[] = [
                        'id' => $entitlement->getId(),
                        'enabled' => true,
                        'validUntil' => $validUntil,
                    ];
                }
            }

            $missing = \max(0, $quantity - \count($productEntitlements));
            for ($index = 0; $index < $missing; ++$index) {
                $writes[] = [
                    'id' => Uuid::randomHex(),
                    'customerId' => $order->getOrderCustomer()->getCustomerId(),
                    'productId' => $productId,
                    'productVersionId' => Defaults::LIVE_VERSION,
                    'salesChannelId' => $order->getSalesChannelId(),
                    'orderId' => $orderId,
                    'orderVersionId' => Defaults::LIVE_VERSION,
                    'enabled' => true,
                    'validUntil' => $validUntil,
                ];
            }
        }
        if ($writes !== []) {
            $this->repository->create($writes, $context);
        }
        if ($updates !== []) {
            $this->repository->update($updates, $context);
        }

        return \count($writes) + \count($updates);
    }

    public function reconcileForOrder(string $orderId, Context $context): int
    {
        if (!Uuid::isValid($orderId)) {
            return 0;
        }

        $criteria = (new Criteria([$orderId]))
            ->addAssociation('stateMachineState')
            ->addAssociation('transactions.stateMachineState');
        $order = $this->orders->search($criteria, $context)->first();
        if (!$order instanceof OrderEntity) {
            return 0;
        }

        $orderCancelled = $order->getStateMachineState()?->getTechnicalName() === OrderStates::STATE_CANCELLED;
        $hasPaidTransaction = false;
        foreach ($order->getTransactions() ?? [] as $transaction) {
            if ($transaction->getStateMachineState()?->getTechnicalName() === OrderTransactionStates::STATE_PAID) {
                $hasPaidTransaction = true;
                break;
            }
        }

        if ($orderCancelled || !$hasPaidTransaction) {
            $this->disableForOrder($orderId, $context);

            return 0;
        }

        return $this->issueForOrder($orderId, $context);
    }

    public function disableForOrder(string $orderId, Context $context): void
    {
        if (!Uuid::isValid($orderId)) {
            return;
        }
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('orderId', $orderId))
            ->addFilter(new EqualsFilter('orderVersionId', Defaults::LIVE_VERSION));
        $ids = $this->repository->searchIds($criteria, $context)->getIds();
        if ($ids === []) {
            return;
        }
        $this->repository->update(\array_map(
            static fn (string $id): array => ['id' => $id, 'enabled' => false],
            $ids
        ), $context);
    }

    /**
     * @param list<string> $productIds
     *
     * @return list<string>
     */
    public function existingProductIds(array $productIds, Context $context): array
    {
        $validIds = \array_values(\array_filter($productIds, Uuid::isValid(...)));
        if ($validIds === []) {
            return [];
        }
        $criteria = (new Criteria($validIds))
            ->addFilter(new EqualsFilter('versionId', Defaults::LIVE_VERSION));

        return $this->products->searchIds($criteria, $context)->getIds();
    }

    /** @return list<string> */
    public function eligibleProductIds(Context $context): array
    {
        $integratedCriteria = (new Criteria())->addFilter(new EqualsFilter('enabled', true));
        $connectionCriteria = (new Criteria())
            ->addFilter(new EqualsFilter('enabled', true))
            ->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
                new EqualsFilter('productId', null),
            ]));

        $ids = [];
        $integratedProducts = $this->integratedProducts->search($integratedCriteria, $context);
        foreach ($integratedProducts as $entity) {
            $ids[$entity->getProductId()] = true;
        }
        $connections = $this->connections->search($connectionCriteria, $context);
        foreach ($connections as $entity) {
            if ($entity->getProductId() !== null) {
                $ids[$entity->getProductId()] = true;
            }
        }

        return \array_keys($ids);
    }

    private function activeCriteria(string $customerId, ?string $salesChannelId): ?Criteria
    {
        if (!Uuid::isValid($customerId) || ($salesChannelId !== null && !Uuid::isValid($salesChannelId))) {
            return null;
        }
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('customerId', $customerId))
            ->addFilter(new EqualsFilter('enabled', true))
            ->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
                new EqualsFilter('validUntil', null),
                new RangeFilter('validUntil', [
                    RangeFilter::GT => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                ]),
            ]));
        if ($salesChannelId !== null) {
            $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
        }

        return $criteria;
    }

    private function orderValidityDays(string $salesChannelId): ?int
    {
        $configured = $this->systemConfig->getInt(
            self::ORDER_VALIDITY_DAYS_CONFIG,
            $salesChannelId
        );

        return $configured > 0 ? $configured : null;
    }

}
