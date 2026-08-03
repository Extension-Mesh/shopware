<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ExtensionMesh\Shopware\Core\Content\AccessToken\AccessTokenEntity;
use ExtensionMesh\Shopware\Core\Content\AccessToken\AccessTokenCollection;
use ExtensionMesh\Shopware\Service\AccessTokenStore;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;

final class AccessTokenRepository implements AccessTokenStore
{
    private const TOKEN_INACTIVITY_WINDOW = 'P90D';

    public function __construct(
        /** @var EntityRepository<AccessTokenCollection> */
        private readonly EntityRepository $repository,
        private readonly Connection $connection
    ) {
    }

    /** @return array{id: string, customerId: string, salesChannelId: string}|null */
    public function activeForCustomer(string $customerId, string $salesChannelId, Context $context): ?array
    {
        if (!Uuid::isValid($customerId) || !Uuid::isValid($salesChannelId)) {
            return null;
        }

        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('customerId', $customerId))
            ->addFilter(new EqualsFilter('salesChannelId', $salesChannelId))
            ->addFilter(new EqualsFilter('revokedAt', null))
            ->addFilter(new EqualsFilter('activeSlot', true))
            ->addFilter(new RangeFilter('expiresAt', [RangeFilter::GT => $this->now()]))
            ->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING))
            ->setLimit(1);

        return $this->hydrate($this->repository->search($criteria, $context)->first());
    }

    /** @return array{id: string, customerId: string, salesChannelId: string}|null */
    public function activeById(string $id, Context $context): ?array
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $criteria = (new Criteria([$id]))
            ->addFilter(new EqualsFilter('revokedAt', null))
            ->addFilter(new EqualsFilter('activeSlot', true))
            ->addFilter(new RangeFilter('expiresAt', [RangeFilter::GT => $this->now()]));

        return $this->hydrate($this->repository->search($criteria, $context)->first());
    }

    /** @return array{id: string, customerId: string, salesChannelId: string} */
    public function create(string $customerId, string $salesChannelId, Context $context): array
    {
        $id = Uuid::randomHex();
        $this->repository->create([[
            'id' => $id,
            'customerId' => $customerId,
            'salesChannelId' => $salesChannelId,
            'expiresAt' => $this->nextExpiry(),
            'activeSlot' => true,
        ]], $context);

        return ['id' => $id, 'customerId' => $customerId, 'salesChannelId' => $salesChannelId];
    }

    public function getOrCreateActive(string $customerId, string $salesChannelId, Context $context): array
    {
        return $this->withCustomerLock(
            $customerId,
            function () use ($customerId, $salesChannelId, $context): array {
                $this->expireInactiveForCustomer($customerId, $salesChannelId, $context);

                return $this->activeForCustomer($customerId, $salesChannelId, $context)
                    ?? $this->create($customerId, $salesChannelId, $context);
            }
        );
    }

    public function rotateActive(string $customerId, string $salesChannelId, Context $context): array
    {
        return $this->withCustomerLock($customerId, function () use ($customerId, $salesChannelId, $context): array {
            $this->revokeForCustomer($customerId, $salesChannelId, $context);

            return $this->create($customerId, $salesChannelId, $context);
        });
    }

    public function revokeForCustomer(string $customerId, string $salesChannelId, Context $context): void
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('customerId', $customerId))
            ->addFilter(new EqualsFilter('salesChannelId', $salesChannelId))
            ->addFilter(new EqualsFilter('revokedAt', null));
        $ids = $this->repository->searchIds($criteria, $context)->getIds();
        if ($ids === []) {
            return;
        }

        $now = new \DateTimeImmutable();
        $this->repository->update(\array_map(
            static fn (string $id): array => ['id' => $id, 'revokedAt' => $now, 'activeSlot' => null],
            $ids
        ), $context);
    }

    public function touch(string $id, Context $context): void
    {
        if (!Uuid::isValid($id)) {
            return;
        }

        $entity = $this->repository->search(new Criteria([$id]), $context)->first();
        if (!$entity instanceof AccessTokenEntity) {
            return;
        }
        $lastUsedAt = $entity->getLastUsedAt();
        if ($lastUsedAt instanceof \DateTimeInterface && $lastUsedAt > new \DateTimeImmutable('-1 hour')) {
            return;
        }

        $this->repository->update([[
            'id' => $id,
            'lastUsedAt' => new \DateTimeImmutable(),
            'expiresAt' => $this->nextExpiry(),
        ]], $context);
    }

    /** @return array{id: string, customerId: string, salesChannelId: string}|null */
    private function hydrate(mixed $entity): ?array
    {
        if (!$entity instanceof AccessTokenEntity) {
            return null;
        }

        return [
            'id' => $entity->getId(),
            'customerId' => $entity->getCustomerId(),
            'salesChannelId' => $entity->getSalesChannelId(),
        ];
    }

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private function withCustomerLock(string $customerId, \Closure $operation): mixed
    {
        if (!Uuid::isValid($customerId)) {
            throw new \InvalidArgumentException('A valid customer ID is required.');
        }

        return $this->connection->transactional(function () use ($customerId, $operation): mixed {
            $this->connection->executeQuery(
                'SELECT `id` FROM `customer` WHERE `id` = :customerId FOR UPDATE',
                ['customerId' => Uuid::fromHexToBytes($customerId)],
                ['customerId' => ParameterType::BINARY]
            )->fetchOne();

            return $operation();
        });
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }

    private function nextExpiry(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->add(new \DateInterval(self::TOKEN_INACTIVITY_WINDOW));
    }

    private function expireInactiveForCustomer(
        string $customerId,
        string $salesChannelId,
        Context $context
    ): void {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('customerId', $customerId))
            ->addFilter(new EqualsFilter('salesChannelId', $salesChannelId))
            ->addFilter(new EqualsFilter('revokedAt', null))
            ->addFilter(new EqualsFilter('activeSlot', true))
            ->addFilter(new RangeFilter('expiresAt', [RangeFilter::LTE => $this->now()]));
        $ids = $this->repository->searchIds($criteria, $context)->getIds();
        if ($ids === []) {
            return;
        }

        $now = new \DateTimeImmutable();
        $this->repository->update(\array_map(
            static fn (string $id): array => ['id' => $id, 'revokedAt' => $now, 'activeSlot' => null],
            $ids
        ), $context);
    }

}
