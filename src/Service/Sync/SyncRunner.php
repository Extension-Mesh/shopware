<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service\Sync;

use Shopware\Core\Framework\Context;

interface SyncRunner
{
    public function synchronize(SyncOptions $options, Context $context): SyncResult;
}
