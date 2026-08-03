<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Subscriber;

use ExtensionMesh\Shopware\Service\OrderEntitlementReconciler;
use ExtensionMesh\Shopware\Subscriber\OrderEntitlementSubscriber;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;

final class OrderEntitlementSubscriberTest extends TestCase
{
    public function testEveryTransactionStateReconcilesInsteadOfBlindlyGrantingAccess(): void
    {
        $events = OrderEntitlementSubscriber::getSubscribedEvents();

        foreach ([
            OrderTransactionStates::STATE_OPEN,
            OrderTransactionStates::STATE_PAID,
            OrderTransactionStates::STATE_PARTIALLY_PAID,
            OrderTransactionStates::STATE_REFUNDED,
            OrderTransactionStates::STATE_PARTIALLY_REFUNDED,
            OrderTransactionStates::STATE_CANCELLED,
            OrderTransactionStates::STATE_REMINDED,
            OrderTransactionStates::STATE_AUTHORIZED,
            OrderTransactionStates::STATE_FAILED,
            OrderTransactionStates::STATE_IN_PROGRESS,
            OrderTransactionStates::STATE_CHARGEBACK,
            OrderTransactionStates::STATE_UNCONFIRMED,
        ] as $state) {
            self::assertSame(
                'reconcileEntitlements',
                $events['state_enter.' . OrderTransactionStates::STATE_MACHINE . '.' . $state]
            );
        }
        self::assertSame(
            'disableEntitlements',
            $events['state_enter.' . OrderStates::STATE_MACHINE . '.' . OrderStates::STATE_CANCELLED]
        );
    }

    public function testTransactionHandlerDelegatesToAggregateReconciliation(): void
    {
        $context = Context::createCLIContext();
        $order = new OrderEntity();
        $order->setId(Uuid::randomHex());
        $reconciler = $this->createMock(OrderEntitlementReconciler::class);
        $reconciler->expects(self::once())
            ->method('reconcileForOrder')
            ->with($order->getId(), $context);

        (new OrderEntitlementSubscriber($reconciler))->reconcileEntitlements(
            new OrderStateMachineStateChangeEvent('transaction-state-changed', $order, $context)
        );
    }

    public function testOrderCancellationAlwaysDisablesEntitlements(): void
    {
        $context = Context::createCLIContext();
        $order = new OrderEntity();
        $order->setId(Uuid::randomHex());
        $reconciler = $this->createMock(OrderEntitlementReconciler::class);
        $reconciler->expects(self::once())
            ->method('disableForOrder')
            ->with($order->getId(), $context);

        (new OrderEntitlementSubscriber($reconciler))->disableEntitlements(
            new OrderStateMachineStateChangeEvent('order-cancelled', $order, $context)
        );
    }
}
