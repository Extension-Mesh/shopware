<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Infrastructure\Security;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Infrastructure\Security\CredentialCipher;
use PHPUnit\Framework\TestCase;

final class CredentialCipherTest extends TestCase
{
    public function testItEncryptsAndDecryptsWithoutPersistingPlaintext(): void
    {
        $cipher = new CredentialCipher('test-app-secret');
        $encrypted = $cipher->encrypt('em1.example.secret');

        self::assertStringStartsWith('v1:', $encrypted);
        self::assertStringNotContainsString('em1.example.secret', $encrypted);
        self::assertSame('em1.example.secret', $cipher->decrypt($encrypted));
        self::assertSame(12, \strlen($cipher->fingerprint('em1.example.secret')));
    }

    public function testItRejectsCiphertextFromAnotherAppSecret(): void
    {
        $encrypted = (new CredentialCipher('first-app-secret'))->encrypt('registry-secret');

        $this->expectException(ExtensionMeshException::class);
        (new CredentialCipher('second-app-secret'))->decrypt($encrypted);
    }
}
