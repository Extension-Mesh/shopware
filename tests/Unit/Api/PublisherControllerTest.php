<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Tests\Unit\Api;

use ExtensionMesh\Shopware\Api\PublisherController;
use ExtensionMesh\Shopware\Message\PublicationSyncMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class PublisherControllerTest extends TestCase
{
    public function testSynchronizationIsQueuedInsteadOfExecutedInTheRequest(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(PublicationSyncMessage::class))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $response = (new PublisherController($messageBus))->synchronize();

        self::assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
    }
}
