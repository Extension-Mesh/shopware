<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Infrastructure\Persistence;

use ExtensionMesh\Shopware\Core\Content\AccessToken\AccessTokenEntity;
use ExtensionMesh\Shopware\Core\Content\AccessToken\AccessTokenCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;

final class AccessTokenRepository
{
    public function __construct(
        /** @var EntityRepository<AccessTokenCollection> */
        private readonly EntityRepository $repository
    )
    {
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
            ->addFilter(new EqualsFilter('revokedAt', null));

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
        ]], $context);

        return ['id' => $id, 'customerId' => $customerId, 'salesChannelId' => $salesChannelId];
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
            static fn (string $id): array => ['id' => $id, 'revokedAt' => $now],
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

        $this->repository->update([['id' => $id, 'lastUsedAt' => new \DateTimeImmutable()]], $context);
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

}
