<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service\Sync;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Infrastructure\Persistence\ExtensionOwnershipRepository;
use ExtensionMesh\Shopware\Service\CatalogService;
use ExtensionMesh\Shopware\Service\ExtensionInstaller;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\PluginCollection;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Shopware\Core\Framework\Plugin\PluginLifecycleService;

final readonly class ShopwareExtensionOperator implements ExtensionOperator
{
    public function __construct(
        /** @var EntityRepository<PluginCollection> */
        private EntityRepository $plugins,
        private CatalogService $catalog,
        private ExtensionInstaller $installer,
        private PluginLifecycleService $lifecycle,
        private LifecycleRunner $lifecycleRunner,
        private ExtensionOwnershipRepository $ownership,
        private string $shopwareVersion
    ) {
    }

    /** @return array<string, LocalExtension> */
    public function inventory(Context $context): array
    {
        $inventory = [];
        $plugins = $this->plugins->search(new Criteria(), $context);
        foreach ($plugins as $plugin) {
            $inventory[$plugin->getName()] = new LocalExtension(
                $plugin->getName(),
                $plugin->getVersion(),
                $plugin->getInstalledAt() !== null,
                $plugin->getActive()
            );
        }

        return $inventory;
    }

    public function install(SyncAction $action, bool $activate, bool $refresh, Context $context): void
    {
        $this->prepare($action, $refresh, $context);
        $this->lifecycleRunner->run(SyncAction::INSTALL, $action->technicalName, $activate);
    }

    public function update(SyncAction $action, bool $activate, bool $refresh, Context $context): void
    {
        $this->prepare($action, $refresh, $context);
        $this->lifecycleRunner->run(SyncAction::UPDATE, $action->technicalName, $activate);
    }

    public function activate(SyncAction $action, Context $context): void
    {
        $plugin = $this->plugin($action->technicalName, $context);
        if (!$plugin->getActive()) {
            $this->lifecycle->activatePlugin($plugin, $context);
        }
    }

    public function prune(SyncAction $action, Context $context): void
    {
        $plugin = $this->plugin($action->technicalName, $context);
        $this->lifecycle->uninstallPlugin($plugin, $context, keepUserData: true);
        $this->ownership->remove($action->technicalName, $context);
    }

    private function prepare(SyncAction $action, bool $refresh, Context $context): void
    {
        if ($action->registryId === null) {
            throw ExtensionMeshException::extensionNotFound($action->technicalName);
        }

        $download = $this->catalog->download(
            $action->registryId,
            $action->technicalName,
            $this->shopwareVersion,
            \PHP_VERSION,
            $context,
            $refresh
        );
        $this->installer->prepare(
            $download['release'],
            $action->technicalName,
            $download['registryUrl'],
            $context,
            $download['accessToken'],
            $download['credentialOrigin']
        );
    }

    private function plugin(string $technicalName, Context $context): PluginEntity
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('name', $technicalName))
            ->setLimit(1);
        $plugin = $this->plugins->search($criteria, $context)->first();
        if (!$plugin instanceof PluginEntity) {
            throw ExtensionMeshException::artifactRejected(
                \sprintf('Shopware did not discover plugin "%s" after preparation.', $technicalName)
            );
        }

        return $plugin;
    }
}
