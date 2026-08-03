<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Infrastructure\Http;

use ExtensionMesh\Shopware\Infrastructure\Http\NetworkPolicy;
use ExtensionMesh\Shopware\Infrastructure\Http\SafeHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SafeHttpClientCredentialTest extends TestCase
{
    public function testItSendsTheBearerTokenToTheConfiguredOrigin(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('http://127.0.0.1/registry.json', $url);
            self::assertContains('Authorization: Bearer paid-token', $options['normalized_headers']['authorization']);
            self::assertGreaterThan(44.0, $options['max_duration']);
            self::assertLessThanOrEqual(45.0, $options['max_duration']);

            return new MockResponse('{"schemaVersion":1,"name":"test","extensions":[]}');
        });
        $safeClient = new SafeHttpClient(
            $client,
            new NetworkPolicy(true),
            new Filesystem()
        );

        self::assertStringContainsString(
            '"schemaVersion":1',
            $safeClient->getRegistry('http://127.0.0.1/registry.json', 'paid-token')
        );
    }

    public function testItStripsTheBearerTokenOnACrossOriginRedirect(): void
    {
        $request = 0;
        $client = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$request): MockResponse {
                ++$request;
                if ($request === 1) {
                    self::assertContains('Authorization: Bearer paid-token', $options['normalized_headers']['authorization']);

                    return new MockResponse('', [
                        'http_code' => 302,
                        'response_headers' => ['Location: http://127.0.0.2/registry.json'],
                    ]);
                }

                self::assertSame('http://127.0.0.2/registry.json', $url);
                self::assertArrayNotHasKey('authorization', $options['normalized_headers']);

                return new MockResponse('{"schemaVersion":1,"name":"test","extensions":[]}');
            }
        );
        $safeClient = new SafeHttpClient(
            $client,
            new NetworkPolicy(true),
            new Filesystem()
        );

        $safeClient->getRegistry('http://127.0.0.1/registry.json', 'paid-token');
        self::assertSame(2, $request);
    }
}
