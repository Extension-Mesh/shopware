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
                return $path === 'src/Resources/store/images/de'
                    ? [
                        ['path' => $path . '/1.png', 'size' => 100],
                        ['path' => $path . '/2.jpg', 'size' => 100],
                        ['path' => $path . '/notes.txt', 'size' => 100],
                    ]
                    : [];
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
    }
}
