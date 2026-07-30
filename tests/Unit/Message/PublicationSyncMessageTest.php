<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Message;

use ExtensionMesh\Shopware\Message\PublicationSyncMessage;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

final class PublicationSyncMessageTest extends TestCase
{
    public function testPublicationSynchronizationRunsAsynchronously(): void
    {
        self::assertInstanceOf(AsyncMessageInterface::class, new PublicationSyncMessage());
    }
}
