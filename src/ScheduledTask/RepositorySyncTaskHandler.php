<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\ScheduledTask;

use ExtensionMesh\Shopware\Service\RepositoryOnboardingService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: RepositorySyncTask::class)]
final class RepositorySyncTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $logger,
        private readonly RepositoryOnboardingService $onboarding
    ) {
        parent::__construct($scheduledTaskRepository, $logger);
    }

    public function run(): void
    {
        $context = Context::createCLIContext();
        foreach ($this->onboarding->readyConnectionIds($context) as $id) {
            $this->onboarding->queueSynchronization($id, $context);
        }
    }
}
