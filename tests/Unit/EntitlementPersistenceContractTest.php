<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit;

use ExtensionMesh\Shopware\Subscriber\OrderEntitlementSubscriber;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderStates;

final class EntitlementPersistenceContractTest extends TestCase
{
    public function testEntitlementsArePersistedWithoutDependingOnOrderState(): void
    {
        $root = __DIR__ . '/../../src';
        $repository = \file_get_contents(
            $root . '/Infrastructure/Persistence/EntitlementRepository.php'
        );
        $definition = \file_get_contents(
            $root . '/Core/Content/Entitlement/EntitlementDefinition.php'
        );
        $entity = \file_get_contents(
            $root . '/Core/Content/Entitlement/EntitlementEntity.php'
        );
        $collection = \file_get_contents(
            $root . '/Core/Content/Entitlement/EntitlementCollection.php'
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
        self::assertIsString($definition);
        self::assertIsString($entity);
        self::assertIsString($collection);
        self::assertIsString($migration);
        self::assertIsString($controller);
        self::assertIsString($subscriber);
        self::assertIsString($config);
        self::assertStringContainsString('EntityRepository', $repository);
        self::assertStringNotContainsString('Doctrine\\DBAL', $repository);
        self::assertStringContainsString('extends EntityDefinition', $definition);
        self::assertStringContainsString('EntitlementEntity::class', $definition);
        self::assertStringContainsString('EntitlementCollection::class', $definition);
        self::assertStringContainsString('ManyToOneAssociationField', $definition);
        self::assertStringContainsString('extends Entity', $entity);
        self::assertStringContainsString('extends EntityCollection', $collection);
        self::assertStringContainsString('`order_id` BINARY(16) NULL', $migration);
        self::assertStringContainsString('`enabled` TINYINT(1) NOT NULL', $migration);
        self::assertStringContainsString('`valid_until` DATETIME(3) NULL', $migration);
        self::assertStringContainsString("new EqualsFilter('validUntil', null)", $repository);
        self::assertStringContainsString("new RangeFilter('validUntil'", $repository);
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
        self::assertSame(1, \substr_count($controller, 'methods: [Request::METHOD_GET]'));
        self::assertStringNotContainsString('Request::METHOD_POST', $controller);
        self::assertStringNotContainsString('Request::METHOD_PUT', $controller);
        self::assertStringNotContainsString('Request::METHOD_DELETE', $controller);
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
        $repository = \file_get_contents(
            __DIR__ . '/../../src/Infrastructure/Persistence/EntitlementRepository.php'
        );
        self::assertIsString($repository);
        self::assertStringContainsString("['id' => \$id, 'enabled' => false]", $repository);
        self::assertStringNotContainsString('->delete(', $repository);
    }
}
