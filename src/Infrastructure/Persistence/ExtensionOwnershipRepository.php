<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Infrastructure\Persistence;

use ExtensionMesh\Shopware\Core\Content\ExtensionOwnership\ExtensionOwnershipEntity;
use ExtensionMesh\Shopware\Core\Content\ExtensionOwnership\ExtensionOwnershipCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

final class ExtensionOwnershipRepository
{
    public function __construct(
        /** @var EntityRepository<ExtensionOwnershipCollection> */
        private readonly EntityRepository $repository
    )
    {
    }

    /** @return array<string, string> */
    public function all(Context $context): array
    {
        $ownership = [];
        $entities = $this->repository->search(new Criteria(), $context);
        foreach ($entities as $entity) {
            $ownership[$entity->getTechnicalName()] = $entity->getRegistryUrl();
        }

        return $ownership;
    }

    public function markPrepared(string $technicalName, string $registryUrl, Context $context): void
    {
        $this->repository->upsert([[
            'technicalName' => $technicalName,
            'registryUrl' => $registryUrl,
            'preparedAt' => new \DateTimeImmutable(),
        ]], $context);
    }
}
