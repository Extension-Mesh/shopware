<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service\Sync;

final readonly class SyncOptions
{
    public function __construct(
        public bool $install = false,
        public bool $update = false,
        public bool $activate = false,
        public bool $prune = false,
        public bool $dryRun = false,
        public bool $refresh = true
    ) {
    }

    public function requests(string $action): bool
    {
        return match ($action) {
            SyncAction::INSTALL => $this->install,
            SyncAction::UPDATE => $this->update,
            SyncAction::ACTIVATE => $this->activate,
            SyncAction::PRUNE => $this->prune,
            default => false,
        };
    }
}
