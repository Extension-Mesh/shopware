<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Service;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Infrastructure\Security\CredentialCipher;
use ExtensionMesh\Shopware\Service\RepositoryCredentialService;
use PHPUnit\Framework\TestCase;

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
}
