<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use ExtensionMesh\Shopware\Core\Content\AccessToken\AccessTokenCollection;
use ExtensionMesh\Shopware\Core\Content\AccessToken\AccessTokenEntity;
use ExtensionMesh\Shopware\Infrastructure\Persistence\AccessTokenRepository;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;

final class AccessTokenRepositoryTest extends TestCase
{
    public function testTouchExtendsTheRollingInactivityWindowByNinetyDays(): void
    {
        $context = Context::createCLIContext();
        $token = $this->token();
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())
            ->method('search')
            ->willReturn($this->searchResult(new AccessTokenCollection([$token]), $context));
        $before = new \DateTimeImmutable('+89 days');
        $after = new \DateTimeImmutable('+91 days');
        $repository->expects(self::once())
            ->method('update')
            ->with(self::callback(static function (array $writes) use ($token, $before, $after): bool {
                $write = $writes[0] ?? null;

                return \is_array($write)
                    && $write['id'] === $token->getId()
                    && $write['lastUsedAt'] instanceof \DateTimeImmutable
                    && $write['expiresAt'] instanceof \DateTimeImmutable
                    && $write['expiresAt'] > $before
                    && $write['expiresAt'] < $after;
            }), $context)
            ->willReturn(EntityWrittenContainerEvent::createWithWrittenEvents([], $context, []));

        (new AccessTokenRepository($repository, $this->createMock(Connection::class)))
            ->touch($token->getId(), $context);
    }

    public function testTouchIsWriteThrottledForRecentlyUsedTokens(): void
    {
        $context = Context::createCLIContext();
        $token = $this->token();
        $token->setLastUsedAt(new \DateTimeImmutable('-30 minutes'));
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())
            ->method('search')
            ->willReturn($this->searchResult(new AccessTokenCollection([$token]), $context));
        $repository->expects(self::never())->method('update');

        (new AccessTokenRepository($repository, $this->createMock(Connection::class)))
            ->touch($token->getId(), $context);
    }

    private function token(): AccessTokenEntity
    {
        $token = new AccessTokenEntity();
        $token->setId(Uuid::randomHex());
        $token->setCustomerId(Uuid::randomHex());
        $token->setSalesChannelId(Uuid::randomHex());

        return $token;
    }

    /** @return EntitySearchResult<AccessTokenCollection> */
    private function searchResult(AccessTokenCollection $tokens, Context $context): EntitySearchResult
    {
        return new EntitySearchResult(
            'extension_mesh_access_token',
            $tokens->count(),
            $tokens,
            new AggregationResultCollection(),
            new Criteria(),
            $context
        );
    }
}
