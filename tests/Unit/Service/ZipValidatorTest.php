<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Service;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Service\PluginArchiveInspector;
use ExtensionMesh\Shopware\Service\ZipValidator;
use PHPUnit\Framework\TestCase;

final class ZipValidatorTest extends TestCase
{
    private ?string $path = null;

    protected function tearDown(): void
    {
        if ($this->path !== null && \is_file($this->path)) {
            \unlink($this->path);
        }
    }

    public function testItAcceptsAConventionalShopwarePluginZip(): void
    {
        $this->path = $this->createZip([
            'AcmeDemoPlugin/composer.json' => $this->composerJson(),
            'AcmeDemoPlugin/src/AcmeDemoPlugin.php' => '<?php',
        ]);

        (new ZipValidator())->validate($this->path, 'AcmeDemoPlugin', '1.1.0');
        self::addToAssertionCount(1);
    }

    public function testItRejectsPathTraversal(): void
    {
        $this->path = $this->createZip([
            'AcmeDemoPlugin/composer.json' => $this->composerJson(),
            'AcmeDemoPlugin/../outside.php' => '<?php',
        ]);

        $this->expectException(ExtensionMeshException::class);
        (new ZipValidator())->validate($this->path, 'AcmeDemoPlugin', '1.1.0');
    }

    public function testItRejectsAClassAndRootMismatch(): void
    {
        $this->path = $this->createZip([
            'WrongPlugin/composer.json' => $this->composerJson(),
        ]);

        $this->expectException(ExtensionMeshException::class);
        (new ZipValidator())->validate($this->path, 'WrongPlugin', '1.1.0');
    }

    public function testItRejectsAnArtifactWithoutAnExplicitReleaseVersion(): void
    {
        $composer = \json_decode($this->composerJson(), true, 128, \JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        unset($composer['version']);
        $this->path = $this->createZip([
            'AcmeDemoPlugin/composer.json' => (string) \json_encode($composer, \JSON_THROW_ON_ERROR),
        ]);

        $this->expectException(ExtensionMeshException::class);
        (new ZipValidator())->validate($this->path, 'AcmeDemoPlugin', '1.1.0');
    }

    public function testInspectorDerivesPublicationMetadataFromThePluginZip(): void
    {
        $composer = \json_decode($this->composerJson(), true, 128, \JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        $composer['description'] = 'Paid fixture';
        $composer['license'] = 'MIT';
        $composer['homepage'] = 'https://example.com/plugin';
        $composer['authors'] = [['name' => 'Example seller']];
        $composer['require'] = [
            'php' => '>=8.2',
            'shopware/core' => '~6.7.0',
        ];
        $this->path = $this->createZip([
            'AcmeDemoPlugin/composer.json' => (string) \json_encode($composer, \JSON_THROW_ON_ERROR),
            'AcmeDemoPlugin/src/AcmeDemoPlugin.php' => '<?php',
        ]);

        $metadata = (new PluginArchiveInspector(new ZipValidator()))->inspect($this->path);

        self::assertSame('AcmeDemoPlugin', $metadata['name']);
        self::assertSame('1.1.0', $metadata['version']);
        self::assertSame('~6.7.0', $metadata['shopware']);
        self::assertSame('>=8.2', $metadata['php']);
        self::assertSame('Example seller', $metadata['manufacturer']);
        self::assertSame(['en-GB' => 'Paid fixture'], $metadata['description']);
    }

    public function testInspectorRejectsAPluginWithoutAShopwareCoreRequirement(): void
    {
        $composer = \json_decode($this->composerJson(), true, 128, \JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        $composer['require'] = ['php' => '>=8.2'];
        $this->path = $this->createZip([
            'AcmeDemoPlugin/composer.json' => (string) \json_encode($composer, \JSON_THROW_ON_ERROR),
        ]);

        $this->expectException(ExtensionMeshException::class);
        (new PluginArchiveInspector(new ZipValidator()))->inspect($this->path);
    }

    /**
     * @param array<string, string> $files
     */
    private function createZip(array $files): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'extension-mesh-test-');
        self::assertIsString($path);

        $archive = new \ZipArchive();
        self::assertTrue($archive->open($path, \ZipArchive::OVERWRITE) === true);
        foreach ($files as $name => $contents) {
            self::assertTrue($archive->addFromString($name, $contents));
        }
        self::assertTrue($archive->close());

        return $path;
    }

    private function composerJson(): string
    {
        return (string) \json_encode([
            'name' => 'extension-mesh/acme-demo-plugin',
            'type' => 'shopware-platform-plugin',
            'version' => '1.1.0',
            'extra' => [
                'shopware-plugin-class' => 'ExtensionMesh\\Fixture\\AcmeDemoPlugin',
            ],
        ], \JSON_THROW_ON_ERROR);
    }
}
