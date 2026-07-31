<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use ExtensionMesh\Shopware\Infrastructure\Persistence\PublicationReader;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItemDownload\OrderLineItemDownloadEntity;
use Shopware\Core\PlatformRequest;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RequestStack;

final class StorefrontDownloadCatalog
{
    /** @var array<string, array<string, mixed>> */
    private array $releasesByMediaId = [];

    /** @var array<string, true> */
    private array $resolvedMediaIds = [];

    public function __construct(
        private readonly PublicationReader $releases,
        private readonly RequestStack $requestStack
    ) {
    }

    /**
     * @param iterable<OrderLineItemDownloadEntity> $downloads
     *
     * @return array{
     *     managed: list<array{download: OrderLineItemDownloadEntity, release: array<string, mixed>}>,
     *     normal: list<OrderLineItemDownloadEntity>
     * }
     */
    public function group(iterable $downloads, ?Context $context = null): array
    {
        $items = \is_array($downloads) ? $downloads : \iterator_to_array($downloads, false);
        $unresolved = [];
        foreach ($items as $download) {
            $mediaId = $download->getMediaId();
            if (!isset($this->resolvedMediaIds[$mediaId])) {
                $unresolved[] = $mediaId;
                $this->resolvedMediaIds[$mediaId] = true;
            }
        }
        if ($unresolved !== []) {
            $context ??= $this->salesChannelContext()?->getContext();
            if ($context !== null) {
                $this->releasesByMediaId += $this->releases->byMediaIds(
                    \array_values(\array_unique($unresolved)),
                    $context
                );
            }
        }

        $normal = [];
        $managed = [];
        foreach ($items as $download) {
            $release = $this->releasesByMediaId[$download->getMediaId()] ?? null;
            $productId = $release['productId'] ?? null;
            if (!\is_array($release) || !\is_string($productId) || $productId === '') {
                $normal[] = $download;
                continue;
            }
            $managed[$productId] ??= ['download' => $download, 'release' => $release];
        }

        return ['managed' => \array_values($managed), 'normal' => $normal];
    }

    private function salesChannelContext(): ?SalesChannelContext
    {
        $context = $this->requestStack->getCurrentRequest()?->attributes->get(
            PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT
        );

        return $context instanceof SalesChannelContext ? $context : null;
    }

}
