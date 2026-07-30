<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Infrastructure\Security\CredentialCipher;

final class RepositoryCredentialService
{
    public function __construct(private readonly CredentialCipher $cipher)
    {
    }

    /**
     * @param array<string, mixed> $connection
     */
    public function resolve(array $connection): string
    {
        $ciphertext = $connection['credentialCiphertext'] ?? null;
        $accessToken = $this->cipher->decrypt(\is_string($ciphertext) ? $ciphertext : null);
        if ($accessToken === null) {
            if (($connection['private'] ?? true) === false) {
                return '';
            }
            throw ExtensionMeshException::invalidRepository(
                'the repository credential is missing.'
            );
        }
        return $accessToken;
    }

    /**
     * Pending connections have not been inspected yet, so an absent credential
     * means "try anonymous access" rather than "known private repository".
     *
     * @param array<string, mixed> $connection
     */
    public function resolveForInspection(array $connection): string
    {
        $ciphertext = $connection['credentialCiphertext'] ?? null;

        return $this->cipher->decrypt(\is_string($ciphertext) ? $ciphertext : null) ?? '';
    }
}
