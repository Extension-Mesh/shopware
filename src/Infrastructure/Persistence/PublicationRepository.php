<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Infrastructure\Persistence;

use ExtensionMesh\Shopware\Core\Content\PublishedRelease\PublishedReleaseEntity;
use ExtensionMesh\Shopware\Core\Content\PublishedRelease\PublishedReleaseCollection;
use ExtensionMesh\Shopware\Core\Content\RepositoryRelease\RepositoryReleaseCollection;
use ExtensionMesh\Shopware\Core\Content\RepositoryRelease\RepositoryReleaseEntity;
use Shopware\Core\Content\Product\Aggregate\ProductDownload\ProductDownloadCollection;
use Shopware\Core\Content\Product\Aggregate\ProductDownload\ProductDownloadEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;

final class PublicationRepository implements PublicationReader
{
    public function __construct(
        /** @var EntityRepository<PublishedReleaseCollection> */
        private readonly EntityRepository $releases,
        /** @var EntityRepository<ProductDownloadCollection> */
        private readonly EntityRepository $productDownloads,
        /** @var EntityRepository<RepositoryReleaseCollection> */
        private readonly EntityRepository $repositoryReleases,
        private readonly EntitlementRepository $entitlements
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function digitalProductDownloads(int $offset, int $limit, Context $context): array
    {
        $eligibleProductIds = $this->entitlements->eligibleProductIds($context);
        if ($eligibleProductIds === []) {
            return [];
        }
        $criteria = (new Criteria())
            ->addAssociation('media')
            ->addFilter(new EqualsAnyFilter('productId', $eligibleProductIds))
            ->addFilter(new EqualsFilter('versionId', Defaults::LIVE_VERSION))
            ->addFilter(new EqualsFilter('productVersionId', Defaults::LIVE_VERSION))
            ->addSorting(new FieldSorting('productId'))
            ->addSorting(new FieldSorting('position'))
            ->addSorting(new FieldSorting('createdAt'))
            ->addSorting(new FieldSorting('id'))
            ->setOffset($offset)
            ->setLimit($limit);
        $downloads = $this->productDownloads->search($criteria, $context);

        $releaseNotes = [];
        $downloadIds = $downloads->getIds();
        if ($downloadIds !== []) {
            $releaseCriteria = (new Criteria())
                ->addFilter(new EqualsAnyFilter('productDownloadId', $downloadIds));
            $repositoryReleases = $this->repositoryReleases->search($releaseCriteria, $context);
            foreach ($repositoryReleases as $entity) {
                $releaseNotes[$entity->getProductDownloadId()] = $entity->getReleaseNotes();
            }
        }

        $rows = [];
        foreach ($downloads as $download) {
            if ($download->getMedia() === null) {
                continue;
            }
            $media = $download->getMedia();
            $notes = $releaseNotes[$download->getId()] ?? null;
            $fingerprintData = [
                $download->getMediaId(),
                (string) ($media->getFileSize() ?? 0),
                ($download->getUpdatedAt() ?? $download->getCreatedAt())?->format(Defaults::STORAGE_DATE_TIME_FORMAT) ?? '',
                ($media->getUpdatedAt() ?? $media->getCreatedAt())?->format(Defaults::STORAGE_DATE_TIME_FORMAT) ?? '',
                \is_string($notes) ? $notes : '',
            ];
            $rows[] = [
                'downloadId' => $download->getId(),
                'productId' => $download->getProductId(),
                'mediaId' => $download->getMediaId(),
                'fileName' => $media->getFileName() ?? '',
                'fileExtension' => \strtolower($media->getFileExtension() ?? ''),
                'fileSize' => $media->getFileSize() ?? 0,
                'releaseNotes' => \is_string($notes) ? $notes : null,
                'fingerprint' => \hash('sha256', \implode("\0", $fingerprintData)),
            ];
        }

        return $rows;
    }

    /**
     * @param list<string> $downloadIds
     *
     * @return array<string, array<string, mixed>>
     */
    public function byDownloadIds(array $downloadIds, Context $context): array
    {
        return $this->keyedBy('productDownloadId', $downloadIds, $context);
    }

    /**
     * @param list<string> $mediaIds
     *
     * @return array<string, array<string, mixed>>
     */
    public function byMediaIds(array $mediaIds, Context $context): array
    {
        return $this->keyedBy('mediaId', $mediaIds, $context, true);
    }

    /** @return array<string, mixed>|null */
    public function get(string $id, Context $context): ?array
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $entity = $this->releases->search(new Criteria([$id]), $context)->first();

        return $entity instanceof PublishedReleaseEntity ? $this->hydrate($entity) : null;
    }

    /** @param array<string, mixed>|null $metadata */
    public function save(
        string $downloadId,
        string $productId,
        string $mediaId,
        string $fingerprint,
        ?array $metadata,
        ?string $sha256,
        ?string $validationError,
        Context $context
    ): void {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('productDownloadId', $downloadId))
            ->setLimit(1);
        $existingId = $this->releases->searchIds($criteria, $context)->firstId();
        $this->releases->upsert([[
            'id' => $existingId ?? Uuid::randomHex(),
            'productDownloadId' => $downloadId,
            'productDownloadVersionId' => Defaults::LIVE_VERSION,
            'productId' => $productId,
            'productVersionId' => Defaults::LIVE_VERSION,
            'mediaId' => $mediaId,
            'fingerprint' => $fingerprint,
            'technicalName' => $metadata['name'] ?? null,
            'version' => $metadata['version'] ?? null,
            'metadata' => $metadata,
            'sha256' => $sha256,
            'validationError' => $validationError,
        ]], $context);
    }

    public function removeMissingDownloads(Context $context): void
    {
        $eligible = \array_flip($this->entitlements->eligibleProductIds($context));
        $all = $this->releases->search(new Criteria(), $context);
        $downloadIds = [];
        foreach ($all as $release) {
            $downloadIds[] = $release->getProductDownloadId();
        }
        $existingDownloads = [];
        if ($downloadIds !== []) {
            $criteria = (new Criteria($downloadIds))
                ->addFilter(new EqualsFilter('versionId', Defaults::LIVE_VERSION));
            $existingDownloads = \array_flip($this->productDownloads->searchIds($criteria, $context)->getIds());
        }

        $deletes = [];
        foreach ($all as $release) {
            if (!isset($eligible[$release->getProductId()]) || !isset($existingDownloads[$release->getProductDownloadId()])) {
                $deletes[] = ['id' => $release->getId()];
            }
        }
        if ($deletes !== []) {
            $this->releases->delete($deletes, $context);
        }
    }

    /**
     * @param list<string> $productIds
     *
     * @return list<array<string, mixed>>
     */
    public function validForProducts(array $productIds, Context $context): array
    {
        if ($productIds === []) {
            return [];
        }
        $criteria = $this->validCriteria()
            ->addFilter(new EqualsAnyFilter('productId', $productIds))
            ->addAssociation('productDownload')
            ->addFilter(new EqualsFilter('productDownload.versionId', Defaults::LIVE_VERSION))
            ->addSorting(new FieldSorting('technicalName'))
            ->addSorting(new FieldSorting('version'));

        return $this->hydrateMany($this->releases->search($criteria, $context)->getElements());
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, array<string, mixed>>
     */
    private function keyedBy(string $field, array $ids, Context $context, bool $validOnly = false): array
    {
        if ($ids === []) {
            return [];
        }
        $criteria = $validOnly ? $this->validCriteria() : new Criteria();
        $criteria->addFilter(new EqualsAnyFilter($field, $ids));
        if ($validOnly) {
            $eligible = $this->entitlements->eligibleProductIds($context);
            if ($eligible === []) {
                return [];
            }
            $criteria->addFilter(new EqualsAnyFilter('productId', $eligible));
        }

        $rows = [];
        $releases = $this->releases->search($criteria, $context);
        foreach ($releases as $entity) {
            $key = $field === 'mediaId' ? $entity->getMediaId() : $entity->getProductDownloadId();
            $rows[$key] = $this->hydrate($entity);
        }

        return $rows;
    }

    private function validCriteria(): Criteria
    {
        return (new Criteria())
            ->addFilter(new EqualsFilter('validationError', null))
            ->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [new EqualsFilter('metadata', null)]))
            ->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [new EqualsFilter('sha256', null)]));
    }

    /**
     * @param array<string, PublishedReleaseEntity> $entities
     *
     * @return list<array<string, mixed>>
     */
    private function hydrateMany(array $entities): array
    {
        return \array_values(\array_map(
            fn (PublishedReleaseEntity $entity): array => $this->hydrate($entity),
            $entities
        ));
    }

    /** @return array<string, mixed> */
    private function hydrate(PublishedReleaseEntity $entity): array
    {
        $createdAt = $entity->getCreatedAt();

        return [
            'id' => $entity->getId(),
            'downloadId' => $entity->getProductDownloadId(),
            'productId' => $entity->getProductId(),
            'mediaId' => $entity->getMediaId(),
            'fingerprint' => $entity->getFingerprint(),
            'technicalName' => $entity->getTechnicalName(),
            'version' => $entity->getVersion(),
            'metadata' => $entity->getMetadata(),
            'sha256' => $entity->getSha256(),
            'validationError' => $entity->getValidationError(),
            'releasedAt' => $createdAt instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($createdAt)
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s\Z')
                : '',
        ];
    }

}
