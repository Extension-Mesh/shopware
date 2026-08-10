<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use Shopware\Core\Framework\Context;

interface RepositoryCredentialStore
{
    /** @return array<string, mixed>|null */
    public function get(string $id, Context $context): ?array;

    /** @return list<array<string, mixed>> */
    public function all(Context $context): array;

    public function findId(string $provider, string $apiBaseUrl, string $fingerprint, Context $context): ?string;

    public function create(
        string $provider,
        string $apiBaseUrl,
        string $ciphertext,
        string $fingerprint,
        Context $context
    ): string;

    public function update(
        string $id,
        string $ciphertext,
        string $fingerprint,
        Context $context
    ): void;

    public function delete(string $id, Context $context): void;
}
