<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Infrastructure\Persistence\RepositoryConnectionRepository;
use ExtensionMesh\Shopware\Infrastructure\Security\CredentialCipher;
use ExtensionMesh\Shopware\Message\RepositoryProcessMessage;
use ExtensionMesh\Shopware\Repository\RepositoryProductMetadataLoader;
use ExtensionMesh\Shopware\Repository\RepositoryProviderRegistry;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;

final class RepositoryOnboardingService
{
    public function __construct(
        private readonly RepositoryConnectionRepository $connections,
        private readonly RepositoryProviderRegistry $providers,
        private readonly RepositoryProductMetadataLoader $metadata,
        private readonly RepositorySynchronizer $synchronizer,
        private readonly RepositoryProductWriter $products,
        private readonly RepositoryCredentialService $credentials,
        private readonly CredentialCipher $cipher,
        private readonly MessageBusInterface $messageBus
    ) {
    }

    /**
     * @return array{
     *     data: list<array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     limit: int
     * }
     */
    public function paginate(int $page, int $limit): array
    {
        $result = $this->connections->paginate($page, $limit);

        return [
            'data' => \array_map($this->publicConnection(...), $result['items']),
            'total' => $result['total'],
            'page' => $result['page'],
            'limit' => $result['limit'],
        ];
    }

    /**
     * @return list<array{key: string, label: string, defaultApiBaseUrl: string}>
     */
    public function providers(): array
    {
        return $this->providers->descriptors();
    }

    /**
     * @return array<string, mixed>
     */
    public function connect(
        string $providerKey,
        string $repository,
        string $apiBaseUrl,
        string $credential,
        string $mode,
        ?string $productId
    ): array {
        $credential = $this->credential($credential);
        $provider = $this->providers->get($providerKey);
        $repository = \trim($repository);
        $apiBaseUrl = \rtrim(\trim($apiBaseUrl), '/');
        if ($repository === '' || \strlen($repository) > 255) {
            throw ExtensionMeshException::invalidRepository(
                'the repository name must contain 1 to 255 bytes.'
            );
        }
        if ($apiBaseUrl === '' || \strlen($apiBaseUrl) > 512) {
            throw ExtensionMeshException::invalidRepository(
                'the provider API URL must contain 1 to 512 bytes.'
            );
        }
        if (!\in_array($mode, ['import', 'link'], true)) {
            throw ExtensionMeshException::invalidRepository('mode must be "link" or "import".');
        }
        if ($mode === 'link') {
            if (!\is_string($productId)) {
                throw ExtensionMeshException::invalidRepository('link mode requires a Shopware product.');
            }
            $this->products->assertProductExists($productId);
        } else {
            $productId = null;
        }
        if ($this->connections->exists($provider->key(), $repository, $apiBaseUrl)) {
            throw ExtensionMeshException::invalidRepository('this repository is already connected.');
        }

        $id = $this->connections->createQueued(
            $provider->key(),
            $repository,
            $apiBaseUrl,
            $credential === '' ? null : $this->cipher->encrypt($credential),
            $credential === '' ? null : $this->cipher->fingerprint($credential),
            $productId,
            $mode
        );
        try {
            $this->messageBus->dispatch(new RepositoryProcessMessage($id));
        } catch (\Throwable $exception) {
            $this->connections->markFailed($id, $exception->getMessage());
            throw $exception;
        }

        return $this->publicConnection(
            $this->connections->get($id)
                ?? throw ExtensionMeshException::repositoryNotFound($id)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function queueSynchronization(string $id): array
    {
        $connection = $this->connections->get($id);
        if ($connection === null) {
            throw ExtensionMeshException::repositoryNotFound($id);
        }
        $stage = RepositoryProcessMessage::STAGE_SYNCHRONIZE;
        if (!$connection['enabled']) {
            $failedStage = $connection['onboardingStage'] ?? null;
            if (!\in_array($failedStage, [
                RepositoryProcessMessage::STAGE_INSPECT,
                RepositoryProcessMessage::STAGE_PREPARE,
            ], true)) {
                throw ExtensionMeshException::invalidRepository(
                    'repository onboarding must finish before it can be synchronized.'
                );
            }
            $stage = $failedStage;
        }
        $this->connections->markProcessing($id, 'queued', $stage);
        $this->messageBus->dispatch(new RepositoryProcessMessage(
            $id,
            $stage
        ));

        return $this->publicConnection(
            $this->connections->get($id)
                ?? throw ExtensionMeshException::repositoryNotFound($id)
        );
    }

    public function inspectQueued(string $id): bool
    {
        $connection = $this->connections->get($id);
        if ($connection === null) {
            return false;
        }
        if ($connection['onboardingStatus'] === 'preparing') {
            return true;
        }
        if (\in_array($connection['onboardingStatus'], ['synchronizing', 'ready'], true)) {
            return false;
        }

        $this->connections->markProcessing($id, 'inspecting', RepositoryProcessMessage::STAGE_INSPECT);
        $credential = $this->credentials->resolveForInspection($connection);
        $provider = $this->providers->get((string) $connection['provider']);
        $inspection = $provider->inspect(
            (string) $connection['repository'],
            (string) $connection['apiBaseUrl'],
            $credential
        );
        if ($this->connections->exists(
            $provider->key(),
            $inspection['repository'],
            $inspection['apiBaseUrl'],
            $id
        )) {
            throw ExtensionMeshException::invalidRepository('this repository is already connected.');
        }
        $this->connections->storeInspection($id, $inspection);

        return true;
    }

    /**
     * @return array{prepared: bool, nextOffset: ?int}
     */
    public function prepareQueued(string $id, Context $context, int $offset = 0): array
    {
        $connection = $this->connections->get($id);
        if ($connection === null) {
            return ['prepared' => false, 'nextOffset' => null];
        }
        if (\in_array($connection['onboardingStatus'], ['synchronizing', 'ready'], true)) {
            return ['prepared' => false, 'nextOffset' => null];
        }
        if (
            !\is_string($connection['defaultBranch'] ?? null)
            || !\is_string($connection['webUrl'] ?? null)
        ) {
            throw ExtensionMeshException::invalidRepository(
                'repository inspection must finish before product preparation.'
            );
        }

        $this->connections->markProcessing($id, 'preparing', RepositoryProcessMessage::STAGE_PREPARE);
        $credential = $this->credentials->resolveForInspection($connection);
        $provider = $this->providers->get((string) $connection['provider']);
        $discovery = $this->synchronizer->discoverLatestBatch(
            $provider,
            (string) $connection['repository'],
            (string) $connection['apiBaseUrl'],
            $credential,
            $offset
        );
        if (!\is_array($discovery['match'])) {
            return ['prepared' => false, 'nextOffset' => $discovery['nextOffset']];
        }
        $latest = $discovery['match'];
        $metadata = $this->metadata->load(
            $provider,
            (string) $connection['repository'],
            (string) $connection['apiBaseUrl'],
            $credential,
            (string) $connection['defaultBranch']
        );

        $mode = $connection['onboardingMode'] ?? null;
        $productId = $connection['productId'] ?? null;
        if ($mode === 'link') {
            if (!\is_string($productId)) {
                throw ExtensionMeshException::invalidRepository('link mode requires a Shopware product.');
            }
            $this->products->assertProductExists($productId);
        } elseif ($mode === 'import') {
            if (!\is_string($productId)) {
                $productId = Uuid::randomHex();
                $this->connections->reserveProduct($id, $productId);
            }
            if (!$this->products->productExists($productId)) {
                $icon = null;
                if (\is_string($metadata['iconPath'])) {
                    $icon = $provider->readFile(
                        (string) $connection['repository'],
                        (string) $connection['apiBaseUrl'],
                        $credential,
                        $metadata['iconPath'],
                        (string) $connection['defaultBranch']
                    );
                }
                $images = [];
                foreach ($metadata['imagePaths'] as $imagePath) {
                    $image = $provider->readFile(
                        (string) $connection['repository'],
                        (string) $connection['apiBaseUrl'],
                        $credential,
                        $imagePath,
                        (string) $connection['defaultBranch']
                    );
                    if ($image !== null) {
                        $images[] = $image;
                    }
                }
                $this->products->createDraftProduct(
                    $latest['archive'],
                    $metadata,
                    (string) $connection['repository'],
                    $icon,
                    $images,
                    $context,
                    $productId
                );
            }
        } else {
            throw ExtensionMeshException::invalidRepository('the onboarding mode is invalid.');
        }

        $this->connections->markPrepared(
            $id,
            $productId,
            (string) $latest['archive']['name'],
            $metadata['configPath']
        );

        return ['prepared' => true, 'nextOffset' => null];
    }

    public function hasConnection(string $id): bool
    {
        return $this->connections->get($id) !== null;
    }

    public function markSynchronizing(string $id): void
    {
        if ($this->connections->get($id) !== null) {
            $this->connections->markProcessing(
                $id,
                'synchronizing',
                RepositoryProcessMessage::STAGE_SYNCHRONIZE
            );
        }
    }

    public function markFailed(string $id, string $message): void
    {
        if ($this->connections->get($id) !== null) {
            $this->connections->markFailed($id, $message);
        }
    }

    /**
     * @return list<string>
     */
    public function readyConnectionIds(): array
    {
        $ids = [];
        foreach ($this->connections->all(true) as $connection) {
            if (
                \is_string($connection['productId'] ?? null)
                && \in_array($connection['onboardingStatus'], ['ready', 'failed'], true)
            ) {
                $ids[] = (string) $connection['id'];
            }
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    public function updateCredential(string $id, string $credential): array
    {
        $connection = $this->connections->get($id);
        if ($connection === null) {
            throw ExtensionMeshException::repositoryNotFound($id);
        }
        $credential = $this->credential($credential);
        $this->providers->get((string) $connection['provider'])->inspect(
            (string) $connection['repository'],
            (string) $connection['apiBaseUrl'],
            $credential
        );
        $this->connections->updateCredential(
            $id,
            $credential === '' ? null : $this->cipher->encrypt($credential),
            $credential === '' ? null : $this->cipher->fingerprint($credential)
        );

        return $this->publicConnection(
            $this->connections->get($id)
                ?? throw ExtensionMeshException::repositoryNotFound($id)
        );
    }

    public function unlink(string $id): void
    {
        if ($this->connections->get($id) === null) {
            throw ExtensionMeshException::repositoryNotFound($id);
        }
        $this->connections->delete($id);
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>
     */
    private function publicConnection(array $connection): array
    {
        $connection['hasCredential'] = \is_string($connection['credentialCiphertext'] ?? null);
        unset($connection['credentialCiphertext']);

        return $connection;
    }

    private function credential(string $credential): string
    {
        $credential = \trim($credential);
        if ($credential === '') {
            return '';
        }
        if (
            \strlen($credential) > 1024
            || \preg_match('/[\x00-\x20\x7f]/', $credential)
        ) {
            throw ExtensionMeshException::invalidRepository(
                'the provider token must contain no more than 1024 non-whitespace bytes.'
            );
        }

        return $credential;
    }
}
