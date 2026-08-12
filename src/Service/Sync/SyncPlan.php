<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service\Sync;

final readonly class SyncPlan
{
    /** @param list<SyncAction> $actions */
    public function __construct(public array $actions)
    {
    }
}
