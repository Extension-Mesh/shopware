<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Infrastructure\Http;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Infrastructure\Http\RegistryUrlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegistryUrlNormalizerTest extends TestCase
{
    #[DataProvider('githubUrls')]
    public function testItTurnsARepositoryIntoAStableRegistryAsset(string $input, string $expected): void
    {
        self::assertSame($expected, (new RegistryUrlNormalizer())->normalize($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function githubUrls(): iterable
    {
        $expected = 'https://raw.githubusercontent.com/acme/shopware-plugin/'
            . 'extension-mesh-registry/extension-mesh-registry.json';

        yield 'repository' => ['https://github.com/acme/shopware-plugin', $expected];
        yield 'trailing slash' => ['https://github.com/acme/shopware-plugin/', $expected];
        yield 'git suffix' => ['https://github.com/acme/shopware-plugin.git', $expected];
    }

    public function testItKeepsAnExplicitRegistryEndpoint(): void
    {
        $url = 'https://plugins.example.test/extension-mesh/registry.json';

        self::assertSame($url, (new RegistryUrlNormalizer())->normalize($url));
    }

    public function testItRejectsCredentials(): void
    {
        $this->expectException(ExtensionMeshException::class);

        (new RegistryUrlNormalizer())->normalize('https://user:secret@example.test/registry.json');
    }

    public function testItRejectsAUrlThatCannotFitThePersistentNormalizedColumn(): void
    {
        $this->expectException(ExtensionMeshException::class);

        (new RegistryUrlNormalizer())->normalize('https://example.test/' . \str_repeat('a', 500));
    }
}
