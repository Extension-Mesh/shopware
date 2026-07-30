<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Infrastructure\Http;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;

final class NetworkPolicy
{
    private readonly bool $allowPrivateNetworks;

    public function __construct(bool $allowPrivateNetworks, bool $debug = false)
    {
        // Debug mode is already unsuitable for production and keeps local
        // Dockware/Docker registries usable when PHP-FPM clears process env.
        $this->allowPrivateNetworks = $allowPrivateNetworks || $debug;
    }

    /**
     * Validates a URL immediately before a request and returns a resolved IP
     * that the HTTP client can pin for this connection.
     */
    public function resolveAllowedIp(string $url): string
    {
        $parts = \parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw ExtensionMeshException::invalidRegistryUrl('the request target is not an absolute URL.');
        }

        $scheme = \strtolower($parts['scheme']);
        if ($scheme !== 'https' && !($this->allowPrivateNetworks && $scheme === 'http')) {
            throw ExtensionMeshException::networkTargetRejected($parts['host']);
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw ExtensionMeshException::networkTargetRejected($parts['host']);
        }

        $host = \strtolower(\rtrim($parts['host'], '.'));
        if ($host === '') {
            throw ExtensionMeshException::networkTargetRejected($host);
        }

        $addresses = $this->resolveHost($host);
        foreach ($addresses as $address) {
            if (!$this->allowPrivateNetworks && !$this->isPublicIp($address)) {
                throw ExtensionMeshException::networkTargetRejected($host);
            }
        }

        if ($addresses === []) {
            throw ExtensionMeshException::registryUnavailable(\sprintf('host "%s" could not be resolved.', $host));
        }

        return $addresses[0];
    }

    /**
     * @return list<string>
     */
    private function resolveHost(string $host): array
    {
        if (\filter_var($host, \FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = \dns_get_record($host, \DNS_A | \DNS_AAAA);
        if ($records === false) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (\is_string($address) && !\in_array($address, $addresses, true)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    private function isPublicIp(string $address): bool
    {
        return \filter_var(
            $address,
            \FILTER_VALIDATE_IP,
            \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
