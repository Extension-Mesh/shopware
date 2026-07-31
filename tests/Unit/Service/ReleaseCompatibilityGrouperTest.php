<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Service;

use ExtensionMesh\Shopware\Service\ReleaseCompatibilityGrouper;
use PHPUnit\Framework\TestCase;

final class ReleaseCompatibilityGrouperTest extends TestCase
{
    public function testItGroupsCompatibilityAndSortsGroupsAndVersionsNewestFirst(): void
    {
        $groups = (new ReleaseCompatibilityGrouper())->group([
            $this->release('1.2.0', '^6.6'),
            $this->release('2.0.0', '~6.7.0'),
            $this->release('1.10.0', '^6.6'),
        ]);

        self::assertSame(['~6.7.0', '^6.6'], \array_column($groups, 'shopware'));
        self::assertSame(
            ['1.10.0', '1.2.0'],
            \array_column($groups[1]['releases'], 'version')
        );
    }

    public function testItSortsFilterOptionsNewestFirst(): void
    {
        self::assertSame(
            ['~6.7.0', '^6.6', 'legacy'],
            (new ReleaseCompatibilityGrouper())->sortConstraints([
                'legacy',
                '^6.6',
                '~6.7.0',
            ])
        );
    }

    /** @return array<string, mixed> */
    private function release(string $version, string $shopware): array
    {
        return [
            'version' => $version,
            'metadata' => ['shopware' => $shopware],
        ];
    }
}
