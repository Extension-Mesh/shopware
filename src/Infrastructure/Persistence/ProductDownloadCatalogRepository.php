<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Infrastructure\Persistence;

use Shopware\Core\Content\Product\Aggregate\ProductDownload\ProductDownloadEntity;
use Shopware\Core\Content\Product\Aggregate\ProductDownload\ProductDownloadCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;

final class ProductDownloadCatalogRepository
{
    public function __construct(
        /** @var EntityRepository<ProductDownloadCollection> */
        private readonly EntityRepository $downloads,
        private readonly PublicationRepository $publications
    ) {
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int} */
    public function paginate(string $productId, int $page, int $limit, Context $context): array
    {
        if (!Uuid::isValid($productId)) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'limit' => $limit];
        }
        $criteria = (new Criteria())
            ->addAssociation('media')
            ->addFilter(new EqualsFilter('productId', $productId))
            ->addFilter(new EqualsFilter('productVersionId', Defaults::LIVE_VERSION))
            ->addFilter(new EqualsFilter('versionId', Defaults::LIVE_VERSION))
            ->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING))
            ->addSorting(new FieldSorting('id', FieldSorting::DESCENDING))
            ->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT)
            ->setLimit($limit)
            ->setOffset(($page - 1) * $limit);
        $result = $this->downloads->search($criteria, $context);
        $total = $result->getTotal();
        $page = \min($page, \max(1, (int) \ceil($total / $limit)));
        $publications = $this->publications->byDownloadIds(\array_values($result->getIds()), $context);
        $items = [];
        foreach ($result as $download) {
            if ($download->getMedia() === null) {
                continue;
            }
            $media = $download->getMedia();
            $release = $publications[$download->getId()] ?? null;
            $metadata = \is_array($release['metadata'] ?? null) ? $release['metadata'] : [];
            $items[] = [
                'id' => $download->getId(),
                'mediaId' => $download->getMediaId(),
                'fileName' => $media->getFileNameIncludingExtension() ?? '',
                'fileSize' => $media->getFileSize() ?? 0,
                'mimeType' => $media->getMimeType() ?? '',
                'position' => $download->getPosition(),
                'createdAt' => $download->getCreatedAt()?->format('Y-m-d H:i:s.v') ?? '',
                'releaseId' => $release['id'] ?? null,
                'technicalName' => $release['technicalName'] ?? null,
                'version' => $release['version'] ?? null,
                'shopware' => \is_string($metadata['shopware'] ?? null) ? $metadata['shopware'] : null,
                'php' => \is_string($metadata['php'] ?? null) ? $metadata['php'] : null,
                'validationError' => $release['validationError'] ?? null,
                'publicationStatus' => $release === null
                    ? 'pending'
                    : (($release['validationError'] ?? null) === null ? 'published' : 'rejected'),
            ];
        }

        return ['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }
}
