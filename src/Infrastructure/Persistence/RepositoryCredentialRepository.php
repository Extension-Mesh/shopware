<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Infrastructure\Persistence;

use ExtensionMesh\Shopware\Core\Content\RepositoryCredential\RepositoryCredentialCollection;
use ExtensionMesh\Shopware\Core\Content\RepositoryCredential\RepositoryCredentialEntity;
use ExtensionMesh\Shopware\Service\RepositoryCredentialStore;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;

final class RepositoryCredentialRepository implements RepositoryCredentialStore
{
    public function __construct(
        /** @var EntityRepository<RepositoryCredentialCollection> */
        private readonly EntityRepository $repository
    ) {
    }

    /** @return array<string, mixed>|null */
    public function get(string $id, Context $context): ?array
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $criteria = (new Criteria([$id]))->addAssociation('connections');
        $entity = $this->repository->search($criteria, $context)->first();

        return $entity instanceof RepositoryCredentialEntity ? $this->hydrate($entity) : null;
    }

    /** @return list<array<string, mixed>> */
    public function all(Context $context): array
    {
        $criteria = (new Criteria())
            ->addAssociation('connections')
            ->addSorting(new FieldSorting('createdAt'));

        return \array_values(\array_map(
            fn (RepositoryCredentialEntity $entity): array => $this->hydrate($entity),
            $this->repository->search($criteria, $context)->getElements()
        ));
    }

    public function findId(string $provider, string $apiBaseUrl, string $fingerprint, Context $context): ?string
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('provider', $provider))
            ->addFilter(new EqualsFilter('apiBaseUrl', $apiBaseUrl))
            ->addFilter(new EqualsFilter('credentialFingerprint', $fingerprint))
            ->setLimit(1);
        $id = $this->repository->searchIds($criteria, $context)->firstId();

        return \is_string($id) ? $id : null;
    }

    public function create(
        string $provider,
        string $apiBaseUrl,
        string $ciphertext,
        string $fingerprint,
        Context $context
    ): string {
        $id = Uuid::randomHex();
        $this->repository->create([[
            'id' => $id,
            'provider' => $provider,
            'apiBaseUrl' => $apiBaseUrl,
            'credentialCiphertext' => $ciphertext,
            'credentialFingerprint' => $fingerprint,
        ]], $context);

        return $id;
    }

    public function update(
        string $id,
        string $ciphertext,
        string $fingerprint,
        Context $context
    ): void {
        $this->repository->update([[
            'id' => $id,
            'credentialCiphertext' => $ciphertext,
            'credentialFingerprint' => $fingerprint,
        ]], $context);
    }

    public function delete(string $id, Context $context): void
    {
        $this->repository->delete([['id' => $id]], $context);
    }

    /** @return array<string, mixed> */
    private function hydrate(RepositoryCredentialEntity $entity): array
    {
        return [
            'id' => $entity->getId(),
            'provider' => $entity->getProvider(),
            'apiBaseUrl' => $entity->getApiBaseUrl(),
            'credentialCiphertext' => $entity->getCredentialCiphertext(),
            'credentialFingerprint' => $entity->getCredentialFingerprint(),
            'connectionCount' => $entity->getConnections()?->count() ?? 0,
            'createdAt' => $entity->getCreatedAt()?->format('Y-m-d H:i:s.v') ?? '',
        ];
    }
}
