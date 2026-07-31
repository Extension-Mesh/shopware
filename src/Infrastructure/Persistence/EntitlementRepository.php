<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class EntitlementRepository
{
    private const ORDER_VALIDITY_DAYS_CONFIG =
        'ExtensionMesh.config.orderEntitlementValidityDays';

    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $systemConfig
    ) {
    }

    /**
     * @return list<string>
     */
    public function entitledProductIds(string $customerId, ?string $salesChannelId = null): array
    {
        if (!Uuid::isValid($customerId)) {
            return [];
        }

        $parameters = ['customerId' => Uuid::fromHexToBytes($customerId)];
        $salesChannelClause = '';
        if ($salesChannelId !== null) {
            if (!Uuid::isValid($salesChannelId)) {
                return [];
            }

            $parameters['salesChannelId'] = Uuid::fromHexToBytes($salesChannelId);
            $salesChannelClause = ' AND sales_channel_id = :salesChannelId';
        }

        return \array_map(
            static fn (mixed $id): string => Uuid::fromBytesToHex((string) $id),
            $this->connection->fetchFirstColumn(
                'SELECT DISTINCT product_id
                   FROM extension_mesh_entitlement
                  WHERE customer_id = :customerId
                    AND enabled = 1
                    AND (
                        valid_until IS NULL
                        OR valid_until > UTC_TIMESTAMP(3)
                    )' . $salesChannelClause,
                $parameters
            )
        );
    }

    public function isEntitled(string $customerId, string $productId, ?string $salesChannelId = null): bool
    {
        if (!Uuid::isValid($customerId) || !Uuid::isValid($productId)) {
            return false;
        }
        if ($salesChannelId !== null && !Uuid::isValid($salesChannelId)) {
            return false;
        }

        $parameters = [
            'customerId' => Uuid::fromHexToBytes($customerId),
            'productId' => Uuid::fromHexToBytes($productId),
        ];
        $salesChannelClause = '';
        if ($salesChannelId !== null) {
            $parameters['salesChannelId'] = Uuid::fromHexToBytes($salesChannelId);
            $salesChannelClause = ' AND sales_channel_id = :salesChannelId';
        }

        return (bool) $this->connection->fetchOne(
            'SELECT 1
               FROM extension_mesh_entitlement
              WHERE customer_id = :customerId
                AND product_id = :productId
                AND enabled = 1
                AND (
                    valid_until IS NULL
                    OR valid_until > UTC_TIMESTAMP(3)
                )' . $salesChannelClause . '
              LIMIT 1',
            $parameters
        );
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     limit: int
     * }
     */
    public function paginate(int $page, int $limit): array
    {
        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM extension_mesh_entitlement'
        );
        $page = \min($page, \max(1, (int) \ceil($total / $limit)));
        $rows = $this->connection->fetchAllAssociative(
            'SELECT entitlement.*,
                    customer.customer_number,
                    customer.first_name,
                    customer.last_name,
                    customer.email,
                    product.product_number,
                    sales_channel_translation.name AS sales_channel_name,
                    linked_order.order_number
               FROM extension_mesh_entitlement entitlement
               INNER JOIN customer
                 ON customer.id = entitlement.customer_id
               INNER JOIN product
                 ON product.id = entitlement.product_id
                AND product.version_id = entitlement.product_version_id
               INNER JOIN sales_channel
                 ON sales_channel.id = entitlement.sales_channel_id
               LEFT JOIN sales_channel_translation
                 ON sales_channel_translation.sales_channel_id = sales_channel.id
                AND sales_channel_translation.language_id = :systemLanguageId
               LEFT JOIN `order` linked_order
                 ON linked_order.id = entitlement.order_id
                AND linked_order.version_id = entitlement.order_version_id
              ORDER BY entitlement.created_at DESC, entitlement.id
              LIMIT :limit OFFSET :offset',
            [
                'limit' => $limit,
                'offset' => ($page - 1) * $limit,
                'systemLanguageId' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
            ],
            [
                'limit' => ParameterType::INTEGER,
                'offset' => ParameterType::INTEGER,
            ]
        );

        return [
            'items' => \array_map($this->hydrate(...), $rows),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function create(
        string $customerId,
        string $productId,
        string $salesChannelId,
        ?string $orderId,
        bool $enabled,
        ?\DateTimeImmutable $validUntil
    ): array {
        $this->assertReferences($customerId, $productId, $salesChannelId, $orderId);

        $id = Uuid::randomHex();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
        $this->connection->insert('extension_mesh_entitlement', [
            'id' => Uuid::fromHexToBytes($id),
            'customer_id' => Uuid::fromHexToBytes($customerId),
            'product_id' => Uuid::fromHexToBytes($productId),
            'product_version_id' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            'sales_channel_id' => Uuid::fromHexToBytes($salesChannelId),
            'order_id' => $orderId === null ? null : Uuid::fromHexToBytes($orderId),
            'order_version_id' => $orderId === null
                ? null
                : Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            'enabled' => $enabled ? 1 : 0,
            'valid_until' => $validUntil === null
                ? null
                : $this->databaseDate($validUntil),
            'created_at' => $now,
        ]);

        return $this->get($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function update(
        string $id,
        string $customerId,
        string $productId,
        string $salesChannelId,
        ?string $orderId,
        bool $enabled,
        ?\DateTimeImmutable $validUntil
    ): array {
        if (!Uuid::isValid($id)) {
            throw ExtensionMeshException::entitlementNotFound($id);
        }
        $this->assertReferences($customerId, $productId, $salesChannelId, $orderId);

        $affected = $this->connection->update('extension_mesh_entitlement', [
            'customer_id' => Uuid::fromHexToBytes($customerId),
            'product_id' => Uuid::fromHexToBytes($productId),
            'product_version_id' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            'sales_channel_id' => Uuid::fromHexToBytes($salesChannelId),
            'order_id' => $orderId === null ? null : Uuid::fromHexToBytes($orderId),
            'order_version_id' => $orderId === null
                ? null
                : Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            'enabled' => $enabled ? 1 : 0,
            'valid_until' => $validUntil === null
                ? null
                : $this->databaseDate($validUntil),
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ], [
            'id' => Uuid::fromHexToBytes($id),
        ]);

        if ($affected === 0 && !$this->exists($id)) {
            throw ExtensionMeshException::entitlementNotFound($id);
        }

        return $this->get($id);
    }

    public function delete(string $id): void
    {
        if (!Uuid::isValid($id)) {
            throw ExtensionMeshException::entitlementNotFound($id);
        }

        $affected = $this->connection->delete(
            'extension_mesh_entitlement',
            ['id' => Uuid::fromHexToBytes($id)]
        );
        if ($affected === 0) {
            throw ExtensionMeshException::entitlementNotFound($id);
        }
    }

    public function issueForOrder(string $orderId): int
    {
        if (!Uuid::isValid($orderId)) {
            return 0;
        }

        $liveVersion = Uuid::fromHexToBytes(Defaults::LIVE_VERSION);
        $orderIdBytes = Uuid::fromHexToBytes($orderId);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT linked_order.sales_channel_id,
                    order_customer.customer_id,
                    order_line_item.product_id,
                    SUM(order_line_item.quantity) AS quantity
               FROM `order` linked_order
               INNER JOIN order_customer
                 ON order_customer.order_id = linked_order.id
                AND order_customer.order_version_id = linked_order.version_id
               INNER JOIN order_line_item
                 ON order_line_item.order_id = linked_order.id
                AND order_line_item.order_version_id = linked_order.version_id
              WHERE linked_order.id = :orderId
                AND linked_order.version_id = :liveVersion
                AND order_line_item.product_id IS NOT NULL
                AND (
                    EXISTS (
                        SELECT 1
                          FROM extension_mesh_repository_connection connection
                         WHERE connection.product_id = order_line_item.product_id
                           AND connection.enabled = 1
                    )
                    OR EXISTS (
                        SELECT 1
                          FROM extension_mesh_product integrated
                         WHERE integrated.product_id = order_line_item.product_id
                           AND integrated.enabled = 1
                    )
                )
              GROUP BY linked_order.sales_channel_id,
                       order_customer.customer_id,
                       order_line_item.product_id',
            [
                'orderId' => $orderIdBytes,
                'liveVersion' => $liveVersion,
            ]
        );
        if ($rows === []) {
            return 0;
        }

        $existingRows = $this->connection->fetchAllAssociative(
            'SELECT product_id, COUNT(*) AS quantity
               FROM extension_mesh_entitlement
              WHERE order_id = :orderId
                AND order_version_id = :liveVersion
              GROUP BY product_id',
            [
                'orderId' => $orderIdBytes,
                'liveVersion' => $liveVersion,
            ]
        );
        $existingByProduct = [];
        foreach ($existingRows as $existingRow) {
            $existingByProduct[Uuid::fromBytesToHex((string) $existingRow['product_id'])]
                = (int) $existingRow['quantity'];
        }

        $created = 0;
        $nowDate = new \DateTimeImmutable();
        $now = $this->databaseDate($nowDate);
        foreach ($rows as $row) {
            $productId = Uuid::fromBytesToHex((string) $row['product_id']);
            $salesChannelId = Uuid::fromBytesToHex((string) $row['sales_channel_id']);
            $validityDays = $this->orderValidityDays($salesChannelId);
            $validUntil = $validityDays === null
                ? null
                : $this->databaseDate(
                    $nowDate->modify(\sprintf('+%d days', $validityDays))
                );
            $missing = \max(0, (int) $row['quantity'] - ($existingByProduct[$productId] ?? 0));
            for ($index = 0; $index < $missing; ++$index) {
                $this->connection->insert('extension_mesh_entitlement', [
                    'id' => Uuid::fromHexToBytes(Uuid::randomHex()),
                    'customer_id' => $row['customer_id'],
                    'product_id' => $row['product_id'],
                    'product_version_id' => $liveVersion,
                    'sales_channel_id' => $row['sales_channel_id'],
                    'order_id' => $orderIdBytes,
                    'order_version_id' => $liveVersion,
                    'enabled' => 1,
                    'valid_until' => $validUntil,
                    'created_at' => $now,
                ]);
                ++$created;
            }
        }

        return $created;
    }

    public function disableForOrder(string $orderId): void
    {
        if (!Uuid::isValid($orderId)) {
            return;
        }

        $this->connection->update('extension_mesh_entitlement', [
            'enabled' => 0,
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ], [
            'order_id' => Uuid::fromHexToBytes($orderId),
            'order_version_id' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
        ]);
    }

    /**
     * @param list<string> $productIds
     *
     * @return list<string>
     */
    public function existingProductIds(array $productIds): array
    {
        $binaryIds = [];
        foreach ($productIds as $productId) {
            if (Uuid::isValid($productId)) {
                $binaryIds[] = Uuid::fromHexToBytes($productId);
            }
        }
        if ($binaryIds === []) {
            return [];
        }

        return \array_map(
            static fn (mixed $id): string => Uuid::fromBytesToHex((string) $id),
            $this->connection->fetchFirstColumn(
                'SELECT id FROM product WHERE id IN (:ids) AND version_id = :liveVersion',
                [
                    'ids' => $binaryIds,
                    'liveVersion' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                ],
                ['ids' => ArrayParameterType::BINARY]
            )
        );
    }

    /**
     * @return list<string>
     */
    public function eligibleProductIds(): array
    {
        return \array_map(
            static fn (mixed $id): string => Uuid::fromBytesToHex((string) $id),
            $this->connection->fetchFirstColumn(
                'SELECT product_id
                   FROM extension_mesh_product
                  WHERE enabled = 1
                  UNION
                 SELECT product_id
                   FROM extension_mesh_repository_connection
                  WHERE enabled = 1
                    AND product_id IS NOT NULL'
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        if (!Uuid::isValid($id)) {
            throw ExtensionMeshException::entitlementNotFound($id);
        }

        $row = $this->connection->fetchAssociative(
            'SELECT entitlement.*,
                    customer.customer_number,
                    customer.first_name,
                    customer.last_name,
                    customer.email,
                    product.product_number,
                    sales_channel_translation.name AS sales_channel_name,
                    linked_order.order_number
               FROM extension_mesh_entitlement entitlement
               INNER JOIN customer
                 ON customer.id = entitlement.customer_id
               INNER JOIN product
                 ON product.id = entitlement.product_id
                AND product.version_id = entitlement.product_version_id
               INNER JOIN sales_channel
                 ON sales_channel.id = entitlement.sales_channel_id
               LEFT JOIN sales_channel_translation
                 ON sales_channel_translation.sales_channel_id = sales_channel.id
                AND sales_channel_translation.language_id = :systemLanguageId
               LEFT JOIN `order` linked_order
                 ON linked_order.id = entitlement.order_id
                AND linked_order.version_id = entitlement.order_version_id
              WHERE entitlement.id = :id',
            [
                'id' => Uuid::fromHexToBytes($id),
                'systemLanguageId' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
            ]
        );
        if ($row === false) {
            throw ExtensionMeshException::entitlementNotFound($id);
        }

        return $this->hydrate($row);
    }

    private function exists(string $id): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM extension_mesh_entitlement WHERE id = :id',
            ['id' => Uuid::fromHexToBytes($id)]
        );
    }

    private function assertReferences(
        string $customerId,
        string $productId,
        string $salesChannelId,
        ?string $orderId
    ): void {
        foreach ([
            'customer' => $customerId,
            'product' => $productId,
            'sales channel' => $salesChannelId,
        ] as $label => $id) {
            if (!Uuid::isValid($id)) {
                throw ExtensionMeshException::invalidEntitlement($label . ' ID is invalid.');
            }
        }
        if ($orderId !== null && !Uuid::isValid($orderId)) {
            throw ExtensionMeshException::invalidEntitlement('order ID is invalid.');
        }

        if (!$this->entityExists('customer', $customerId)) {
            throw ExtensionMeshException::invalidEntitlement('customer does not exist.');
        }
        if (!$this->entityExists('sales_channel', $salesChannelId)) {
            throw ExtensionMeshException::invalidEntitlement('sales channel does not exist.');
        }
        if (!(bool) $this->connection->fetchOne(
            'SELECT 1
               FROM product
              WHERE id = :id
                AND version_id = :liveVersion
                AND (
                    EXISTS (
                        SELECT 1
                          FROM extension_mesh_product integrated
                         WHERE integrated.product_id = product.id
                           AND integrated.enabled = 1
                    )
                    OR EXISTS (
                        SELECT 1
                          FROM extension_mesh_repository_connection connection
                         WHERE connection.product_id = product.id
                           AND connection.enabled = 1
                    )
                )',
            [
                'id' => Uuid::fromHexToBytes($productId),
                'liveVersion' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            ]
        )) {
            throw ExtensionMeshException::invalidEntitlement(
                'product is not connected to Extension Mesh.'
            );
        }

        if ($orderId === null) {
            return;
        }

        $order = $this->connection->fetchAssociative(
            'SELECT linked_order.sales_channel_id, order_customer.customer_id
               FROM `order` linked_order
               INNER JOIN order_customer
                 ON order_customer.order_id = linked_order.id
                AND order_customer.order_version_id = linked_order.version_id
              WHERE linked_order.id = :id
                AND linked_order.version_id = :liveVersion',
            [
                'id' => Uuid::fromHexToBytes($orderId),
                'liveVersion' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            ]
        );
        if ($order === false) {
            throw ExtensionMeshException::invalidEntitlement('order does not exist.');
        }
        if (
            !\hash_equals(
                Uuid::fromHexToBytes($customerId),
                (string) $order['customer_id']
            )
        ) {
            throw ExtensionMeshException::invalidEntitlement(
                'the linked order belongs to another customer.'
            );
        }
        if (
            !\hash_equals(
                Uuid::fromHexToBytes($salesChannelId),
                (string) $order['sales_channel_id']
            )
        ) {
            throw ExtensionMeshException::invalidEntitlement(
                'the linked order belongs to another sales channel.'
            );
        }
    }

    private function entityExists(string $table, string $id): bool
    {
        return (bool) $this->connection->fetchOne(
            \sprintf('SELECT 1 FROM `%s` WHERE id = :id', $table),
            ['id' => Uuid::fromHexToBytes($id)]
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $orderId = \is_string($row['order_id'] ?? null)
            ? Uuid::fromBytesToHex($row['order_id'])
            : null;
        $customerName = \trim(
            (string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')
        );

        return [
            'id' => Uuid::fromBytesToHex((string) $row['id']),
            'customerId' => Uuid::fromBytesToHex((string) $row['customer_id']),
            'customerNumber' => (string) ($row['customer_number'] ?? ''),
            'customerName' => $customerName,
            'customerEmail' => (string) ($row['email'] ?? ''),
            'productId' => Uuid::fromBytesToHex((string) $row['product_id']),
            'productNumber' => (string) ($row['product_number'] ?? ''),
            'salesChannelId' => Uuid::fromBytesToHex((string) $row['sales_channel_id']),
            'salesChannelName' => (string) ($row['sales_channel_name'] ?? ''),
            'orderId' => $orderId,
            'orderNumber' => \is_string($row['order_number'] ?? null) ? $row['order_number'] : null,
            'enabled' => (bool) $row['enabled'],
            'validUntil' => \is_string($row['valid_until'] ?? null)
                ? $this->apiDate($row['valid_until'])
                : null,
            'expired' => \is_string($row['valid_until'] ?? null)
                && new \DateTimeImmutable(
                    $row['valid_until'],
                    new \DateTimeZone('UTC')
                ) <= new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => \is_string($row['updated_at'] ?? null) ? $row['updated_at'] : null,
        ];
    }

    private function orderValidityDays(string $salesChannelId): ?int
    {
        $configured = $this->systemConfig->getInt(
            self::ORDER_VALIDITY_DAYS_CONFIG,
            $salesChannelId
        );

        return $configured > 0 ? $configured : null;
    }

    private function databaseDate(\DateTimeImmutable $date): string
    {
        return $date
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.v');
    }

    private function apiDate(string $date): string
    {
        return (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))
            ->format(\DATE_ATOM);
    }
}
