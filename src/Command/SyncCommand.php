<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Command;

use ExtensionMesh\Shopware\Service\Sync\SyncAction;
use ExtensionMesh\Shopware\Service\Sync\SyncActionResult;
use ExtensionMesh\Shopware\Service\Sync\SyncOptions;
use ExtensionMesh\Shopware\Service\Sync\SyncResult;
use ExtensionMesh\Shopware\Service\Sync\SyncRunner;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'extension-mesh:sync',
    description: 'Plan and apply ExtensionMesh-managed plugin changes'
)]
final class SyncCommand extends Command
{
    public function __construct(private readonly SyncRunner $sync)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('install', null, InputOption::VALUE_NONE, 'Install missing managed extensions')
            ->addOption('update', null, InputOption::VALUE_NONE, 'Update managed extensions')
            ->addOption('activate', null, InputOption::VALUE_NONE, 'Activate installed managed extensions')
            ->addOption('prune', null, InputOption::VALUE_NONE, 'Uninstall no-longer-entitled managed extensions')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Plan changes without making mutations')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Write only machine-readable JSON to stdout')
            ->addOption('no-refresh', null, InputOption::VALUE_NONE, 'Use the cached catalog without refreshing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = (bool) $input->getOption('json');
        try {
            $result = $this->sync->synchronize(new SyncOptions(
                install: (bool) $input->getOption('install'),
                update: (bool) $input->getOption('update'),
                activate: (bool) $input->getOption('activate'),
                prune: (bool) $input->getOption('prune'),
                dryRun: (bool) $input->getOption('dry-run'),
                refresh: !(bool) $input->getOption('no-refresh')
            ), Context::createCLIContext());

            if ($json) {
                $this->writeJson($output, $result);
            } else {
                $this->writeHuman($output, $result);
            }

            return $result->isSuccessful() ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $exception) {
            $result = new SyncResult([], [$exception->getMessage()]);
            if ($json) {
                $this->writeJson($output, $result);
            } else {
                $output->writeln('<error>ExtensionMesh sync failed: ' . $exception->getMessage() . '</error>');
            }

            return self::FAILURE;
        }
    }

    private function writeJson(OutputInterface $output, SyncResult $result): void
    {
        $output->writeln((string) \json_encode(
            $result->toArray(),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES
        ));
    }

    private function writeHuman(OutputInterface $output, SyncResult $result): void
    {
        $output->writeln('<info>ExtensionMesh sync</info>');
        $output->writeln('');

        foreach ($result->actions as $resultAction) {
            $action = $resultAction->action;
            $version = $action->installedVersion ?? '-';
            if ($action->availableVersion !== null && $action->availableVersion !== $action->installedVersion) {
                $version .= ' -> ' . $action->availableVersion;
            }
            $output->writeln(\sprintf(
                '%s %-32s %-24s %s%s',
                $this->symbol($action->action),
                $action->technicalName,
                $version,
                $action->action,
                $this->outcome($resultAction)
            ));
        }

        if ($result->actions === []) {
            $output->writeln('No ExtensionMesh extensions found.');
        }

        foreach ($result->errors as $error) {
            $output->writeln('<error>' . $error . '</error>');
        }

        $summary = $result->summary();
        $output->writeln('');
        $output->writeln(\sprintf(
            '%d install, %d update, %d activate, %d current, %d prune, %d failed',
            $summary[SyncAction::INSTALL],
            $summary[SyncAction::UPDATE],
            $summary[SyncAction::ACTIVATE],
            $summary[SyncAction::CURRENT],
            $summary[SyncAction::PRUNE],
            $summary['failed']
        ));
    }

    private function symbol(string $action): string
    {
        return match ($action) {
            SyncAction::INSTALL => '+',
            SyncAction::UPDATE => '↑',
            SyncAction::CURRENT => '=',
            SyncAction::ACTIVATE => '!',
            SyncAction::PRUNE => '-',
            SyncAction::UNMANAGED => '~',
            default => '?',
        };
    }

    private function outcome(SyncActionResult $result): string
    {
        if ($result->error !== null) {
            return ': ' . $result->error;
        }
        if (\in_array($result->status, ['pending', 'current', 'skipped'], true)) {
            return '';
        }

        return ' [' . $result->status . ']';
    }
}
