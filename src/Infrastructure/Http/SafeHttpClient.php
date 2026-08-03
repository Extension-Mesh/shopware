<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Infrastructure\Http;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class SafeHttpClient
{
    private const MAX_REDIRECTS = 5;
    private const DOCUMENT_MAX_DURATION = 45.0;
    private const ARTIFACT_MAX_DURATION = 600.0;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly NetworkPolicy $networkPolicy,
        private readonly Filesystem $filesystem
    ) {
    }

    public function getRegistry(string $url, ?string $accessToken = null): string
    {
        return $this->getDocument(
            $url,
            $accessToken === null ? [] : ['Authorization' => 'Bearer ' . $accessToken],
            $this->origin($url)
        );
    }

    /**
     * Fetches a small provider document while keeping credentials on one origin.
     *
     * @param array<string, string> $credentialHeaders
     */
    public function getDocument(
        string $url,
        array $credentialHeaders = [],
        ?string $credentialOrigin = null,
        string $accept = 'application/json',
        int $maxBytes = 2097152
    ): string {
        if ($maxBytes < 1 || $maxBytes > 2 * 1024 * 1024) {
            throw new \InvalidArgumentException('Document size limit must be between 1 byte and 2 MiB.');
        }

        $response = $this->requestFollowingRedirects(
            $url,
            $credentialHeaders,
            $credentialOrigin,
            $accept,
            self::DOCUMENT_MAX_DURATION
        );
        $content = '';

        try {
            foreach ($this->client->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    throw ExtensionMeshException::registryUnavailable('the request timed out.');
                }

                $content .= $chunk->getContent();
                if (\strlen($content) > $maxBytes) {
                    throw ExtensionMeshException::registryUnavailable('the response document exceeds its size limit.');
                }
            }
        } catch (TransportExceptionInterface $exception) {
            throw ExtensionMeshException::registryUnavailable($exception->getMessage());
        }

        return $content;
    }

    public function downloadArtifact(
        string $url,
        ?string $accessToken = null,
        ?string $credentialOrigin = null
    ): string
    {
        return $this->downloadFile(
            $url,
            $accessToken === null ? [] : ['Authorization' => 'Bearer ' . $accessToken],
            $credentialOrigin
        );
    }

    /**
     * Streams an external artifact to a temporary file.
     *
     * @param array<string, string> $credentialHeaders
     */
    public function downloadFile(
        string $url,
        array $credentialHeaders = [],
        ?string $credentialOrigin = null,
        string $accept = 'application/zip, application/octet-stream;q=0.9'
    ): string {
        $response = $this->requestFollowingRedirects(
            $url,
            $credentialHeaders,
            $credentialOrigin,
            $accept,
            self::ARTIFACT_MAX_DURATION
        );
        $temporaryPath = \tempnam(\sys_get_temp_dir(), 'extension-mesh-');
        if (!\is_string($temporaryPath)) {
            throw ExtensionMeshException::registryUnavailable('a temporary file could not be created.');
        }

        $file = new \SplFileObject($temporaryPath, 'wb');

        $bytes = 0;

        try {
            foreach ($this->client->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    throw ExtensionMeshException::registryUnavailable('the artifact download timed out.');
                }

                $content = $chunk->getContent();
                $bytes += \strlen($content);
                if ($bytes > 100 * 1024 * 1024) {
                    throw ExtensionMeshException::artifactRejected('the download exceeds the 100 MiB limit.');
                }

                if ($content !== '' && $file->fwrite($content) === 0) {
                    throw ExtensionMeshException::registryUnavailable('the artifact could not be written.');
                }
            }
        } catch (TransportExceptionInterface $exception) {
            $this->filesystem->remove($temporaryPath);
            throw ExtensionMeshException::registryUnavailable($exception->getMessage());
        } catch (\Throwable $exception) {
            $this->filesystem->remove($temporaryPath);
            throw $exception;
        }

        return $temporaryPath;
    }

    /**
     * @param array<string, string> $credentialHeaders
     */
    private function requestFollowingRedirects(
        string $url,
        array $credentialHeaders = [],
        ?string $credentialOrigin = null,
        string $accept = 'application/json',
        float $maxDuration = self::DOCUMENT_MAX_DURATION
    ): ResponseInterface
    {
        $credentialHeaders = $this->validateCredentialHeaders($credentialHeaders);
        $deadline = \microtime(true) + $maxDuration;

        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; ++$redirects) {
            $remainingDuration = $deadline - \microtime(true);
            if ($remainingDuration <= 0) {
                throw ExtensionMeshException::registryUnavailable('the request exceeded its total duration limit.');
            }

            $parts = \parse_url($url);
            if ($parts === false || !isset($parts['host'])) {
                throw ExtensionMeshException::invalidRegistryUrl('a redirect target is invalid.');
            }

            $resolvedIp = $this->networkPolicy->resolveAllowedIp($url);
            try {
                $headers = [
                    'Accept' => $accept,
                    'User-Agent' => 'ExtensionMesh-Shopware/1',
                ];
                if (
                    $credentialHeaders !== []
                    && $credentialOrigin !== null
                    && \hash_equals($credentialOrigin, $this->origin($url))
                ) {
                    $headers = [...$headers, ...$credentialHeaders];
                }

                $response = $this->client->request('GET', $url, [
                    'headers' => $headers,
                    'max_redirects' => 0,
                    'timeout' => 30,
                    'max_duration' => $remainingDuration,
                    'resolve' => [$parts['host'] => $resolvedIp],
                ]);

                $status = $response->getStatusCode();
            } catch (TransportExceptionInterface $exception) {
                throw ExtensionMeshException::registryUnavailable($exception->getMessage());
            }
            if ($status >= 200 && $status < 300) {
                return $response;
            }

            if (!\in_array($status, [301, 302, 303, 307, 308], true)) {
                throw ExtensionMeshException::registryUnavailable(\sprintf('server returned HTTP %d.', $status));
            }

            $headers = $response->getHeaders(false);
            $location = $headers['location'][0] ?? null;
            if (!\is_string($location) || $location === '') {
                throw ExtensionMeshException::registryUnavailable('a redirect had no Location header.');
            }

            $response->cancel();
            $url = $this->resolveRedirect($url, $location);
        }

        throw ExtensionMeshException::registryUnavailable('too many redirects.');
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private function validateCredentialHeaders(array $headers): array
    {
        $allowed = ['authorization', 'private-token', 'job-token'];
        $validated = [];

        foreach ($headers as $name => $value) {
            if (
                !\in_array(\strtolower($name), $allowed, true)
                || $value === ''
                || \strlen($value) > 2048
                || \preg_match('/[\r\n]/', $value)
            ) {
                throw ExtensionMeshException::invalidCredential('a provider credential header is invalid.');
            }
            $validated[$name] = $value;
        }

        return $validated;
    }

    private function resolveRedirect(string $baseUrl, string $location): string
    {
        if (\preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $base = \parse_url($baseUrl);
        if ($base === false || !isset($base['scheme'], $base['host'])) {
            throw ExtensionMeshException::invalidRegistryUrl('the redirect base URL is invalid.');
        }

        $authority = $base['scheme'] . '://' . $base['host'];
        if (isset($base['port'])) {
            $authority .= ':' . $base['port'];
        }

        if (\str_starts_with($location, '//')) {
            return $base['scheme'] . ':' . $location;
        }

        if (\str_starts_with($location, '/')) {
            return $authority . $location;
        }

        $basePath = $base['path'] ?? '/';
        $directory = \rtrim(\str_replace('\\', '/', \dirname($basePath)), '/');

        return $authority . ($directory === '' ? '' : $directory) . '/' . $location;
    }

    private function origin(string $url): string
    {
        $parts = \parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw ExtensionMeshException::invalidRegistryUrl('the credential origin is invalid.');
        }

        $scheme = \strtolower($parts['scheme']);
        $host = \strtolower($parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }
}
