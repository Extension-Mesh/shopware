<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Framework\Uuid\Uuid;

final class RepositoryConnectionRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(bool $enabledOnly = false): array
    {
        $sql = 'SELECT * FROM extension_mesh_repository_connection';
        if ($enabledOnly) {
            $sql .= ' WHERE enabled = 1';
        }
        $sql .= ' ORDER BY created_at';

        return \array_map($this->hydrate(...), $this->connection->fetchAllAssociative($sql));
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     limit: int
     * }
     */
    public function paginate(int $page, int $limit): array
    {
        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM extension_mesh_repository_connection'
        );
        $page = \min($page, \max(1, (int) \ceil($total / $limit)));
        $rows = $this->connection->fetchAllAssociative(
            'SELECT *
               FROM extension_mesh_repository_connection
              ORDER BY created_at, id
              LIMIT :limit OFFSET :offset',
            [
                'limit' => $limit,
                'offset' => ($page - 1) * $limit,
            ],
            [
                'limit' => ParameterType::INTEGER,
                'offset' => ParameterType::INTEGER,
            ]
        );

        return [
            'items' => \array_map($this->hydrate(...), $rows),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM extension_mesh_repository_connection WHERE id = :id',
            ['id' => Uuid::fromHexToBytes($id)]
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function create(
        string $provider,
        string $repository,
        string $apiBaseUrl,
        string $webUrl,
        string $defaultBranch,
        bool $private,
        ?string $credentialCiphertext,
        ?string $credentialFingerprint,
        ?string $productId,
        ?string $technicalName,
        ?string $configPath
    ): string {
        $id = Uuid::randomHex();
        $this->connection->insert('extension_mesh_repository_connection', [
            'id' => Uuid::fromHexToBytes($id),
            'provider' => $provider,
            'repository' => $repository,
            'api_base_url' => $apiBaseUrl,
            'web_url' => $webUrl,
            'default_branch' => $defaultBranch,
            'repository_private' => $private ? 1 : 0,
            'credential_ciphertext' => $credentialCiphertext,
            'credential_fingerprint' => $credentialFingerprint,
            'product_id' => $productId === null ? null : Uuid::fromHexToBytes($productId),
            'technical_name' => $technicalName,
            'config_path' => $configPath,
            'enabled' => 1,
            'created_at' => $this->now(),
        ]);

        return $id;
    }

    public function createQueued(
        string $provider,
        string $repository,
        string $apiBaseUrl,
        ?string $credentialCiphertext,
        ?string $credentialFingerprint,
        ?string $productId,
        string $mode
    ): string {
        $id = Uuid::randomHex();
        $this->connection->insert('extension_mesh_repository_connection', [
            'id' => Uuid::fromHexToBytes($id),
            'provider' => $provider,
            'repository' => $repository,
            'api_base_url' => $apiBaseUrl,
            'repository_private' => $credentialCiphertext === null ? 0 : 1,
            'credential_ciphertext' => $credentialCiphertext,
            'credential_fingerprint' => $credentialFingerprint,
            'product_id' => $productId === null ? null : Uuid::fromHexToBytes($productId),
            'onboarding_mode' => $mode,
            'onboarding_status' => 'queued',
            'onboarding_stage' => 'inspect',
            'enabled' => 0,
            'created_at' => $this->now(),
        ]);

        return $id;
    }

    public function exists(
        string $provider,
        string $repository,
        string $apiBaseUrl,
        ?string $excludeId = null
    ): bool
    {
        $exclude = '';
        $parameters = [
            'provider' => $provider,
            'repository' => $repository,
            'apiBaseUrl' => $apiBaseUrl,
        ];
        if ($excludeId !== null && Uuid::isValid($excludeId)) {
            $exclude = ' AND id != :excludeId';
            $parameters['excludeId'] = Uuid::fromHexToBytes($excludeId);
        }

        return (bool) $this->connection->fetchOne(
            'SELECT 1
               FROM extension_mesh_repository_connection
              WHERE provider = :provider
                AND repository = :repository
                AND api_base_url = :apiBaseUrl'
                . $exclude,
            $parameters
        );
    }

    public function updateCredential(string $id, ?string $ciphertext, ?string $fingerprint): void
    {
        $this->connection->update(
            'extension_mesh_repository_connection',
            [
                'credential_ciphertext' => $ciphertext,
                'credential_fingerprint' => $fingerprint,
                'updated_at' => $this->now(),
            ],
            ['id' => Uuid::fromHexToBytes($id)]
        );
    }

    /**
     * @param array{
     *     repository: string,
     *     apiBaseUrl: string,
     *     webUrl: string,
     *     defaultBranch: string,
     *     private: bool
     * } $inspection
     */
    public function storeInspection(string $id, array $inspection): void
    {
        $this->connection->update(
            'extension_mesh_repository_connection',
            [
                'repository' => $inspection['repository'],
                'api_base_url' => $inspection['apiBaseUrl'],
                'web_url' => $inspection['webUrl'],
                'default_branch' => $inspection['defaultBranch'],
                'repository_private' => $inspection['private'] ? 1 : 0,
                'onboarding_status' => 'preparing',
                'onboarding_stage' => 'prepare',
                'last_error' => null,
                'updated_at' => $this->now(),
            ],
            ['id' => Uuid::fromHexToBytes($id)]
        );
    }

    public function reserveProduct(string $id, string $productId): void
    {
        $this->connection->update(
            'extension_mesh_repository_connection',
            [
                'product_id' => Uuid::fromHexToBytes($productId),
                'updated_at' => $this->now(),
            ],
            ['id' => Uuid::fromHexToBytes($id)]
        );
    }

    public function markPrepared(
        string $id,
        string $productId,
        string $technicalName,
        ?string $configPath
    ): void {
        $this->connection->update(
            'extension_mesh_repository_connection',
            [
                'product_id' => Uuid::fromHexToBytes($productId),
                'technical_name' => $technicalName,
                'config_path' => $configPath,
                'onboarding_status' => 'synchronizing',
                'onboarding_stage' => 'synchronize',
                'last_error' => null,
                'enabled' => 1,
                'updated_at' => $this->now(),
            ],
            ['id' => Uuid::fromHexToBytes($id)]
        );
    }

    public function markProcessing(string $id, string $status, ?string $stage = null): void
    {
        $this->connection->update(
            'extension_mesh_repository_connection',
            [
                'onboarding_status' => $status,
                'onboarding_stage' => $stage,
                'last_error' => null,
                'updated_at' => $this->now(),
            ],
            ['id' => Uuid::fromHexToBytes($id)]
        );
    }

    public function setProductAndMetadata(
        string $id,
        string $productId,
        string $technicalName,
        ?string $configPath
    ): void {
        $this->connection->update(
            'extension_mesh_repository_connection',
            [
                'product_id' => Uuid::fromHexToBytes($productId),
                'technical_name' => $technicalName,
                'config_path' => $configPath,
                'updated_at' => $this->now(),
            ],
            ['id' => Uuid::fromHexToBytes($id)]
        );
    }

    public function setTechnicalName(string $id, string $technicalName): void
    {
        $this->connection->update(
            'extension_mesh_repository_connection',
            ['technical_name' => $technicalName, 'updated_at' => $this->now()],
            ['id' => Uuid::fromHexToBytes($id)]
        );
    }

    public function markSynchronized(string $id): void
    {
        $this->connection->update(
            'extension_mesh_repository_connection',
            [
                'onboarding_status' => 'ready',
                'onboarding_stage' => null,
                'last_synced_at' => $this->now(),
                'last_error' => null,
                'updated_at' => $this->now(),
            ],
            ['id' => Uuid::fromHexToBytes($id)]
        );
    }

    public function markFailed(string $id, string $message): void
    {
        $this->connection->update(
            'extension_mesh_repository_connection',
            [
                'onboarding_status' => 'failed',
                'last_error' => \mb_substr($message, 0, 65535),
                'updated_at' => $this->now(),
            ],
            ['id' => Uuid::fromHexToBytes($id)]
        );
    }

    public function delete(string $id): void
    {
        if (Uuid::isValid($id)) {
            $this->connection->delete(
                'extension_mesh_repository_connection',
                ['id' => Uuid::fromHexToBytes($id)]
            );
        }
    }

    public function hasImportedAsset(string $connectionId, string $releaseId, string $assetId): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1
               FROM extension_mesh_repository_release
              WHERE connection_id = :connectionId
                AND provider_release_id = :releaseId
                AND provider_asset_id = :assetId',
            [
                'connectionId' => Uuid::fromHexToBytes($connectionId),
                'releaseId' => $releaseId,
                'assetId' => $assetId,
            ]
        );
    }

    public function hasImportedRelease(string $connectionId, string $releaseId): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1
               FROM extension_mesh_repository_release
              WHERE connection_id = :connectionId
                AND provider_release_id = :releaseId
              LIMIT 1',
            [
                'connectionId' => Uuid::fromHexToBytes($connectionId),
                'releaseId' => $releaseId,
            ]
        );
    }

    public function hasImportedVersion(string $connectionId, string $version): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1
               FROM extension_mesh_repository_release
              WHERE connection_id = :connectionId
                AND version = :version
              LIMIT 1',
            [
                'connectionId' => Uuid::fromHexToBytes($connectionId),
                'version' => $version,
            ]
        );
    }

    public function recordImportedAsset(
        string $connectionId,
        string $releaseId,
        string $assetId,
        string $tag,
        string $assetName,
        string $version,
        ?string $releaseNotes,
        string $sha256,
        string $mediaId,
        string $productDownloadId,
        string $releasedAt
    ): void {
        $date = (new \DateTimeImmutable($releasedAt))->format('Y-m-d H:i:s.v');
        $this->connection->insert('extension_mesh_repository_release', [
            'id' => Uuid::fromHexToBytes(Uuid::randomHex()),
            'connection_id' => Uuid::fromHexToBytes($connectionId),
            'provider_release_id' => $releaseId,
            'provider_asset_id' => $assetId,
            'tag' => $tag,
            'asset_name' => $assetName,
            'version' => $version,
            'release_notes' => $releaseNotes,
            'sha256' => $sha256,
            'media_id' => Uuid::fromHexToBytes($mediaId),
            'product_download_id' => Uuid::fromHexToBytes($productDownloadId),
            'released_at' => $date,
            'created_at' => $this->now(),
        ]);
    }

    public function updateImportedReleaseNotes(
        string $connectionId,
        string $releaseId,
        ?string $releaseNotes
    ): void {
        $this->connection->update(
            'extension_mesh_repository_release',
            ['release_notes' => $releaseNotes],
            [
                'connection_id' => Uuid::fromHexToBytes($connectionId),
                'provider_release_id' => $releaseId,
            ]
        );
    }

    public function acquireLock(string $id): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT GET_LOCK(:name, 0)',
            ['name' => 'extension_mesh_repository_' . $id]
        ) === 1;
    }

    public function releaseLock(string $id): void
    {
        $this->connection->executeQuery(
            'SELECT RELEASE_LOCK(:name)',
            ['name' => 'extension_mesh_repository_' . $id]
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => Uuid::fromBytesToHex((string) $row['id']),
            'provider' => (string) $row['provider'],
            'repository' => (string) $row['repository'],
            'apiBaseUrl' => (string) $row['api_base_url'],
            'webUrl' => \is_string($row['web_url'] ?? null) ? $row['web_url'] : null,
            'defaultBranch' => \is_string($row['default_branch'] ?? null) ? $row['default_branch'] : null,
            'private' => (bool) $row['repository_private'],
            'credentialCiphertext' => \is_string($row['credential_ciphertext'] ?? null)
                ? $row['credential_ciphertext']
                : null,
            'credentialFingerprint' => \is_string($row['credential_fingerprint'] ?? null)
                ? $row['credential_fingerprint']
                : null,
            'productId' => $row['product_id'] === null
                ? null
                : Uuid::fromBytesToHex((string) $row['product_id']),
            'technicalName' => \is_string($row['technical_name'] ?? null) ? $row['technical_name'] : null,
            'configPath' => \is_string($row['config_path'] ?? null) ? $row['config_path'] : null,
            'onboardingMode' => \is_string($row['onboarding_mode'] ?? null)
                ? $row['onboarding_mode']
                : null,
            'onboardingStatus' => \is_string($row['onboarding_status'] ?? null)
                ? $row['onboarding_status']
                : 'ready',
            'onboardingStage' => \is_string($row['onboarding_stage'] ?? null)
                ? $row['onboarding_stage']
                : null,
            'enabled' => (bool) $row['enabled'],
            'lastSyncedAt' => \is_string($row['last_synced_at'] ?? null) ? $row['last_synced_at'] : null,
            'lastError' => \is_string($row['last_error'] ?? null) ? $row['last_error'] : null,
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => \is_string($row['updated_at'] ?? null) ? $row['updated_at'] : null,
        ];
    }

    private function now(): string
    {
        return $this->date(new \DateTimeImmutable());
    }

    private function date(\DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d H:i:s.v');
    }
}
