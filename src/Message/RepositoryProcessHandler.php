<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Message;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Service\RepositoryOnboardingService;
use ExtensionMesh\Shopware\Service\RepositorySynchronizer;
use Shopware\Core\Framework\Context;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class RepositoryProcessHandler
{
    public function __construct(
        private readonly RepositoryOnboardingService $onboarding,
        private readonly RepositorySynchronizer $synchronizer,
        private readonly MessageBusInterface $messageBus
    ) {
    }

    public function __invoke(RepositoryProcessMessage $message): void
    {
        $id = $message->getConnectionId();
        $context = Context::createCLIContext();
        if (!$this->onboarding->hasConnection($id, $context)) {
            return;
        }

        try {
            if ($message->getStage() === RepositoryProcessMessage::STAGE_INSPECT) {
                if ($this->onboarding->inspectQueued($id, $context)) {
                    $this->dispatch($id, RepositoryProcessMessage::STAGE_PREPARE);
                }

                return;
            }
            if ($message->getStage() === RepositoryProcessMessage::STAGE_PREPARE) {
                $result = $this->onboarding->prepareQueued(
                    $id,
                    $context,
                    $message->getOffset()
                );
                if ($result['prepared']) {
                    $this->dispatch($id, RepositoryProcessMessage::STAGE_SYNCHRONIZE);
                } elseif (\is_int($result['nextOffset'])) {
                    $this->dispatch(
                        $id,
                        RepositoryProcessMessage::STAGE_PREPARE,
                        $result['nextOffset']
                    );
                }

                return;
            }
            if ($message->getStage() !== RepositoryProcessMessage::STAGE_SYNCHRONIZE) {
                throw ExtensionMeshException::invalidRepository('the repository queue stage is invalid.');
            }

            $this->onboarding->markSynchronizing($id, $context);
            $result = $this->synchronizer->synchronizeBatch(
                $id,
                $context,
                $message->getOffset()
            );
            if (!$result['finished'] && \is_int($result['nextOffset'])) {
                $this->dispatch(
                    $id,
                    RepositoryProcessMessage::STAGE_SYNCHRONIZE,
                    $result['nextOffset']
                );
            } elseif ($result['finished']) {
                $this->messageBus->dispatch(new PublicationSyncMessage());
            }
        } catch (\Throwable $exception) {
            $this->onboarding->markFailed($id, $exception->getMessage(), $context);
            if ($exception instanceof ExtensionMeshException) {
                throw new UnrecoverableMessageHandlingException(
                    $exception->getMessage(),
                    0,
                    $exception
                );
            }

            throw $exception;
        }
    }

    private function dispatch(string $id, string $stage, int $offset = 0): void
    {
        $this->messageBus->dispatch(new RepositoryProcessMessage($id, $stage, $offset));
    }
}
