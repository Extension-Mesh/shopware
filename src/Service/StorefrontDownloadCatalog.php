<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use ExtensionMesh\Shopware\Infrastructure\Persistence\PublicationRepository;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItemDownload\OrderLineItemDownloadEntity;

final class StorefrontDownloadCatalog
{
    /** @var array<string, array<string, mixed>> */
    private array $releasesByMediaId = [];

    /** @var array<string, true> */
    private array $resolvedMediaIds = [];

    public function __construct(private readonly PublicationRepository $releases)
    {
    }

    /**
     * @param iterable<OrderLineItemDownloadEntity> $downloads
     *
     * @return array{
     *     groups: list<array{
     *         shopware: string,
     *         releases: list<array{download: OrderLineItemDownloadEntity, release: array<string, mixed>}>
     *     }>,
     *     normal: list<OrderLineItemDownloadEntity>
     * }
     */
    public function group(iterable $downloads): array
    {
        $items = \is_array($downloads) ? $downloads : \iterator_to_array($downloads, false);
        $unresolved = [];
        foreach ($items as $download) {
            $mediaId = $download->getMediaId();
            if (!isset($this->resolvedMediaIds[$mediaId])) {
                $unresolved[] = $mediaId;
                $this->resolvedMediaIds[$mediaId] = true;
            }
        }
        if ($unresolved !== []) {
            $this->releasesByMediaId += $this->releases->byMediaIds(\array_values(\array_unique($unresolved)));
        }

        $normal = [];
        $groups = [];
        foreach ($items as $download) {
            $release = $this->releasesByMediaId[$download->getMediaId()] ?? null;
            $shopware = $release['metadata']['shopware'] ?? null;
            if (!\is_array($release) || !\is_string($shopware) || $shopware === '') {
                $normal[] = $download;
                continue;
            }
            $groups[$shopware][] = ['download' => $download, 'release' => $release];
        }

        \uksort($groups, $this->compareShopwareConstraints(...));
        $result = [];
        foreach ($groups as $shopware => $releases) {
            $releasesByVersion = [];
            foreach ($releases as $release) {
                $version = (string) ($release['release']['version'] ?? '');
                $existingNotes = $releasesByVersion[$version]['release']['metadata']['releaseNotes'] ?? null;
                $candidateNotes = $release['release']['metadata']['releaseNotes'] ?? null;
                if (
                    !isset($releasesByVersion[$version])
                    || (
                        (!\is_string($existingNotes) || $existingNotes === '')
                        && \is_string($candidateNotes)
                        && $candidateNotes !== ''
                    )
                ) {
                    $releasesByVersion[$version] = $release;
                }
            }
            $releases = \array_values($releasesByVersion);
            \usort($releases, static function (array $left, array $right): int {
                $leftVersion = \ltrim((string) ($left['release']['version'] ?? ''), 'vV');
                $rightVersion = \ltrim((string) ($right['release']['version'] ?? ''), 'vV');

                return \version_compare($rightVersion, $leftVersion);
            });
            $result[] = ['shopware' => $shopware, 'releases' => $releases];
        }

        return ['groups' => $result, 'normal' => $normal];
    }

    private function compareShopwareConstraints(string $left, string $right): int
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
