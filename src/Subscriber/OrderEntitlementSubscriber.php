<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Subscriber;

use ExtensionMesh\Shopware\Infrastructure\Persistence\EntitlementRepository;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Checkout\Order\OrderStates;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class OrderEntitlementSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly EntitlementRepository $entitlements)
    {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'state_enter.' . OrderTransactionStates::STATE_MACHINE
                . '.' . OrderTransactionStates::STATE_PAID => 'issueEntitlements',
            'state_enter.' . OrderTransactionStates::STATE_MACHINE
                . '.' . OrderTransactionStates::STATE_PARTIALLY_PAID => 'issueEntitlements',
            'state_enter.' . OrderTransactionStates::STATE_MACHINE
                . '.' . OrderTransactionStates::STATE_AUTHORIZED => 'issueEntitlements',
            'state_enter.' . OrderTransactionStates::STATE_MACHINE
                . '.' . OrderTransactionStates::STATE_REFUNDED => 'disableEntitlements',
            'state_enter.' . OrderTransactionStates::STATE_MACHINE
                . '.' . OrderTransactionStates::STATE_CANCELLED => 'disableEntitlements',
            'state_enter.' . OrderTransactionStates::STATE_MACHINE
                . '.' . OrderTransactionStates::STATE_CHARGEBACK => 'disableEntitlements',
            'state_enter.' . OrderStates::STATE_MACHINE
                . '.' . OrderStates::STATE_CANCELLED => 'disableEntitlements',
        ];
    }

    public function issueEntitlements(OrderStateMachineStateChangeEvent $event): void
    {
        $this->entitlements->issueForOrder($event->getOrderId(), $event->getContext());
    }

    public function disableEntitlements(OrderStateMachineStateChangeEvent $event): void
    {
        $this->entitlements->disableForOrder($event->getOrderId(), $event->getContext());
    }
}
