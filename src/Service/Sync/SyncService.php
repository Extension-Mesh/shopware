<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service\Sync;

use ExtensionMesh\Shopware\Infrastructure\Persistence\ExtensionOwnershipRepository;
use ExtensionMesh\Shopware\Service\CatalogManager;
use Shopware\Core\Framework\Context;

final readonly class SyncService implements SyncRunner
{
    public function __construct(
        private CatalogManager $catalog,
        private ExtensionOwnershipRepository $ownership,
        private ExtensionOperator $operator,
        private SyncPlanner $planner,
        private SyncExecutor $executor,
        private string $shopwareVersion
    ) {
    }

    public function synchronize(SyncOptions $options, Context $context): SyncResult
    {
        $errors = [];
        if ($options->refresh && !$options->dryRun) {
            foreach ($this->catalog->refreshAll($context) as $refresh) {
                if (!$refresh['success']) {
                    $errors[] = \sprintf(
                        'Refresh failed for %s: %s',
                        $refresh['url'],
                        $refresh['error'] ?? 'unknown error'
                    );
                }
            }
        }

        $catalog = $this->catalog->catalog(
            $this->shopwareVersion,
            \PHP_VERSION,
            'en-GB',
            $context,
            $options->refresh && !$options->dryRun
        );
        foreach ($catalog['warnings'] as $warning) {
            $errors[] = \sprintf('Registry %s: %s', $warning['registryId'], $warning['message']);
        }

        $plan = $this->planner->plan(
            $catalog['extensions'],
            $this->operator->inventory($context),
            $this->ownership->all($context)
        );

        return $this->executor->execute($plan, $options, $context, $errors);
    }
}
