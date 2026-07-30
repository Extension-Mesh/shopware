<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Infrastructure\Http;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Infrastructure\Http\NetworkPolicy;
use PHPUnit\Framework\TestCase;

final class NetworkPolicyTest extends TestCase
{
    public function testProductionRejectsPlainHttpBeforeConnecting(): void
    {
        $this->expectException(ExtensionMeshException::class);

        (new NetworkPolicy(false))->resolveAllowedIp('http://example.com/registry.json');
    }

    public function testProductionRejectsPrivateLiteralAddresses(): void
    {
        $this->expectException(ExtensionMeshException::class);

        (new NetworkPolicy(false))->resolveAllowedIp('https://127.0.0.1/registry.json');
    }

    public function testDevelopmentMayResolvePrivateLiteralAddressesOverHttp(): void
    {
        self::assertSame(
            '127.0.0.1',
            (new NetworkPolicy(true))->resolveAllowedIp('http://127.0.0.1/registry.json')
        );
    }
}

