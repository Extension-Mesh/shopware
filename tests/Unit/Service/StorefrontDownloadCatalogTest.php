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
    public function testItGroupsManagedDownloadsAndLeavesNormalDownloadsAlone(): void
    {
        $mediaV1 = Uuid::randomHex();
        $mediaV2 = Uuid::randomHex();
        $mediaV2Duplicate = Uuid::randomHex();
        $newerShopwareMedia = Uuid::randomHex();
        $normalMedia = Uuid::randomHex();
        $releases = $this->createMock(PublicationReader::class);
        $releases->method('byMediaIds')->willReturn([
            $mediaV1 => $this->release($mediaV1, '1.2.0', '^6.6'),
            $mediaV2Duplicate => $this->release($mediaV2Duplicate, '1.10.0', '^6.6'),
            $mediaV2 => $this->release($mediaV2, '1.10.0', '^6.6', 'Fixes the important issue.'),
            $newerShopwareMedia => $this->release($newerShopwareMedia, '2.0.0', '~6.7.0'),
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
        self::assertCount(2, $result['groups']);
        self::assertSame(['~6.7.0', '^6.6'], \array_column($result['groups'], 'shopware'));
        self::assertCount(2, $result['groups'][1]['releases']);
        self::assertSame(
            ['1.10.0', '1.2.0'],
            \array_column(\array_column($result['groups'][1]['releases'], 'release'), 'version')
        );
        self::assertSame(
            'Fixes the important issue.',
            $result['groups'][1]['releases'][0]['release']['metadata']['releaseNotes']
        );
        $template = \file_get_contents(
            __DIR__ . '/../../../src/Resources/views/storefront/component/line-item/element/'
                . 'extension-mesh-download-item.html.twig'
        );
        self::assertIsString($template);
        self::assertStringContainsString('metadata.releaseNotes', $template);
        self::assertStringContainsString('extension_mesh_release_notes', $template);
        self::assertStringNotContainsString('extensionMesh.downloads.shopware', $template);
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
        string $version,
        string $shopware,
        ?string $releaseNotes = null
    ): array
    {
        return [
            'id' => Uuid::randomHex(),
            'downloadId' => Uuid::randomHex(),
            'productId' => Uuid::randomHex(),
            'mediaId' => $mediaId,
            'fingerprint' => \hash('sha256', $mediaId),
            'technicalName' => 'ExamplePlugin',
            'version' => $version,
            'metadata' => [
                'name' => 'ExamplePlugin',
                'version' => $version,
                'shopware' => $shopware,
                'releaseNotes' => $releaseNotes,
            ],
            'sha256' => \hash('sha256', $version),
            'validationError' => null,
            'releasedAt' => '2026-07-29T10:00:00Z',
        ];
    }
}
