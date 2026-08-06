<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Repository;

use ExtensionMesh\Shopware\Repository\RepositoryProductMetadataLoader;
use ExtensionMesh\Shopware\Repository\RepositoryProvider;
use PHPUnit\Framework\TestCase;

final class RepositoryProductMetadataLoaderTest extends TestCase
{
    public function testItFallsBackWhenOptionalMetadataHasNoStoreMapping(): void
    {
        $provider = new class implements RepositoryProvider {
            public function key(): string
            {
                return 'fixture';
            }

            public function label(): string
            {
                return 'Fixture';
            }

            public function defaultApiBaseUrl(): string
            {
                return 'https://api.example.test';
            }

            public function inspect(string $repository, string $apiBaseUrl, string $credential): array
            {
                throw new \LogicException('Not used.');
            }

            public function releases(string $repository, string $apiBaseUrl, string $credential): array
            {
                throw new \LogicException('Not used.');
            }

            public function readFile(
                string $repository,
                string $apiBaseUrl,
                string $credential,
                string $path,
                string $reference
            ): ?string {
                return $path === '.shopware-extension.yml'
                    ? "build:\n  zip:\n    assets: []\n"
                    : null;
            }

            public function listFiles(
                string $repository,
                string $apiBaseUrl,
                string $credential,
                string $path,
                string $reference
            ): array {
                return [];
            }

            public function downloadAsset(string $apiBaseUrl, string $credential, array $asset): string
            {
                throw new \LogicException('Not used.');
            }
        };

        $metadata = (new RepositoryProductMetadataLoader())->load(
            $provider,
            'acme/plugin',
            'https://api.example.test',
            '',
            'main'
        );

        self::assertNull($metadata['configPath']);
        self::assertSame([], $metadata['labels']);
        self::assertSame([], $metadata['imagePaths']);
    }

    public function testItLoadsShopwareCliMetadataAndReferencedDescriptions(): void
    {
        $provider = new class implements RepositoryProvider {
            public function key(): string
            {
                return 'fixture';
            }

            public function label(): string
            {
                return 'Fixture';
            }

            public function defaultApiBaseUrl(): string
            {
                return 'https://api.example.test';
            }

            public function inspect(string $repository, string $apiBaseUrl, string $credential): array
            {
                throw new \LogicException('Not used.');
            }

            public function releases(string $repository, string $apiBaseUrl, string $credential): array
            {
                throw new \LogicException('Not used.');
            }

            public function readFile(
                string $repository,
                string $apiBaseUrl,
                string $credential,
                string $path,
                string $reference
            ): ?string {
                return match ($path) {
                    '.shopware-extension.yml' => <<<'YAML'
                        store:
                          icon: src/Resources/store/icon.png
                          default_locale: de_DE
                          image_directory: src/Resources/store/images
                          availabilities: [German, International]
                          localizations: [de_DE, en_GB]
                          categories: [StorefrontDetailanpassungen]
                          type: extension
                          automatic_bugfix_version_compatibility: true
                          meta_title:
                            de: Beispiel Erweiterung – Mehr verkaufen
                            en: Example Extension - Sell more
                          meta_description:
                            de: Deutsche Metabeschreibung
                            en: English meta description
                          description:
                            de: file:src/Resources/store/description.de.html
                            en: A safe inline description
                          tags:
                            de: [Checkout, Conversion]
                            en: [checkout, conversion]
                          installation_manual:
                            de: <p>Installationsanleitung</p>
                            en: <p>Installation guide</p>
                          highlights:
                            de: [Erstes Highlight]
                            en: [First highlight]
                          features:
                            de: [Erstes Feature]
                            en: [First feature]
                          faq:
                            de:
                              - question: Eine Frage?
                                answer: Eine Antwort.
                                position: 1
                          images:
                            - file: de/2.jpg
                              activate: { de: true, en: false }
                              preview: { de: false, en: false }
                              priority: 2
                            - file: de/1.png
                              activate: { de: true, en: true }
                              preview: { de: true, en: true }
                              priority: 1
                          future_store_information:
                            nested: [is, retained, unchanged]
                        YAML,
                    'src/Resources/store/description.de.html' => '<p>Sichere Beschreibung</p>',
                    default => null,
                };
            }

            public function listFiles(
                string $repository,
                string $apiBaseUrl,
                string $credential,
                string $path,
                string $reference
            ): array {
                throw new \LogicException('The explicit image list must be used.');
            }

            public function downloadAsset(string $apiBaseUrl, string $credential, array $asset): string
            {
                throw new \LogicException('Not used.');
            }
        };

        $metadata = (new RepositoryProductMetadataLoader())->load(
            $provider,
            'acme/plugin',
            'https://api.example.test',
            'token',
            'main'
        );

        self::assertSame('.shopware-extension.yml', $metadata['configPath']);
        self::assertSame('Beispiel Erweiterung', $metadata['labels']['de']);
        self::assertSame('<p>Sichere Beschreibung</p>', $metadata['descriptions']['de']);
        self::assertSame('checkout, conversion', $metadata['keywords']['en']);
        self::assertSame('src/Resources/store/icon.png', $metadata['iconPath']);
        self::assertSame([
            'src/Resources/store/images/de/1.png',
            'src/Resources/store/images/de/2.jpg',
        ], $metadata['imagePaths']);
        self::assertSame(['German', 'International'], $metadata['store']['availabilities']);
        self::assertTrue($metadata['store']['automatic_bugfix_version_compatibility']);
        self::assertSame(
            '<p>Installation guide</p>',
            $metadata['store']['installation_manual']['en']
        );
        self::assertSame(['First highlight'], $metadata['store']['highlights']['en']);
        self::assertSame(['First feature'], $metadata['store']['features']['en']);
        self::assertSame('Eine Antwort.', $metadata['store']['faq']['de'][0]['answer']);
        self::assertSame(
            ['de' => true, 'en' => true],
            $metadata['store']['images'][1]['preview']
        );
        self::assertSame(
            ['nested' => ['is', 'retained', 'unchanged']],
            $metadata['store']['future_store_information']
        );
        self::assertSame(
            'file:src/Resources/store/description.de.html',
            $metadata['store']['description']['de']
        );
    }
}
