<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Service;

use Doctrine\DBAL\Connection;
use ExtensionMesh\Shopware\Infrastructure\Persistence\PublicationRepository;
use ExtensionMesh\Shopware\Service\StorefrontDownloadCatalog;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItemDownload\OrderLineItemDownloadEntity;
use Shopware\Core\Framework\Uuid\Uuid;

final class StorefrontDownloadCatalogTest extends TestCase
{
    public function testItGroupsManagedDownloadsAndLeavesNormalDownloadsAlone(): void
    {
        $mediaV1 = Uuid::randomHex();
        $mediaV2 = Uuid::randomHex();
        $mediaV2Duplicate = Uuid::randomHex();
        $newerShopwareMedia = Uuid::randomHex();
        $normalMedia = Uuid::randomHex();
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            $this->releaseRow($mediaV1, '1.2.0', '^6.6'),
            $this->releaseRow($mediaV2Duplicate, '1.10.0', '^6.6'),
            $this->releaseRow($mediaV2, '1.10.0', '^6.6', 'Fixes the important issue.'),
            $this->releaseRow($newerShopwareMedia, '2.0.0', '~6.7.0'),
        ]);
        $catalog = new StorefrontDownloadCatalog(new PublicationRepository($connection));
        $normal = $this->download($normalMedia);

        $result = $catalog->group([
            $this->download($mediaV1),
            $normal,
            $this->download($mediaV2Duplicate),
            $this->download($mediaV2),
            $this->download($newerShopwareMedia),
        ]);

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
    private function releaseRow(
        string $mediaId,
        string $version,
        string $shopware,
        ?string $releaseNotes = null
    ): array
    {
        return [
            'id' => Uuid::fromHexToBytes(Uuid::randomHex()),
            'product_download_id' => Uuid::fromHexToBytes(Uuid::randomHex()),
            'product_id' => Uuid::fromHexToBytes(Uuid::randomHex()),
            'media_id' => Uuid::fromHexToBytes($mediaId),
            'fingerprint' => \hash('sha256', $mediaId),
            'technical_name' => 'ExamplePlugin',
            'version' => $version,
            'metadata' => \json_encode([
                'name' => 'ExamplePlugin',
                'version' => $version,
                'shopware' => $shopware,
                'releaseNotes' => $releaseNotes,
            ], \JSON_THROW_ON_ERROR),
            'sha256' => \hash('sha256', $version),
            'validation_error' => null,
            'created_at' => '2026-07-29 10:00:00.000',
        ];
    }
}
