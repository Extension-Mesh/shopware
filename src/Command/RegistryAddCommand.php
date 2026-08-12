<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Command;

use ExtensionMesh\Shopware\Service\CatalogManager;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'extension-mesh:registry:add',
    description: 'Add an ExtensionMesh registry source non-interactively'
)]
final class RegistryAddCommand extends Command
{
    public function __construct(private readonly CatalogManager $catalog)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('url', InputArgument::REQUIRED, 'Registry or supported repository URL')
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'Optional registry access token');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $url = $input->getArgument('url');
            $token = $input->getOption('token');
            if (!\is_string($url) || !\is_string($token) && $token !== null) {
                throw new \InvalidArgumentException('The registry URL or token has an invalid value.');
            }

            $result = $this->catalog->addSourceIdempotently($url, $token, Context::createCLIContext());
            if ($result['created']) {
                $output->writeln(\sprintf('<info>Registry added.</info> ID: %s', $result['id']));
            } elseif ($result['credentialUpdated']) {
                $output->writeln(\sprintf('<info>Registry already exists; credential updated.</info> ID: %s', $result['id']));
            } else {
                $output->writeln(\sprintf('<info>Registry already exists; no changes made.</info> ID: %s', $result['id']));
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $output->writeln('<error>Unable to add registry: ' . $exception->getMessage() . '</error>');

            return self::FAILURE;
        }
    }
}
