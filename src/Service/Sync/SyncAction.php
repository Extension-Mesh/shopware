<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service\Sync;

final readonly class SyncAction
{
    public const INSTALL = 'install';
    public const UPDATE = 'update';
    public const ACTIVATE = 'activate';
    public const CURRENT = 'current';
    public const PRUNE = 'prune';
    public const UNMANAGED = 'unmanaged';
    public const CONFLICT = 'conflict';

    public function __construct(
        public string $technicalName,
        public string $action,
        public ?string $installedVersion,
        public ?string $availableVersion,
        public ?string $registryId,
        public string $registryUrl,
        public bool $active = false
    ) {
    }
}
