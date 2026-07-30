<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

final class ExtensionOwnershipRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT `technical_name`, `registry_url`
               FROM `extension_mesh_extension_ownership`'
        );

        $ownership = [];
        foreach ($rows as $technicalName => $registryUrl) {
            if (\is_string($technicalName) && \is_string($registryUrl)) {
                $ownership[$technicalName] = $registryUrl;
            }
        }

        return $ownership;
    }

    public function markPrepared(string $technicalName, string $registryUrl): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO `extension_mesh_extension_ownership` (
                    `technical_name`,
                    `registry_url`,
                    `prepared_at`
                ) VALUES (
                    :technicalName,
                    :registryUrl,
                    :preparedAt
                )
                ON DUPLICATE KEY UPDATE
                    `registry_url` = VALUES(`registry_url`),
                    `prepared_at` = VALUES(`prepared_at`)
            SQL,
            [
                'technicalName' => $technicalName,
                'registryUrl' => $registryUrl,
                'preparedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
            ]
        );
    }
}
