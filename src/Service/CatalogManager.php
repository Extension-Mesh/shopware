<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use Shopware\Core\Framework\Context;

interface CatalogManager
{
    /** @return array{id: string, created: bool, credentialUpdated: bool} */
    public function addSourceIdempotently(string $inputUrl, ?string $accessToken, Context $context): array;

    /**
     * @return list<array{id: string, url: string, label: ?string, success: bool, error: ?string}>
     */
    public function refreshAll(Context $context): array;

    /**
     * @return array{
     *     extensions: list<array<string, mixed>>,
     *     warnings: list<array{registryId: string, message: string}>
     * }
     */
    public function catalog(
        string $shopwareVersion,
        string $phpVersion,
        string $locale,
        Context $context,
        bool $refreshStale = true
    ): array;
}
