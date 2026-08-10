<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Service;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Infrastructure\Security\CredentialCipher;
use ExtensionMesh\Shopware\Service\RepositoryCredentialService;
use ExtensionMesh\Shopware\Service\RepositoryCredentialStore;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;

final class RepositoryCredentialServiceTest extends TestCase
{
    public function testItDecryptsTheLocallyStoredRepositoryToken(): void
    {
        $cipher = new CredentialCipher('shopware-test-secret');
        $credentials = new RepositoryCredentialService($cipher);

        self::assertSame('github_pat_read_only', $credentials->resolve([
            'credentialCiphertext' => $cipher->encrypt('github_pat_read_only'),
        ]));
    }

    public function testItRejectsAMissingRepositoryToken(): void
    {
        $credentials = new RepositoryCredentialService(
            new CredentialCipher('shopware-test-secret')
        );

        $this->expectException(ExtensionMeshException::class);
        $this->expectExceptionMessage('the repository credential is missing');
        $credentials->resolve([]);
    }

    public function testItUsesAnonymousAccessForAPublicRepository(): void
    {
        $credentials = new RepositoryCredentialService(
            new CredentialCipher('shopware-test-secret')
        );

        self::assertSame('', $credentials->resolve([
            'private' => false,
            'credentialCiphertext' => null,
        ]));
    }

    public function testItSelectsAReusableCredentialForTheSameProviderOrigin(): void
    {
        $store = $this->store([
            'id' => 'credential-id',
            'provider' => 'github',
            'apiBaseUrl' => 'https://api.github.com',
            'credentialCiphertext' => 'unused',
            'credentialFingerprint' => 'abc123',
        ]);
        $credentials = new RepositoryCredentialService(
            new CredentialCipher('shopware-test-secret'),
            $store
        );

        self::assertSame('credential-id', $credentials->select(
            'github',
            'https://api.github.com',
            'credential-id',
            '',
            Context::createCLIContext()
        ));
    }

    public function testItDoesNotSendAReusableCredentialToAnotherOrigin(): void
    {
        $credentials = new RepositoryCredentialService(
            new CredentialCipher('shopware-test-secret'),
            $this->store([
                'id' => 'credential-id',
                'provider' => 'github',
                'apiBaseUrl' => 'https://github.example/api/v3',
                'credentialCiphertext' => 'unused',
                'credentialFingerprint' => 'abc123',
            ])
        );

        $this->expectException(ExtensionMeshException::class);
        $this->expectExceptionMessage('not available for this repository origin');
        $credentials->select(
            'github',
            'https://api.github.com',
            'credential-id',
            '',
            Context::createCLIContext()
        );
    }

    /** @param array<string, mixed>|null $credential */
    private function store(?array $credential): RepositoryCredentialStore
    {
        return new class($credential) implements RepositoryCredentialStore {
            /** @param array<string, mixed>|null $credential */
            public function __construct(private readonly ?array $credential)
            {
            }

            public function get(string $id, Context $context): ?array
            {
                return ($this->credential['id'] ?? null) === $id ? $this->credential : null;
            }

            public function all(Context $context): array
            {
                return $this->credential === null ? [] : [$this->credential];
            }

            public function findId(
                string $provider,
                string $apiBaseUrl,
                string $fingerprint,
                Context $context
            ): ?string {
                return null;
            }

            public function create(
                string $provider,
                string $apiBaseUrl,
                string $ciphertext,
                string $fingerprint,
                Context $context
            ): string {
                return 'created-credential-id';
            }

            public function update(
                string $id,
                string $ciphertext,
                string $fingerprint,
                Context $context
            ): void {
            }

            public function delete(string $id, Context $context): void
            {
            }
        };
    }
}
