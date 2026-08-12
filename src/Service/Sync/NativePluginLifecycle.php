<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service\Sync;

use ExtensionMesh\Shopware\Infrastructure\Persistence\ExtensionOwnershipRepository;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\PluginCollection;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Shopware\Core\Framework\Plugin\PluginLifecycleService;

final readonly class NativePluginLifecycle
{
    public function __construct(
        /** @var EntityRepository<PluginCollection> */
        private EntityRepository $plugins,
        private PluginLifecycleService $lifecycle,
        private ExtensionOwnershipRepository $ownership
    ) {
    }

    public function apply(string $action, string $technicalName, bool $activate, Context $context): void
    {
        if (!isset($this->ownership->all($context)[$technicalName])) {
            throw new \RuntimeException(\sprintf(
                'Plugin "%s" is not managed by ExtensionMesh.',
                $technicalName
            ));
        }

        $plugin = $this->plugin($technicalName, $context);
        if ($action === SyncAction::INSTALL) {
            $this->lifecycle->installPlugin($plugin, $context);
        } elseif ($action === SyncAction::UPDATE) {
            $this->lifecycle->updatePlugin($plugin, $context);
        } else {
            throw new \InvalidArgumentException('Unsupported lifecycle action: ' . $action);
        }

        if ($activate && !$plugin->getActive()) {
            $this->lifecycle->activatePlugin(
                $plugin,
                $context,
                validateRequirements: $action !== SyncAction::INSTALL
            );
        }
    }

    private function plugin(string $technicalName, Context $context): PluginEntity
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('name', $technicalName))
            ->setLimit(1);
        $plugin = $this->plugins->search($criteria, $context)->first();
        if (!$plugin instanceof PluginEntity) {
            throw new \RuntimeException(\sprintf('Plugin "%s" was not discovered by Shopware.', $technicalName));
        }

        return $plugin;
    }
}
