<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use Shopware\Core\Framework\Context;

interface OrderEntitlementReconciler
{
    public function reconcileForOrder(string $orderId, Context $context): int;

    public function disableForOrder(string $orderId, Context $context): void;
}
