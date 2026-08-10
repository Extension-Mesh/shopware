<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Infrastructure\Security\CredentialCipher;
use Shopware\Core\Framework\Context;

final class RepositoryCredentialService
{
    public function __construct(
        private readonly CredentialCipher $cipher,
        private readonly ?RepositoryCredentialStore $repository = null
    ) {
    }

    /** @return list<array{id: string, provider: string, apiBaseUrl: string, credentialFingerprint: string, connectionCount: int}> */
    public function available(Context $context): array
    {
        $credentials = $this->credentialRepository()->all($context);

        return \array_map(static fn (array $credential): array => [
            'id' => (string) $credential['id'],
            'provider' => (string) $credential['provider'],
            'apiBaseUrl' => (string) $credential['apiBaseUrl'],
            'credentialFingerprint' => (string) $credential['credentialFingerprint'],
            'connectionCount' => (int) ($credential['connectionCount'] ?? 0),
        ], $credentials);
    }

    /** @return array<string, mixed> */
    public function get(string $credentialId, Context $context): array
    {
        return $this->credentialRepository()->get($credentialId, $context)
            ?? throw ExtensionMeshException::repositoryCredentialNotFound($credentialId);
    }

    public function findIdForToken(
        string $provider,
        string $apiBaseUrl,
        string $accessToken,
        Context $context
    ): ?string {
        return $this->credentialRepository()->findId(
            $provider,
            $apiBaseUrl,
            $this->cipher->fingerprint($accessToken),
            $context
        );
    }

    public function update(string $credentialId, string $accessToken, Context $context): void
    {
        $this->credentialRepository()->update(
            $credentialId,
            $this->cipher->encrypt($accessToken),
            $this->cipher->fingerprint($accessToken),
            $context
        );
    }

    public function delete(string $credentialId, Context $context): void
    {
        $this->credentialRepository()->delete($credentialId, $context);
    }

    public function select(
        string $provider,
        string $apiBaseUrl,
        ?string $credentialId,
        string $accessToken,
        Context $context
    ): ?string {
        if ($accessToken !== '') {
            $fingerprint = $this->cipher->fingerprint($accessToken);
            $repository = $this->credentialRepository();

            return $repository->findId($provider, $apiBaseUrl, $fingerprint, $context)
                ?? $repository->create(
                    $provider,
                    $apiBaseUrl,
                    $this->cipher->encrypt($accessToken),
                    $fingerprint,
                    $context
                );
        }
        if ($credentialId === null || $credentialId === '') {
            return null;
        }

        $credential = $this->credentialRepository()->get($credentialId, $context);
        if (
            $credential === null
            || $credential['provider'] !== $provider
            || $credential['apiBaseUrl'] !== $apiBaseUrl
        ) {
            throw ExtensionMeshException::invalidRepository(
                'the selected provider credential is not available for this repository origin.'
            );
        }

        return $credentialId;
    }

    public function resolveId(string $credentialId, Context $context): string
    {
        $credential = $this->credentialRepository()->get($credentialId, $context);
        if ($credential === null) {
            throw ExtensionMeshException::invalidRepository(
                'the selected provider credential is unavailable.'
            );
        }

        return $this->cipher->decrypt((string) $credential['credentialCiphertext'])
            ?? throw ExtensionMeshException::invalidRepository(
                'the selected provider credential is unavailable.'
            );
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

    private function credentialRepository(): RepositoryCredentialStore
    {
        return $this->repository
            ?? throw new \LogicException('The repository credential store is unavailable.');
    }
}
