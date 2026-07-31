<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Service;

use ExtensionMesh\Shopware\Infrastructure\Persistence\PublicationReader;
use ExtensionMesh\Shopware\Service\StorefrontDownloadCatalog;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItemDownload\OrderLineItemDownloadEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\RequestStack;

final class StorefrontDownloadCatalogTest extends TestCase
{
    public function testItLinksEachManagedProductOnceAndLeavesNormalDownloadsAlone(): void
    {
        $mediaV1 = Uuid::randomHex();
        $mediaV2 = Uuid::randomHex();
        $mediaV2Duplicate = Uuid::randomHex();
        $newerShopwareMedia = Uuid::randomHex();
        $normalMedia = Uuid::randomHex();
        $product = Uuid::randomHex();
        $newerProduct = Uuid::randomHex();
        $releases = $this->createMock(PublicationReader::class);
        $releases->method('byMediaIds')->willReturn([
            $mediaV1 => $this->release($mediaV1, $product, '1.2.0', '^6.6'),
            $mediaV2Duplicate => $this->release($mediaV2Duplicate, $product, '1.10.0', '^6.6'),
            $mediaV2 => $this->release($mediaV2, $product, '1.10.0', '^6.6'),
            $newerShopwareMedia => $this->release(
                $newerShopwareMedia,
                $newerProduct,
                '2.0.0',
                '~6.7.0'
            ),
        ]);
        $catalog = new StorefrontDownloadCatalog($releases, new RequestStack());
        $normal = $this->download($normalMedia);

        $result = $catalog->group([
            $this->download($mediaV1),
            $normal,
            $this->download($mediaV2Duplicate),
            $this->download($mediaV2),
            $this->download($newerShopwareMedia),
        ], Context::createCLIContext());

        self::assertSame([$normal], $result['normal']);
        self::assertCount(2, $result['managed']);
        self::assertSame(
            [$product, $newerProduct],
            \array_column(\array_column($result['managed'], 'release'), 'productId')
        );
        $template = \file_get_contents(
            __DIR__ . '/../../../src/Resources/views/storefront/component/line-item/element/'
                . 'downloads.html.twig'
        );
        self::assertIsString($template);
        self::assertStringContainsString('frontend.extension_mesh.licenses.detail', $template);
        self::assertStringNotContainsString('showOlder', $template);
        self::assertStringNotContainsString('extension-mesh-download-item', $template);
    }

    private function download(string $mediaId): OrderLineItemDownloadEntity
    {
        $download = new OrderLineItemDownloadEntity();
        $download->setId(Uuid::randomHex());
        $download->setMediaId($mediaId);

        return $download;
    }

    /**
     * @return array<string, mixed>
     */
    private function release(
        string $mediaId,
        string $productId,
        string $version,
        string $shopware
    ): array
    {
        return [
            'id' => Uuid::randomHex(),
            'downloadId' => Uuid::randomHex(),
            'productId' => $productId,
            'mediaId' => $mediaId,
            'fingerprint' => \hash('sha256', $mediaId),
            'technicalName' => 'ExamplePlugin',
            'version' => $version,
            'metadata' => [
                'name' => 'ExamplePlugin',
                'version' => $version,
                'shopware' => $shopware,
                'label' => 'Example plugin',
            ],
            'sha256' => \hash('sha256', $version),
            'validationError' => null,
            'releasedAt' => '2026-07-29T10:00:00Z',
        ];
    }
}
