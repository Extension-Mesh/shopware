<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use Shopware\Core\Framework\Context;

interface AccessTokenStore
{
    /** @return array{id: string, customerId: string, salesChannelId: string} */
    public function getOrCreateActive(string $customerId, string $salesChannelId, Context $context): array;

    /** @return array{id: string, customerId: string, salesChannelId: string} */
    public function rotateActive(string $customerId, string $salesChannelId, Context $context): array;

    /** @return array{id: string, customerId: string, salesChannelId: string}|null */
    public function activeById(string $id, Context $context): ?array;

    public function revokeForCustomer(string $customerId, string $salesChannelId, Context $context): void;

    public function touch(string $id, Context $context): void;
}
