<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit;

use Doctrine\DBAL\Connection;
use ExtensionMesh\Shopware\Infrastructure\Persistence\EntitlementRepository;
use ExtensionMesh\Shopware\Subscriber\OrderEntitlementSubscriber;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class EntitlementPersistenceContractTest extends TestCase
{
    public function testEntitlementsArePersistedWithoutDependingOnOrderState(): void
    {
        $root = __DIR__ . '/../../src';
        $repository = \file_get_contents(
            $root . '/Infrastructure/Persistence/EntitlementRepository.php'
        );
        $migration = \file_get_contents(
            $root . '/Migration/Migration1785257000Entitlements.php'
        );
        $controller = \file_get_contents(
            $root . '/Api/EntitlementController.php'
        );
        $subscriber = \file_get_contents(
            $root . '/Subscriber/OrderEntitlementSubscriber.php'
        );
        $config = \file_get_contents($root . '/Resources/config/config.xml');

        self::assertIsString($repository);
        self::assertIsString($migration);
        self::assertIsString($controller);
        self::assertIsString($subscriber);
        self::assertIsString($config);
        self::assertStringContainsString('FROM extension_mesh_entitlement', $repository);
        self::assertStringNotContainsString('order_line_item_download', $repository);
        self::assertStringNotContainsString('state_machine_state', $repository);
        self::assertStringContainsString('`order_id` BINARY(16) NULL', $migration);
        self::assertStringContainsString('`enabled` TINYINT(1) NOT NULL', $migration);
        self::assertStringContainsString('`valid_until` DATETIME(3) NULL', $migration);
        self::assertStringContainsString('valid_until IS NULL', $repository);
        self::assertStringContainsString('valid_until > UTC_TIMESTAMP(3)', $repository);
        self::assertStringContainsString('customer.customer_number LIKE :search', $repository);
        self::assertStringContainsString(
            "'customerId' => 'entitlement.customer_id'",
            $repository
        );
        self::assertStringContainsString(
            "'productId' => 'entitlement.product_id'",
            $repository
        );
        self::assertStringContainsString(
            "'salesChannelId' => 'entitlement.sales_channel_id'",
            $repository
        );
        self::assertStringContainsString('entitlement.order_id IS NULL', $repository);
        self::assertStringContainsString('entitlement.order_id IS NOT NULL', $repository);
        self::assertStringContainsString("'customerFirstName'", $repository);
        self::assertStringContainsString("'customerLastName'", $repository);
        self::assertStringContainsString("'orderLink'", $controller);
        self::assertStringContainsString(
            'ExtensionMesh.config.orderEntitlementValidityDays',
            $repository
        );
        self::assertStringContainsString(
            '<name>orderEntitlementValidityDays</name>',
            $config
        );
        self::assertStringNotContainsString('<defaultValue>', $config);
        self::assertStringNotContainsString(
            'UNIQUE KEY `uniq.extension_mesh_entitlement.grant`',
            $migration
        );
        self::assertStringContainsString('methods: [Request::METHOD_POST]', $controller);
        self::assertSame(3, \substr_count($controller, 'methods: [Request::METHOD_GET]'));
        self::assertStringContainsString('methods: [Request::METHOD_PUT]', $controller);
        self::assertStringContainsString('methods: [Request::METHOD_DELETE]', $controller);
        self::assertStringContainsString('STATE_PAID', $subscriber);
        self::assertStringContainsString('STATE_REFUNDED', $subscriber);
        self::assertStringContainsString('STATE_CANCELLED', $subscriber);
        self::assertStringContainsString('issueForOrder', $subscriber);
        self::assertStringContainsString('disableForOrder', $subscriber);
    }

    public function testFullRefundAndCancellationEventsDisableLinkedEntitlements(): void
    {
        $events = OrderEntitlementSubscriber::getSubscribedEvents();

        self::assertSame(
            'disableEntitlements',
            $events[
                'state_enter.' . OrderTransactionStates::STATE_MACHINE
                . '.' . OrderTransactionStates::STATE_REFUNDED
            ]
        );
        self::assertSame(
            'disableEntitlements',
            $events[
                'state_enter.' . OrderTransactionStates::STATE_MACHINE
                . '.' . OrderTransactionStates::STATE_CANCELLED
            ]
        );
        self::assertSame(
            'disableEntitlements',
            $events[
                'state_enter.' . OrderStates::STATE_MACHINE
                . '.' . OrderStates::STATE_CANCELLED
            ]
        );
        self::assertArrayNotHasKey(
            'state_enter.' . OrderTransactionStates::STATE_MACHINE
            . '.' . OrderTransactionStates::STATE_PARTIALLY_REFUNDED,
            $events
        );
    }

    public function testDisablingAnOrderKeepsItsEntitlementRowsForAudit(): void
    {
        $orderId = Uuid::randomHex();
        $connection = $this->createMock(Connection::class);
        $systemConfig = $this->createMock(SystemConfigService::class);
        $connection->expects(self::once())
            ->method('update')
            ->with(
                'extension_mesh_entitlement',
                self::callback(static fn (array $values): bool => $values['enabled'] === 0),
                self::callback(static fn (array $criteria): bool => (
                    $criteria['order_id'] === Uuid::fromHexToBytes($orderId)
                    && $criteria['order_version_id'] === Uuid::fromHexToBytes(Defaults::LIVE_VERSION)
                ))
            )
            ->willReturn(2);

        (new EntitlementRepository($connection, $systemConfig))->disableForOrder($orderId);
    }
}
