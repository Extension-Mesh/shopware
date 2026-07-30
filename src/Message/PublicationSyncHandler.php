<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Message;

use ExtensionMesh\Shopware\Service\PublicationSynchronizer;
use Shopware\Core\Framework\Context;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PublicationSyncHandler
{
    public function __construct(private readonly PublicationSynchronizer $synchronizer)
    {
    }

    public function __invoke(PublicationSyncMessage $message): void
    {
        $this->synchronizer->synchronize(Context::createCLIContext());
    }
}
