<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use ExtensionMesh\Shopware\Core\Content\RepositoryConnection\RepositoryConnectionEntity;
use ExtensionMesh\Shopware\Core\Content\RepositoryConnection\RepositoryConnectionCollection;
use ExtensionMesh\Shopware\Core\Content\RepositoryRelease\RepositoryReleaseCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;

final class RepositoryConnectionRepository
{
    public function __construct(
        /** @var EntityRepository<RepositoryConnectionCollection> */
        private readonly EntityRepository $connections,
        /** @var EntityRepository<RepositoryReleaseCollection> */
        private readonly EntityRepository $releases,
        private readonly Connection $connection
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function all(bool $enabledOnly, Context $context): array
    {
        $criteria = (new Criteria())->addSorting(new FieldSorting('createdAt'));
        if ($enabledOnly) {
            $criteria->addFilter(new EqualsFilter('enabled', true));
        }

        return $this->hydrateMany($this->connections->search($criteria, $context)->getElements());
    }

    /** @return array<string, mixed>|null */
    public function get(string $id, Context $context): ?array
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $entity = $this->connections->search(new Criteria([$id]), $context)->first();

        return $entity instanceof RepositoryConnectionEntity ? $this->hydrate($entity) : null;
    }

    public function create(
        string $provider,
        string $repository,
        string $apiBaseUrl,
        string $webUrl,
        string $defaultBranch,
        bool $private,
        ?string $credentialCiphertext,
        ?string $credentialFingerprint,
        ?string $productId,
        ?string $technicalName,
        ?string $configPath,
        Context $context
    ): string {
        $id = Uuid::randomHex();
        $this->connections->create([[
            'id' => $id,
            'provider' => $provider,
            'repository' => $repository,
            'apiBaseUrl' => $apiBaseUrl,
            'webUrl' => $webUrl,
            'defaultBranch' => $defaultBranch,
            'repositoryPrivate' => $private,
            'credentialCiphertext' => $credentialCiphertext,
            'credentialFingerprint' => $credentialFingerprint,
            'productId' => $productId,
            'productVersionId' => $productId === null ? null : Defaults::LIVE_VERSION,
            'technicalName' => $technicalName,
            'configPath' => $configPath,
            'onboardingStatus' => 'ready',
            'enabled' => true,
        ]], $context);

        return $id;
    }

    public function createQueued(
        string $provider,
        string $repository,
        string $apiBaseUrl,
        ?string $credentialCiphertext,
        ?string $credentialFingerprint,
        ?string $productId,
        string $mode,
        Context $context
    ): string {
        $id = Uuid::randomHex();
        $this->connections->create([[
            'id' => $id,
            'provider' => $provider,
            'repository' => $repository,
            'apiBaseUrl' => $apiBaseUrl,
            'repositoryPrivate' => $credentialCiphertext !== null,
            'credentialCiphertext' => $credentialCiphertext,
            'credentialFingerprint' => $credentialFingerprint,
            'productId' => $productId,
            'productVersionId' => $productId === null ? null : Defaults::LIVE_VERSION,
            'onboardingMode' => $mode,
            'onboardingStatus' => 'queued',
            'onboardingStage' => 'inspect',
            'enabled' => false,
        ]], $context);

        return $id;
    }

    public function exists(string $provider, string $repository, string $apiBaseUrl, ?string $excludeId, Context $context): bool
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('provider', $provider))
            ->addFilter(new EqualsFilter('repository', $repository))
            ->addFilter(new EqualsFilter('apiBaseUrl', $apiBaseUrl))
            ->setLimit(1);
        $ids = $this->connections->searchIds($criteria, $context)->getIds();
        if ($excludeId !== null && Uuid::isValid($excludeId)) {
            $ids = \array_values(\array_diff($ids, [$excludeId]));
        }

        return $ids !== [];
    }

    public function updateCredential(string $id, ?string $ciphertext, ?string $fingerprint, Context $context): void
    {
        $this->update($id, ['credentialCiphertext' => $ciphertext, 'credentialFingerprint' => $fingerprint], $context);
    }

    /** @param array{repository: string, apiBaseUrl: string, webUrl: string, defaultBranch: string, private: bool} $inspection */
    public function storeInspection(string $id, array $inspection, Context $context): void
    {
        $this->update($id, [
            'repository' => $inspection['repository'],
            'apiBaseUrl' => $inspection['apiBaseUrl'],
            'webUrl' => $inspection['webUrl'],
            'defaultBranch' => $inspection['defaultBranch'],
            'repositoryPrivate' => $inspection['private'],
            'onboardingStatus' => 'preparing',
            'onboardingStage' => 'prepare',
            'lastError' => null,
        ], $context);
    }

    public function markPrepared(string $id, string $productId, string $technicalName, ?string $configPath, Context $context): void
    {
        $this->update($id, [
            'productId' => $productId,
            'productVersionId' => Defaults::LIVE_VERSION,
            'technicalName' => $technicalName,
            'configPath' => $configPath,
            'onboardingStatus' => 'synchronizing',
            'onboardingStage' => 'synchronize',
            'lastError' => null,
            'enabled' => true,
        ], $context);
    }

    public function markProcessing(string $id, string $status, ?string $stage, Context $context): void
    {
        $this->update($id, ['onboardingStatus' => $status, 'onboardingStage' => $stage, 'lastError' => null], $context);
    }

    public function setProductAndMetadata(string $id, string $productId, string $technicalName, ?string $configPath, Context $context): void
    {
        $this->update($id, [
            'productId' => $productId,
            'productVersionId' => Defaults::LIVE_VERSION,
            'technicalName' => $technicalName,
            'configPath' => $configPath,
        ], $context);
    }

    public function setTechnicalName(string $id, string $technicalName, Context $context): void
    {
        $this->update($id, ['technicalName' => $technicalName], $context);
    }

    public function markSynchronized(string $id, Context $context): void
    {
        $this->update($id, [
            'onboardingStatus' => 'ready',
            'onboardingStage' => null,
            'lastSyncedAt' => new \DateTimeImmutable(),
            'lastError' => null,
        ], $context);
    }

    public function markFailed(string $id, string $message, Context $context): void
    {
        $this->update($id, ['onboardingStatus' => 'failed', 'lastError' => \mb_substr($message, 0, 65535)], $context);
    }

    public function delete(string $id, Context $context): void
    {
        if (Uuid::isValid($id)) {
            $this->connections->delete([['id' => $id]], $context);
        }
    }

    public function hasImportedAsset(string $connectionId, string $releaseId, string $assetId, Context $context): bool
    {
        return $this->releaseExists([
            new EqualsFilter('connectionId', $connectionId),
            new EqualsFilter('providerReleaseId', $releaseId),
            new EqualsFilter('providerAssetId', $assetId),
        ], $context);
    }

    public function hasImportedRelease(string $connectionId, string $releaseId, Context $context): bool
    {
        return $this->releaseExists([
            new EqualsFilter('connectionId', $connectionId),
            new EqualsFilter('providerReleaseId', $releaseId),
        ], $context);
    }

    public function hasImportedVersion(string $connectionId, string $version, Context $context): bool
    {
        return $this->releaseExists([
            new EqualsFilter('connectionId', $connectionId),
            new EqualsFilter('version', $version),
        ], $context);
    }

    public function recordImportedAsset(
        string $connectionId,
        string $releaseId,
        string $assetId,
        string $tag,
        string $assetName,
        string $version,
        ?string $releaseNotes,
        string $sha256,
        string $mediaId,
        string $productDownloadId,
        string $releasedAt,
        Context $context
    ): void {
        $this->releases->create([[
            'id' => Uuid::randomHex(),
            'connectionId' => $connectionId,
            'providerReleaseId' => $releaseId,
            'providerAssetId' => $assetId,
            'tag' => $tag,
            'assetName' => $assetName,
            'version' => $version,
            'releaseNotes' => $releaseNotes,
            'sha256' => $sha256,
            'mediaId' => $mediaId,
            'productDownloadId' => $productDownloadId,
            'productDownloadVersionId' => Defaults::LIVE_VERSION,
            'releasedAt' => new \DateTimeImmutable($releasedAt),
        ]], $context);
    }

    public function updateImportedReleaseNotes(
        string $connectionId,
        string $releaseId,
        ?string $releaseNotes,
        Context $context
    ): void
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('connectionId', $connectionId))
            ->addFilter(new EqualsFilter('providerReleaseId', $releaseId));
        $ids = $this->releases->searchIds($criteria, $context)->getIds();
        if ($ids !== []) {
            $this->releases->update(\array_map(
                static fn (string $id): array => ['id' => $id, 'releaseNotes' => $releaseNotes],
                $ids
            ), $context);
        }
    }

    public function acquireLock(string $id): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT GET_LOCK(:name, 0)',
            ['name' => 'extension_mesh_repository_' . $id]
        ) === 1;
    }

    public function releaseLock(string $id): void
    {
        $this->connection->executeQuery(
            'SELECT RELEASE_LOCK(:name)',
            ['name' => 'extension_mesh_repository_' . $id]
        );
    }

    /** @param array<string, mixed> $values */
    private function update(string $id, array $values, Context $context): void
    {
        if (Uuid::isValid($id)) {
            $this->connections->update([['id' => $id, ...$values]], $context);
        }
    }

    /** @param list<EqualsFilter> $filters */
    private function releaseExists(array $filters, Context $context): bool
    {
        $criteria = (new Criteria())->setLimit(1);
        foreach ($filters as $filter) {
            $criteria->addFilter($filter);
        }

        return $this->releases->searchIds($criteria, $context)->getTotal() > 0;
    }

    /**
     * @param array<string, RepositoryConnectionEntity> $entities
     *
     * @return list<array<string, mixed>>
     */
    private function hydrateMany(array $entities): array
    {
        return \array_values(\array_map(
            fn (RepositoryConnectionEntity $entity): array => $this->hydrate($entity),
            $entities
        ));
    }

    /** @return array<string, mixed> */
    private function hydrate(RepositoryConnectionEntity $entity): array
    {
        return [
            'id' => $entity->getId(),
            'provider' => $entity->getProvider(),
            'repository' => $entity->getRepository(),
            'apiBaseUrl' => $entity->getApiBaseUrl(),
            'webUrl' => $entity->getWebUrl(),
            'defaultBranch' => $entity->getDefaultBranch(),
            'private' => $entity->isRepositoryPrivate(),
            'credentialCiphertext' => $entity->getCredentialCiphertext(),
            'credentialFingerprint' => $entity->getCredentialFingerprint(),
            'productId' => $entity->getProductId(),
            'technicalName' => $entity->getTechnicalName(),
            'configPath' => $entity->getConfigPath(),
            'onboardingMode' => $entity->getOnboardingMode(),
            'onboardingStatus' => $entity->getOnboardingStatus(),
            'onboardingStage' => $entity->getOnboardingStage(),
            'enabled' => $entity->isEnabled(),
            'lastSyncedAt' => $this->date($entity->getLastSyncedAt()),
            'lastError' => $entity->getLastError(),
            'createdAt' => $this->date($entity->getCreatedAt()) ?? '',
            'updatedAt' => $this->date($entity->getUpdatedAt()),
        ];
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s.v') : null;
    }

}
