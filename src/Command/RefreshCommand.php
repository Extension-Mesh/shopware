<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Command;

use ExtensionMesh\Shopware\Service\CatalogManager;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'extension-mesh:refresh',
    description: 'Refresh all configured ExtensionMesh registries'
)]
final class RefreshCommand extends Command
{
    public function __construct(private readonly CatalogManager $catalog)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $results = $this->catalog->refreshAll(Context::createCLIContext());
            if ($results === []) {
                $output->writeln('<comment>No enabled registries are configured.</comment>');

                return self::SUCCESS;
            }

            $failed = false;
            foreach ($results as $result) {
                $name = $result['label'] ?? $result['url'];
                if ($result['success']) {
                    $output->writeln(\sprintf('<info>OK</info> %s (%s)', $name, $result['url']));
                    continue;
                }

                $failed = true;
                $output->writeln(\sprintf(
                    '<error>FAILED</error> %s (%s): %s',
                    $name,
                    $result['url'],
                    $result['error'] ?? 'unknown error'
                ));
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $exception) {
            $output->writeln('<error>Unable to refresh registries: ' . $exception->getMessage() . '</error>');

            return self::FAILURE;
        }
    }
}
