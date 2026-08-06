<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Language\LanguageCollection;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\Tax\TaxCollection;
use Shopware\Core\System\Tax\TaxEntity;

final class RepositoryProductWriter
{
    private const STORE_METADATA_CUSTOM_FIELD = 'extension_mesh_store_metadata';

    public function __construct(
        /** @var EntityRepository<ProductCollection> */
        private readonly EntityRepository $productRepository,
        private readonly MediaService $mediaService,
        /** @var EntityRepository<MediaCollection> */
        private readonly EntityRepository $mediaRepository,
        /** @var EntityRepository<TaxCollection> */
        private readonly EntityRepository $taxRepository,
        /** @var EntityRepository<LanguageCollection> */
        private readonly EntityRepository $languageRepository
    ) {
    }

    public function assertProductExists(string $productId, Context $context): void
    {
        if (!$this->productExists($productId, $context)) {
            throw ExtensionMeshException::invalidRepository('the linked Shopware product does not exist.');
        }
    }

    public function productExists(string $productId, Context $context): bool
    {
        if (!Uuid::isValid($productId)) {
            return false;
        }
        $criteria = (new Criteria([$productId]))
            ->addFilter(new EqualsFilter('versionId', Defaults::LIVE_VERSION));

        return $this->productRepository->searchIds(
            $criteria,
            $context
        )->getTotal() > 0;
    }

    /**
     * @param array<string, mixed> $archive
     * @param array<string, mixed> $metadata
     * @param list<string>         $imageContents
     */
    public function createDraftProduct(
        array $archive,
        array $metadata,
        string $repository,
        ?string $iconContents,
        array $imageContents,
        Context $context,
        ?string $productId = null
    ): string {
        $technicalName = $archive['name'] ?? null;
        if (!\is_string($technicalName) || !\preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $technicalName)) {
            throw ExtensionMeshException::invalidRepository('the latest release has no valid technical name.');
        }

        $translations = $this->translations($archive, $metadata, $technicalName, $context);
        $tax = $this->taxRepository->search(
            (new Criteria())
                ->addSorting(new FieldSorting('taxRate', FieldSorting::DESCENDING))
                ->addSorting(new FieldSorting('createdAt'))
                ->setLimit(1),
            $context
        )->first();
        if (!$tax instanceof TaxEntity) {
            throw ExtensionMeshException::invalidRepository('Shopware has no tax rate for the imported product.');
        }

        $productId ??= Uuid::randomHex();
        $systemTranslation = $translations[Defaults::LANGUAGE_SYSTEM];
        $storeMetadata = $metadata['store'] ?? null;
        if (\is_array($storeMetadata) && $storeMetadata !== []) {
            $systemTranslation['customFields'] = [
                self::STORE_METADATA_CUSTOM_FIELD => $storeMetadata,
            ];
        }
        $additionalTranslations = $translations;
        unset($additionalTranslations[Defaults::LANGUAGE_SYSTEM]);
        $payload = [
            'id' => $productId,
            'productNumber' => \mb_substr(
                'EM-' . $technicalName . '-' . \substr(\hash('sha256', $repository), 0, 8),
                0,
                55
            ) . '-' . \substr(\hash('sha256', $productId), 0, 8),
            'stock' => 999999,
            'active' => false,
            'taxId' => $tax->getId(),
            'type' => ProductDefinition::TYPE_DIGITAL,
            'shippingFree' => true,
            'maxPurchase' => 1,
            // Shopware requires a price at creation time. Zero is a deliberately
            // unsellable placeholder; the product stays inactive and invisible.
            'price' => [[
                'currencyId' => Defaults::CURRENCY,
                'gross' => 0.0,
                'net' => 0.0,
                'linked' => true,
            ]],
            ...$systemTranslation,
            'translations' => \array_map(
                static fn (string $languageId, array $translation): array => [
                    'languageId' => $languageId,
                    ...$translation,
                ],
                \array_keys($additionalTranslations),
                $additionalTranslations
            ),
        ];

        $mediaIds = [];
        $productMedia = [];
        $coverId = null;
        if ($iconContents !== null) {
            $icon = $this->icon($iconContents);
            if ($icon !== null) {
                $mediaId = $this->mediaService->saveFile(
                    $iconContents,
                    $icon['extension'],
                    $icon['mime'],
                    'extension-mesh-' . \strtolower($technicalName)
                        . '-' . \substr(\hash('sha256', $productId), 0, 12) . '-cover',
                    $context,
                    'product',
                    null,
                    false
                );
                $mediaIds[] = $mediaId;
                $productMediaId = Uuid::randomHex();
                $productMedia[] = [
                    'id' => $productMediaId,
                    'mediaId' => $mediaId,
                    'position' => 1,
                ];
                $coverId = $productMediaId;
            }
        }
        foreach (\array_slice($imageContents, 0, 8) as $position => $contents) {
            $image = $this->icon($contents);
            if ($image === null) {
                continue;
            }
            $mediaId = $this->mediaService->saveFile(
                $contents,
                $image['extension'],
                $image['mime'],
                'extension-mesh-' . \strtolower($technicalName)
                    . '-' . \substr(\hash('sha256', $productId), 0, 12)
                    . '-image-' . ($position + 1),
                $context,
                'product',
                null,
                false
            );
            $mediaIds[] = $mediaId;
            $productMediaId = Uuid::randomHex();
            $productMedia[] = [
                'id' => $productMediaId,
                'mediaId' => $mediaId,
                'position' => $position + 2,
            ];
            $coverId ??= $productMediaId;
        }
        if ($productMedia !== []) {
            $payload['media'] = $productMedia;
            $payload['coverId'] = $coverId;
        }

        try {
            $this->productRepository->create([$payload], $context);
        } catch (\Throwable $exception) {
            if ($mediaIds !== []) {
                $this->mediaRepository->delete(
                    \array_map(static fn (string $id): array => ['id' => $id], $mediaIds),
                    $context
                );
            }
            throw $exception;
        }

        return $productId;
    }

    public function markDigital(string $productId, Context $context): void
    {
        $this->productRepository->update([[
            'id' => $productId,
            'type' => ProductDefinition::TYPE_DIGITAL,
            'shippingFree' => true,
        ]], $context);
    }

    /** @param array<string, mixed> $storeMetadata */
    public function updateStoreMetadata(string $productId, array $storeMetadata, Context $context): void
    {
        $this->productRepository->update([[
            'id' => $productId,
            'customFields' => [
                self::STORE_METADATA_CUSTOM_FIELD => $storeMetadata,
            ],
        ]], $context);
    }

    /**
     * @param array<string, mixed> $archive
     * @param array<string, mixed> $metadata
     *
     * @return array<string, array<string, string>>
     */
    private function translations(array $archive, array $metadata, string $fallback, Context $context): array
    {
        $archiveLabels = $this->localized($archive['label'] ?? null);
        $metadataLabels = $this->localized($metadata['labels'] ?? null);
        $archiveDescriptions = $this->localized($archive['description'] ?? null, true);
        $metadataDescriptions = $this->localized($metadata['descriptions'] ?? null, true);
        $metaTitles = $this->localized($metadata['metaTitles'] ?? null);
        $metaDescriptions = $this->localized($metadata['metaDescriptions'] ?? null);
        $keywords = $this->localized($metadata['keywords'] ?? null);

        $languageLocales = $this->languageLocales($context);
        $translations = [];
        foreach ($languageLocales as $languageId => $locale) {
            $name = $this->forLocale($metadataLabels, $locale)
                ?? $this->forLocale($archiveLabels, $locale)
                ?? $fallback;
            $translation = ['name' => \mb_substr($name, 0, 255)];
            $description = $this->forLocale($metadataDescriptions, $locale)
                ?? $this->forLocale($archiveDescriptions, $locale);
            $metaTitle = $this->forLocale($metaTitles, $locale);
            $metaDescription = $this->forLocale($metaDescriptions, $locale);
            $keyword = $this->forLocale($keywords, $locale);
            if ($description !== null) {
                $translation['description'] = \mb_substr($description, 0, 100000);
            }
            if ($metaTitle !== null) {
                $translation['metaTitle'] = \mb_substr($metaTitle, 0, 255);
            }
            if ($metaDescription !== null) {
                $translation['metaDescription'] = \mb_substr($metaDescription, 0, 255);
            }
            if ($keyword !== null) {
                $translation['keywords'] = \mb_substr($keyword, 0, 1000);
            }
            $translations[$languageId] = $translation;
        }

        if (!isset($translations[Defaults::LANGUAGE_SYSTEM])) {
            $translations[Defaults::LANGUAGE_SYSTEM] = [
                'name' => \mb_substr(
                    $this->forLocale($metadataLabels, 'en-GB')
                        ?? $this->forLocale($archiveLabels, 'en-GB')
                        ?? $fallback,
                    0,
                    255
                ),
            ];
        }

        return $translations;
    }

    /**
     * @return array<string, string>
     */
    private function languageLocales(Context $context): array
    {
        $locales = [];
        $criteria = (new Criteria())->addAssociation('locale');
        $languages = $this->languageRepository->search($criteria, $context);
        foreach ($languages as $language) {
            if ($language->getLocale() === null) {
                continue;
            }
            $locales[$language->getId()] = \str_replace('_', '-', $language->getLocale()->getCode());
        }

        return $locales;
    }

    /**
     * @return array<string, string>
     */
    private function localized(mixed $value, bool $allowLong = false): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $locale => $text) {
            if (\is_string($locale) && \is_string($text) && \trim($text) !== '') {
                $result[\str_replace('_', '-', $locale)] = \mb_substr(
                    \trim($text),
                    0,
                    $allowLong ? 100000 : 5000
                );
            }
        }

        return $result;
    }

    /**
     * @param array<string, string> $values
     */
    private function forLocale(array $values, string $locale): ?string
    {
        if (isset($values[$locale])) {
            return $values[$locale];
        }
        $language = \strtolower(\substr($locale, 0, 2));
        if (isset($values[$language])) {
            return $values[$language];
        }
        foreach ($values as $candidate => $value) {
            if (\strtolower(\substr($candidate, 0, 2)) === $language) {
                return $value;
            }
        }

        return $values['en-GB'] ?? $values['en'] ?? (\array_values($values)[0] ?? null);
    }

    /**
     * @return array{mime: string, extension: string}|null
     */
    private function icon(string $contents): ?array
    {
        if (\strlen($contents) > 2 * 1024 * 1024) {
            return null;
        }
        $info = @\getimagesizefromstring($contents);
        $mime = \is_array($info) ? $info['mime'] : null;

        return match ($mime) {
            'image/png' => ['mime' => $mime, 'extension' => 'png'],
            'image/jpeg' => ['mime' => $mime, 'extension' => 'jpg'],
            'image/webp' => ['mime' => $mime, 'extension' => 'webp'],
            default => null,
        };
    }
}
