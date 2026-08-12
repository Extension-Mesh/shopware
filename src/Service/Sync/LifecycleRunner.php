<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service\Sync;

interface LifecycleRunner
{
    public function run(string $action, string $technicalName, bool $activate): void;
}
