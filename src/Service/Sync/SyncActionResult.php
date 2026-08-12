<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service\Sync;

final readonly class SyncActionResult
{
    public function __construct(
        public SyncAction $action,
        public string $status,
        public bool $requested,
        public ?string $error = null
    ) {
    }

    /** @return array<string, bool|string|null> */
    public function toArray(): array
    {
        return [
            'technicalName' => $this->action->technicalName,
            'action' => $this->action->action,
            'installedVersion' => $this->action->installedVersion,
            'availableVersion' => $this->action->availableVersion,
            'registry' => $this->action->registryUrl,
            'requested' => $this->requested,
            'status' => $this->status,
            'error' => $this->error,
        ];
    }
}
