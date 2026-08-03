<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Service;

use ExtensionMesh\Shopware\Service\EntitlementInvariantValidator;
use ExtensionMesh\Shopware\Service\EntitlementProductEligibility;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;

final class EntitlementInvariantValidatorTest extends TestCase
{
    public function testManualEntitlementAcceptsAnEligibleProductWithoutAnOrder(): void
    {
        $productId = Uuid::randomHex();
        $eligibility = $this->createMock(EntitlementProductEligibility::class);
        $eligibility->method('eligibleProductIds')->willReturn([$productId]);
        $orders = $this->createMock(EntityRepository::class);
        $orders->expects(self::never())->method('search');
        $validator = new EntitlementInvariantValidator($eligibility, $orders);

        self::assertNull($validator->violation(
            Uuid::randomHex(),
            $productId,
            Uuid::randomHex(),
            null,
            Context::createCLIContext()
        ));
    }

    public function testItRejectsAProductThatIsNotPublishedByExtensionMesh(): void
    {
        $eligibility = $this->createMock(EntitlementProductEligibility::class);
        $eligibility->method('eligibleProductIds')->willReturn([]);
        $orders = $this->createMock(EntityRepository::class);
        $orders->expects(self::never())->method('search');
        $validator = new EntitlementInvariantValidator($eligibility, $orders);

        self::assertSame(
            'The selected product is not enabled for ExtensionMesh publication.',
            $validator->violation(
                Uuid::randomHex(),
                Uuid::randomHex(),
                Uuid::randomHex(),
                null,
                Context::createCLIContext()
            )
        );
    }

    public function testItRejectsAnOrderFromAnotherCustomer(): void
    {
        $context = Context::createCLIContext();
        $productId = Uuid::randomHex();
        $salesChannelId = Uuid::randomHex();
        $order = new OrderEntity();
        $order->setId(Uuid::randomHex());
        $order->setSalesChannelId($salesChannelId);
        $orderCustomer = new OrderCustomerEntity();
        $orderCustomer->setId(Uuid::randomHex());
        $orderCustomer->setCustomerId(Uuid::randomHex());
        $order->setOrderCustomer($orderCustomer);
        $eligibility = $this->createMock(EntitlementProductEligibility::class);
        $eligibility->method('eligibleProductIds')->willReturn([$productId]);
        $orders = $this->createMock(EntityRepository::class);
        $orders->expects(self::once())
            ->method('search')
            ->willReturn($this->searchResult(new OrderCollection([$order]), $context));
        $validator = new EntitlementInvariantValidator($eligibility, $orders);

        self::assertSame(
            'The selected order does not belong to this customer and sales channel.',
            $validator->violation(
                Uuid::randomHex(),
                $productId,
                $salesChannelId,
                $order->getId(),
                $context
            )
        );
    }

    /** @return EntitySearchResult<OrderCollection> */
    private function searchResult(OrderCollection $orders, Context $context): EntitySearchResult
    {
        return new EntitySearchResult(
            'order',
            $orders->count(),
            $orders,
            new AggregationResultCollection(),
            new Criteria(),
            $context
        );
    }
}
