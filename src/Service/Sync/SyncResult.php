<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service\Sync;

final readonly class SyncResult
{
    /**
     * @param list<SyncActionResult> $actions
     * @param list<string> $errors
     */
    public function __construct(
        public array $actions,
        public array $errors = []
    ) {
    }

    public function isSuccessful(): bool
    {
        if ($this->errors !== []) {
            return false;
        }

        foreach ($this->actions as $action) {
            if ($action->status === 'failed') {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, int> */
    public function summary(): array
    {
        $summary = [
            SyncAction::INSTALL => 0,
            SyncAction::UPDATE => 0,
            SyncAction::ACTIVATE => 0,
            SyncAction::CURRENT => 0,
            SyncAction::PRUNE => 0,
            SyncAction::UNMANAGED => 0,
            SyncAction::CONFLICT => 0,
            'failed' => 0,
        ];

        foreach ($this->actions as $result) {
            ++$summary[$result->action->action];
            if ($result->status === 'failed') {
                ++$summary['failed'];
            }
        }

        return $summary;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'success' => $this->isSuccessful(),
            'actions' => \array_map(
                static fn (SyncActionResult $action): array => $action->toArray(),
                $this->actions
            ),
            'summary' => $this->summary(),
            'errors' => $this->errors,
        ];
    }
}
