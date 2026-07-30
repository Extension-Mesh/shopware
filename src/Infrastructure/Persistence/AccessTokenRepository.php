<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

final class AccessTokenRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{id: string, customerId: string, salesChannelId: string}|null
     */
    public function activeForCustomer(string $customerId, string $salesChannelId): ?array
    {
        if (!Uuid::isValid($customerId) || !Uuid::isValid($salesChannelId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT id, customer_id, sales_channel_id
               FROM extension_mesh_access_token
              WHERE customer_id = :customerId
                AND sales_channel_id = :salesChannelId
                AND revoked_at IS NULL
              ORDER BY created_at DESC
              LIMIT 1',
            [
                'customerId' => Uuid::fromHexToBytes($customerId),
                'salesChannelId' => Uuid::fromHexToBytes($salesChannelId),
            ]
        );

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return array{id: string, customerId: string, salesChannelId: string}|null
     */
    public function activeById(string $id): ?array
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT id, customer_id, sales_channel_id
               FROM extension_mesh_access_token
              WHERE id = :id AND revoked_at IS NULL',
            ['id' => Uuid::fromHexToBytes($id)]
        );

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return array{id: string, customerId: string, salesChannelId: string}
     */
    public function create(string $customerId, string $salesChannelId): array
    {
        $id = Uuid::randomHex();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
        $this->connection->insert('extension_mesh_access_token', [
            'id' => Uuid::fromHexToBytes($id),
            'customer_id' => Uuid::fromHexToBytes($customerId),
            'sales_channel_id' => Uuid::fromHexToBytes($salesChannelId),
            'created_at' => $now,
        ]);

        return ['id' => $id, 'customerId' => $customerId, 'salesChannelId' => $salesChannelId];
    }

    public function revokeForCustomer(string $customerId, string $salesChannelId): void
    {
        $this->connection->executeStatement(
            'UPDATE extension_mesh_access_token
                SET revoked_at = :now, updated_at = :now
              WHERE customer_id = :customerId
                AND sales_channel_id = :salesChannelId
                AND revoked_at IS NULL',
            [
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
                'customerId' => Uuid::fromHexToBytes($customerId),
                'salesChannelId' => Uuid::fromHexToBytes($salesChannelId),
            ]
        );
    }

    public function touch(string $id): void
    {
        if (!Uuid::isValid($id)) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE extension_mesh_access_token
                SET last_used_at = :now
              WHERE id = :id
                AND (last_used_at IS NULL OR last_used_at < :threshold)',
            [
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
                'threshold' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s.v'),
                'id' => Uuid::fromHexToBytes($id),
            ]
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id: string, customerId: string, salesChannelId: string}
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => Uuid::fromBytesToHex((string) $row['id']),
            'customerId' => Uuid::fromBytesToHex((string) $row['customer_id']),
            'salesChannelId' => Uuid::fromBytesToHex((string) $row['sales_channel_id']),
        ];
    }
}
