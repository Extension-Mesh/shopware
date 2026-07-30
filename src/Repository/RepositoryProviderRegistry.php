<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Repository;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;

final class RepositoryProviderRegistry
{
    /**
     * @var array<string, RepositoryProvider>
     */
    private array $providers = [];

    /**
     * @param iterable<RepositoryProvider> $providers
     */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $key = \strtolower($provider->key());
            if ($key === '' || isset($this->providers[$key])) {
                throw new \LogicException(\sprintf('Duplicate or empty repository provider key "%s".', $key));
            }
            $this->providers[$key] = $provider;
        }
    }

    public function get(string $key): RepositoryProvider
    {
        $key = \strtolower(\trim($key));

        return $this->providers[$key]
            ?? throw ExtensionMeshException::invalidRepository(\sprintf('provider "%s" is not supported.', $key));
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return \array_keys($this->providers);
    }

    /**
     * @return list<array{key: string, label: string, defaultApiBaseUrl: string}>
     */
    public function descriptors(): array
    {
        return \array_values(\array_map(
            static fn (RepositoryProvider $provider): array => [
                'key' => $provider->key(),
                'label' => $provider->label(),
                'defaultApiBaseUrl' => $provider->defaultApiBaseUrl(),
            ],
            $this->providers
        ));
    }
}
