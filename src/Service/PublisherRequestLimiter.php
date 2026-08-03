<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use Shopware\Core\Framework\RateLimiter\RateLimiterFactory;
use Symfony\Component\HttpFoundation\Request;

final class PublisherRequestLimiter
{
    public function __construct(
        private readonly RateLimiterFactory $registryFactory,
        private readonly RateLimiterFactory $artifactFactory
    ) {
    }

    public function registry(Request $request): ?\DateTimeImmutable
    {
        return $this->consume($this->registryFactory, $request);
    }

    public function artifact(Request $request): ?\DateTimeImmutable
    {
        return $this->consume($this->artifactFactory, $request);
    }

    private function consume(RateLimiterFactory $factory, Request $request): ?\DateTimeImmutable
    {
        $authorization = (string) $request->headers->get('Authorization', '');
        $key = \hash('sha256', $authorization . "\0" . ($request->getClientIp() ?? 'unknown'));
        $limit = $factory->create($key)->consume();

        return $limit->isAccepted() ? null : $limit->getRetryAfter();
    }
}
