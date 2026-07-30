<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use Shopware\Core\Framework\Uuid\Uuid;

final class RegistrySourceRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<array{
     *     id: string,
     *     url: string,
     *     normalizedUrl: string,
     *     label: ?string,
     *     enabled: bool,
     *     credentialCiphertext: ?string,
     *     credentialFingerprint: ?string,
     *     cachedRegistry: ?string,
     *     lastRefreshedAt: ?string,
     *     lastError: ?string
     * }>
     */
    public function all(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM `extension_mesh_registry_source` ORDER BY `created_at` ASC'
        );

        return \array_map($this->hydrate(...), $rows);
    }

    /**
     * @return array{
     *     id: string,
     *     url: string,
     *     normalizedUrl: string,
     *     label: ?string,
     *     enabled: bool,
     *     credentialCiphertext: ?string,
     *     credentialFingerprint: ?string,
     *     cachedRegistry: ?string,
     *     lastRefreshedAt: ?string,
     *     lastError: ?string
     * }
     */
    public function get(string $id): array
    {
        if (!Uuid::isValid($id)) {
            throw ExtensionMeshException::sourceNotFound($id);
        }

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM `extension_mesh_registry_source` WHERE `id` = :id',
            ['id' => Uuid::fromHexToBytes($id)]
        );

        if ($row === false) {
            throw ExtensionMeshException::sourceNotFound($id);
        }

        return $this->hydrate($row);
    }

    public function add(
        string $url,
        string $normalizedUrl,
        string $label,
        string $registryJson,
        ?string $credentialCiphertext = null,
        ?string $credentialFingerprint = null
    ): string
    {
        $id = Uuid::randomHex();

        try {
            $this->connection->insert('extension_mesh_registry_source', [
                'id' => Uuid::fromHexToBytes($id),
                'url' => $url,
                'normalized_url' => $normalizedUrl,
                'label' => $label,
                'enabled' => 1,
                'credential_ciphertext' => $credentialCiphertext,
                'credential_fingerprint' => $credentialFingerprint,
                'cached_registry' => $registryJson,
                'last_refreshed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
                'last_error' => null,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ExtensionMeshException::invalidRegistryUrl('this registry has already been added.');
        }

        return $id;
    }

    public function remove(string $id): void
    {
        if (!Uuid::isValid($id)) {
            throw ExtensionMeshException::sourceNotFound($id);
        }

        $affected = $this->connection->delete(
            'extension_mesh_registry_source',
            ['id' => Uuid::fromHexToBytes($id)]
        );

        if ($affected === 0) {
            throw ExtensionMeshException::sourceNotFound($id);
        }
    }

    public function updateCache(string $id, string $label, string $registryJson): void
    {
        $this->connection->update('extension_mesh_registry_source', [
            'label' => $label,
            'cached_registry' => $registryJson,
            'last_refreshed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
            'last_error' => null,
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ], [
            'id' => Uuid::fromHexToBytes($id),
        ]);
    }

    public function updateCredential(
        string $id,
        ?string $credentialCiphertext,
        ?string $credentialFingerprint,
        string $label,
        string $registryJson
    ): void {
        if (!Uuid::isValid($id)) {
            throw ExtensionMeshException::sourceNotFound($id);
        }

        $affected = $this->connection->update('extension_mesh_registry_source', [
            'credential_ciphertext' => $credentialCiphertext,
            'credential_fingerprint' => $credentialFingerprint,
            'label' => $label,
            'cached_registry' => $registryJson,
            'last_refreshed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
            'last_error' => null,
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ], [
            'id' => Uuid::fromHexToBytes($id),
        ]);

        if ($affected === 0) {
            throw ExtensionMeshException::sourceNotFound($id);
        }
    }

    public function recordError(string $id, string $message): void
    {
        $this->connection->update('extension_mesh_registry_source', [
            'last_error' => \mb_substr($message, 0, 65535),
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ], [
            'id' => Uuid::fromHexToBytes($id),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{
     *     id: string,
     *     url: string,
     *     normalizedUrl: string,
     *     label: ?string,
     *     enabled: bool,
     *     credentialCiphertext: ?string,
     *     credentialFingerprint: ?string,
     *     cachedRegistry: ?string,
     *     lastRefreshedAt: ?string,
     *     lastError: ?string
     * }
     */
    private function hydrate(array $row): array
    {
        $binaryId = $row['id'] ?? null;
        if (!\is_string($binaryId)) {
            throw new \LogicException('Registry source has no binary identifier.');
        }

        return [
            'id' => Uuid::fromBytesToHex($binaryId),
            'url' => (string) $row['url'],
            'normalizedUrl' => (string) $row['normalized_url'],
            'label' => \is_string($row['label'] ?? null) ? $row['label'] : null,
            'enabled' => (bool) $row['enabled'],
            'credentialCiphertext' => \is_string($row['credential_ciphertext'] ?? null)
                ? $row['credential_ciphertext']
                : null,
            'credentialFingerprint' => \is_string($row['credential_fingerprint'] ?? null)
                ? $row['credential_fingerprint']
                : null,
            'cachedRegistry' => \is_string($row['cached_registry'] ?? null) ? $row['cached_registry'] : null,
            'lastRefreshedAt' => \is_string($row['last_refreshed_at'] ?? null) ? $row['last_refreshed_at'] : null,
            'lastError' => \is_string($row['last_error'] ?? null) ? $row['last_error'] : null,
        ];
    }
}
