<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Command;

use ExtensionMesh\Shopware\Service\Sync\NativePluginLifecycle;
use ExtensionMesh\Shopware\Service\Sync\SyncAction;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'extension-mesh:sync:lifecycle',
    description: 'Internal fresh-process lifecycle worker',
    hidden: true
)]
final class SyncLifecycleCommand extends Command
{
    public function __construct(private readonly NativePluginLifecycle $lifecycle)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'install or update')
            ->addArgument('technical-name', InputArgument::REQUIRED, 'Managed plugin technical name')
            ->addOption('activate', null, InputOption::VALUE_NONE, 'Activate the plugin after lifecycle completion');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $action = $input->getArgument('action');
            $technicalName = $input->getArgument('technical-name');
            if (!\is_string($action) || !\in_array($action, [SyncAction::INSTALL, SyncAction::UPDATE], true)) {
                throw new \InvalidArgumentException('Lifecycle action must be install or update.');
            }
            if (!\is_string($technicalName) || !\preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $technicalName)) {
                throw new \InvalidArgumentException('The plugin technical name is invalid.');
            }

            $this->lifecycle->apply(
                $action,
                $technicalName,
                (bool) $input->getOption('activate'),
                Context::createCLIContext()
            );

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return self::FAILURE;
        }
    }
}
