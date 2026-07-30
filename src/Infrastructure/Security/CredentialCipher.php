<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Infrastructure\Security;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;

final class CredentialCipher
{
    private const PREFIX = 'v1:';

    private readonly string $key;

    public function __construct(string $appSecret)
    {
        if ($appSecret === '') {
            throw new \LogicException('APP_SECRET must not be empty.');
        }

        $this->key = \hash_hkdf('sha256', $appSecret, \SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'extension-mesh-registry-credentials-v1');
    }

    public function encrypt(string $credential): string
    {
        $credential = \trim($credential);
        if ($credential === '' || \strlen($credential) > 1024) {
            throw ExtensionMeshException::invalidCredential('it must contain between 1 and 1024 bytes.');
        }

        $nonce = \random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = \sodium_crypto_secretbox($credential, $nonce, $this->key);

        return self::PREFIX . \sodium_bin2base64(
            $nonce . $ciphertext,
            \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING
        );
    }

    public function decrypt(?string $encrypted): ?string
    {
        if ($encrypted === null) {
            return null;
        }
        if (!\str_starts_with($encrypted, self::PREFIX)) {
            throw ExtensionMeshException::invalidCredential('the stored credential format is not supported.');
        }

        try {
            $payload = \sodium_base642bin(
                \substr($encrypted, \strlen(self::PREFIX)),
                \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING
            );
        } catch (\SodiumException) {
            throw ExtensionMeshException::invalidCredential('the stored credential is corrupted.');
        }

        $nonce = \substr($payload, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = \substr($payload, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        if (\strlen($nonce) !== \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || $ciphertext === '') {
            throw ExtensionMeshException::invalidCredential('the stored credential is corrupted.');
        }

        $plaintext = \sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        if ($plaintext === false) {
            throw ExtensionMeshException::invalidCredential('it can no longer be decrypted with this APP_SECRET.');
        }

        return $plaintext;
    }

    public function fingerprint(string $credential): string
    {
        return \substr(\hash('sha256', $credential), 0, 12);
    }
}
