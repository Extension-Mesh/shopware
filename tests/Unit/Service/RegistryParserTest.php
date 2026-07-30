<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Service;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Service\RegistryParser;
use PHPUnit\Framework\TestCase;

final class RegistryParserTest extends TestCase
{
    public function testItSelectsTheNewestCompatibleRelease(): void
    {
        $parser = new RegistryParser();
        $registry = $parser->parse($this->registryJson([
            $this->release('2.0.0', '^6.8'),
            $this->release('1.2.0', '~6.7.0'),
            $this->release('1.1.0', '~6.7.0'),
        ]));

        $release = $parser->newestCompatibleRelease(
            $registry['extensions'][0]['releases'],
            '6.7.12.1',
            '8.3.0'
        );

        self::assertNotNull($release);
        self::assertSame('1.2.0', $release['version']);
    }

    public function testItRejectsDuplicateTechnicalNames(): void
    {
        $document = \json_decode($this->registryJson([$this->release('1.0.0', '~6.7.0')]), true);
        self::assertIsArray($document);
        $document['extensions'][] = $document['extensions'][0];

        $this->expectException(ExtensionMeshException::class);
        (new RegistryParser())->parse((string) \json_encode($document));
    }

    public function testItRejectsInvalidCompatibilityConstraints(): void
    {
        $this->expectException(ExtensionMeshException::class);
        (new RegistryParser())->parse($this->registryJson([
            $this->release('1.0.0', 'definitely not a constraint'),
        ]));
    }

    public function testItRejectsNonRfc3339ReleaseDates(): void
    {
        $release = $this->release('1.0.0', '~6.7.0');
        $release['releasedAt'] = 'next Tuesday';

        $this->expectException(ExtensionMeshException::class);
        (new RegistryParser())->parse($this->registryJson([$release]));
    }

    public function testItRejectsAnImpossibleCalendarDate(): void
    {
        $release = $this->release('1.0.0', '~6.7.0');
        $release['releasedAt'] = '2026-02-31T12:00:00+00:00';

        $this->expectException(ExtensionMeshException::class);
        (new RegistryParser())->parse($this->registryJson([$release]));
    }

    public function testItRejectsUnknownFieldsAndWrongBooleanTypes(): void
    {
        $release = $this->release('1.0.0', '~6.7.0');
        $release['security'] = 'yes';

        $this->expectException(ExtensionMeshException::class);
        (new RegistryParser())->parse($this->registryJson([$release]));
    }

    /**
     * @param list<array<string, mixed>> $releases
     */
    private function registryJson(array $releases): string
    {
        return (string) \json_encode([
            'schemaVersion' => 1,
            'name' => 'Test registry',
            'extensions' => [[
                'name' => 'AcmeDemoPlugin',
                'label' => ['en-GB' => 'Demo'],
                'releases' => $releases,
            ]],
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function release(string $version, string $shopware): array
    {
        return [
            'version' => $version,
            'shopware' => $shopware,
            'php' => '>=8.2',
            'downloadUrl' => 'https://example.test/plugin.zip',
            'sha256' => \str_repeat('a', 64),
            'releasedAt' => '2026-07-27T12:00:00+00:00',
        ];
    }
}
