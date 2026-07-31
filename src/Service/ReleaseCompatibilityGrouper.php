<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

final class ReleaseCompatibilityGrouper
{
    /**
     * @param list<array<string, mixed>> $releases
     *
     * @return list<array{shopware: string, releases: list<array<string, mixed>>}>
     */
    public function group(array $releases): array
    {
        $groups = [];
        foreach ($releases as $release) {
            $metadata = $release['metadata'] ?? null;
            $shopware = \is_array($metadata) ? ($metadata['shopware'] ?? null) : null;
            if (!\is_string($shopware) || $shopware === '') {
                continue;
            }
            $groups[$shopware][] = $release;
        }

        \uksort($groups, $this->compareConstraints(...));
        $result = [];
        foreach ($groups as $shopware => $groupedReleases) {
            \usort($groupedReleases, static function (array $left, array $right): int {
                $leftVersion = \ltrim((string) ($left['version'] ?? ''), 'vV');
                $rightVersion = \ltrim((string) ($right['version'] ?? ''), 'vV');

                return \version_compare($rightVersion, $leftVersion);
            });
            $result[] = ['shopware' => $shopware, 'releases' => $groupedReleases];
        }

        return $result;
    }

    /**
     * @param list<string> $constraints
     *
     * @return list<string>
     */
    public function sortConstraints(array $constraints): array
    {
        \usort($constraints, $this->compareConstraints(...));

        return $constraints;
    }

    private function compareConstraints(string $left, string $right): int
    {
        $leftVersion = $this->constraintVersion($left);
        $rightVersion = $this->constraintVersion($right);
        if ($leftVersion !== null && $rightVersion !== null) {
            $comparison = \version_compare($rightVersion, $leftVersion);
            if ($comparison !== 0) {
                return $comparison;
            }
        } elseif ($leftVersion !== null) {
            return -1;
        } elseif ($rightVersion !== null) {
            return 1;
        }

        return \strnatcasecmp($right, $left);
    }

    private function constraintVersion(string $constraint): ?string
    {
        if (!\preg_match('/(?<!\d)(\d+(?:\.\d+){0,3})/D', $constraint, $match)) {
            return null;
        }

        $parts = \explode('.', $match[1]);
        while (\count($parts) < 4) {
            $parts[] = '0';
        }

        return \implode('.', $parts);
    }
}
