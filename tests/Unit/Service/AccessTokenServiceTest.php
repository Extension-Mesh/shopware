<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Service;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Service\AccessTokenService;
use ExtensionMesh\Shopware\Service\AccessTokenStore;
use ExtensionMesh\Shopware\Service\CustomerProductAccessProvider;
use ExtensionMesh\Shopware\Service\CustomerProductAccessResolver;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\Uuid\Uuid;

final class AccessTokenServiceTest extends TestCase
{
    public function testItReusesTheStoresAtomicGetOrCreateOperation(): void
    {
        $customerId = Uuid::randomHex();
        $salesChannelId = Uuid::randomHex();
        $tokenId = Uuid::randomHex();
        $context = Context::createCLIContext();
        $store = $this->createMock(AccessTokenStore::class);
        $store->expects(self::once())
            ->method('getOrCreateActive')
            ->with($customerId, $salesChannelId, $context)
            ->willReturn(['id' => $tokenId, 'customerId' => $customerId, 'salesChannelId' => $salesChannelId]);
        $service = new AccessTokenService($store, $this->accessResolver([[Uuid::randomHex()]]), 'test-secret');

        $token = $service->getOrCreate($customerId, $salesChannelId, $context);

        self::assertIsString($token);
        self::assertStringStartsWith('em1.', $token);
    }

    public function testAuthenticationRevokesATokenWhenAllProductAccessWasLost(): void
    {
        $customerId = Uuid::randomHex();
        $salesChannelId = Uuid::randomHex();
        $tokenId = Uuid::randomHex();
        $context = Context::createCLIContext();
        $store = $this->createMock(AccessTokenStore::class);
        $storedToken = ['id' => $tokenId, 'customerId' => $customerId, 'salesChannelId' => $salesChannelId];
        $store->method('getOrCreateActive')->willReturn($storedToken);
        $store->expects(self::once())->method('activeById')->with($tokenId, $context)->willReturn($storedToken);
        $store->expects(self::once())->method('revokeForCustomer')->with($customerId, $salesChannelId, $context);
        $store->expects(self::never())->method('touch');
        $service = new AccessTokenService(
            $store,
            $this->accessResolver([[Uuid::randomHex()], []]),
            'test-secret'
        );
        $token = $service->getOrCreate($customerId, $salesChannelId, $context);

        $this->expectException(ExtensionMeshException::class);
        $this->expectExceptionMessage('no longer covers an active entitlement');

        $service->authenticate('Bearer ' . $token, $context);
    }

    /**
     * @param list<list<string>> $productIdsByCall
     */
    private function accessResolver(array $productIdsByCall): CustomerProductAccessResolver
    {
        $products = $this->createMock(EntityRepository::class);
        $provider = new class($productIdsByCall) implements CustomerProductAccessProvider {
            private int $call = 0;

            /** @param list<list<string>> $productIdsByCall */
            public function __construct(private readonly array $productIdsByCall)
            {
            }

            public function productIds(string $customerId, string $salesChannelId, Context $context): array
            {
                return $this->productIdsByCall[$this->call++] ?? [];
            }

            public function grants(
                string $customerId,
                string $productId,
                string $salesChannelId,
                Context $context
            ): bool {
                return false;
            }

            public function productFilter(string $customerId, string $salesChannelId): Filter
            {
                return new EqualsFilter('active', true);
            }
        };

        return new CustomerProductAccessResolver($products, [$provider]);
    }
}
