<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Infrastructure\Persistence\EntitlementRepository;
use ExtensionMesh\Shopware\Infrastructure\Persistence\PublicationRepository;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class PublisherCatalogService
{
    public function __construct(
        private readonly PublicationSynchronizer $synchronizer,
        private readonly PublicationRepository $releases,
        private readonly EntitlementRepository $entitlements,
        private readonly SystemConfigService $systemConfig
    ) {
    }

    /**
     * @return array{schemaVersion: 1, name: string, extensions: list<array<string, mixed>>}
     */
    public function forCustomer(
        string $customerId,
        string $salesChannelId,
        string $artifactUrlTemplate,
        Context $context
    ): array {
        $this->synchronizer->synchronize($context);
        $productIds = $this->entitlements->entitledProductIds($customerId, $salesChannelId, $context);
        $published = $this->releases->validForProducts($productIds, $context);

        /** @var array<string, array<string, mixed>> $extensions */
        $extensions = [];
        foreach ($published as $release) {
            $metadata = $release['metadata'] ?? null;
            if (!\is_array($metadata) || !\is_string($release['technicalName'] ?? null)) {
                continue;
            }

            $name = $release['technicalName'];
            if (!isset($extensions[$name])) {
                $extensions[$name] = [
                    'name' => $name,
                    'label' => $metadata['label'],
                    'description' => $metadata['description'],
                    'manufacturer' => $metadata['manufacturer'],
                    'license' => $metadata['license'],
                    'homepage' => $metadata['homepage'],
                    'releases' => [],
                    '_products' => [],
                    '_versions' => [],
                ];
            }

            if (
                isset($extensions[$name]['_versions'][$release['version']])
                && $extensions[$name]['_versions'][$release['version']] !== $release['productId']
            ) {
                throw ExtensionMeshException::invalidRegistry(
                    \sprintf('extension "%s" version "%s" is attached to multiple products.', $name, $release['version'])
                );
            }

            $extensions[$name]['_products'][$release['productId']] = true;
            $extensions[$name]['_versions'][$release['version']] = $release['productId'];
            $extensions[$name]['releases'][] = [
                'version' => $metadata['version'],
                'shopware' => $metadata['shopware'],
                'php' => $metadata['php'],
                'downloadUrl' => \str_replace('{releaseId}', $release['id'], $artifactUrlTemplate),
                'sha256' => $release['sha256'],
                'releasedAt' => $release['releasedAt'],
                'security' => false,
                'changelogUrl' => null,
            ];
        }

        foreach ($extensions as &$extension) {
            \usort(
                $extension['releases'],
                static fn (array $left, array $right): int => \version_compare($right['version'], $left['version'])
            );
            unset($extension['_products'], $extension['_versions']);
        }
        unset($extension);

        $name = $this->systemConfig->getString('core.basicInformation.shopName', $salesChannelId);

        return [
            'schemaVersion' => 1,
            'name' => $name !== '' ? $name . ' extensions' : 'ExtensionMesh seller registry',
            'extensions' => \array_values($extensions),
        ];
    }

}
