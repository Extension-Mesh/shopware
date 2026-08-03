<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Service;

use ExtensionMesh\Shopware\Service\PublisherRequestLimiter;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\RateLimiter\RateLimiterFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;

final class PublisherRequestLimiterTest extends TestCase
{
    public function testItUsesSeparateLimitsAndDoesNotStoreTheBearerTokenAsAKey(): void
    {
        $request = Request::create('/extension-mesh/v1/registry');
        $request->headers->set('Authorization', 'Bearer sensitive-token');
        $request->server->set('REMOTE_ADDR', '203.0.113.10');
        $expectedKey = \hash('sha256', "Bearer sensitive-token\0" . '203.0.113.10');
        $registryFactory = $this->factory($expectedKey, true);
        $artifactFactory = $this->factory($expectedKey, false);
        $limiter = new PublisherRequestLimiter($registryFactory, $artifactFactory);

        self::assertNull($limiter->registry($request));
        self::assertInstanceOf(\DateTimeImmutable::class, $limiter->artifact($request));
    }

    private function factory(string $expectedKey, bool $accepted): RateLimiterFactory
    {
        $retryAfter = new \DateTimeImmutable('+1 minute');
        $limiter = $this->createMock(LimiterInterface::class);
        $limiter->expects(self::once())
            ->method('consume')
            ->willReturn(new RateLimit($accepted ? 1 : 0, $retryAfter, $accepted, 1));
        $factory = $this->createMock(RateLimiterFactory::class);
        $factory->expects(self::once())
            ->method('create')
            ->with($expectedKey)
            ->willReturn($limiter);

        return $factory;
    }
}
