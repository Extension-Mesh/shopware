<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Subscriber;

use ExtensionMesh\Shopware\Service\OrderEntitlementReconciler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Checkout\Order\OrderStates;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class OrderEntitlementSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly OrderEntitlementReconciler $entitlements)
    {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        $events = [];
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
            $events['state_enter.' . OrderTransactionStates::STATE_MACHINE . '.' . $state] = 'reconcileEntitlements';
        }
        $events['state_enter.' . OrderStates::STATE_MACHINE . '.' . OrderStates::STATE_CANCELLED] = 'disableEntitlements';

        return $events;
    }

    public function reconcileEntitlements(OrderStateMachineStateChangeEvent $event): void
    {
        $this->entitlements->reconcileForOrder($event->getOrderId(), $event->getContext());
    }

    public function disableEntitlements(OrderStateMachineStateChangeEvent $event): void
    {
        $this->entitlements->disableForOrder($event->getOrderId(), $event->getContext());
    }
}
