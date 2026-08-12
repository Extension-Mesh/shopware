<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service\Sync;

use Shopware\Core\Framework\Context;

interface ExtensionOperator
{
    /** @return array<string, LocalExtension> */
    public function inventory(Context $context): array;

    public function install(SyncAction $action, bool $activate, bool $refresh, Context $context): void;

    public function update(SyncAction $action, bool $activate, bool $refresh, Context $context): void;

    public function activate(SyncAction $action, Context $context): void;

    public function prune(SyncAction $action, Context $context): void;
}
