<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Repository;

use ExtensionMesh\Shopware\Infrastructure\Http\NetworkPolicy;
use ExtensionMesh\Shopware\Infrastructure\Http\SafeHttpClient;
use ExtensionMesh\Shopware\Repository\GithubRepositoryProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GithubRepositoryProviderTest extends TestCase
{
    public function testItUsesAnonymousRequestsForPublicRepositories(): void
    {
        $client = new MockHttpClient(
            static function (string $method, string $url, array $options): MockResponse {
                self::assertSame('http://127.0.0.1/repos/acme/public-plugin', $url);
                self::assertArrayNotHasKey('authorization', $options['normalized_headers']);

                return new MockResponse((string) \json_encode([
                    'full_name' => 'acme/public-plugin',
                    'html_url' => 'https://github.test/acme/public-plugin',
                    'default_branch' => 'main',
                    'private' => false,
                ], \JSON_THROW_ON_ERROR));
            }
        );
        $provider = new GithubRepositoryProvider(
            new SafeHttpClient($client, new NetworkPolicy(true), new Filesystem())
        );

        $inspection = $provider->inspect(
            'acme/public-plugin',
            'http://127.0.0.1',
            ''
        );

        self::assertFalse($inspection['private']);
    }

    public function testItNormalizesPrivateRepositoryMetadataAndStableZipReleases(): void
    {
        $request = 0;
        $client = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$request): MockResponse {
                ++$request;
                self::assertContains(
                    'Authorization: Bearer github-token',
                    $options['normalized_headers']['authorization']
                );

                if ($request === 1) {
                    self::assertSame('http://127.0.0.1/repos/acme/private-plugin', $url);

                    return new MockResponse((string) \json_encode([
                        'full_name' => 'acme/private-plugin',
                        'html_url' => 'https://github.test/acme/private-plugin',
                        'default_branch' => 'main',
                        'private' => true,
                    ], \JSON_THROW_ON_ERROR));
                }

                if ($request === 2) {
                    self::assertSame(
                        'http://127.0.0.1/repos/acme/private-plugin/releases?per_page=100',
                        $url
                    );

                    return new MockResponse((string) \json_encode([
                        [
                            'id' => 10,
                            'tag_name' => 'v1.2.0',
                            'name' => 'Stable',
                            'body' => "Release notes\n\n- Important fix",
                            'published_at' => '2026-07-28T12:00:00Z',
                            'draft' => false,
                            'prerelease' => false,
                            'assets' => [
                                [
                                    'id' => 100,
                                    'name' => 'AcmePlugin-1.2.0.zip',
                                    'size' => 1234,
                                    'url' => 'http://127.0.0.1/repos/acme/private-plugin/releases/assets/100',
                                ],
                                [
                                    'id' => 101,
                                    'name' => 'checksums.txt',
                                    'size' => 123,
                                    'url' => 'http://127.0.0.1/repos/acme/private-plugin/releases/assets/101',
                                ],
                            ],
                        ],
                        [
                            'id' => 11,
                            'tag_name' => 'v1.3.0-beta',
                            'name' => 'Beta',
                            'published_at' => '2026-07-29T12:00:00Z',
                            'draft' => false,
                            'prerelease' => true,
                            'assets' => [],
                        ],
                    ], \JSON_THROW_ON_ERROR));
                }

                self::assertSame(
                    'http://127.0.0.1/repos/acme/private-plugin/contents/src/Resources/store/images/en?ref=main',
                    $url
                );

                return new MockResponse((string) \json_encode([
                    [
                        'type' => 'file',
                        'path' => 'src/Resources/store/images/en/1.png',
                        'size' => 100,
                    ],
                    [
                        'type' => 'file',
                        'path' => 'src/Resources/store/images/en/too-large.png',
                        'size' => 3 * 1024 * 1024,
                    ],
                    [
                        'type' => 'dir',
                        'path' => 'src/Resources/store/images/en/nested',
                        'size' => 0,
                    ],
                ], \JSON_THROW_ON_ERROR));
            }
        );
        $provider = new GithubRepositoryProvider(
            new SafeHttpClient($client, new NetworkPolicy(true), new Filesystem())
        );

        $inspection = $provider->inspect(
            'https://github.com/acme/private-plugin.git',
            'http://127.0.0.1',
            'github-token'
        );
        self::assertTrue($inspection['private']);
        self::assertSame('main', $inspection['defaultBranch']);

        $releases = $provider->releases(
            'acme/private-plugin',
            'http://127.0.0.1',
            'github-token'
        );
        self::assertCount(1, $releases);
        self::assertSame('AcmePlugin-1.2.0.zip', $releases[0]['assets'][0]['name']);
        self::assertSame("Release notes\n\n- Important fix", $releases[0]['releaseNotes']);
        self::assertCount(1, $releases[0]['assets']);

        self::assertSame([[
            'path' => 'src/Resources/store/images/en/1.png',
            'size' => 100,
        ]], $provider->listFiles(
            'acme/private-plugin',
            'http://127.0.0.1',
            'github-token',
            'src/Resources/store/images/en',
            'main'
        ));
    }

    public function testAssetCredentialIsStrippedFromCrossOriginRedirect(): void
    {
        $request = 0;
        $client = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$request): MockResponse {
                ++$request;
                if ($request === 1) {
                    self::assertContains(
                        'Authorization: Bearer github-token',
                        $options['normalized_headers']['authorization']
                    );

                    return new MockResponse('', [
                        'http_code' => 302,
                        'response_headers' => ['Location: http://127.0.0.2/plugin.zip'],
                    ]);
                }

                self::assertSame('http://127.0.0.2/plugin.zip', $url);
                self::assertArrayNotHasKey('authorization', $options['normalized_headers']);

                return new MockResponse('zip bytes');
            }
        );
        $provider = new GithubRepositoryProvider(
            new SafeHttpClient($client, new NetworkPolicy(true), new Filesystem())
        );

        $path = $provider->downloadAsset('http://127.0.0.1', 'github-token', [
            'id' => '100',
            'name' => 'AcmePlugin.zip',
            'size' => 9,
            'apiUrl' => 'http://127.0.0.1/repos/acme/private-plugin/releases/assets/100',
        ]);
        self::assertSame('zip bytes', \file_get_contents($path));
        @\unlink(\sys_get_temp_dir() . \DIRECTORY_SEPARATOR . \basename($path));
        self::assertSame(2, $request);
    }
}
