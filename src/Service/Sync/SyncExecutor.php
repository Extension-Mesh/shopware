<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service\Sync;

use Shopware\Core\Framework\Context;

final readonly class SyncExecutor
{
    public function __construct(private ExtensionOperator $operator)
    {
    }

    /** @param list<string> $errors */
    public function execute(SyncPlan $plan, SyncOptions $options, Context $context, array $errors = []): SyncResult
    {
        $results = [];
        foreach ($plan->actions as $action) {
            $requested = $options->requests($action->action);

            if (!$requested) {
                $status = match ($action->action) {
                    SyncAction::CURRENT => 'current',
                    SyncAction::UNMANAGED, SyncAction::CONFLICT => 'skipped',
                    default => 'pending',
                };
                $results[] = new SyncActionResult($action, $status, false);
                continue;
            }

            if ($options->dryRun) {
                $results[] = new SyncActionResult($action, 'planned', true);
                continue;
            }

            try {
                match ($action->action) {
                    SyncAction::INSTALL => $this->operator->install(
                        $action,
                        $options->activate,
                        $options->refresh,
                        $context
                    ),
                    SyncAction::UPDATE => $this->operator->update(
                        $action,
                        $options->activate,
                        $options->refresh,
                        $context
                    ),
                    SyncAction::ACTIVATE => $this->operator->activate($action, $context),
                    SyncAction::PRUNE => $this->operator->prune($action, $context),
                    default => null,
                };
                $results[] = new SyncActionResult($action, 'completed', true);
            } catch (\Throwable $exception) {
                $results[] = new SyncActionResult($action, 'failed', true, $exception->getMessage());
            }
        }

        return new SyncResult($results, $errors);
    }
}
