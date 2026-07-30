<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

final class EntitlementRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * A granted Shopware order download is the entitlement source of truth.
     *
     * @return list<string>
     */
    public function entitledProductIds(string $customerId, ?string $salesChannelId = null): array
    {
        if (!Uuid::isValid($customerId)) {
            return [];
        }

        $parameters = ['customerId' => Uuid::fromHexToBytes($customerId)];
        $salesChannelClause = '';
        if ($salesChannelId !== null && Uuid::isValid($salesChannelId)) {
            $parameters['salesChannelId'] = Uuid::fromHexToBytes($salesChannelId);
            $salesChannelClause = ' AND o.sales_channel_id = :salesChannelId';
        }

        $rows = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT oli.product_id
               FROM order_line_item_download olid
               INNER JOIN order_line_item oli
                 ON oli.id = olid.order_line_item_id
                AND oli.version_id = olid.order_line_item_version_id
               INNER JOIN `order` o
                 ON o.id = oli.order_id
                AND o.version_id = oli.order_version_id
               INNER JOIN order_transaction ot
                 ON ot.id = o.primary_order_transaction_id
                AND ot.version_id = o.primary_order_transaction_version_id
               INNER JOIN state_machine_state payment_state
                 ON payment_state.id = ot.state_id
               INNER JOIN order_customer oc
                 ON oc.order_id = o.id
                AND oc.order_version_id = o.version_id
              WHERE oc.customer_id = :customerId
                AND olid.access_granted = 1
                AND oli.product_id IS NOT NULL
                AND payment_state.technical_name IN (
                    \'authorized\',
                    \'paid\',
                    \'paid_partially\',
                    \'refunded_partially\'
                )' . $salesChannelClause,
            $parameters
        );

        return \array_map(
            static fn (mixed $id): string => Uuid::fromBytesToHex((string) $id),
            $rows
        );
    }

    public function isEntitled(string $customerId, string $productId, ?string $salesChannelId = null): bool
    {
        return \in_array($productId, $this->entitledProductIds($customerId, $salesChannelId), true);
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
                'SELECT id FROM product WHERE id IN (:ids)',
                ['ids' => $binaryIds],
                ['ids' => ArrayParameterType::BINARY]
            )
        );
    }
}
