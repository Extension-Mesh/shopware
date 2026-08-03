<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Infrastructure\Persistence;

use ExtensionMesh\Shopware\Core\Content\Entitlement\EntitlementCollection;
use ExtensionMesh\Shopware\Core\Content\Entitlement\EntitlementEntity;
use ExtensionMesh\Shopware\Core\Content\ExtensionMeshProduct\ExtensionMeshProductCollection;
use ExtensionMesh\Shopware\Core\Content\ExtensionMeshProduct\ExtensionMeshProductEntity;
use ExtensionMesh\Shopware\Core\Content\RepositoryConnection\RepositoryConnectionCollection;
use ExtensionMesh\Shopware\Infrastructure\Persistence\EntitlementRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class EntitlementRepositoryTest extends TestCase
{
    public function testPaidOrderReEnablesAnExistingRevokedEntitlement(): void
    {
        $context = Context::createCLIContext();
        $productId = Uuid::randomHex();
        $order = $this->order($productId, [OrderTransactionStates::STATE_PAID]);
        $entitlement = $this->entitlement($order, $productId, false);
        $repositories = $this->repositories();
        $repositories->orders->expects(self::exactly(2))
            ->method('search')
            ->willReturn($this->searchResult(new OrderCollection([$order]), $context));
        $repositories->entitlements->expects(self::once())
            ->method('search')
            ->willReturn($this->searchResult(new EntitlementCollection([$entitlement]), $context));
        $repositories->entitlements->expects(self::never())->method('create');
        $repositories->entitlements->expects(self::once())
            ->method('update')
            ->with(self::callback(static function (array $updates) use ($entitlement): bool {
                return $updates === [[
                    'id' => $entitlement->getId(),
                    'enabled' => true,
                    'validUntil' => null,
                ]];
            }), $context)
            ->willReturn($this->writtenEvent($context));
        $this->eligibleProduct($repositories, $productId, $context);

        $changed = $this->repository($repositories)->reconcileForOrder($order->getId(), $context);

        self::assertSame(1, $changed);
    }

    public function testPartialPaymentDoesNotGrantFullOrderAccess(): void
    {
        $context = Context::createCLIContext();
        $productId = Uuid::randomHex();
        $order = $this->order($productId, [OrderTransactionStates::STATE_PARTIALLY_PAID]);
        $entitlementId = Uuid::randomHex();
        $repositories = $this->repositories();
        $repositories->orders->expects(self::once())
            ->method('search')
            ->willReturn($this->searchResult(new OrderCollection([$order]), $context));
        $repositories->entitlements->expects(self::once())
            ->method('searchIds')
            ->willReturnCallback(static fn (Criteria $criteria, Context $actualContext): IdSearchResult => IdSearchResult::fromIds(
                [$entitlementId],
                $criteria,
                $actualContext
            ));
        $repositories->entitlements->expects(self::once())
            ->method('update')
            ->with([['id' => $entitlementId, 'enabled' => false]], $context)
            ->willReturn($this->writtenEvent($context));

        $changed = $this->repository($repositories)->reconcileForOrder($order->getId(), $context);

        self::assertSame(0, $changed);
    }

    public function testOnePaidTransactionKeepsAccessWhenAnotherTransactionIsRefunded(): void
    {
        $context = Context::createCLIContext();
        $productId = Uuid::randomHex();
        $order = $this->order($productId, [
            OrderTransactionStates::STATE_REFUNDED,
            OrderTransactionStates::STATE_PAID,
        ]);
        $repositories = $this->repositories();
        $repositories->orders->expects(self::exactly(2))
            ->method('search')
            ->willReturn($this->searchResult(new OrderCollection([$order]), $context));
        $repositories->entitlements->expects(self::once())
            ->method('search')
            ->willReturn($this->searchResult(new EntitlementCollection(), $context));
        $repositories->entitlements->expects(self::once())
            ->method('create')
            ->with(self::callback(static function (array $writes) use ($order, $productId): bool {
                return \count($writes) === 1
                    && $writes[0]['orderId'] === $order->getId()
                    && $writes[0]['productId'] === $productId
                    && $writes[0]['enabled'] === true;
            }), $context)
            ->willReturn($this->writtenEvent($context));
        $this->eligibleProduct($repositories, $productId, $context);

        self::assertSame(1, $this->repository($repositories)->reconcileForOrder($order->getId(), $context));
    }

    public function testReprocessingAPaidOrderDoesNotExtendAnActiveEntitlement(): void
    {
        $context = Context::createCLIContext();
        $productId = Uuid::randomHex();
        $order = $this->order($productId, [OrderTransactionStates::STATE_PAID]);
        $entitlement = $this->entitlement($order, $productId, true);
        $entitlement->setValidUntil(new \DateTimeImmutable('+5 days'));
        $repositories = $this->repositories();
        $repositories->orders->expects(self::exactly(2))
            ->method('search')
            ->willReturn($this->searchResult(new OrderCollection([$order]), $context));
        $repositories->entitlements->expects(self::once())
            ->method('search')
            ->willReturn($this->searchResult(new EntitlementCollection([$entitlement]), $context));
        $repositories->entitlements->expects(self::never())->method('create');
        $repositories->entitlements->expects(self::never())->method('update');
        $this->eligibleProduct($repositories, $productId, $context);

        self::assertSame(0, $this->repository($repositories, 30)->reconcileForOrder($order->getId(), $context));
    }

    private function repositories(): EntitlementRepositoryDependencies
    {
        return new EntitlementRepositoryDependencies(
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class)
        );
    }

    private function repository(
        EntitlementRepositoryDependencies $repositories,
        int $validityDays = 0
    ): EntitlementRepository
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('getInt')->willReturn($validityDays);

        return new EntitlementRepository(
            $repositories->entitlements,
            $repositories->orders,
            $repositories->products,
            $repositories->integratedProducts,
            $repositories->connections,
            $config
        );
    }

    private function eligibleProduct(
        EntitlementRepositoryDependencies $repositories,
        string $productId,
        Context $context
    ): void
    {
        $product = new ExtensionMeshProductEntity();
        $product->setUniqueIdentifier($productId . '-' . Defaults::LIVE_VERSION);
        $product->setProductId($productId);
        $product->setProductVersionId(Defaults::LIVE_VERSION);
        $product->setEnabled(true);
        $repositories->integratedProducts->expects(self::once())
            ->method('search')
            ->willReturn($this->searchResult(new ExtensionMeshProductCollection([$product]), $context));
        $repositories->connections->expects(self::once())
            ->method('search')
            ->willReturn($this->searchResult(new RepositoryConnectionCollection(), $context));
    }

    /** @param list<string> $transactionStates */
    private function order(string $productId, array $transactionStates): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId(Uuid::randomHex());
        $order->setSalesChannelId(Uuid::randomHex());
        $customer = new OrderCustomerEntity();
        $customer->setId(Uuid::randomHex());
        $customer->setCustomerId(Uuid::randomHex());
        $order->setOrderCustomer($customer);
        $lineItem = new OrderLineItemEntity();
        $lineItem->setId(Uuid::randomHex());
        $lineItem->setProductId($productId);
        $lineItem->setQuantity(1);
        $order->setLineItems(new OrderLineItemCollection([$lineItem]));
        $transactions = [];
        foreach ($transactionStates as $technicalName) {
            $transaction = new OrderTransactionEntity();
            $transaction->setId(Uuid::randomHex());
            $transaction->setStateMachineState($this->state($technicalName));
            $transactions[] = $transaction;
        }
        $order->setTransactions(new OrderTransactionCollection($transactions));

        return $order;
    }

    private function entitlement(OrderEntity $order, string $productId, bool $enabled): EntitlementEntity
    {
        $entity = new EntitlementEntity();
        $entity->setId(Uuid::randomHex());
        $entity->setCustomerId($order->getOrderCustomer()?->getCustomerId() ?? Uuid::randomHex());
        $entity->setProductId($productId);
        $entity->setProductVersionId(Defaults::LIVE_VERSION);
        $entity->setSalesChannelId($order->getSalesChannelId());
        $entity->setOrderId($order->getId());
        $entity->setOrderVersionId(Defaults::LIVE_VERSION);
        $entity->setEnabled($enabled);

        return $entity;
    }

    private function state(string $technicalName): StateMachineStateEntity
    {
        $state = new StateMachineStateEntity();
        $state->setId(Uuid::randomHex());
        $state->setTechnicalName($technicalName);

        return $state;
    }

    /**
     * @template TCollection of EntityCollection
     * @param TCollection $entities
     * @return EntitySearchResult<TCollection>
     */
    private function searchResult(EntityCollection $entities, Context $context): EntitySearchResult
    {
        $criteria = new Criteria();

        return new EntitySearchResult(
            'test',
            $entities->count(),
            $entities,
            new AggregationResultCollection(),
            $criteria,
            $context
        );
    }

    private function writtenEvent(Context $context): EntityWrittenContainerEvent
    {
        return EntityWrittenContainerEvent::createWithWrittenEvents([], $context, []);
    }
}

final class EntitlementRepositoryDependencies
{
    /** @var EntityRepository<EntitlementCollection>&MockObject */
    public readonly EntityRepository $entitlements;

    /** @var EntityRepository<OrderCollection>&MockObject */
    public readonly EntityRepository $orders;

    /** @var EntityRepository<ProductCollection>&MockObject */
    public readonly EntityRepository $products;

    /** @var EntityRepository<ExtensionMeshProductCollection>&MockObject */
    public readonly EntityRepository $integratedProducts;

    /** @var EntityRepository<RepositoryConnectionCollection>&MockObject */
    public readonly EntityRepository $connections;

    /**
     * @param EntityRepository<EntitlementCollection>&MockObject $entitlements
     * @param EntityRepository<OrderCollection>&MockObject $orders
     * @param EntityRepository<ProductCollection>&MockObject $products
     * @param EntityRepository<ExtensionMeshProductCollection>&MockObject $integratedProducts
     * @param EntityRepository<RepositoryConnectionCollection>&MockObject $connections
     */
    public function __construct(
        EntityRepository $entitlements,
        EntityRepository $orders,
        EntityRepository $products,
        EntityRepository $integratedProducts,
        EntityRepository $connections
    ) {
        $this->entitlements = $entitlements;
        $this->orders = $orders;
        $this->products = $products;
        $this->integratedProducts = $integratedProducts;
        $this->connections = $connections;
    }
}
