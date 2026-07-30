<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Message;

use ExtensionMesh\Shopware\Message\RepositoryProcessMessage;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

final class RepositoryProcessMessageTest extends TestCase
{
    public function testItIsAnAsyncSerializableStageMessage(): void
    {
        $message = new RepositoryProcessMessage(
            '0198f9074c68721fba0439d90800921c',
            RepositoryProcessMessage::STAGE_SYNCHRONIZE,
            15
        );

        self::assertInstanceOf(AsyncMessageInterface::class, $message);
        self::assertSame('0198f9074c68721fba0439d90800921c', $message->getConnectionId());
        self::assertSame(RepositoryProcessMessage::STAGE_SYNCHRONIZE, $message->getStage());
        self::assertSame(15, $message->getOffset());
    }
}
