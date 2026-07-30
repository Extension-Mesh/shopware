<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

final class RepositorySyncTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'extension_mesh.repository_sync';
    }

    public static function getDefaultInterval(): int
    {
        return 15 * 60;
    }

    public static function shouldRescheduleOnFailure(): bool
    {
        return true;
    }
}
