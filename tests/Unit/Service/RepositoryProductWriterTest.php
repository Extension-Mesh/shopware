<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Service;

use ExtensionMesh\Shopware\Service\RepositoryProductWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Language\LanguageCollection;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\Locale\LocaleEntity;
use Shopware\Core\System\Tax\TaxCollection;
use Shopware\Core\System\Tax\TaxEntity;

final class RepositoryProductWriterTest extends TestCase
{
    public function testMetadataRefreshOnlyUpdatesTheExtensionMeshCustomField(): void
    {
        $context = Context::createCLIContext();
        $productId = Uuid::randomHex();
        $storeMetadata = [
            'installation_manual' => ['en' => '<p>Updated instructions</p>'],
            'features' => ['en' => ['Updated feature']],
        ];

        /** @var EntityRepository<ProductCollection>&MockObject $products */
        $products = $this->createMock(EntityRepository::class);
        $products->expects(self::once())
            ->method('update')
            ->with([[
                'id' => $productId,
                'customFields' => [
                    'extension_mesh_store_metadata' => $storeMetadata,
                ],
            ]], $context)
            ->willReturn(EntityWrittenContainerEvent::createWithWrittenEvents([], $context, []));

        $writer = new RepositoryProductWriter(
            $products,
            $this->createMock(MediaService::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class)
        );

        $writer->updateStoreMetadata($productId, $storeMetadata, $context);
    }

    public function testItRetainsTheCompleteStoreMetadataOnTheImportedProduct(): void
    {
        $context = Context::createCLIContext();
        $productId = Uuid::randomHex();

        $tax = new TaxEntity();
        $tax->setId(Uuid::randomHex());
        $tax->setTaxRate(19.0);

        $locale = new LocaleEntity();
        $locale->setId(Uuid::randomHex());
        $locale->setCode('en-GB');
        $language = new LanguageEntity();
        $language->setId(Defaults::LANGUAGE_SYSTEM);
        $language->setLocale($locale);

        /** @var EntityRepository<ProductCollection>&MockObject $products */
        $products = $this->createMock(EntityRepository::class);
        $products->expects(self::once())
            ->method('create')
            ->with(self::callback(static function (array $payloads) use ($productId): bool {
                self::assertCount(1, $payloads);
                self::assertSame($productId, $payloads[0]['id']);
                self::assertSame([
                    'installation_manual' => [
                        'de' => '<p>Installieren und aktivieren.</p>',
                        'en' => '<p>Install and activate.</p>',
                    ],
                    'highlights' => ['en' => ['First highlight']],
                    'features' => ['en' => ['First feature']],
                    'faq' => ['en' => [[
                        'question' => 'A question?',
                        'answer' => 'An answer.',
                        'position' => 1,
                    ]]],
                    'future_store_information' => ['nested' => ['unchanged']],
                ], $payloads[0]['customFields']['extension_mesh_store_metadata']);

                return true;
            }), $context)
            ->willReturn(EntityWrittenContainerEvent::createWithWrittenEvents([], $context, []));

        /** @var EntityRepository<MediaCollection>&MockObject $media */
        $media = $this->createMock(EntityRepository::class);
        /** @var EntityRepository<TaxCollection>&MockObject $taxes */
        $taxes = $this->createMock(EntityRepository::class);
        $taxes->expects(self::once())
            ->method('search')
            ->willReturn($this->searchResult(new TaxCollection([$tax]), $context));
        /** @var EntityRepository<LanguageCollection>&MockObject $languages */
        $languages = $this->createMock(EntityRepository::class);
        $languages->expects(self::once())
            ->method('search')
            ->willReturn($this->searchResult(new LanguageCollection([$language]), $context));

        $writer = new RepositoryProductWriter(
            $products,
            $this->createMock(MediaService::class),
            $media,
            $taxes,
            $languages
        );
        $writer->createDraftProduct(
            [
                'name' => 'ExamplePlugin',
                'label' => ['en-GB' => 'Example Plugin'],
                'description' => ['en-GB' => 'Example description'],
            ],
            [
                'store' => [
                    'installation_manual' => [
                        'de' => '<p>Installieren und aktivieren.</p>',
                        'en' => '<p>Install and activate.</p>',
                    ],
                    'highlights' => ['en' => ['First highlight']],
                    'features' => ['en' => ['First feature']],
                    'faq' => ['en' => [[
                        'question' => 'A question?',
                        'answer' => 'An answer.',
                        'position' => 1,
                    ]]],
                    'future_store_information' => ['nested' => ['unchanged']],
                ],
            ],
            'acme/example-plugin',
            null,
            [],
            $context,
            $productId
        );
    }

    /**
     * @template TCollection of EntityCollection
     *
     * @param TCollection $entities
     *
     * @return EntitySearchResult<TCollection>
     */
    private function searchResult(EntityCollection $entities, Context $context): EntitySearchResult
    {
        return new EntitySearchResult(
            'fixture',
            $entities->count(),
            $entities,
            null,
            new Criteria(),
            $context
        );
    }
}
