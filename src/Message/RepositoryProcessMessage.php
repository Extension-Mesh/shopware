<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

final class RepositoryProcessMessage implements AsyncMessageInterface
{
    public const STAGE_INSPECT = 'inspect';
    public const STAGE_PREPARE = 'prepare';
    public const STAGE_SYNCHRONIZE = 'synchronize';

    public function __construct(
        private readonly string $connectionId,
        private readonly string $stage = self::STAGE_INSPECT,
        private readonly int $offset = 0
    ) {
    }

    public function getConnectionId(): string
    {
        return $this->connectionId;
    }

    public function getStage(): string
    {
        return $this->stage;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }
}
