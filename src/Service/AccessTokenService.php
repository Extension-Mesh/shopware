<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Infrastructure\Persistence\AccessTokenRepository;
use ExtensionMesh\Shopware\Infrastructure\Persistence\EntitlementRepository;
use Shopware\Core\Framework\Uuid\Uuid;

final class AccessTokenService
{
    private const PREFIX = 'em1';

    public function __construct(
        private readonly AccessTokenRepository $tokens,
        private readonly EntitlementRepository $entitlements,
        private readonly string $appSecret
    ) {
    }

    public function getOrCreate(string $customerId, string $salesChannelId): ?string
    {
        if ($this->entitlements->entitledProductIds($customerId, $salesChannelId) === []) {
            return null;
        }

        $token = $this->tokens->activeForCustomer($customerId, $salesChannelId)
            ?? $this->tokens->create($customerId, $salesChannelId);

        return $this->encode($token['id']);
    }

    public function rotate(string $customerId, string $salesChannelId): ?string
    {
        $this->tokens->revokeForCustomer($customerId, $salesChannelId);

        return $this->getOrCreate($customerId, $salesChannelId);
    }

    /**
     * @return array{id: string, customerId: string, salesChannelId: string}
     */
    public function authenticate(?string $authorization): array
    {
        if (!\is_string($authorization) || !\preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches)) {
            throw ExtensionMeshException::accessDenied('a bearer access token is required.');
        }

        $parts = \explode('.', $matches[1]);
        if (\count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            throw ExtensionMeshException::accessDenied('the access token format is invalid.');
        }

        try {
            $idBytes = \sodium_base642bin($parts[1], \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
            $signature = \sodium_base642bin($parts[2], \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\SodiumException) {
            throw ExtensionMeshException::accessDenied('the access token format is invalid.');
        }
        if (\strlen($idBytes) !== 16 || \strlen($signature) !== 32) {
            throw ExtensionMeshException::accessDenied('the access token format is invalid.');
        }

        $expected = $this->signature($idBytes);
        if (!\hash_equals($expected, $signature)) {
            throw ExtensionMeshException::accessDenied('the access token signature is invalid.');
        }

        $id = Uuid::fromBytesToHex($idBytes);
        $token = $this->tokens->activeById($id);
        if ($token === null) {
            throw ExtensionMeshException::accessDenied('the access token is revoked or unknown.');
        }

        $this->tokens->touch($id);

        return $token;
    }

    private function encode(string $id): string
    {
        $idBytes = Uuid::fromHexToBytes($id);

        return self::PREFIX
            . '.' . \sodium_bin2base64($idBytes, \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)
            . '.' . \sodium_bin2base64($this->signature($idBytes), \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    private function signature(string $idBytes): string
    {
        return \hash_hmac('sha256', "extension-mesh-access-v1\0" . $idBytes, $this->appSecret, true);
    }
}
