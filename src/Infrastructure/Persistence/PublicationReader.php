<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Infrastructure\Persistence;

use Shopware\Core\Framework\Context;

interface PublicationReader
{
    /**
     * @param list<string> $mediaIds
     *
     * @return array<string, array<string, mixed>>
     */
    public function byMediaIds(array $mediaIds, Context $context): array;
}
