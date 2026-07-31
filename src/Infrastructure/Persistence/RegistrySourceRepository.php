<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Infrastructure\Persistence;

use ExtensionMesh\Shopware\Core\Content\RegistrySource\RegistrySourceEntity;
use ExtensionMesh\Shopware\Core\Content\RegistrySource\RegistrySourceCollection;
use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;

final class RegistrySourceRepository
{
    public function __construct(
        /** @var EntityRepository<RegistrySourceCollection> */
        private readonly EntityRepository $repository
    )
    {
    }

    /**
     * @return list<array{
     *     id: string,
     *     url: string,
     *     normalizedUrl: string,
     *     label: ?string,
     *     enabled: bool,
     *     credentialCiphertext: ?string,
     *     credentialFingerprint: ?string,
     *     cachedRegistry: ?string,
     *     lastRefreshedAt: ?string,
     *     lastError: ?string
     * }>
     */
    public function all(Context $context): array
    {
        $criteria = (new Criteria())->addSorting(new FieldSorting('createdAt'));

        return \array_values(\array_map(
            $this->hydrate(...),
            $this->repository->search($criteria, $context)->getElements()
        ));
    }

    /**
     * @return array{
     *     id: string,
     *     url: string,
     *     normalizedUrl: string,
     *     label: ?string,
     *     enabled: bool,
     *     credentialCiphertext: ?string,
     *     credentialFingerprint: ?string,
     *     cachedRegistry: ?string,
     *     lastRefreshedAt: ?string,
     *     lastError: ?string
     * }
     */
    public function get(string $id, Context $context): array
    {
        if (!Uuid::isValid($id)) {
            throw ExtensionMeshException::sourceNotFound($id);
        }
        $entity = $this->repository->search(new Criteria([$id]), $context)->first();
        if (!$entity instanceof RegistrySourceEntity) {
            throw ExtensionMeshException::sourceNotFound($id);
        }

        return $this->hydrate($entity);
    }

    public function add(
        string $url,
        string $normalizedUrl,
        string $label,
        string $registryJson,
        ?string $credentialCiphertext,
        ?string $credentialFingerprint,
        Context $context
    ): string {
        $duplicate = (new Criteria())
            ->addFilter(new EqualsFilter('normalizedUrl', $normalizedUrl))
            ->setLimit(1);
        if ($this->repository->searchIds($duplicate, $context)->getTotal() > 0) {
            throw ExtensionMeshException::invalidRegistryUrl('this registry has already been added.');
        }

        $id = Uuid::randomHex();
        $this->repository->create([[
            'id' => $id,
            'url' => $url,
            'normalizedUrl' => $normalizedUrl,
            'label' => $label,
            'enabled' => true,
            'credentialCiphertext' => $credentialCiphertext,
            'credentialFingerprint' => $credentialFingerprint,
            'cachedRegistry' => $registryJson,
            'lastRefreshedAt' => new \DateTimeImmutable(),
            'lastError' => null,
        ]], $context);

        return $id;
    }

    public function remove(string $id, Context $context): void
    {
        $this->get($id, $context);
        $this->repository->delete([['id' => $id]], $context);
    }

    public function updateCache(string $id, string $label, string $registryJson, Context $context): void
    {
        $this->updateExisting($id, [
            'label' => $label,
            'cachedRegistry' => $registryJson,
            'lastRefreshedAt' => new \DateTimeImmutable(),
            'lastError' => null,
        ], $context);
    }

    public function updateCredential(
        string $id,
        ?string $credentialCiphertext,
        ?string $credentialFingerprint,
        string $label,
        string $registryJson,
        Context $context
    ): void {
        $this->updateExisting($id, [
            'credentialCiphertext' => $credentialCiphertext,
            'credentialFingerprint' => $credentialFingerprint,
            'label' => $label,
            'cachedRegistry' => $registryJson,
            'lastRefreshedAt' => new \DateTimeImmutable(),
            'lastError' => null,
        ], $context);
    }

    public function recordError(string $id, string $message, Context $context): void
    {
        $this->updateExisting($id, ['lastError' => \mb_substr($message, 0, 65535)], $context);
    }

    /** @param array<string, mixed> $values */
    private function updateExisting(string $id, array $values, Context $context): void
    {
        $this->get($id, $context);
        $this->repository->update([['id' => $id, ...$values]], $context);
    }

    /**
     * @return array{
     *     id: string,
     *     url: string,
     *     normalizedUrl: string,
     *     label: ?string,
     *     enabled: bool,
     *     credentialCiphertext: ?string,
     *     credentialFingerprint: ?string,
     *     cachedRegistry: ?string,
     *     lastRefreshedAt: ?string,
     *     lastError: ?string
     * }
     */
    private function hydrate(RegistrySourceEntity $entity): array
    {
        return [
            'id' => $entity->getId(),
            'url' => $entity->getUrl(),
            'normalizedUrl' => $entity->getNormalizedUrl(),
            'label' => $entity->getLabel(),
            'enabled' => $entity->isEnabled(),
            'credentialCiphertext' => $entity->getCredentialCiphertext(),
            'credentialFingerprint' => $entity->getCredentialFingerprint(),
            'cachedRegistry' => $entity->getCachedRegistry(),
            'lastRefreshedAt' => $this->date($entity->getLastRefreshedAt()),
            'lastError' => $entity->getLastError(),
        ];
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s.v') : null;
    }

}
